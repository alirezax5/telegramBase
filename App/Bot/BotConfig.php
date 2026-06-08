<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bot;

use alirezax5\TelegramBase\App\Environment\EnvHandler;

final  class BotConfig
{
    public function __construct(
        public string $token,
        public string $apiUrl,
        public string $apiUrlFile,
        public string $mode,
        public string $webhookUrl,
        public string $allowedUpdates,
        public int    $pollingLimit,
        public int    $pollingTimeout,
        public string $pollingStateFile,
        public bool   $appDebug,
    )
    {
        $this->validate();
    }

    public static function fromEnv(): self
    {
        return new self(
            token: EnvHandler::string('BOT_TOKEN'),
            apiUrl: EnvHandler::string('BOT_API_URL', 'https://tapi.bale.ai'),
            apiUrlFile: EnvHandler::string('BOT_API_URL_FILE', 'https://tapi.bale.ai'),
            mode: EnvHandler::string('BOT_MODE', 'webhook_normal'),
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

        if (!in_array($this->mode, ['update_direct', 'update_queue', 'webhook_direct', 'webhook_queue', 'cronjob_update', 'cronjob_queue'], true)) {
            throw new \InvalidArgumentException(
                'BOT_MODE Not Supported: ' . $this->mode
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
        return $this->mode === 'webhook';
    }

    public function isUpdateMode(): bool
    {
        return $this->mode === 'update';
    }
}