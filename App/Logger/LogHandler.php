<?php
namespace alirezax5\TelegramBase\App\Logger;


use alirezax5\TelegramBase\App\Environment\EnvHandler;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class LogHandler
{
    private static ?Logger $logger = null;


    private static function init(): void
    {
        if (self::$logger !== null) {
            return;
        }

        if (strtolower(EnvHandler::get('LOG_ENABLED',false) ) !== 'true') {
            return;
        }

        $path = rtrim(EnvHandler::get('LOG_DIR','./logs/'), '/');
        $file = EnvHandler::get('LOG_FILE','log.txt');
        $fullPath = "{$path}/{$file}";

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        self::$logger = new Logger('AppLogger');

        $handler = new StreamHandler($fullPath, Logger::DEBUG);

        $formatter = new LineFormatter("[%datetime%] [%level_name%]: %message%\n", "Y-m-d H:i:s", true, true);
        $handler->setFormatter($formatter);

        self::$logger->pushHandler($handler);
    }


    public static function log(string $level, string $message, array $context = []): void
    {
        self::init();

        if (self::$logger === null) {
            return;
        }

        self::$logger->log(strtoupper($level), $message, $context);
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
