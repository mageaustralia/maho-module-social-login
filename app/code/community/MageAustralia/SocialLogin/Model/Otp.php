<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Otp extends Mage_Core_Model_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('sociallogin/otp');
    }
}
