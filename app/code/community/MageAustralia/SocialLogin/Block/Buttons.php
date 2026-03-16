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
     * Get the social auth API endpoint URL.
     */
    public function getApiUrl(): string
    {
        return rtrim(Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB), '/') . '/api/customers/social-auth';
    }
}
