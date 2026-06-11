<?php

/**
 * @copyright Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license   https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * Admin actions on a single customer related to SocialLogin/OTP.
 *
 * Current actions:
 *   promoteMobile  — pre-approve a verified mobile by copying the best
 *                    candidate from the customer's address book onto the
 *                    customer record. Surfaced as a button on the customer
 *                    edit page via Observer::addPromoteMobileButton().
 */
class MageAustralia_SocialLogin_Adminhtml_Sociallogin_CustomerController extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = 'customer/sociallogin_promote_mobile';

    #[\Override]
    public function preDispatch(): static
    {
        $this->_setForcedFormKeyActions(['promoteMobile']);
        parent::preDispatch();
        return $this;
    }

    public function promoteMobileAction(): void
    {
        $customerId = (int) $this->getRequest()->getParam('id');
        $session    = Mage::getSingleton('adminhtml/session');

        if ($customerId <= 0) {
            $session->addError(Mage::helper('sociallogin')->__('Missing customer id.'));
            $this->_redirect('*/customer/index');
            return;
        }

        try {
            $mobile = Mage::helper('sociallogin')->promoteAddressMobileToCustomer($customerId);
            if ($mobile === null) {
                $session->addNotice(Mage::helper('sociallogin')->__(
                    'No valid mobile (per the configured default country) was found on this customer\'s addresses.',
                ));
            } else {
                $session->addSuccess(Mage::helper('sociallogin')->__('Pre-approved mobile: %s', $mobile));
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
            $session->addError(Mage::helper('sociallogin')->__('Promote failed: %s', $e->getMessage()));
        }

        $this->_redirect('*/customer/edit', ['id' => $customerId]);
    }

    #[\Override]
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed(self::ADMIN_RESOURCE);
    }
}
