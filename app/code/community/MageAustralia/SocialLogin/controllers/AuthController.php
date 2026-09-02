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
    /** Sign-in attempts allowed per IP per window. */
    private const RL_LOGIN_MAX = 20;
    private const RL_LOGIN_WINDOW = 300;

    /** Password attempts allowed per IP per window when linking an existing account. */
    private const RL_LINK_MAX = 5;
    private const RL_LINK_WINDOW = 900;

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

        $helper = Mage::helper('sociallogin');
        $ip = $helper->getRequestIp();

        if ($helper->isThrottled('login:' . $ip, self::RL_LOGIN_MAX, self::RL_LOGIN_WINDOW)) {
            $this->_jsonError('Too many attempts. Please wait a few minutes and try again.', 429);
            return;
        }

        // A supplied password means this is an attempt to link an existing
        // account, i.e. a password guess. Cap those far harder than sign-ins.
        if ($password !== null
            && $helper->isThrottled('link:' . $ip, self::RL_LINK_MAX, self::RL_LINK_WINDOW)
        ) {
            $this->_jsonError('Too many attempts. Please wait a few minutes and try again.', 429);
            return;
        }

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
                'redirect' => $this->_resolveRedirect(),
            ]));
        } catch (Mage_Core_Exception $e) {
            $this->_jsonError($e->getMessage(), 400);
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_jsonError('Sign-in failed. Please try again.', 500);
        }
    }

    /**
     * Where to send the customer after a successful sign-in / account link.
     * The XHR client may post a `redirect` target (e.g. so a checkout sign-in
     * returns to checkout instead of the account dashboard). Only same-site
     * relative paths are honoured, to prevent an open redirect.
     */
    private function _resolveRedirect(): string
    {
        $redirect = (string) $this->getRequest()->getPost('redirect');

        // Strip control characters (CR, LF, TAB, NUL...) BEFORE the checks.
        // A browser drops them when parsing a URL, so "/\n//evil.com" passes a
        // naive "starts with / and not //" test and then navigates offsite.
        $redirect = preg_replace('/[\x00-\x1F\x7F]/', '', $redirect) ?? '';

        if ($redirect !== ''
            && $redirect[0] === '/'
            && substr($redirect, 0, 2) !== '//'
            && strpos($redirect, '\\') === false
            && strpos($redirect, '://') === false
        ) {
            return $redirect;
        }
        return Mage::getUrl('customer/account');
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
            // Ask JwtService for the secret rather than re-deriving it. The
            // hand-rolled derivation this replaced could never match a real
            // token: it read 'maho_apiplatform/oauth2/secret' and
            // 'maho_api/settings/jwt_secret', neither of which exists (the
            // real path is 'apiplatform/oauth2/secret'), then fell back to a
            // crypt-key hash that JwtService explicitly refuses to use --
            // deriving from the encryption key would turn local.xml exposure
            // into a JWT-forgery primitive. So this action rejected every
            // token it was ever given.
            // This action only exists to bridge a headless storefront, so the
            // API module is a hard dependency of it (and of nothing else here).
            if (!class_exists(\Maho\ApiPlatform\Service\JwtService::class)) {
                throw new Exception('ApiPlatform is not installed; the JWT callback is unavailable.');
            }
            $secret = \Maho\ApiPlatform\Service\JwtService::resolveSecret();

            $payload = \Firebase\JWT\JWT::decode(
                $token,
                new \Firebase\JWT\Key($secret, 'HS256'),
            );

            $payload = (array) $payload;

            // JWT::decode verifies the signature and the time claims, and
            // nothing else. JwtService signs THREE kinds of token with this one
            // secret and one audience -- customer, admin and api_user, told
            // apart only by the `type` claim -- so accepting any validly signed
            // token here would let a non-customer token open a customer session
            // the moment one of them ever carried a customer_id.
            // lcobucci's permittedFor() encodes `aud` as an ARRAY, and the RFC
            // permits either form, so accept both rather than assume.
            $aud = $payload['aud'] ?? null;
            $audValues = is_array($aud) ? $aud : (is_object($aud) ? (array) $aud : [$aud]);
            if (!in_array('maho-api', array_map('strval', $audValues), true)) {
                throw new \Exception('Token audience mismatch');
            }
            if (($payload['type'] ?? null) !== 'customer') {
                throw new \Exception('Token is not a customer token');
            }

            if (empty($payload['customer_id'])) {
                throw new \Exception('No customer_id in token');
            }

            // sub is 'customer_<id>'; it must agree with the customer_id claim.
            if (($payload['sub'] ?? null) !== 'customer_' . (int) $payload['customer_id']) {
                throw new \Exception('Token subject does not match customer_id');
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
