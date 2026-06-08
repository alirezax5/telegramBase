<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Language;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final  class LanguageConfig
{
    public function __construct(
        public string $driver,
        public string $defaultLang,
        public string $dir,
        public int $cacheTTL,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            driver: EnvHandler::get('LANG_DRIVER', 'json'),
            defaultLang: EnvHandler::get('DEFAULT_LANG', 'fa'),
            dir: EnvHandler::get('LANG_DIR', '/Language'),
            cacheTTL: (int) EnvHandler::get('LANG_CACHE_TTL', 60),
        );
    }
}