<?php

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

    public function push( $update): bool
    {
        if (!$this->isConnected()) {
            LogHandler::error("Cannot push: Redis not connected.");
            return false;
        }

        return $this->redis->rPush($this->key, json_encode($update, JSON_UNESCAPED_UNICODE)) !== false;
    }

    /**
     * POP with optional blocking (blPop) for Redis
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

            $data = json_decode($result[1], true);
            return is_array($data) ? $data : null;
        }

        $data = $this->redis->lPop($this->key);
        return $data ? json_decode($data, true) : null;
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
