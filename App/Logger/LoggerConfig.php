<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Logger;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final  class LoggerConfig
{
    public function __construct(
        public bool $enabled,
        public string $directory,
        public string $file,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            enabled: self::toBool(EnvHandler::get('LOG_ENABLED', false)),
            directory: rtrim(
                (string) EnvHandler::get('LOG_DIR', './logs/'),
                '/\\'
            ),
            file: (string) EnvHandler::get('LOG_FILE', 'log.txt'),
        );
    }

    public function fullPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->file;
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}