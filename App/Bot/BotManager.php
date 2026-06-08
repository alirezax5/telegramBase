<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bot;

use telegramBotApiPhp\Telegram;
use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;

final class BotManager
{
    private static ?self $instance = null;
    private array $bots = [];
    private string $defaultBotName = 'main';

    private function __construct()
    {
        $this->initDefaultBot();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function initDefaultBot(): void
    {
        $config = Config::bot();
        $this->bots[$this->defaultBotName] = new Telegram(
            $config->token,
            $config->apiUrl,
            $config->apiUrlFile
        );
    }

    public function get(string $name = null): Telegram
    {
        $name ??= $this->defaultBotName;
        return $this->bots[$name] ?? $this->bots[$this->defaultBotName];
    }

    public function setDefaultToken(string $token): Telegram
    {
        $bot = $this->get();
        $bot->setToken($token);
        return $bot;
    }
}