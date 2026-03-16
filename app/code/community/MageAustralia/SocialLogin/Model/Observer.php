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
}
