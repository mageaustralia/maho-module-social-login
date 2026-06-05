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

    /**
     * Whether OTP (email-code) account linking is enabled.
     */
    public function isOtpEnabled(): bool
    {
        return Mage::helper('sociallogin')->isOtpEnabled();
    }

    /**
     * Endpoint that requests an OTP to link a social account (alternative to password).
     */
    public function getOtpLinkRequestUrl(): string
    {
        return Mage::getUrl('sociallogin/otp/link/request');
    }

    /**
     * Endpoint that verifies the link OTP and completes the social link.
     */
    public function getOtpLinkVerifyUrl(): string
    {
        return Mage::getUrl('sociallogin/otp/link/verify');
    }
}
