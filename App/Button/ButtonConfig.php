<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Button;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Paths;

final  class ButtonConfig
{
    public function __construct(
        public string $file,
        public int $cacheTTL,
        public bool $cacheEnabled,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            file: Paths::buttonFile() ,
            cacheTTL: (int) EnvHandler::get('BUTTONS_CACHE_TTL', 300),
            cacheEnabled: true,
        );
    }
}