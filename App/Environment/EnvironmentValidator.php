<?php

namespace alirezax5\TelegramBase\App\Environment;

class EnvironmentValidator
{

    public function validate(): void
    {
        $this->validateTelegramBotToken();
        $this->validateTelegramApiUrl();
        $this->validateBotType();
    }


    private function validateTelegramBotToken(): void
    {
        if (!isset($_ENV['BOT_TOKEN']))
            throw new \InvalidArgumentException("BOT_TOKEN is not set in environment variables.");


        $token = $_ENV['BOT_TOKEN'];

        if ($token === '')
            throw new \InvalidArgumentException("BOT_TOKEN cannot be empty.");


        if (!preg_match('/^\d+:[\w-]+$/', $token))
            throw new \InvalidArgumentException("BOT_TOKEN has invalid format.");

    }

    private function validateTelegramApiUrl(): void
    {
        if (!isset($_ENV['BOT_API_URL']))
            throw new \InvalidArgumentException("BOT_API_URL is not set in environment variables.");


        $url = $_ENV['BOT_API_URL'];

        if ($url === '') {
            throw new \InvalidArgumentException("BOT_API_URL cannot be empty.");
        }


        if (filter_var($url, FILTER_VALIDATE_URL) === false)
            throw new \InvalidArgumentException("BOT_API_URL is not a valid URL.");

    }


    private function validateBotType(): void
    {
        if (!isset($_ENV['BOT_MODE']))
            throw new \InvalidArgumentException("BOT_MODE is not set in environment variables.");


        $botType = $_ENV['BOT_MODE'];

        if ($botType === '')
            throw new \InvalidArgumentException("BOT_MODE cannot be empty.");


        if ($botType !== 'update' && $botType !== 'webhook')
            throw new \InvalidArgumentException(
                "Invalid BOT_MODE: '{$botType}'. Allowed values: 'update', 'webhook'"
            );

    }

}