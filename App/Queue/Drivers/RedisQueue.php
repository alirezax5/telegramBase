<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue\Drivers;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Queue\QueueInterface;
use alirezax5\TelegramBase\App\Connection\ConnectionManager;
use Redis;
use Exception;

class RedisQueue implements QueueInterface
{
    protected ?Redis $redis = null;
    protected string $key;

    private bool $connected = false;
    private float $lastPingAt = 0;
    private const PING_INTERVAL = 5.0;
    private const JOB_TTL = 86400; // 24h — prevent unbounded growth on crash

    public function __construct(array $config)
    {
        $this->key = $config['key'] ?? 'bot_queue';

        $this->redis = ConnectionManager::getInstance()->getRedis();

        if (!$this->redis) {
            LogHandler::error("RedisQueue: No shared connection available");
        } else {
            $this->connected = true;
            $this->lastPingAt = microtime(true);
        }
    }

    /**
     * Push an update onto the queue tail.
     *
     * Each element is a structured {payload, ts} JSON so pop() can validate
     * and survive partial Redis failures without silently losing jobs.
     *
     * @param array $update Telegram update array
     * @return bool True on success
     */
    public function push(array $update): bool
    {
        if (!$this->isConnected()) {
            LogHandler::error("Cannot push: Redis not connected.");
            return false;
        }

        $item = json_encode(
            ['payload' => $update, 'ts' => time()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($item === false) {
            return false;
        }

        return $this->redis->rPush($this->key, $item) !== false;
    }

    /**
     * POP with optional blocking (blPop) for Redis.
     *
     * @param int $timeout Blocking timeout in seconds (0 = non-blocking)
     * @return array|null Decoded update array, or null when empty/timed out
     */
    public function pop(int $timeout = 0): ?array
    {
        if (!$this->isConnected()) {
            LogHandler::error("Cannot pop: Redis not connected.");
            return null;
        }

        if ($timeout > 0) {
            $result = $this->redis->blPop([$this->key], $timeout);

            if (empty($result) || !isset($result[1])) {
                return null;
            }

            $item = json_decode($result[1], true);

            return $this->extractPayload($item);
        }

        $item = $this->redis->lPop($this->key);

        if (!$item) {
            return null;
        }

        return $this->extractPayload(json_decode($item, true));
    }

    /**
     * Extract the update payload from the stored item.
     *
     * Legacy plain-array items (pushed before the structured format) are
     * handled transparently so existing queued data is not lost.
     *
     * @param mixed $item Decoded queue element
     * @return array|null Update array or null when corrupted
     */
    private function extractPayload(mixed $item): ?array
    {
        if (is_array($item) && isset($item['payload']) && is_array($item['payload'])) {
            return $item['payload'];
        }

        return is_array($item) ? $item : null;
    }

    public function count(): int
    {
        if (!$this->isConnected()) {
            return 0;
        }

        return (int) $this->redis->lLen($this->key);
    }

    /**
     * CLEAR - delete the queue key
     */
    public function clear(): int
    {
        if (!$this->isConnected()) {
            return 0;
        }

        $count = (int) $this->redis->lLen($this->key);
        $this->redis->del($this->key);

        return $count;
    }

    /**
     * CONNECTION check (cached ping to avoid per-operation overhead)
     */
    public function isConnected(): bool
    {
        if (!$this->redis instanceof Redis) {
            return false;
        }

        $now = microtime(true);

        if ($this->connected && ($now - $this->lastPingAt) < self::PING_INTERVAL) {
            return true;
        }

        try {
            $pong = $this->redis->ping();
            $this->connected = ($pong === true || strtoupper((string)$pong) === 'PONG' || strtoupper((string)$pong) === '+PONG');
            $this->lastPingAt = $now;
            return $this->connected;
        } catch (Exception) {
            $this->connected = false;
            return false;
        }
    }
}
