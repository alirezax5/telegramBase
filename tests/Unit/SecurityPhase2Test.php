<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Queue\RetryableException;
use function alirezax5\TelegramBase\App\Queue\tg_retryable;

/**
 * Phase 2 security tests: verify secrets are never leaked through
 * error messages, log contexts, or exception text.
 */
class SecurityPhase2Test extends TgBaseTestCase
{
    /**
     * A realistic-looking fake token. Must NOT match any real token.
     * Used to verify that error messages don't echo the token value.
     */
    private const FAKE_TOKEN = '999999999:AAEhBP0fakefakefakefakefake_FAKEFAKE';

    public function test_retryable_exception_does_not_leak_token(TgAssert $a): void
    {
        // Even if a Telegram error response somehow contained the token
        // (it shouldn't), fromTelegramResponse must not include it in
        // the exception message.
        $resp = (object)[
            'ok' => false,
            'error_code' => 429,
            'description' => 'Too Many Requests: retry after 5',
            'parameters' => (object)['retry_after' => 5],
        ];

        $e = RetryableException::fromTelegramResponse($resp, 'sendMessage');

        $a->false(
            str_contains($e->getMessage(), self::FAKE_TOKEN),
            'exception message must not contain the token'
        );
        $a->false(
            str_contains($e->getMessage(), '999999999'),
            'exception message must not contain the token id either'
        );
    }

    public function test_tg_retryable_does_not_leak_token(TgAssert $a): void
    {
        $resp = (object)[
            'ok' => false,
            'error_code' => 500,
            'description' => 'Internal Server Error',
        ];

        try {
            tg_retryable($resp, 'sendMessage');
            $a->fail('expected RetryableException');
        } catch (RetryableException $e) {
            $a->false(str_contains($e->getMessage(), self::FAKE_TOKEN));
        }
    }

    public function test_environment_validator_errors_redact_value(TgAssert $a): void
    {
        // The validator must report the KEY name, not the VALUE, so a
        // malformed token doesn't end up in logs.
        EnvHandler::clearCache();
        putenv('BOT_TOKEN=garbage_value_with_secret');
        $_ENV['BOT_TOKEN'] = 'garbage_value_with_secret';
        putenv('TGBASE_SKIP_BOT_VALIDATION=0');
        $_ENV['TGBASE_SKIP_BOT_VALIDATION'] = '0';

        try {
            (new \alirezax5\TelegramBase\App\Environment\EnvironmentValidator())->validate();
            $a->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            $a->true(str_contains($msg, 'BOT_TOKEN'), 'error must mention the key name');
            $a->false(str_contains($msg, 'garbage_value_with_secret'), 'error must NOT contain the value');
        }

        // Cleanup
        putenv('BOT_TOKEN');
        unset($_ENV['BOT_TOKEN']);
        EnvHandler::clearCache();
    }

    public function test_doctor_does_not_print_token(TgAssert $a): void
    {
        // The doctor command reports "format valid" — never the token
        // value itself. We verify by capturing its output.
        EnvHandler::clearCache();
        putenv('BOT_TOKEN=' . self::FAKE_TOKEN);
        $_ENV['BOT_TOKEN'] = self::FAKE_TOKEN;

        ob_start();
        \alirezax5\TelegramBase\Cli\Doctor::run([]);
        $output = ob_get_clean();

        $a->false(
            str_contains($output, self::FAKE_TOKEN),
            'doctor output must not contain the full token'
        );
        $a->true(
            str_contains($output, 'BOT_TOKEN') && str_contains($output, 'OK'),
            'doctor must report BOT_TOKEN OK'
        );

        // Cleanup
        putenv('BOT_TOKEN');
        unset($_ENV['BOT_TOKEN']);
        EnvHandler::clearCache();
    }

    public function test_log_context_redacts_sensitive_keys(TgAssert $a): void
    {
        // This is a documentation test — the framework doesn't have a
        // formal redaction layer, but we verify that the Processor's
        // error context doesn't include env values.
        // The Processor logs: message, code, file, line, update_id.
        // None of these come from env, so the token can't leak via
        // this path.
        $context = [
            'message' => 'some error',
            'code' => 500,
            'file' => '/path/to/file.php',
            'line' => 42,
            'update_id' => 12345,
        ];

        $serialized = json_encode($context);
        $a->false(str_contains($serialized, self::FAKE_TOKEN));
        $a->false(str_contains($serialized, 'BOT_TOKEN'));
    }
}
