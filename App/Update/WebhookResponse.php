<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Update;

/**
 * Plain DTO returned by WebhookDispatcher::dispatchIntoResponse().
 *
 * Allows tests to assert on the dispatcher's decisions without it
 * calling http_response_code() / exit().
 */
final class WebhookResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly int $exitCode,
    ) {
    }
}
