<?php

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $this */
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$table = $installer->getTable('sociallogin_otp');

if (!$connection->isTableExists($table)) {
    $t = $connection->newTable($table)
        ->addColumn('otp_id', Maho\Db\Ddl\Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true], 'OTP ID')
        ->addColumn('identifier', Maho\Db\Ddl\Table::TYPE_TEXT, 255, ['nullable' => false], 'Normalised email or E.164 mobile')
        ->addColumn('purpose', Maho\Db\Ddl\Table::TYPE_TEXT, 32, ['nullable' => false], 'login|register|link|add_mobile')
        ->addColumn('channel', Maho\Db\Ddl\Table::TYPE_TEXT, 16, ['nullable' => false], 'email|sms')
        ->addColumn('code_hash', Maho\Db\Ddl\Table::TYPE_TEXT, 128, ['nullable' => false], 'SHA-256 of code + pepper')
        ->addColumn('attempts', Maho\Db\Ddl\Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false, 'default' => 0], 'Verify attempts used')
        ->addColumn('expires_at', Maho\Db\Ddl\Table::TYPE_DATETIME, null, ['nullable' => false], 'Expiry')
        ->addColumn('consumed_at', Maho\Db\Ddl\Table::TYPE_DATETIME, null, ['nullable' => true, 'default' => null], 'Consumed (single-use)')
        ->addColumn('request_ip', Maho\Db\Ddl\Table::TYPE_TEXT, 45, ['nullable' => true, 'default' => null], 'Requesting IP')
        ->addColumn('created_at', Maho\Db\Ddl\Table::TYPE_DATETIME, null, ['nullable' => false], 'Created At')
        ->addIndex($installer->getIdxName($table, ['identifier', 'purpose']), ['identifier', 'purpose'])
        ->addIndex($installer->getIdxName($table, ['created_at']), ['created_at'])
        ->addIndex($installer->getIdxName($table, ['request_ip', 'created_at']), ['request_ip', 'created_at'])
        ->setComment('SocialLogin OTP codes');
    $connection->createTable($t);
}

/** @var Mage_Customer_Model_Resource_Setup $customerSetup */
$customerSetup = Mage::getResourceModel('customer/setup', ['core_setup']);
if (!$customerSetup->getAttribute('customer', 'mobile')) {
    $customerSetup->addAttribute('customer', 'mobile', [
        'type' => 'varchar', 'label' => 'Mobile', 'input' => 'text',
        'required' => false, 'visible' => true, 'user_defined' => true,
        'system' => false, 'position' => 100,
    ]);
}
if (!$customerSetup->getAttribute('customer', 'mobile_verified')) {
    $customerSetup->addAttribute('customer', 'mobile_verified', [
        'type' => 'datetime', 'label' => 'Mobile Verified At', 'input' => 'date',
        'required' => false, 'visible' => false, 'user_defined' => true, 'system' => false,
    ]);
}

$installer->endSetup();
