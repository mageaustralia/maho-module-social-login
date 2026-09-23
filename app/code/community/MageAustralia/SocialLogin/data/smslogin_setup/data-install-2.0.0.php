<?php

/**
 * @copyright Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license   https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * Social sign-in moved to Maho core (Maho_SocialLogin, 26.9+). Copy the linked
 * identities from this module's 1.x table into core's so existing customers
 * keep signing in with the same Google/Apple/Facebook account.
 *
 * The 1.x table is left in place: nothing reads it any more, but dropping
 * customer data belongs to a deliberate cleanup, not an upgrade. Re-running
 * is safe, rows core already has are skipped.
 */

/** @var Mage_Core_Model_Resource_Setup $this */
$installer = $this;
$connection = $installer->getConnection();

$oldTable = $installer->getTable('mageaustralia_social_login');
$newTable = $installer->getTable('social_login_identity');

if (!$connection->isTableExists($oldTable) || !$connection->isTableExists($newTable)) {
    return;
}

$defaultWebsiteId = (int) Mage::app()->getDefaultStoreView()?->getWebsiteId();

$rows = $connection->fetchAll(
    $connection->select()
        ->from(['o' => $oldTable], ['customer_id', 'provider', 'provider_id', 'provider_email', 'created_at'])
        ->join(['c' => $installer->getTable('customer/entity')], 'c.entity_id = o.customer_id', ['website_id']),
);

$copied = 0;
foreach ($rows as $row) {
    // Core keys identities by website; a customer with no website (global share)
    // belongs to the default one, which is where core's own lookup falls back
    $websiteId = (int) ($row['website_id'] ?: $defaultWebsiteId);

    $exists = $connection->fetchOne(
        $connection->select()
            ->from($newTable, ['identity_id'])
            ->where('provider = ?', $row['provider'])
            ->where('provider_id = ?', $row['provider_id'])
            ->where('website_id = ?', $websiteId),
    );
    if ($exists) {
        continue;
    }

    $connection->insert($newTable, [
        'customer_id'    => (int) $row['customer_id'],
        'website_id'     => $websiteId,
        'provider'       => $row['provider'],
        'provider_id'    => $row['provider_id'],
        'provider_email' => $row['provider_email'],
        'created_at'     => $row['created_at'] ?: Mage_Core_Model_Locale::nowUtc(),
    ]);
    $copied++;
}

Mage::log(
    sprintf('Copied %d of %d social identities to Maho core social_login_identity', $copied, count($rows)),
    Mage::LOG_INFO,
    'sociallogin_otp.log',
);
