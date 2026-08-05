<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Update;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Storage\OffsetStorage;
use telegramBotApiPhp\Telegram;

class Fetcher
{
    private int $limit;
    private int $timeout;
    private ?array $allowedUpdates;
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
     * Fetch the next batch of updates from the Telegram API.
     *
     * The stored offset is passed to Telegram so delivered updates are
     * marked consumed; offset 0 is suppressed on first run to avoid
     * Telegram rejecting it.
     *
     * @return array List of update objects
     */
    public function fetch(): array
    {
        try {
            $offset = $this->offsetStorage->get();
            
            // SDK requires int; pass 0 on first run (Telegram ignores 0 offset)
            $response = $this->Telegram->getUpdates(
                $offset > 0 ? $offset : 0,
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
     * Mark an update ID (and everything before it) as consumed.
     *
     * @param int $updateId Next offset to request (update_id + 1)
     */
    public function updateLastId(int $updateId): void
    {
        $this->offsetStorage->set($updateId);
    }

    /**
     * Parse the ALLOWED_UPDATES config into a Telegram update-type list.
     *
     * @return array|null List of update types, or null for 'all'
     */
    private function resolveAllowedUpdates(): ?array
    {
        $allowed = Config::bot()->allowedUpdates;

        if ($allowed === 'all' || $allowed === '') {
            return null;
        }

        return array_values(
            array_filter(
                array_map('trim', explode(',', $allowed))
            )
        );
    }
}