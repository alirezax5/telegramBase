<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bot;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final class BotConfig
{
    public function __construct(
        public readonly string $token,
        public readonly string $apiUrl,
        public readonly string $apiUrlFile,
        public readonly string $mode,
        public readonly string $webhookUrl,
        public readonly string $allowedUpdates,
        public readonly int    $pollingLimit,
        public readonly int    $pollingTimeout,
        public readonly string $pollingStateFile,
        public readonly bool   $appDebug,
    ) {
        $this->validate();
    }

    public static function fromEnv(): self
    {
        return new self(
            token: EnvHandler::string('BOT_TOKEN'),
            apiUrl: EnvHandler::string('BOT_API_URL', 'https://api.telegram.org'),
            apiUrlFile: EnvHandler::string('BOT_API_URL_FILE', 'https://api.telegram.org'),
            mode: EnvHandler::string('BOT_MODE', 'update_direct'),
            webhookUrl: EnvHandler::string('BOT_WEBHOOK_URL', ''),
            allowedUpdates: EnvHandler::string('ALLOWED_UPDATES', 'all'),
            pollingLimit: EnvHandler::int('POLLING_LIMIT', 100),
            pollingTimeout: EnvHandler::int('POLLING_TIMEOUT', 30),
            pollingStateFile: EnvHandler::string('POLLING_STATE_FILE', 'lastupdate.txt'),
            appDebug: EnvHandler::bool('APP_DEBUG', false),
        );
    }

    private function validate(): void
    {
        if ($this->token === '') {
            throw new \InvalidArgumentException('BOT_TOKEN cannot be empty');
        }

        $validModes = [
            'update_direct', 'update_queue',
            'webhook_direct', 'webhook_queue',
            'cronjob_update', 'cronjob_queue',
        ];

        if (!in_array($this->mode, $validModes, true)) {
            throw new \InvalidArgumentException(
                "BOT_MODE not supported: {$this->mode}. Valid modes: " . implode(', ', $validModes)
            );
        }

        if ($this->pollingLimit < 1 || $this->pollingLimit > 1000) {
            throw new \InvalidArgumentException(
                'POLLING_LIMIT must be between 1 and 1000'
            );
        }

        if ($this->pollingTimeout < 1) {
            throw new \InvalidArgumentException(
                'POLLING_TIMEOUT must be greater than 0'
            );
        }
    }

    public function isWebhookMode(): bool
    {
        return str_starts_with($this->mode, 'webhook');
    }

    public function isUpdateMode(): bool
    {
        return str_starts_with($this->mode, 'update');
    }

    public function isQueueMode(): bool
    {
        return str_ends_with($this->mode, '_queue');
    }

    public function isCronjobMode(): bool
    {
        return str_starts_with($this->mode, 'cronjob');
    }
}