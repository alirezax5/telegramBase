<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Config;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final class SessionConfig
{
    public function __construct(
        public readonly int    $ttl,
        public readonly string $prefix,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            ttl: EnvHandler::int('SESSION_TTL', 3600),
            prefix: EnvHandler::string('SESSION_PREFIX', 'tgbase:session:'),
        );
    }
}
