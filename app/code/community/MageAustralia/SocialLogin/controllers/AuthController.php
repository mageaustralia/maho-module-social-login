<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_AuthController extends Mage_Core_Controller_Front_Action
{
    /**
     * Disable form key validation for the callback action.
     * The JWT itself serves as the authentication proof.
     */
    #[\Override]
    protected function _validateFormKey(): bool
    {
        if ($this->getRequest()->getActionName() === 'callback') {
            return true;
        }
        return parent::_validateFormKey();
    }

    /**
     * POST /sociallogin/auth/callback
     * Validates the JWT from the social auth API and creates a Maho customer session.
     */
    #[\Maho\Config\Route('/sociallogin/auth/callback', name: 'sociallogin.auth.callback')]
    public function callbackAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('customer/account/login');
            return;
        }

        $token = $this->getRequest()->getPost('token');
        if (empty($token)) {
            Mage::getSingleton('core/session')->addError('Authentication failed. Please try again.');
            $this->_redirect('customer/account/login');
            return;
        }

        try {
            // Validate the JWT using the same secret derivation as JwtService::getSecret()
            $secret = Mage::getStoreConfig('maho_apiplatform/oauth2/secret');
            if (empty($secret)) {
                $secret = Mage::getStoreConfig('maho_api/settings/jwt_secret');
            }
            if (empty($secret)) {
                $cryptKey = (string) Mage::getConfig()->getNode('global/crypt/key');
                $secret = hash('sha256', $cryptKey . ':maho_api_jwt');
            }

            $payload = \Firebase\JWT\JWT::decode(
                $token,
                new \Firebase\JWT\Key($secret, 'HS256')
            );

            $payload = (array) $payload;

            if (empty($payload['customer_id'])) {
                throw new \Exception('No customer_id in token');
            }

            $customerId = (int) $payload['customer_id'];

            /** @var Mage_Customer_Model_Session $session */
            $session = Mage::getSingleton('customer/session');

            if (!$session->loginById($customerId)) {
                throw new \Exception('loginById failed for customer ' . $customerId);
            }

            Mage::log("Social login session created for customer #{$customerId}", null, 'social_login.log');
            $this->_redirect('customer/account');

        } catch (\Exception $e) {
            Mage::log('Social login session failed: ' . $e->getMessage(), null, 'social_login.log');
            Mage::getSingleton('core/session')->addError('Authentication failed. Please try again.');
            $this->_redirect('customer/account/login');
        }
    }
}
