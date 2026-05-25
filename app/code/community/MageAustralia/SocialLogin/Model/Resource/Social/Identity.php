<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Resource_Social_Identity extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct(): void
    {
        $this->_init('sociallogin/social_identity', 'entity_id');
    }

    /**
     * Load by provider + provider_id (unique key).
     */
    public function loadByProviderIdentity(
        MageAustralia_SocialLogin_Model_Social_Identity $object,
        string $provider,
        string $providerId,
    ): self {
        $read = $this->_getReadAdapter();
        $select = $read->select()
            ->from($this->getMainTable())
            ->where('provider = ?', $provider)
            ->where('provider_id = ?', $providerId)
            ->limit(1);

        $data = $read->fetchRow($select);
        if ($data) {
            $object->setData($data);
        }

        return $this;
    }
}
