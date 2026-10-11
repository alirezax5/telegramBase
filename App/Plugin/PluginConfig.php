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

        // If PLUGINS_DIR is an absolute path, use it directly — don't
        // prepend the project base (which would produce a doubled path
        // like /home/user/repo/home/user/repo/Plugin). Otherwise, join
        // the relative path against the project base.
        if (str_starts_with($dir, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $dir)) {
            // Absolute path — use as-is, just normalize trailing slash.
            $dir = rtrim($dir, '/\\');
        } else {
            // Relative path — join against project base.
            $dir = rtrim(Paths::base(), '/') . '/' . ltrim($dir, '/');
        }

        return new self(
            path: $dir,
            reloadInterval: EnvHandler::int('PLUGINS_RELOAD_INTERVAL', 600),
            cacheEnabled: EnvHandler::bool('PLUGIN_CACHE_ENABLED', true),
            autoReload: true,
        );
    }
}