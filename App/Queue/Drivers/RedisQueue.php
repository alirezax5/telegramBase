<?php

namespace alirezax5\TelegramBase\App\Queue\Drivers;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Queue\QueueInterface;
use Redis;
use Exception;

class RedisQueue implements QueueInterface
{
    protected ?Redis $redis = null;
    protected string $key;

    public function __construct(array $config)
    {
        $this->key = $config['key'] ?? 'bot_queue';

        try {
            $this->redis = new Redis();

            // اتصال
            if (!$this->redis->connect($config['host'] ?? '127.0.0.1', $config['port'] ?? 6379, 2)) {
                throw new Exception('Redis connection failed.');
            }

            // احراز هویت
            if (!empty($config['password'])) {
                if (!$this->redis->auth($config['password'])) {
                    throw new Exception('Redis authentication failed.');
                }
            }

            // انتخاب دیتابیس در صورت وجود
            if (isset($config['database'])) {
                $this->redis->select((int)$config['database']);
            }

            // تاخیر کوتاه برای پایداری
            usleep(200000); // 0.2 ثانیه

            LogHandler::info("✅ Connected to Redis successfully");

        } catch (Exception $e) {
            LogHandler::error("❌ Redis connection error: " . $e->getMessage());
            $this->redis = null;
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
