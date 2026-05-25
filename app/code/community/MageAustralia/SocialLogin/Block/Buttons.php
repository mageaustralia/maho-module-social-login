<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Block_Buttons extends Mage_Core_Block_Template
{
    /**
     * Get enabled social login providers with their config.
     */
    public function getProviders(): array
    {
        return Mage::helper('sociallogin')->getEnabledProviders();
    }

    /**
     * Check if any providers are enabled.
     */
    public function hasProviders(): bool
    {
        return !empty($this->getProviders());
    }

    /**
     * Frontend controller endpoint that verifies the credential and creates a
     * normal Maho customer session (storefront-independent — no headless API).
     */
    public function getLoginUrl(): string
    {
        return Mage::getUrl('sociallogin/auth/login');
    }
}
