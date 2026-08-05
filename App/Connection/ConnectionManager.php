<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Connection;

use Redis;
use Memcached;
use Throwable;
use PhpAmqpLib\Connection\AMQPStreamConnection;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;

final class ConnectionManager
{
    private static ?self $instance = null;

    private ?Redis $redis = null;
    private ?Memcached $memcached = null;
    private ?AMQPStreamConnection $rabbitmq = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private ConnectionConfig $config;

    private function __construct()
    {
        $this->config = Config::Connection();
    }

    /* =========================
       REDIS
    ========================= */

    public function getRedis(): ?Redis
    {
        if ($this->redis && $this->isRedisAlive()) {
            return $this->redis;
        }

        return $this->redis = $this->createRedis();
    }

    private function isRedisAlive(): bool
    {
        try {
            $pong = $this->redis?->ping();
            // phpredis may return true (new versions) or '+PONG' string (older versions)
            $this->redisAlive = ($pong === true || strtoupper((string)$pong) === '+PONG');
            return $this->redisAlive;
        } catch (Throwable) {
            $this->redis = null;
            $this->redisAlive = false;
            return false;
        }
    }

    private bool $redisAlive = false;

    private function createRedis(): ?Redis
    {
        try {
            if (!class_exists(Redis::class)) {
                LogHandler::warning("⚠️ Redis extension not installed");
                return null;
            }

            $cfg = $this->config->redis;

            $redis = new Redis();

            if (!$redis->connect($cfg->host, $cfg->port, $cfg->timeout)) {
                throw new \RuntimeException("Redis connect failed");
            }

            if ($cfg->password !== '' && !$redis->auth($cfg->password)) {
                throw new \RuntimeException("Redis auth failed");
            }

            if ($cfg->database > 0) {
                $redis->select($cfg->database);
            }

            LogHandler::info("✅ Redis connected");
            return $redis;

        } catch (Throwable $e) {
            LogHandler::error("❌ Redis error: " . $e->getMessage());
            return null;
        }
    }

    /* =========================
       MEMCACHED
    ========================= */

    public function getMemcached(): ?Memcached
    {
        if ($this->memcached) {
            return $this->memcached;
        }

        return $this->memcached = $this->createMemcached();
    }

    private function createMemcached(): ?Memcached
    {
        try {
            if (!extension_loaded('memcached')) {
                LogHandler::warning("⚠️ Memcached extension missing");
                return null;
            }

            $cfg = $this->config->memcached;

            $mc = new Memcached();

            if (!$mc->getServerList()) {
                $mc->addServer($cfg->host, $cfg->port);

                if ($cfg->username && $cfg->password) {
                    $mc->setSaslAuthData($cfg->username, $cfg->password);
                }
            }

            // Liveness check — Memcached::getVersion() returns false on failure
            if (!$mc->getVersion()) {
                LogHandler::error("❌ Memcached ping failed");
                return null;
            }

            LogHandler::info("✅ Memcached connected");
            return $mc;

        } catch (Throwable $e) {
            LogHandler::error("❌ Memcached error: " . $e->getMessage());
            return null;
        }
    }

    /* =========================
       RABBITMQ
    ========================= */

    public function getRabbitMQ(): ?AMQPStreamConnection
    {
        if ($this->rabbitmq && $this->rabbitmq->isConnected()) {
            return $this->rabbitmq;
        }

        return $this->rabbitmq = $this->createRabbitMQ();
    }

    private function createRabbitMQ(): ?AMQPStreamConnection
    {
        try {
            $cfg = $this->config->rabbitmq;

            $conn = new AMQPStreamConnection(
                $cfg->host,
                $cfg->port,
                $cfg->user,
                $cfg->password,
                $cfg->vhost,
                false,       // insist
                'default',   // login_method
                null,        // login_response
                'en_US',     // locale
                3.0,         // connection_timeout
                3.0          // read_write_timeout (heartbeat interval)
            );

            LogHandler::info("✅ RabbitMQ connected");
            return $conn;

        } catch (Throwable $e) {
            LogHandler::error("❌ RabbitMQ error: " . $e->getMessage());
            return null;
        }
    }

    /* =========================
       CLEANUP
    ========================= */

    public function closeAll(): void
    {
        try {
            $this->redis?->close();
        } catch (Throwable) {}

        try {
            $this->memcached?->quit();
        } catch (Throwable) {}

        try {
            $this->rabbitmq?->close();
        } catch (Throwable) {}

        $this->redis = null;
        $this->memcached = null;
        $this->rabbitmq = null;

        LogHandler::debug("🧹 Connections cleaned");
    }

    public function __destruct()
    {
        $this->closeAll();
    }
}