<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue;

/**
 * Inspect a Telegram Bot API response and throw a RetryableException
 * when the response represents a transient failure (429 / 5xx).
 *
 * Plugin authors can wrap SDK calls to get automatic retry-queue
 * protection without writing the same `if (empty($response->ok))`
 * boilerplate in every handler:
 *
 *     $resp = $Telegram->sendMessage($chatId, $text);
 *     tg_retryable($resp, 'sendMessage');  // throws on 429/5xx
 *
 * For non-retryable failures (4xx other than 429) the function returns
 * the response unchanged so the plugin can inspect `->description`
 * and decide what to do (e.g. log and skip). This avoids retrying
 * permanent errors like "chat not found" or "bad request".
 *
 * @param object      $response The stdClass returned by the SDK
 * @param string      $context  Method name for error messages
 * @param string|null $okField  Field to check for success (default 'ok')
 * @return object The original response (unchanged) when non-retryable
 * @throws RetryableException When the response is a transient failure
 */
function tg_retryable(object $response, string $context = '', ?string $okField = 'ok'): object
{
    // Success — return as-is.
    if (isset($response->{$okField}) && $response->{$okField} === true) {
        return $response;
    }

    $errorCode = (int)($response->error_code ?? 0);

    // 429 (rate limit) and 5xx (server error) are retryable.
    if ($errorCode === 429 || ($errorCode >= 500 && $errorCode < 600)) {
        throw RetryableException::fromTelegramResponse($response, $context);
    }

    // Everything else (including ok=false without an error_code, or
    // 4xx client errors) is non-retryable. Return the response so the
    // caller can inspect ->description and handle it.
    return $response;
}
