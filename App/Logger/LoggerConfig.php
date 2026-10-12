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
        public readonly ?\DateTimeZone $timezone = null,
    ) {
        $this->validate();
    }

    public static function fromEnv(): self
    {
        return new self(
            // Default ON: with the historical default (false), every
            // installation that forgot to set LOG_ENABLED lost all
            // errors silently — plugin exceptions were invisible. Ops
            // who want a quiet log still opt out with LOG_ENABLED=false.
            enabled: self::toBool(EnvHandler::get('LOG_ENABLED', true)),
            directory: rtrim(
                (string) EnvHandler::get('LOG_DIR', './logs/'),
                '/\\'
            ),
            file: (string) EnvHandler::get('LOG_FILE', 'log.txt'),
            timezone: self::toTimezone(EnvHandler::get('LOG_TIMEZONE', '')),
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
        self::assertSafeLogDir($this->directory, $this->file);
    }

    /**
     * Fail fast on unsafe log path configuration.
     *
     * The same guard used by LoggerConfig::validate(), exposed statically so
     * Paths::ensureDirectories() can reject unsafe LOG_DIR values BEFORE any
     * directory is created. Otherwise a traversal value (e.g. "../../etc")
     * would first be created outside the project root and only rejected
     * later — or never rejected on hosts where the mkdir itself succeeds.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertSafeLogDir(?string $directory = null, ?string $file = null): void
    {
        $directory ??= rtrim(
            (string) EnvHandler::get('LOG_DIR', './logs/'),
            '/\\'
        );
        $file ??= (string) EnvHandler::get('LOG_FILE', 'log.txt');

        if (Path::isAbsolute($directory) || Path::isAbsolute($file)) {
            throw new \InvalidArgumentException('LOG_DIR and LOG_FILE must be relative paths.');
        }

        $dir = str_replace('\\', '/', $directory);

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

    /**
     * Absolute path of the log file, resolved against the project base.
     *
     * Use this when handing the path to Monolog/StreamHandler — using the
     * relative fullPath() would write logs into the current working
     * directory, which is wrong for `bin/tgbase` invocations from
     * elsewhere in the filesystem.
     */
    public function absolutePath(): string
    {
        return \alirezax5\TelegramBase\App\Paths::logDirectory()
            . DIRECTORY_SEPARATOR
            . $this->file;
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Optional pinned timezone for log timestamps (LOG_TIMEZONE).
     *
     * Web entries (PHP-FPM) and CLI/cron workers frequently run under
     * different php.ini timezones, which mixes two offsets into the same
     * log file and makes incident timelines unreadable. Setting
     * LOG_TIMEZONE pins every Monolog timestamp to one zone regardless of
     * the SAPI that wrote the line.
     *
     * An empty value keeps the runtime default. An invalid identifier ALSO
     * falls back to the runtime default (availability first — a typo in an
     * observability knob must not kill the boot; the mixed-offset symptom
     * in the log is the visible hint).
     */
    private static function toTimezone(mixed $value): ?\DateTimeZone
    {
        $name = trim((string) $value);

        if ($name === '') {
            return null;
        }

        try {
            return new \DateTimeZone($name);
        } catch (\Throwable) {
            return null;
        }
    }
}