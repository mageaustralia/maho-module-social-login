<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Helper_Otp extends Mage_Core_Helper_Abstract
{
    public const PURPOSES = ['login', 'add_mobile'];

    /**
     * Request a code for an identifier+purpose. Always enumeration-safe: returns the
     * same shape regardless of whether an account exists. Returns
     * ['ok' => bool, 'throttled' => bool, 'channel' => string].
     */
    public function requestCode(string $identifier, string $purpose, string $channel, ?int $storeId = null, ?string $ip = null): array
    {
        $identifier = $this->_normaliseIdentifier($identifier);
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

        $this->_consumeOpenCodes($identifier, $purpose);
        Mage::getModel('sociallogin/otp')
            ->setIdentifier($identifier)->setPurpose($purpose)->setChannel('sms')
            ->setCodeHash($this->_hash($code, $storeId))->setAttempts(0)
            ->setExpiresAt($expires)->setConsumedAt(null)->setRequestIp($ip)->setCreatedAt($now)
            ->save();

        // Resolve where the code is SMS-delivered. For login this is the verified mobile
        // of the account that owns the email; for add_mobile it is the identifier itself.
        // When no destination resolves (no account / no verified mobile) we send nothing -
        // whether it exists is NOT revealed in the return value (enumeration-safe).
        $mobile = $this->_deliveryMobile($identifier, $purpose, $storeId);
        if ($mobile !== null && $mobile !== '') {
            (new MageAustralia_SocialLogin_Model_Otp_Channel_Sms())->send($mobile, $code, $purpose, $storeId);
        }
        return ['ok' => true, 'throttled' => false, 'channel' => $channel];
    }

    /**
     * Verify a code. Returns ['ok' => bool, 'reason' => string]. Constant-time,
     * single-use, attempt-capped.
     */
    public function verifyCode(string $identifier, string $purpose, string $code, ?int $storeId = null): array
    {
        $identifier = $this->_normaliseIdentifier($identifier);
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

        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $table = $resource->getTableName('sociallogin_otp');
        $affected = $write->update(
            $table,
            ['consumed_at' => $now],
            ['otp_id = ?' => (int) $row->getId(), 'consumed_at IS NULL'],
        );
        if ($affected < 1) {
            // Lost the race - another request already consumed this code.
            return ['ok' => false, 'reason' => 'invalid'];
        }
        return ['ok' => true, 'reason' => 'ok'];
    }

    protected function _normaliseIdentifier(string $identifier): string
    {
        $helper = Mage::helper('sociallogin');
        return strpos($identifier, '@') !== false
            ? $helper->normaliseEmail($identifier)
            : $helper->normaliseMobile($identifier);
    }

    /**
     * Invalidate any prior unconsumed codes for this (identifier, purpose) so the
     * per-code attempt cap cannot be reset by repeatedly minting new codes.
     */
    protected function _consumeOpenCodes(string $identifier, string $purpose): void
    {
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $table = $resource->getTableName('sociallogin_otp');
        $write->update(
            $table,
            ['consumed_at' => Mage_Core_Model_Locale::nowUtc()],
            ['identifier = ?' => $identifier, 'purpose = ?' => $purpose, 'consumed_at IS NULL'],
        );
    }

    /**
     * Resolve the mobile number a code is SMS-delivered to.
     * - add_mobile: the identifier IS the mobile being verified.
     * - login: the verified mobile of the customer that owns this email. If none,
     *   we fall back to scanning the customer's address book for a valid mobile
     *   in the default country — read-only (no DB write) so a hostile actor can't
     *   weaponise an email to promote an address phone to verified.
     */
    protected function _deliveryMobile(string $identifier, string $purpose, ?int $storeId): ?string
    {
        if ($purpose === 'add_mobile') {
            return $identifier;
        }
        $websiteId = $storeId !== null
            ? (int) Mage::app()->getStore($storeId)->getWebsiteId()
            : (int) Mage::app()->getStore()->getWebsiteId();
        $customer = Mage::getModel('customer/customer')->setWebsiteId($websiteId)->loadByEmail($identifier);
        if (!$customer->getId()) {
            return null;
        }
        if ($customer->getMobileVerified()) {
            $mobile = (string) $customer->getMobile();
            return $mobile !== '' ? $mobile : null;
        }
        // Fallback: look for a valid AU mobile (or whatever default-country is set
        // to) on the customer's addresses. Read-only — does NOT persist to the
        // customer record. Bulk promotion happens via the CLI sweep or admin button.
        return Mage::helper('sociallogin')->findValidMobileFromAddresses($customer);
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
        if ($byId >= $idCount) {
            return true;
        }
        if ($ip !== null && $ip !== '') {
            $byIp = Mage::getModel('sociallogin/otp')->getCollection()
                ->addFieldToFilter('request_ip', $ip)
                ->addFieldToFilter('created_at', ['gteq' => $since($ipWindow)])->getSize();
            if ($byIp >= $ipCount) {
                return true;
            }
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
}
