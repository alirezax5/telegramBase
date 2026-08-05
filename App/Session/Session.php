<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Session;

use alirezax5\TelegramBase\App\Cache\CacheManager;
use alirezax5\TelegramBase\App\Config\Config;

/**
 * Per-user session state, persisted through the cache backend.
 *
 * Each session has a stable session id (regenerated on login for security)
 * and a TTL, so long-idle sessions expire automatically. The underlying
 * storage is the configured cache driver (array / file / redis / memcached).
 */
final class Session
{
    public function __construct(
        private readonly int $chatId,
        private readonly string $sessionId,
        private array $data,
        private int $lastActivity,
    ) {
    }

    /**
     * Set a value in the session, persisting it immediately.
     */
    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        $this->persist();
        return $this;
    }

    /**
     * Set multiple values at once.
     */
    public function setMany(array $values): self
    {
        $this->data = array_merge($this->data, $values);
        $this->persist();
        return $this;
    }

    /**
     * Get a value, or the default when the key is not present.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Whether a key exists (even if its value is null).
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Remove a single key.
     */
    public function forget(string $key): self
    {
        unset($this->data[$key]);
        $this->persist();
        return $this;
    }

    /**
     * All session data as an array.
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Replace the session id (call after login to prevent session fixation).
     */
    public function regenerate(): string
    {
        $this->sessionId = SessionManager::generateId();
        $this->persist();
        return $this->sessionId;
    }

    /**
     * Destroy the session entirely.
     */
    public function destroy(): void
    {
        SessionManager::flush($this->chatId);
    }

    /**
     * Refresh the last-activity timestamp and save.
     */
    public function touch(): self
    {
        $this->lastActivity = time();
        $this->persist();
        return $this;
    }

    public function chatId(): int
    {
        return $this->chatId;
    }

    public function id(): string
    {
        return $this->sessionId;
    }

    public function lastActivity(): int
    {
        return $this->lastActivity;
    }

    /**
     * Persist the in-memory state to the cache backend.
     */
    public function persist(): void
    {
        CacheManager::put(
            SessionManager::cacheKey($this->chatId),
            $this->toArray(),
            SessionManager::ttl()
        );
    }

    /**
     * Serializable payload.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->sessionId,
            'data' => $this->data,
            'last_activity' => $this->lastActivity,
        ];
    }

    /**
     * Hydrate a Session from a cached payload.
     */
    public static function fromArray(int $chatId, array $raw): self
    {
        return new self(
            chatId: $chatId,
            sessionId: (string)($raw['id'] ?? SessionManager::generateId()),
            data: (array)($raw['data'] ?? []),
            lastActivity: (int)($raw['last_activity'] ?? time()),
        );
    }
}