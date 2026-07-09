<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Environment;

use InvalidArgumentException;

class EnvironmentValidator
{
    public function validate(): void
    {
        $this->require('BOT_TOKEN', fn($v) =>
        preg_match('/^\d+:[\w-]+$/', $v)
            , "Invalid BOT_TOKEN format");

        $this->require('BOT_API_URL', fn($v) =>
            filter_var($v, FILTER_VALIDATE_URL) !== false
            , "BOT_API_URL is not valid URL");

        $this->require('BOT_MODE', fn($v) =>
        in_array($v, ['update_direct', 'update_queue', 'webhook_direct', 'webhook_queue', 'cronjob_update', 'cronjob_queue'], true)
            , "BOT_MODE must be one of: update_direct, update_queue, webhook_direct, webhook_queue, cronjob_update, cronjob_queue");
    }

    /**
     * Generic validator helper
     */
    private function require(string $key, callable $rule, string $error): void
    {
        $value = EnvHandler::get($key);

        if ($value === null || $value === '') {
            throw new InvalidArgumentException("{$key} is not set or empty.");
        }

        if (!$rule($value)) {
            throw new InvalidArgumentException("{$error}: {$value}");
        }
    }
}