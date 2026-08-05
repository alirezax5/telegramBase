<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Environment;

class EnvironmentValidator
{
    /**
     * Validate all critical environment variables.
     *
     * Must run unconditionally at bootstrap — an invalid BOT_TOKEN
     * or BOT_MODE is a hard runtime failure regardless of debug mode.
     *
     * @throws \InvalidArgumentException When a value is missing or invalid
     */
    public function validate(): void
    {
        $this->require('BOT_TOKEN', fn($v) =>
        preg_match('/^\d+:[\w-]+$/', $v)
            , "BOT_TOKEN format invalid (expected: <digits>:<alphanumeric>)");

        $this->require('BOT_API_URL', fn($v) =>
            filter_var($v, FILTER_VALIDATE_URL) !== false
            , "BOT_API_URL is not a valid URL");

        $this->require('BOT_MODE', fn($v) =>
        in_array($v, ['update_direct', 'update_queue', 'webhook_direct', 'webhook_queue', 'cronjob_update', 'cronjob_queue'], true)
            , "BOT_MODE must be one of: update_direct, update_queue, webhook_direct, webhook_queue, cronjob_update, cronjob_queue");
    }

    /**
     * Generic validator helper.
     *
     * Errors intentionally do NOT include the actual value to avoid
     * leaking secrets (BOT_TOKEN) into log files.
     *
     * @param string   $key   Environment variable name
     * @param callable $rule  Validation predicate
     * @param string   $error Error message on failure
     * @throws \InvalidArgumentException
     */
    private function require(string $key, callable $rule, string $error): void
    {
        $value = EnvHandler::get($key);

        if ($value === null || $value === '') {
            throw new \InvalidArgumentException("{$key} is not set or empty.");
        }

        if (!$rule($value)) {
            throw new \InvalidArgumentException("{$error} (key: {$key})");
        }
    }
}