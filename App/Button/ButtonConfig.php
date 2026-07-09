<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Button;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Paths;

final class ButtonConfig
{
    public function __construct(
        public readonly string $file,
        public readonly string $dir,
        public readonly int    $cacheTTL,
        public readonly bool   $cacheEnabled,
    ) {
    }

    public static function fromEnv(): self
    {
        $dir = EnvHandler::get('BUTTONS_DIR', '/Button');
        $file = EnvHandler::get('BUTTONS_FILE', 'btn.php');

        if (strlen($file) > 0 && ($file[0] === '/' || $file[0] === '\\')) {
            $file = ltrim($file, '/\\');
        }

        return new self(
            file: Paths::base() . DIRECTORY_SEPARATOR . ltrim($dir, '/\\') . DIRECTORY_SEPARATOR . ltrim($file, '/\\'),
            dir: Paths::base() . DIRECTORY_SEPARATOR . ltrim($dir, '/\\'),
            cacheTTL: EnvHandler::int('BUTTONS_CACHE_TTL', 300),
            cacheEnabled: EnvHandler::bool('BUTTONS_CACHE_ENABLED', true),
        );
    }
}