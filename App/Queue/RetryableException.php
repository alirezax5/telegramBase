<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue;

use RuntimeException;

/**
 * Thrown when an API call fails with a transient, retryable error
 * (rate limit, server error, timeout). Processor::handle catches this
 * and routes the update into the retry queue.
 */
final class RetryableException extends RuntimeException
{
    /**
     * Map known error patterns to retryability.
     */
    public static function isRetryable(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, '429') || str_contains($msg, 'too many requests')) {
            return true;
        }
        if (preg_match('/5[0-9]{2}\s/', $msg) || str_contains($msg, 'server error')) {
            return true;
        }
        if (str_contains($msg, 'timeout') || str_contains($msg, 'connect')) {
            return true;
        }
        if (preg_match('/^cURL error [0-9]+$/', $e->getMessage())) {
            return true;
        }
        return false;
    }
}
