<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Resource_Otp_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('sociallogin/otp');
    }
}
