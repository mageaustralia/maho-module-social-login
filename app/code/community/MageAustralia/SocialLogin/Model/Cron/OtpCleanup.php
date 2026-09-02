<?php

/**
 * Purge spent one-time codes.
 *
 * Nothing ever deleted from sociallogin_otp, so the table grew for the life of
 * the install and every expired hash stayed on disk indefinitely. Codes are
 * single-use and short-lived (default 10 minutes), so a row is worthless the
 * moment it expires; keeping it only widens what a database leak exposes and
 * slows the rate-limit counts, which scan by identifier/IP over a time window.
 *
 * A short grace period is kept so the rate limiter still sees recent history.
 */
class MageAustralia_SocialLogin_Model_Cron_OtpCleanup
{
    /** Keep expired rows this long so rate-limit windows still see them. */
    private const RETENTION_HOURS = 48;

    public function run(): void
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT' . self::RETENTION_HOURS . 'H'))
            ->format('Y-m-d H:i:s');

        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $table = $resource->getTableName('sociallogin/otp');

        $deleted = $write->delete($table, ['created_at < ?' => $cutoff]);

        if ($deleted > 0) {
            Mage::log("OTP cleanup removed {$deleted} rows older than {$cutoff}", null, 'sociallogin_otp.log');
        }
    }
}
