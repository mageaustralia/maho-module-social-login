<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Resource_SocialIdentity_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct(): void
    {
        $this->_init('sociallogin/social_identity');
    }
}
