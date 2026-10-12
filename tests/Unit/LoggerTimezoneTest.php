<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LoggerConfig;

/**
 * LOG_TIMEZONE — pinning log timestamps to one zone.
 *
 * Background: the webhook entry (PHP-FPM) and the CLI/cron workers of one
 * deployment can run under different php.ini timezones. Without a pin, the
 * same log file mixes two offsets and the incident timeline becomes
 * unreadable (observed live: webhook lines +03:30, cron lines +08:00 in one
 * file). LoggerConfig resolves LOG_TIMEZONE once; LogHandler hands the
 * resolved DateTimeZone to Monolog.
 */
class LoggerTimezoneTest extends TgBaseTestCase
{
    private function clearTzEnv(): void
    {
        putenv('LOG_TIMEZONE');
        unset($_ENV['LOG_TIMEZONE']);
        EnvHandler::clearCache();
    }

    private function setTzEnv(string $value): void
    {
        putenv("LOG_TIMEZONE={$value}");
        $_ENV['LOG_TIMEZONE'] = $value;
        EnvHandler::clearCache();
    }

    public function test_unset_env_resolves_to_null_runtime_default(TgAssert $a): void
    {
        $this->clearTzEnv();
        $a->true(LoggerConfig::fromEnv()->timezone === null, 'no LOG_TIMEZONE must resolve to null (runtime default)');
    }

    public function test_empty_env_resolves_to_null_runtime_default(TgAssert $a): void
    {
        $this->setTzEnv('');
        $a->true(LoggerConfig::fromEnv()->timezone === null, 'empty LOG_TIMEZONE must resolve to null (runtime default)');
        $this->clearTzEnv();
    }

    public function test_valid_identifier_is_pinned(TgAssert $a): void
    {
        $this->setTzEnv('Asia/Tehran');

        $tz = LoggerConfig::fromEnv()->timezone;

        $a->true($tz instanceof \DateTimeZone, 'valid LOG_TIMEZONE must resolve to a DateTimeZone');
        if ($tz instanceof \DateTimeZone) {
            $a->equals('Asia/Tehran', $tz->getName());
        }

        $this->clearTzEnv();
    }

    public function test_whitespace_is_tolerated(TgAssert $a): void
    {
        $this->setTzEnv(' UTC ');

        $tz = LoggerConfig::fromEnv()->timezone;

        $a->true($tz instanceof \DateTimeZone, 'padded LOG_TIMEZONE must still resolve');
        if ($tz instanceof \DateTimeZone) {
            $a->equals('UTC', $tz->getName());
        }

        $this->clearTzEnv();
    }

    public function test_invalid_identifier_falls_back_to_null(TgAssert $a): void
    {
        // Availability first: a typo in an observability knob must not kill
        // the boot — the mixed-offset symptom in the log is the visible hint.
        $this->setTzEnv('Mars/Olympus_Mons');

        $a->true(LoggerConfig::fromEnv()->timezone === null, 'invalid LOG_TIMEZONE must fall back to null, not throw');

        $this->clearTzEnv();
    }
}
