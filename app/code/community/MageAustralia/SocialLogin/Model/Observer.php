<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Observer
{
    /**
     * Inject social login provider config into the store config DTO
     * so the headless storefront can render provider buttons.
     */
    public function registerSocialLoginProviders(Varien_Event_Observer $observer): void
    {
        $dto = $observer->getEvent()->getDto();
        if (!property_exists($dto, 'extensions')) {
            return;
        }

        /** @var MageAustralia_SocialLogin_Helper_Data $helper */
        $helper = Mage::helper('sociallogin');
        $providers = $helper->getEnabledProviders();

        if (empty($providers)) {
            return;
        }

        $dto->extensions['socialLoginProviders'] = $providers;
    }

    /**
     * Add a "Pre-approve mobile from address" button to the admin customer edit
     * page when the customer has no verified mobile yet. Clicking it runs
     * Helper::promoteAddressMobileToCustomer() via the admin controller.
     *
     * Wired via the adminhtml_widget_container_html_before event so we can act
     * on the actual container (Mage_Adminhtml_Block_Customer_Edit) at the point
     * its button row is about to render.
     */
    public function addPromoteMobileButton(Varien_Event_Observer $observer): void
    {
        /** @var Mage_Adminhtml_Block_Widget_Container $container */
        $container = $observer->getEvent()->getBlock();
        if (!$container instanceof Mage_Adminhtml_Block_Customer_Edit) {
            return;
        }

        $customer = Mage::registry('current_customer');
        if (!$customer instanceof Mage_Customer_Model_Customer || !$customer->getId()) {
            return;
        }
        if ($customer->getMobileVerified()) {
            // Already has a verified mobile — nothing to promote.
            return;
        }

        $url = Mage::helper('adminhtml')->getUrl(
            'adminhtml/sociallogin_customer/promoteMobile',
            ['id' => $customer->getId()],
        );
        $confirm = Mage::helper('sociallogin')
            ->__('Pre-approve a verified mobile by copying it from this customer\'s address book?');

        $container->addButton('sociallogin_promote_mobile', [
            'label'   => Mage::helper('sociallogin')->__('Pre-approve mobile from address'),
            'onclick' => "confirmSetLocation('" . addslashes($confirm) . "', '" . $url . "')",
            'class'   => 'reset',
        ], 0, 10);
    }
}
