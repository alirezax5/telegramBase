<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Plugin;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Paths;

final  class PluginConfig
{
    public function __construct(
        public readonly string $path,
        public readonly int $reloadInterval,
        public readonly bool $cacheEnabled,
        public readonly bool $autoReload,
    ) {}

    public static function fromEnv(): self
    {
        $dir = EnvHandler::get('PLUGINS_DIR', '/Plugin/');
        $dir = Paths::base() . $dir;

        return new self(
            path: $dir,
            reloadInterval: (int) EnvHandler::get('PLUGINS_RELOAD_INTERVAL', 600),
            cacheEnabled: (bool) EnvHandler::get('PLUGIN_CACHE_ENABLED', true),
            autoReload: true,
        );
    }
}