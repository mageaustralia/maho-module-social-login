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
        // callback authenticates via the JWT; login does its own form_key check
        // below so it can return a JSON error to the XHR client.
        $action = $this->getRequest()->getActionName();
        if ($action === 'callback' || $action === 'login') {
            return true;
        }
        return parent::_validateFormKey();
    }

    /**
     * POST /sociallogin/auth/login
     * Normal-Maho frontend social sign-in: verify the provider credential,
     * create a customer session, and return JSON for the XHR client. This is
     * the storefront-independent path (no headless API / JWT round-trip).
     */
    #[\Maho\Config\Route('/sociallogin/auth/login', name: 'sociallogin.auth.login')]
    public function loginAction(): void
    {
        $this->getResponse()->setHeader('Content-Type', 'application/json', true);

        if (!$this->getRequest()->isPost()) {
            $this->_jsonError('Invalid request.', 405);
            return;
        }

        // CSRF — the login form on the page carries form_key.
        $formKey = (string) $this->getRequest()->getPost('form_key');
        if ($formKey === '' || $formKey !== Mage::getSingleton('core/session')->getFormKey()) {
            $this->_jsonError('Invalid form key. Please refresh and try again.', 403);
            return;
        }

        $provider = (string) $this->getRequest()->getPost('provider');
        $token    = (string) $this->getRequest()->getPost('token');
        $password = $this->getRequest()->getPost('password');
        $password = ($password === null || $password === '') ? null : (string) $password;

        try {
            $result = Mage::helper('sociallogin')->authenticate($provider, $token, $password);

            if (!empty($result['linkRequired'])) {
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode([
                    'linkRequired' => 'account_exists',
                    'email'        => $result['email'],
                ]));
                return;
            }

            /** @var Mage_Customer_Model_Customer $customer */
            $customer = $result['customer'];
            /** @var Mage_Customer_Model_Session $session */
            $session = Mage::getSingleton('customer/session');
            if (!$session->loginById((int) $customer->getId())) {
                throw new Exception('loginById failed for customer ' . $customer->getId());
            }
            Mage::log("Social login (frontend) customer #{$customer->getId()}", null, 'social_login.log');

            $this->getResponse()->setBody(Mage::helper('core')->jsonEncode([
                'success'  => true,
                'redirect' => Mage::getUrl('customer/account'),
            ]));
        } catch (Mage_Core_Exception $e) {
            $this->_jsonError($e->getMessage(), 400);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_jsonError('Sign-in failed. Please try again.', 500);
        }
    }

    private function _jsonError(string $message, int $code): void
    {
        $this->getResponse()
            ->setHttpResponseCode($code)
            ->setBody(Mage::helper('core')->jsonEncode(['error' => $message]));
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
                new \Firebase\JWT\Key($secret, 'HS256'),
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
