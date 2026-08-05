<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Logger;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Paths;
use Symfony\Component\Filesystem\Path;

final  class LoggerConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $directory,
        public readonly string $file,
    ) {
        $this->validate();
    }

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

    /**
     * Validate log path configuration.
     *
     * Guards against absolute paths and directory traversal
     * (e.g. LOG_DIR=../../etc), which would let an env change write
     * log files outside the project.
     *
     * @throws \InvalidArgumentException
     */
    private function validate(): void
    {
        if (Path::isAbsolute($this->directory) || Path::isAbsolute($this->file)) {
            throw new \InvalidArgumentException('LOG_DIR and LOG_FILE must be relative paths.');
        }

        $dir = str_replace('\\', '/', $this->directory);

        if (str_contains($dir, '..')) {
            throw new \InvalidArgumentException('LOG_DIR must not contain "..".');
        }
    }

    /**
     * Full path of the log file (relative to the project base).
     */
    public function fullPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->file;
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}