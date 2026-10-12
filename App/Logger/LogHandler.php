<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Logger;

use alirezax5\TelegramBase\App\Config\AppConfig;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use \alirezax5\TelegramBase\App\Config\Config;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class LogHandler
{
    private static ?Logger $logger = null;
    private static bool $enabled = false;


    public static function init(): void
    {
        if (self::$logger !== null || self::$enabled) {
            self::$enabled = true;
            return;
        }

        $config = Config::logger();

        self::$enabled = $config->enabled;

        if (!$config->enabled) {
            return;
        }

        try {
            if (!is_dir($config->directory)) {
                mkdir($config->directory, 0777, true);
            }

            // 4th ctor arg = pinned timezone (LOG_TIMEZONE); null keeps the
            // runtime default. Keeps webhook (FPM) and CLI/cron lines in one
            // offset inside the same log file.
            self::$logger = new Logger('AppLogger', [], [], $config->timezone);

            $level = EnvHandler::get('LOG_LEVEL', 'debug');

            $handler = new StreamHandler(
                $config->absolutePath(),
                match (strtolower($level)) {
                    'error' => Logger::ERROR,
                    'warning' => Logger::WARNING,
                    'info' => Logger::INFO,
                    'debug' => Logger::DEBUG,
                    default => Logger::DEBUG,
                }
            );

            $handler->setFormatter(
                new LineFormatter(
                    "[%datetime%] [%level_name%]: %message% %context%\n",
                    "Y-m-d H:i:s",
                    true,
                    true
                )
            );

            self::$logger->pushHandler($handler);

            self::$logger->info("Logger initialized");

        } catch (\Throwable) {
            self::$logger = null;
            self::$enabled = false;
        }
    }


    public static function log(string $level, string $message, array $context = []): void
    {
        // If the framework was never bootstrapped (e.g. unit tests that
        // exercise a single class in isolation), silently no-op rather
        // than crash with a TypeError on Config::logger().
        if (!\alirezax5\TelegramBase\App\Config\Config::has()) {
            return;
        }

        self::init();

        if (!self::$enabled || self::$logger === null) {
            return;
        }

        try {
            self::$logger->log(strtolower($level), $message, $context);
        } catch (\Throwable) {
            // Never propagate logger errors to the application
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }
}