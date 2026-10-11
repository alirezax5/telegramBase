<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Queue\RetryableException;

class RetryableExceptionTest extends TgBaseTestCase
{
    public function test_is_retryable_for_429_message(TgAssert $a): void
    {
        $e = new \RuntimeException('HTTP 429 Too Many Requests');
        $a->true(RetryableException::isRetryable($e));
    }

    public function test_is_retryable_for_5xx_message(TgAssert $a): void
    {
        $e = new \RuntimeException('HTTP 500 Internal Server Error');
        $a->true(RetryableException::isRetryable($e));
    }

    public function test_is_retryable_for_503_message(TgAssert $a): void
    {
        $e = new \RuntimeException('server error: temporarily unavailable');
        $a->true(RetryableException::isRetryable($e));
    }

    public function test_is_retryable_for_timeout(TgAssert $a): void
    {
        $e = new \RuntimeException('Operation timed out');
        $a->true(RetryableException::isRetryable($e));
    }

    public function test_is_retryable_for_curl_error(TgAssert $a): void
    {
        $e = new \RuntimeException('cURL error 28: Connection timed out');
        $a->true(RetryableException::isRetryable($e));
    }

    public function test_is_retryable_for_self_instance(TgAssert $a): void
    {
        $e = new RetryableException('whatever');
        $a->true(RetryableException::isRetryable($e));
    }

    public function test_is_not_retryable_for_400_message(TgAssert $a): void
    {
        $e = new \RuntimeException('Bad Request: chat not found');
        $a->false(RetryableException::isRetryable($e));
    }

    public function test_is_not_retryable_for_unrelated_exception(TgAssert $a): void
    {
        $e = new \RuntimeException('something completely different');
        $a->false(RetryableException::isRetryable($e));
    }

    public function test_from_telegram_response_extracts_retry_after(TgAssert $a): void
    {
        $resp = (object)[
            'ok' => false,
            'error_code' => 429,
            'description' => 'Too Many Requests: retry after 7',
            'parameters' => (object)['retry_after' => 7],
        ];

        $e = RetryableException::fromTelegramResponse($resp, 'sendMessage');
        $a->equals(7, $e->retryAfter);
        $a->contains('sendMessage', $e->getMessage());
        $a->contains('429', $e->getMessage());
    }

    public function test_from_telegram_response_parses_retry_after_from_description(TgAssert $a): void
    {
        // Telegram sometimes returns the retry_after hint only inside the description.
        $resp = (object)[
            'ok' => false,
            'error_code' => 429,
            'description' => 'Too Many Requests: retry after 3',
        ];

        $e = RetryableException::fromTelegramResponse($resp);
        $a->equals(3, $e->retryAfter);
    }

    public function test_from_telegram_response_no_retry_after(TgAssert $a): void
    {
        $resp = (object)[
            'ok' => false,
            'error_code' => 500,
            'description' => 'Internal Server Error',
        ];

        $e = RetryableException::fromTelegramResponse($resp);
        $a->true($e->retryAfter === null);
    }
}
