<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Resource_Otp extends Mage_Core_Model_Resource_Db_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('smslogin/otp', 'otp_id');
    }
}
