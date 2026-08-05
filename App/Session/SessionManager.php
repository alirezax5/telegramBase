<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Session;

use alirezax5\TelegramBase\App\Cache\CacheManager;
use alirezax5\TelegramBase\App\Config\Config;

/**
 * SessionManager: entry point for per-chat session state.
 *
 * Sessions are stored through CacheManager (array/file/redis/memcached),
 * keyed by chat id, with a TTL so idle sessions expire automatically.
 */
final class SessionManager
{
    private static string $prefix = 'tgbase:session:';
    private static int $ttl = 3600;
    private static bool $configured = false;

    /**
     * Start (or resume) a session for a chat.
     */
    public static function start(int $chatId): Session
    {
        self::configure();

        $raw = CacheManager::get(self::cacheKey($chatId));

        if (!is_array($raw)) {
            $session = new Session(
                chatId: $chatId,
                sessionId: self::generateId(),
                data: [],
                lastActivity: time(),
            );
            $session->persist();
            return $session;
        }

        return Session::fromArray($chatId, $raw);
    }

    /**
     * Read an existing session without creating one.
     */
    public static function get(int $chatId): ?Session
    {
        self::configure();

        $raw = CacheManager::get(self::cacheKey($chatId));

        if (!is_array($raw)) {
            return null;
        }

        return Session::fromArray($chatId, $raw);
    }

    /**
     * Whether a session currently exists.
     */
    public static function has(int $chatId): bool
    {
        self::configure();

        return CacheManager::has(self::cacheKey($chatId));
    }

    /**
     * Delete a session entirely.
     */
    public static function flush(int $chatId): void
    {
        self::configure();

        CacheManager::forget(self::cacheKey($chatId));
    }

    /**
     * Cache key for a chat id.
     */
    public static function cacheKey(int $chatId): string
    {
        return self::$prefix . $chatId;
    }

    /**
     * Session TTL (seconds).
     */
    public static function ttl(): int
    {
        return self::$ttl;
    }

    /**
     * Random session id.
     */
    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function configure(): void
    {
        if (self::$configured) {
            return;
        }

        $config = Config::session();

        self::$prefix = $config->prefix;
        self::$ttl = max(60, $config->ttl);
        self::$configured = true;
    }
}
