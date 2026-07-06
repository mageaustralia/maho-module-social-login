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

        $helper = Mage::helper('sociallogin');
        $purpose = (string) $this->getRequest()->getPost('purpose', 'login');
        if ($purpose === 'add_mobile') {
            if (!Mage::getSingleton('customer/session')->isLoggedIn()) {
                $this->_json(['error' => 'Please sign in.'], 403);
                return;
            }
            $identifier = $helper->normaliseMobile((string) $this->getRequest()->getPost('mobile'));
            // Reject malformed mobile up-front (input validation, not enumeration).
            if (!$helper->mobileIsValid($identifier)) {
                $this->_json(['ok' => false, 'message' => 'Please enter a valid mobile number.'], 400);
                return;
            }
        } else {
            $purpose = 'login';
            $identifier = $helper->normaliseEmail((string) $this->getRequest()->getPost('email'));
        }

        Mage::helper('sociallogin/otp')->requestCode($identifier, $purpose, 'sms', $this->_storeId(), $this->_ip());

        // Enumeration-safe: identical body whatever the outcome (sent, not-sent,
        // throttled, cooldown). Do not leak account existence or throttling state.
        $this->_json([
            'ok'      => true,
            'message' => 'If your details match an account with a verified mobile, a code has been sent by SMS.',
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

        // Merge any guest cart with the customer's previously-saved quote.
        // loginById() dispatches `customer_login` which
        // Mage_Checkout_Model_Observer listens for at `area: frontend` and
        // delegates to $checkoutSession->loadCustomerQuote() — but the
        // area-routed observer doesn't reliably fire on AJAX controllers
        // handled outside the standard frontend dispatcher. The customer
        // ends up with a NEW empty quote AND their previously-saved quote
        // stays orphaned (is_active=1 but no longer the session quote)
        // — visible to the user as "cart badge says N but the cart is
        // empty after I logged in".
        //
        // The explicit call is idempotent: a no-op when the observer
        // already ran, the right merge when it didn't. Wrapped in try/catch
        // so a cart-merge failure doesn't break the login itself; the
        // customer is logged in either way.
        try {
            Mage::getSingleton('checkout/session')->loadCustomerQuote();
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

        $this->_json(['ok' => true, 'redirect' => $this->_resolveRedirect()]);
    }

    /**
     * POST /sociallogin/otp/addMobile (legacy alias: /sociallogin/otp/add-mobile)
     * Verify an 'add_mobile' code for the logged-in customer and store the verified
     * mobile number against their account.
     */
    #[\Maho\Config\Route('/sociallogin/otp/addMobile', name: 'sociallogin.otp.add_mobile')]
    #[\Maho\Config\Route('/sociallogin/otp/add-mobile', name: 'sociallogin.otp.add_mobile_legacy')]
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

        $helper = Mage::helper('sociallogin');
        $mobile = $helper->normaliseMobile((string) $this->getRequest()->getPost('mobile', ''));
        $code   = (string) $this->getRequest()->getPost('code', '');

        if (!$helper->mobileIsValid($mobile)) {
            $this->_json(['ok' => false, 'message' => 'Please enter a valid mobile number.'], 400);
            return;
        }

        $res = Mage::helper('sociallogin/otp')->verifyCode($mobile, 'add_mobile', $code, $this->_storeId());
        if (empty($res['ok'])) {
            $this->_json(['ok' => false, 'message' => 'Invalid or expired code.']);
            return;
        }

        $customerId = (int) Mage::getSingleton('customer/session')->getCustomer()->getId();
        try {
            /** @var Mage_Customer_Model_Customer $customer */
            $customer = Mage::getModel('customer/customer')->load($customerId);
            $customer->setMobile($mobile)
                ->setMobileVerified(Mage_Core_Model_Locale::nowUtc())
                ->save();
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_json(['ok' => false, 'message' => 'Could not save your mobile. Please try again.']);
            return;
        }

        $this->_json(['ok' => true, 'message' => 'Mobile verified.']);
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
