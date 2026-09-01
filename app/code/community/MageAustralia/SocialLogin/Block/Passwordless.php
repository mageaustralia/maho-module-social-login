<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Block_Passwordless extends Mage_Core_Block_Template
{
    public function shouldRender(): bool
    {
        return $this->isMagicLinkEnabled() || $this->isOtpEnabled();
    }

    public function isMagicLinkEnabled(): bool
    {
        return Mage::helper('customer')->isModuleEnabled('Mage_Customer')
            && Mage::helper('customer')->isMagicLinkEnabled();
    }

    public function getMagicLinkRequestUrl(): string
    {
        return $this->getUrl('customer/account/magiclinkrequestpost');
    }

    public function isOtpEnabled(): bool
    {
        // OTP is only usable when both the engine is on AND at least one
        // delivery channel is enabled. SMS (Clickatell) is the only channel
        // currently shipped, so hide the OTP UI entirely when SMS is off.
        $h = Mage::helper('sociallogin');
        return $h->isOtpEnabled() && $h->isOtpSmsEnabled();
    }

    public function getOtpRequestUrl(): string
    {
        return $this->getUrl('sociallogin/otp/request');
    }

    public function getOtpVerifyUrl(): string
    {
        return $this->getUrl('sociallogin/otp/verify');
    }

    #[\Override]
    public function getFormKey(): string
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }
}
