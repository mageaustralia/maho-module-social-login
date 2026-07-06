<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Block_Mobile extends Mage_Core_Block_Template
{
    public function isEnabled(): bool
    {
        $helper = Mage::helper('sociallogin');
        return Mage::getSingleton('customer/session')->isLoggedIn()
            && $helper->isOtpEnabled() && $helper->isOtpSmsEnabled();
    }

    public function getCurrentMobile(): string
    {
        return (string) Mage::getSingleton('customer/session')->getCustomer()->getMobile();
    }

    public function isMobileVerified(): bool
    {
        return (bool) Mage::getSingleton('customer/session')->getCustomer()->getMobileVerified();
    }

    public function getRequestUrl(): string
    {
        return $this->getUrl('sociallogin/otp/request');
    }

    public function getVerifyUrl(): string
    {
        return $this->getUrl('sociallogin/otp/addMobile');
    }

    public function getFormKey(): string
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }
}
