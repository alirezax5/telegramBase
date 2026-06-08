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
       $config= Config::logger();
        if (self::$logger !== null || self::$enabled) {
            return;
        }

<<<<<<< HEAD
        self::$enabled = $config->enabled;

        if (!$config->enabled) {
            return;
        }
=======
        $logEnabled = EnvHandler::get('LOG_ENABLED', false);
        $logEnabled = filter_var($logEnabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($logEnabled !== true) {
            return;
        }
>>>>>>> d91868226f4706400172e5afe1f25691cc14083f

        try {
            if (!is_dir($config->directory)) {
                mkdir($config->directory, 0777, true);
            }

            self::$logger = new Logger('AppLogger');

            $handler = new StreamHandler(
                $config->fullPath(),
                Logger::DEBUG
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
        self::init();

        if (!self::$enabled || self::$logger === null) {
            return;
        }

        try {
            self::$logger->log(strtolower($level), $message, $context);
        } catch (\Throwable) {
            // هیچ خطایی نباید به اپلیکیشن سرایت کند
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