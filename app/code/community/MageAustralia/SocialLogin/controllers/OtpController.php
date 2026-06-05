<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_OtpController extends Mage_Core_Controller_Front_Action
{
    /**
     * Every action in this controller is a POST XHR endpoint that returns JSON and
     * performs its own form_key check (so it can return a JSON error to the client
     * instead of a redirect). Disable the framework's HTML-form-style validation.
     */
    #[\Override]
    protected function _validateFormKey(): bool
    {
        return true;
    }

    /**
     * POST /sociallogin/otp/request
     * Mint and deliver a one-time code for an identifier+purpose. Always returns the
     * same enumeration-safe body so the response never reveals whether an account
     * exists or whether the request was throttled.
     */
    #[\Maho\Config\Route('/sociallogin/otp/request', name: 'sociallogin.otp.request')]
    public function requestAction(): void
    {
        if (($guard = $this->_guard()) !== null) {
            $this->_json($guard[0], $guard[1]);
            return;
        }

        $req = $this->getRequest();
        $purpose = (string) $req->getPost('purpose', 'login');
        $channel = (string) $req->getPost('channel', 'email');

        $rawIdentifier = (string) $req->getPost('identifier', '');
        if ($rawIdentifier === '') {
            $rawIdentifier = (string) $req->getPost('email', '');
        }
        if ($rawIdentifier === '') {
            $rawIdentifier = (string) $req->getPost('mobile', '');
        }

        $helper = Mage::helper('sociallogin');
        $identifier = $channel === 'sms'
            ? $helper->normaliseMobile($rawIdentifier)
            : $helper->normaliseEmail($rawIdentifier);

        if ($purpose === 'add_mobile' && !Mage::getSingleton('customer/session')->isLoggedIn()) {
            $this->_json(['error' => 'Please sign in.'], 403);
            return;
        }

        Mage::helper('sociallogin/otp')->requestCode(
            $identifier,
            $purpose,
            $channel,
            $this->_storeId(),
            $this->_ip(),
        );

        // Enumeration-safe: identical body whatever the outcome (sent, not-sent,
        // throttled, cooldown). Do not leak account existence or throttling state.
        $this->_json([
            'ok'      => true,
            'message' => 'If your details match an account, a verification code has been sent.',
        ]);
    }

    /**
     * POST /sociallogin/otp/verify
     * Passwordless login: verify a 'login' code for an email, then create the customer
     * session via loginById.
     */
    #[\Maho\Config\Route('/sociallogin/otp/verify', name: 'sociallogin.otp.verify')]
    public function verifyAction(): void
    {
        if (($guard = $this->_guard()) !== null) {
            $this->_json($guard[0], $guard[1]);
            return;
        }

        $email = Mage::helper('sociallogin')->normaliseEmail((string) $this->getRequest()->getPost('email', ''));
        $code  = (string) $this->getRequest()->getPost('code', '');

        $res = Mage::helper('sociallogin/otp')->verifyCode($email, 'login', $code, $this->_storeId());
        if (empty($res['ok'])) {
            $this->_json(['ok' => false, 'message' => 'Invalid or expired code.']);
            return;
        }

        $customer = Mage::getModel('customer/customer')
            ->setWebsiteId($this->_websiteId())
            ->loadByEmail($email);

        $session = Mage::getSingleton('customer/session');
        if (!$customer->getId() || !$session->loginById((int) $customer->getId())) {
            $this->_json(['ok' => false, 'message' => 'Could not sign in.']);
            return;
        }

        $this->_json(['ok' => true, 'redirect' => $this->_resolveRedirect()]);
    }

    /**
     * POST /sociallogin/otp/register
     * Verify a 'register' code, create a passwordless customer (random password, kept
     * additive so password login still works after a reset), and sign them in.
     */
    #[\Maho\Config\Route('/sociallogin/otp/register', name: 'sociallogin.otp.register')]
    public function registerAction(): void
    {
        if (($guard = $this->_guard()) !== null) {
            $this->_json($guard[0], $guard[1]);
            return;
        }

        $req       = $this->getRequest();
        $email     = Mage::helper('sociallogin')->normaliseEmail((string) $req->getPost('email', ''));
        $code      = (string) $req->getPost('code', '');
        $firstname = trim((string) $req->getPost('firstname', ''));
        $lastname  = trim((string) $req->getPost('lastname', ''));

        $res = Mage::helper('sociallogin/otp')->verifyCode($email, 'register', $code, $this->_storeId());
        if (empty($res['ok'])) {
            $this->_json(['ok' => false, 'message' => 'Invalid or expired code.']);
            return;
        }

        $websiteId = $this->_websiteId();
        $existing = Mage::getModel('customer/customer')->setWebsiteId($websiteId)->loadByEmail($email);
        if ($existing->getId()) {
            $this->_json(['ok' => false, 'message' => 'An account already exists. Please sign in.']);
            return;
        }

        try {
            /** @var Mage_Customer_Model_Customer $customer */
            $customer = Mage::getModel('customer/customer');
            $customer->setWebsiteId($websiteId)
                ->setStoreId($this->_storeId())
                ->setEmail($email)
                ->setFirstname($firstname !== '' ? $firstname : 'Customer')
                ->setLastname($lastname !== '' ? $lastname : 'Account')
                ->setPassword(Mage::helper('core')->getRandomString(24));
            $customer->save();

            // Allow immediate login even when customer/create_account/confirm is enabled.
            if ($customer->getConfirmation()) {
                $customer->setConfirmation(null)->save();
            }
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_json(['ok' => false, 'message' => 'Could not create your account. Please try again.']);
            return;
        }

        $session = Mage::getSingleton('customer/session');
        if (!$session->loginById((int) $customer->getId())) {
            $this->_json(['ok' => false, 'message' => 'Could not sign in.']);
            return;
        }

        $this->_json(['ok' => true, 'redirect' => $this->_resolveRedirect()]);
    }

    /**
     * POST /sociallogin/otp/add-mobile
     * Verify an 'add_mobile' code for the logged-in customer and store the verified
     * mobile number against their account.
     */
    #[\Maho\Config\Route('/sociallogin/otp/add-mobile', name: 'sociallogin.otp.add_mobile')]
    public function addMobileAction(): void
    {
        if (($guard = $this->_guard()) !== null) {
            $this->_json($guard[0], $guard[1]);
            return;
        }

        if (!Mage::getSingleton('customer/session')->isLoggedIn()) {
            $this->_json(['error' => 'Please sign in.'], 403);
            return;
        }

        $mobile = Mage::helper('sociallogin')->normaliseMobile((string) $this->getRequest()->getPost('mobile', ''));
        $code   = (string) $this->getRequest()->getPost('code', '');

        $res = Mage::helper('sociallogin/otp')->verifyCode($mobile, 'add_mobile', $code, $this->_storeId());
        if (empty($res['ok'])) {
            $this->_json(['ok' => false, 'message' => 'Invalid or expired code.']);
            return;
        }

        $customerId = (int) Mage::getSingleton('customer/session')->getCustomer()->getId();
        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer')->load($customerId);
        $customer->setMobile($mobile)
            ->setMobileVerified(Mage_Core_Model_Locale::nowUtc())
            ->save();

        $this->_json(['ok' => true, 'message' => 'Mobile verified.']);
    }

    /**
     * POST /sociallogin/otp/link/request
     * Request an OTP to link a social account to the existing customer that owns the
     * verified provider email. Always returns the same enumeration-safe body, even on
     * an invalid token, so the response never leaks token validity or account existence.
     */
    #[\Maho\Config\Route('/sociallogin/otp/link/request', name: 'sociallogin.otp.link_request')]
    public function linkRequestAction(): void
    {
        if (($guard = $this->_guard()) !== null) {
            $this->_json($guard[0], $guard[1]);
            return;
        }

        $req      = $this->getRequest();
        $provider = (string) $req->getPost('provider', '');
        $token    = (string) $req->getPost('token', '');

        try {
            Mage::helper('sociallogin')->requestOtpLink($provider, $token, $this->_storeId(), $this->_ip());
        } catch (Exception $e) {
            // Swallow: do not reveal token validity or account existence.
            Mage::log('OTP link request error: ' . $e->getMessage(), null, 'social_login.log');
        }

        $this->_json([
            'ok'      => true,
            'message' => 'If your details match an account, a verification code has been sent.',
        ]);
    }

    /**
     * POST /sociallogin/otp/link/verify
     * Verify a 'link' OTP, attach the social identity to the customer that owns the
     * verified provider email, and sign them in.
     */
    #[\Maho\Config\Route('/sociallogin/otp/link/verify', name: 'sociallogin.otp.link_verify')]
    public function linkVerifyAction(): void
    {
        if (($guard = $this->_guard()) !== null) {
            $this->_json($guard[0], $guard[1]);
            return;
        }

        $req      = $this->getRequest();
        $provider = (string) $req->getPost('provider', '');
        $token    = (string) $req->getPost('token', '');
        $code     = (string) $req->getPost('code', '');

        try {
            $res = Mage::helper('sociallogin')->completeOtpLink($provider, $token, $code, $this->_storeId());
        } catch (Exception $e) {
            Mage::log('OTP link verify error: ' . $e->getMessage(), null, 'social_login.log');
            $this->_json(['ok' => false, 'message' => 'Invalid or expired code.']);
            return;
        }

        if (empty($res['ok'])) {
            $this->_json(['ok' => false, 'message' => 'Invalid or expired code.']);
            return;
        }

        /** @var Mage_Customer_Model_Customer $customer */
        $customer = $res['customer'];
        $session = Mage::getSingleton('customer/session');
        if (!$customer->getId() || !$session->loginById((int) $customer->getId())) {
            $this->_json(['ok' => false, 'message' => 'Could not sign in.']);
            return;
        }

        $this->_json(['ok' => true, 'redirect' => $this->_resolveRedirect()]);
    }

    /**
     * Common precondition guard for every action. Returns [body, httpCode] to emit, or
     * null when the request passes (POST, OTP enabled, valid form key).
     *
     * @return array{0: array<string, mixed>, 1: int}|null
     */
    private function _guard(): ?array
    {
        if (!$this->getRequest()->isPost()) {
            return [['error' => 'Invalid request.'], 405];
        }
        if (!Mage::helper('sociallogin')->isOtpEnabled($this->_storeId())) {
            return [['error' => 'Not available.'], 404];
        }
        if (!$this->_formKeyOk()) {
            return [['error' => 'Invalid form key. Please refresh and try again.'], 403];
        }
        return null;
    }

    private function _formKeyOk(): bool
    {
        $formKey = (string) $this->getRequest()->getPost('form_key');
        return $formKey !== '' && $formKey === Mage::getSingleton('core/session')->getFormKey();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function _json(array $data, int $code = 200): void
    {
        $response = $this->getResponse()->setHeader('Content-Type', 'application/json', true);
        if ($code !== 200) {
            $response->setHttpResponseCode($code);
        }
        $response->setBody(Mage::helper('core')->jsonEncode($data));
    }

    private function _ip(): string
    {
        return (string) Mage::helper('core/http')->getRemoteAddr();
    }

    private function _storeId(): int
    {
        return (int) Mage::app()->getStore()->getId();
    }

    private function _websiteId(): int
    {
        return (int) Mage::app()->getStore()->getWebsiteId();
    }

    /**
     * Where to send the customer after a successful sign-in. The XHR client may post a
     * `redirect` target; only same-site relative paths are honoured, to prevent an open
     * redirect.
     */
    private function _resolveRedirect(): string
    {
        $redirect = (string) $this->getRequest()->getPost('redirect');
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
}
