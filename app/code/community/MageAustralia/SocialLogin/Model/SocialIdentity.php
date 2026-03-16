<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_SocialIdentity extends Mage_Core_Model_Abstract
{
    protected function _construct(): void
    {
        $this->_init('sociallogin/social_identity');
    }

    /**
     * Load a social identity by provider + provider_id.
     */
    public function loadByProviderIdentity(string $provider, string $providerId): self
    {
        $this->getResource()->loadByProviderIdentity($this, $provider, $providerId);
        return $this;
    }

    /**
     * Load all social identities for a customer.
     */
    public function loadByCustomerId(int $customerId): Mage_Core_Model_Resource_Db_Collection_Abstract
    {
        return $this->getCollection()
            ->addFieldToFilter('customer_id', $customerId);
    }
}
