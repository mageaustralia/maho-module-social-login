<?php

/**
 * @copyright Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license   https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

// Customer attributes live here rather than in sql/: on a fresh install the
// customer entity type is itself created by a data script, after every sql one
$customerSetup = new Mage_Customer_Model_Resource_Setup('core_setup');
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
