<?php

/**
 * @copyright Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license   https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backfill customer.mobile + mobile_verified by copying a valid mobile
 * (per the configured default country) from each customer's address book
 * onto their customer record. Run once after enabling SMS OTP to make
 * existing customers usable without forcing every one through a fresh
 * add-mobile flow.
 *
 * Skips customers who already have mobile_verified set (so it is safe to
 * re-run / schedule as a cron).
 *
 * Modes:
 *   --dry-run   report what would be promoted without writing
 *   --limit=N   process at most N candidates (0 = no cap)
 */
#[AsCommand(
    name: 'sociallogin:promote-address-mobiles',
    description: 'Pre-approve customer mobiles by copying a valid mobile from their address book onto the customer record',
)]
class SocialLoginPromoteAddressMobiles extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report candidates without writing to the DB.');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N promotions (0 = no cap).', '0');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = max(0, (int) $input->getOption('limit'));
        $helper = Mage::helper('sociallogin');

        $output->writeln('<info>sociallogin:promote-address-mobiles</info>');
        $output->writeln('default country = ' . $helper->getDefaultMobileCountry());
        $output->writeln('dry-run         = ' . ($dryRun ? 'yes' : 'no'));
        $output->writeln('limit           = ' . ($limit === 0 ? 'none' : (string) $limit));
        $output->writeln('');

        // Find customers without mobile_verified set. EAV: the absence of a row
        // in customer_entity_datetime for attribute_id 512 = unverified. We could
        // join EAV but the simplest portable path is "iterate customers and ask
        // the model" — Maho's customer collection eagerly loads attributes.
        $resource = Mage::getSingleton('core/resource');
        $read     = $resource->getConnection('core_read');

        $mobileAttrId   = $this->_getCustomerAttributeId($read, 'mobile');
        $verifiedAttrId = $this->_getCustomerAttributeId($read, 'mobile_verified');
        if ($mobileAttrId === null || $verifiedAttrId === null) {
            $output->writeln('<error>customer mobile / mobile_verified EAV attributes not installed</error>');
            return Command::FAILURE;
        }

        // Candidate set: customers with no row in customer_entity_datetime for
        // mobile_verified. Cheap to compute, scales to any catalog size.
        $select = $read->select()
            ->from(['ce' => $resource->getTableName('customer_entity')], ['entity_id', 'email'])
            ->joinLeft(
                ['cev' => $resource->getTableName('customer_entity_datetime')],
                "cev.entity_id = ce.entity_id AND cev.attribute_id = {$verifiedAttrId}",
                [],
            )
            ->where('cev.value_id IS NULL');

        $rows = $read->fetchAll($select);
        $output->writeln('candidates without mobile_verified: ' . count($rows));
        $output->writeln('');

        $promoted = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($rows as $row) {
            $customerId = (int) $row['entity_id'];
            $email      = (string) $row['email'];

            try {
                if ($dryRun) {
                    /** @var \Mage_Customer_Model_Customer $customer */
                    $customer = Mage::getModel('customer/customer')->load($customerId);
                    $mobile = $customer->getId() ? $helper->findValidMobileFromAddresses($customer) : null;
                    if ($mobile === null) {
                        $skipped++;
                        continue;
                    }
                    $output->writeln(sprintf('  WOULD promote #%d <%s>  ->  %s', $customerId, $email, $mobile));
                    $promoted++;
                } else {
                    $mobile = $helper->promoteAddressMobileToCustomer($customerId);
                    if ($mobile === null) {
                        $skipped++;
                        continue;
                    }
                    $output->writeln(sprintf('  promoted   #%d <%s>  ->  %s', $customerId, $email, $mobile));
                    $promoted++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $output->writeln(sprintf('<error>  failed     #%d <%s>: %s</error>', $customerId, $email, $e->getMessage()));
            }

            if ($limit > 0 && $promoted >= $limit) {
                $output->writeln('');
                $output->writeln("<comment>--limit={$limit} reached</comment>");
                break;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>done</info>  promoted=%d  skipped=%d  errors=%d  %s',
            $promoted,
            $skipped,
            $errors,
            $dryRun ? '(dry-run)' : '',
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function _getCustomerAttributeId(\Maho\Db\Adapter\AdapterInterface $read, string $code): ?int
    {
        $resource = Mage::getSingleton('core/resource');
        $row = $read->fetchRow(
            $read->select()
                ->from(['a' => $resource->getTableName('eav_attribute')], ['attribute_id'])
                ->join(
                    ['t' => $resource->getTableName('eav_entity_type')],
                    'a.entity_type_id = t.entity_type_id',
                    [],
                )
                ->where('t.entity_type_code = ?', 'customer')
                ->where('a.attribute_code = ?', $code),
        );
        return $row ? (int) $row['attribute_id'] : null;
    }
}
