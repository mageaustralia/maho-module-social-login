<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Helper_Otp extends Mage_Core_Helper_Abstract
{
    public const PURPOSES = ['login', 'register', 'link', 'add_mobile'];

    /**
     * Request a code for an identifier+purpose. Always enumeration-safe: returns the
     * same shape regardless of whether an account exists. Returns
     * ['ok' => bool, 'throttled' => bool, 'channel' => string].
     */
    public function requestCode(string $identifier, string $purpose, string $channel, ?int $storeId = null, ?string $ip = null): array
    {
        $helper = Mage::helper('sociallogin');
        if (!in_array($purpose, self::PURPOSES, true)) {
            return ['ok' => false, 'throttled' => false, 'channel' => $channel];
        }
        // Click-spam guard: a minimum interval between consecutive requests for the same
        // (identifier, purpose). Distinct from the volume rate-limit below - this stops
        // someone hammering "send code". Treated as a (silent) success to stay enumeration-safe.
        if ($this->_isInCooldown($identifier, $purpose, $storeId)) {
            return ['ok' => true, 'throttled' => false, 'cooldown' => true, 'channel' => $channel];
        }
        if ($this->_isRateLimited($identifier, $ip)) {
            return ['ok' => false, 'throttled' => true, 'channel' => $channel];
        }

        $code = $this->_generateCode($helper->getOtpLength($storeId));
        $now = Mage_Core_Model_Locale::nowUtc();
        $expires = (new DateTimeImmutable($now, new DateTimeZone('UTC')))
            ->add(new DateInterval('PT' . $helper->getOtpExpiryMinutes($storeId) . 'M'))
            ->format('Y-m-d H:i:s');

        Mage::getModel('sociallogin/otp')
            ->setIdentifier($identifier)->setPurpose($purpose)->setChannel($channel)
            ->setCodeHash($this->_hash($code, $storeId))->setAttempts(0)
            ->setExpiresAt($expires)->setConsumedAt(null)->setRequestIp($ip)->setCreatedAt($now)
            ->save();

        // Only actually deliver if the action makes sense (e.g. for login, the account
        // must exist). Whether it exists is NOT revealed in the return value.
        $send = $this->_shouldSend($identifier, $purpose);
        if ($send) {
            $delivered = $this->_channel($channel)->send($identifier, $code, $purpose, $storeId);
            if (!$delivered && $channel === 'sms') {
                // fall back to email for login if SMS fails and identifier is an email
                if (strpos($identifier, '@') !== false) {
                    $this->_channel('email')->send($identifier, $code, $purpose, $storeId);
                }
            }
        }
        return ['ok' => true, 'throttled' => false, 'channel' => $channel];
    }

    /**
     * Verify a code. Returns ['ok' => bool, 'reason' => string]. Constant-time,
     * single-use, attempt-capped.
     */
    public function verifyCode(string $identifier, string $purpose, string $code, ?int $storeId = null): array
    {
        $helper = Mage::helper('sociallogin');
        $now = Mage_Core_Model_Locale::nowUtc();
        /** @var MageAustralia_SocialLogin_Model_Otp $row */
        $row = Mage::getModel('sociallogin/otp')->getCollection()
            ->addFieldToFilter('identifier', $identifier)
            ->addFieldToFilter('purpose', $purpose)
            ->addFieldToFilter('consumed_at', ['null' => true])
            ->addFieldToFilter('expires_at', ['gteq' => $now])
            ->setOrder('otp_id', 'DESC')->setPageSize(1)->getFirstItem();

        if (!$row->getId()) {
            return ['ok' => false, 'reason' => 'invalid'];
        }
        if ((int) $row->getAttempts() >= $helper->getOtpMaxAttempts($storeId)) {
            return ['ok' => false, 'reason' => 'locked'];
        }

        $expected = (string) $row->getCodeHash();
        $given = $this->_hash($code, $storeId);
        if (!hash_equals($expected, $given)) {
            $row->setAttempts((int) $row->getAttempts() + 1)->save();
            return ['ok' => false, 'reason' => 'invalid'];
        }

        $row->setConsumedAt($now)->save();
        return ['ok' => true, 'reason' => 'ok'];
    }

    protected function _shouldSend(string $identifier, string $purpose): bool
    {
        if ($purpose === 'register' || $purpose === 'add_mobile') {
            return true; // register: identifier is new; add_mobile: caller is authenticated
        }
        // login / link: only deliver if a customer with this email exists
        $website = Mage::app()->getStore()->getWebsiteId();
        $customer = Mage::getModel('customer/customer')->setWebsiteId($website)->loadByEmail($identifier);
        return (bool) $customer->getId();
    }

    /**
     * Click-spam guard. True if a code for this (identifier, purpose) was issued more
     * recently than the configured resend cooldown - i.e. the user is hammering the
     * "send code" button. A cooldown of 0 disables the guard.
     */
    protected function _isInCooldown(string $identifier, string $purpose, ?int $storeId): bool
    {
        $cooldown = Mage::helper('sociallogin')->getOtpResendCooldown($storeId);
        if ($cooldown <= 0) {
            return false;
        }
        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT' . $cooldown . 'S'))->format('Y-m-d H:i:s');
        $recent = Mage::getModel('sociallogin/otp')->getCollection()
            ->addFieldToFilter('identifier', $identifier)
            ->addFieldToFilter('purpose', $purpose)
            ->addFieldToFilter('created_at', ['gteq' => $since])->getSize();
        return $recent > 0;
    }

    protected function _isRateLimited(string $identifier, ?string $ip): bool
    {
        $idCount = (int) Mage::getStoreConfig('customer/sociallogin/otp_rl_identifier_count');
        $idWindow = (int) Mage::getStoreConfig('customer/sociallogin/otp_rl_identifier_window');
        $ipCount = (int) Mage::getStoreConfig('customer/sociallogin/otp_rl_ip_count');
        $ipWindow = (int) Mage::getStoreConfig('customer/sociallogin/otp_rl_ip_window');
        $since = static fn(int $secs): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub(new DateInterval('PT' . $secs . 'S'))->format('Y-m-d H:i:s');

        $byId = Mage::getModel('sociallogin/otp')->getCollection()
            ->addFieldToFilter('identifier', $identifier)
            ->addFieldToFilter('created_at', ['gteq' => $since($idWindow)])->getSize();
        if ($byId >= $idCount) { return true; }
        if ($ip !== null && $ip !== '') {
            $byIp = Mage::getModel('sociallogin/otp')->getCollection()
                ->addFieldToFilter('request_ip', $ip)
                ->addFieldToFilter('created_at', ['gteq' => $since($ipWindow)])->getSize();
            if ($byIp >= $ipCount) { return true; }
        }
        return false;
    }

    protected function _generateCode(int $length): string
    {
        $max = (10 ** $length) - 1;
        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    protected function _hash(string $code, ?int $storeId): string
    {
        return hash('sha256', $code . '|' . Mage::helper('sociallogin')->getOtpPepper($storeId));
    }

    protected function _channel(string $channel): MageAustralia_SocialLogin_Model_Otp_ChannelInterface
    {
        return $channel === 'sms'
            ? new MageAustralia_SocialLogin_Model_Otp_Channel_Sms()
            : new MageAustralia_SocialLogin_Model_Otp_Channel_Email();
    }
}
