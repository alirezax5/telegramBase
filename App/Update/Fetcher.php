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
     * FIX: robust normalization (handles array + object + SDK wrapper)
     */
    private function normalize(mixed $items): array
    {
        if (is_array($items)) {
            return $this->normalizeArray($items);
        }

        if (is_object($items)) {

            // SDK wrapper case: returnedMessage -> result inside
            if (isset($items->result)) {
                return $this->normalize($items->result);
            }

            // toArray support
            if (method_exists($items, 'toArray')) {
                return $this->normalizeArray($items->toArray());
            }

            // fallback object → array
            return $this->normalizeArray([(array)$items]);
        }

        return [];
    }

    /**
     * Normalize array items
     */
    private function normalizeArray(array $items): array
    {
        return array_map(
            static function ($item): array {

                if (is_array($item)) {
                    return $item;
                }

                if (is_object($item) && method_exists($item, 'toArray')) {
                    return $item->toArray();
                }

                return (array) $item;
            },
            $items
        );
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