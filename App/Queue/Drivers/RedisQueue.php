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

    public function __construct(array $config)
    {
        $this->key = $config['key'] ?? 'bot_queue';

        // استفاده از اتصال مشترک
        $this->redis = ConnectionManager::getInstance()->getRedis();

        if (!$this->redis) {
            LogHandler::error("❌ RedisQueue: No shared connection available");
        }
    }

    public function push(array $update): bool
    {
        if (!$this->isConnected()) {
            LogHandler::error("❌ Cannot push: Redis not connected.");
            return false;
        }

        return $this->redis->rPush($this->key, json_encode($update, JSON_UNESCAPED_UNICODE)) !== false;
    }

    public function pop(): ?array
    {
        if (!$this->isConnected()) {
            LogHandler::error("❌ Cannot pop: Redis not connected.");
            return null;
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

    public function isConnected(): bool
    {
        if (!$this->redis instanceof Redis) {
            return false;
        }

        try {
            $pong = $this->redis->ping();
            return $pong === true || strtoupper((string)$pong) === 'PONG' || strtoupper((string)$pong) === '+PONG';
        } catch (Exception) {
            return false;
        }
    }
}