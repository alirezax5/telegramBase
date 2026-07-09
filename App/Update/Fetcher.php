<?php

namespace alirezax5\TelegramBase\App\Update;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Storage\OffsetStorage;
use telegramBotApiPhp\Telegram;

class Fetcher
{
    private  int $limit;
    private  int $timeout;
    private  ?array $allowedUpdates;
    private OffsetStorage $offsetStorage;

    public function __construct(
        private  Telegram $Telegram,
    ) {
        $this->limit = Config::bot()->pollingLimit;
        $this->timeout = Config::bot()->pollingTimeout;

        $this->offsetStorage = new OffsetStorage();

        $this->allowedUpdates = $this->resolveAllowedUpdates();
    }

    /**
     * Fetch updates from API
     */
    public function fetch()
    {
        try {
            $response = $this->Telegram->getUpdates(
                $this->offsetStorage->get(),
                $this->limit,
                $this->timeout,
                $this->allowedUpdates
            );

            if (empty($response?->ok) || !isset($response->result)) {
                return [];
            }

            $updates = $response->result;

            if (!empty($updates)) {
                LogHandler::debug('📥 Updates fetched: ' . count($updates));
            }

            return $updates;

        } catch (\Throwable $e) {
            LogHandler::error('Update fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Update last processed offset
     */
    public function updateLastId(int $updateId): void
    {
        $this->offsetStorage->set($updateId);
    }

    /**
     * Allowed updates parser
     */
    private function resolveAllowedUpdates(): ?array
    {
        $allowed = Config::bot()->allowedUpdates;

        if ($allowed === 'all') {
            return null;
        }

        return array_values(
            array_filter(
                array_map('trim', explode(',', $allowed))
            )
        );
    }
}