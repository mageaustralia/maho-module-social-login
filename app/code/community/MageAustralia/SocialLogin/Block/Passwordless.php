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
        return Mage::helper('sociallogin')->isOtpEnabled();
    }

    public function getOtpRequestUrl(): string
    {
        return $this->getUrl('sociallogin/otp/request');
    }

    public function getOtpVerifyUrl(): string
    {
        return $this->getUrl('sociallogin/otp/verify');
    }

    public function getFormKey(): string
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }
}
