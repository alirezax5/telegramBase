<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Queue\RetryableException;
use function alirezax5\TelegramBase\App\Queue\tg_retryable;

/**
 * Tests the tg_retryable() helper that classifies Telegram API
 * responses and throws RetryableException on transient failures.
 */
class TgRetryableHelperTest extends TgBaseTestCase
{
    public function test_success_response_returned_unchanged(TgAssert $a): void
    {
        $resp = (object)[
            'ok' => true,
            'result' => (object)['message_id' => 42],
        ];

        $result = tg_retryable($resp, 'sendMessage');
        $a->equals($resp, $result);
    }

    public function test_429_throws_retryable(TgAssert $a): void
    {
        $resp = (object)[
            'ok' => false,
            'error_code' => 429,
            'description' => 'Too Many Requests: retry after 5',
            'parameters' => (object)['retry_after' => 5],
        ];

        try {
            tg_retryable($resp, 'sendMessage');
            $a->fail('expected RetryableException for 429');
        } catch (RetryableException $e) {
            $a->equals(429, $e->getCode());
            $a->equals(5, $e->retryAfter);
            $a->contains('sendMessage', $e->getMessage());
        }
    }

    public function test_500_throws_retryable(TgAssert $a): void
    {
        $resp = (object)[
            'ok' => false,
            'error_code' => 500,
            'description' => 'Internal Server Error',
        ];

        $a->throws(RetryableException::class, fn() => tg_retryable($resp, 'getMe'));
    }

    public function test_502_throws_retryable(TgAssert $a): void
    {
        $resp = (object)[
            'ok' => false,
            'error_code' => 502,
            'description' => 'Bad Gateway',
        ];

        $a->throws(RetryableException::class, fn() => tg_retryable($resp));
    }

    public function test_400_returns_response_not_throw(TgAssert $a): void
    {
        // 400 is a client error (e.g. "chat not found") — not retryable.
        $resp = (object)[
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad Request: chat not found',
        ];

        $result = tg_retryable($resp, 'sendMessage');
        $a->equals($resp, $result, '400 should return response, not throw');
    }

    public function test_401_returns_response_not_throw(TgAssert $a): void
    {
        // 401 unauthorized — not retryable (bad token).
        $resp = (object)[
            'ok' => false,
            'error_code' => 401,
            'description' => 'Unauthorized',
        ];

        $result = tg_retryable($resp, 'getMe');
        $a->equals($resp, $result);
    }

    public function test_403_returns_response_not_throw(TgAssert $a): void
    {
        // 403 forbidden (bot blocked by user) — not retryable.
        $resp = (object)[
            'ok' => false,
            'error_code' => 403,
            'description' => 'Forbidden: bot was blocked by the user',
        ];

        $result = tg_retryable($resp, 'sendMessage');
        $a->equals($resp, $result);
    }

    public function test_ok_false_without_error_code_returns_response(TgAssert $a): void
    {
        // SDK sometimes returns ok=false without an error_code (e.g.
        // curl failure that the SDK swallowed). Don't throw — let the
        // caller inspect and decide.
        $resp = (object)['ok' => false, 'error' => 'cURL error 28'];

        $result = tg_retryable($resp);
        $a->equals($resp, $result);
    }

    public function test_custom_ok_field(TgAssert $a): void
    {
        // Some SDK wrappers use 'success' instead of 'ok'.
        $resp = (object)['success' => true, 'data' => 'x'];
        $result = tg_retryable($resp, 'custom', 'success');
        $a->equals($resp, $result);
    }
}
