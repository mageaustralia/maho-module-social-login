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
            Maho\Db\Ddl\Table::TYPE_INTEGER,
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
            Maho\Db\Ddl\Table::TYPE_INTEGER,
            null,
            [
                'unsigned' => true,
                'nullable' => false,
            ],
            'Customer ID',
        )
        ->addColumn(
            'provider',
            Maho\Db\Ddl\Table::TYPE_TEXT,
            32,
            [
                'nullable' => false,
            ],
            'Provider Code (google, apple)',
        )
        ->addColumn(
            'provider_id',
            Maho\Db\Ddl\Table::TYPE_TEXT,
            255,
            [
                'nullable' => false,
            ],
            'Provider User ID (sub claim)',
        )
        ->addColumn(
            'provider_email',
            Maho\Db\Ddl\Table::TYPE_TEXT,
            255,
            [
                'nullable' => true,
                'default'  => null,
            ],
            'Email from Provider (stored on first auth)',
        )
        ->addColumn(
            'created_at',
            Maho\Db\Ddl\Table::TYPE_TIMESTAMP,
            null,
            [
                'nullable' => false,
                'default'  => Maho\Db\Ddl\Table::TIMESTAMP_INIT,
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
            Maho\Db\Ddl\Table::ACTION_CASCADE,
        )
        ->setComment('MageAustralia Social Login Identities');

    $connection->createTable($table);
}

$installer->endSetup();
