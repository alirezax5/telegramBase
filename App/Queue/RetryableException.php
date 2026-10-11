<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue;

use RuntimeException;

/**
 * Thrown when an API call fails with a transient, retryable error
 * (rate limit, server error, timeout). Processor::handle catches this
 * and routes the update into the retry queue.
 *
 * Construct with the optional Telegram-provided `retry_after` (seconds)
 * so the retry queue can honour the server's requested backoff rather
 * than guessing.
 */
final class RetryableException extends RuntimeException
{
    /**
     * Telegram-suggested retry delay (seconds), parsed from the API
     * `parameters.retry_after` field on 429 responses. Null when the
     * error did not include one.
     */
    public readonly ?int $retryAfter;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?int $retryAfter = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->retryAfter = $retryAfter;
    }

    /**
     * Construct from a Telegram Bot API error response.
     *
     * The API returns:
     *   { "ok": false, "error_code": 429, "description": "Too Many Requests: retry after 5",
     *     "parameters": { "retry_after": 5 } }
     */
    public static function fromTelegramResponse(object $response, string $context = ''): self
    {
        $retryAfter = null;

        if (isset($response->parameters->retry_after)) {
            $retryAfter = (int)$response->parameters->retry_after;
        } elseif (preg_match('/retry after (\d+)/i', (string)($response->description ?? ''), $m)) {
            $retryAfter = (int)$m[1];
        }

        $errorCode = (int)($response->error_code ?? 0);
        $description = (string)($response->description ?? 'unknown Telegram error');
        $message = $context !== ''
            ? "Telegram API error ({$errorCode}) during {$context}: {$description}"
            : "Telegram API error ({$errorCode}): {$description}";

        return new self($message, $errorCode, null, $retryAfter);
    }

    /**
     * Decide whether a Throwable represents a transient failure that
     * should be re-queued.
     *
     * Recognises:
     *   - HTTP 429 / "Too Many Requests" (Telegram rate limit)
     *   - HTTP 5xx / "server error"
     *   - curl connect / timeout errors
     *   - any RetryableException (including subclasses)
     *
     * The check is intentionally permissive: it's better to retry a
     * possibly-non-retryable error than to drop a real user update.
     */
    public static function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof self) {
            return true;
        }

        $msg = strtolower((string)$e->getMessage());

        if (str_contains($msg, '429') || str_contains($msg, 'too many requests')) {
            return true;
        }

        // 5xx HTTP status code mentions (Telegram SDK includes these
        // in error messages as "500 internal server error" etc.).
        if (preg_match('/\b5\d{2}\b/', $msg) || str_contains($msg, 'server error')) {
            return true;
        }

        if (str_contains($msg, 'timeout') || str_contains($msg, 'timed out') || str_contains($msg, 'connect')) {
            return true;
        }

        // curl errors: "cURL error 28: …" etc.
        if (preg_match('/curl error \d+/', $msg)) {
            return true;
        }

        return false;
    }
}
