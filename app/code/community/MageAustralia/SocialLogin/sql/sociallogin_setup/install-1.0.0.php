<?php

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$tableName = $installer->getTable('mageaustralia_social_login');

if (!$connection->isTableExists($tableName)) {
    $table = $connection
        ->newTable($tableName)
        ->addColumn(
            'entity_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            [
                'identity' => true,
                'unsigned' => true,
                'nullable' => false,
                'primary'  => true,
            ],
            'Entity ID',
        )
        ->addColumn(
            'customer_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            [
                'unsigned' => true,
                'nullable' => false,
            ],
            'Customer ID',
        )
        ->addColumn(
            'provider',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            32,
            [
                'nullable' => false,
            ],
            'Provider Code (google, apple)',
        )
        ->addColumn(
            'provider_id',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            255,
            [
                'nullable' => false,
            ],
            'Provider User ID (sub claim)',
        )
        ->addColumn(
            'provider_email',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            255,
            [
                'nullable' => true,
                'default'  => null,
            ],
            'Email from Provider (stored on first auth)',
        )
        ->addColumn(
            'created_at',
            Varien_Db_Ddl_Table::TYPE_TIMESTAMP,
            null,
            [
                'nullable' => false,
                'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
            ],
            'Created At',
        )
        ->addIndex(
            $installer->getIdxName($tableName, ['provider', 'provider_id']),
            ['provider', 'provider_id'],
            ['type' => 'unique'],
        )
        ->addIndex(
            $installer->getIdxName($tableName, ['customer_id']),
            ['customer_id'],
        )
        ->addForeignKey(
            $installer->getFkName($tableName, 'customer_id', 'customer/entity', 'entity_id'),
            'customer_id',
            $installer->getTable('customer/entity'),
            'entity_id',
            Varien_Db_Ddl_Table::ACTION_CASCADE,
        )
        ->setComment('MageAustralia Social Login Identities');

    $connection->createTable($table);
}

$installer->endSetup();
