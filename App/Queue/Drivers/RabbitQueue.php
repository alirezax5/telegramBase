<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue\Drivers;

use alirezax5\TelegramBase\App\Queue\QueueInterface;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Connection\ConnectionManager;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class RabbitQueue implements QueueInterface
{
    protected $channel = null;
    protected string $queue;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->queue = $config['queue'] ?? 'bot_queue';

        $this->initChannel();
    }

    private function initChannel(): void
    {
        $connection = ConnectionManager::getInstance()->getRabbitMQ();

        if (!$connection || !$connection->isConnected()) {
            LogHandler::error("❌ RabbitMQQueue: no connection");
            $this->channel = null;
            return;
        }

        try {
            $this->channel = $connection->channel();

            $this->channel->queue_declare(
                $this->queue,
                false,
                true,
                false,
                false
            );

            LogHandler::info("✅ RabbitMQ ready: {$this->queue}");

        } catch (Throwable $e) {
            LogHandler::error("❌ RabbitMQ init failed: {$e->getMessage()}");
            $this->channel = null;
        }
    }

    private function reconnectIfNeeded(): bool
    {
        try {
            if (
                $this->channel === null ||
                !$this->channel->getConnection() ||
                !$this->channel->getConnection()->isConnected()
            ) {
                LogHandler::warning("🔄 RabbitMQ reconnect...");
                $this->initChannel();
            }
        } catch (Throwable $e) {
            $this->channel = null;
        }

        return $this->channel !== null;
    }

    public function push( $update): bool
    {
        if (!$this->reconnectIfNeeded()) return false;

        try {
            $payload = json_encode(
                $update,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($payload === false) {
                return false;
            }

            $msg = new AMQPMessage($payload, [
                'delivery_mode' => 2
            ]);

            $this->channel->basic_publish($msg, '', $this->queue);

            return true;

        } catch (Throwable $e) {
            LogHandler::error("❌ RabbitMQ push failed: {$e->getMessage()}");
            return false;
        }
    }

    public function pop(int $timeout = 0): ?array
    {
        if (!$this->reconnectIfNeeded()) return null;

        try {
            $msg = $this->channel->basic_get($this->queue);

            if (!$msg) {
                return null;
            }

            $data = json_decode($msg->body, true);

            $deliveryTag = $msg->delivery_info['delivery_tag'] ?? null;

            if (!$deliveryTag) {
                LogHandler::error("Missing delivery tag");
                return null;
            }

            if (!is_array($data)) {
                $this->channel->basic_ack($deliveryTag);
                return null;
            }

            $this->channel->basic_ack($deliveryTag);

            return $data;

        } catch (\Throwable $e) {
            LogHandler::error("❌ RabbitMQ pop failed: {$e->getMessage()}");
            return null;
        }
    }

    public function count(): int
    {
        if (!$this->reconnectIfNeeded()) return 0;

        try {
            $result = $this->channel->queue_declare(
                $this->queue,
                true,
                true,
                false,
                false
            );

            return (int)($result[1] ?? 0);

        } catch (Throwable $e) {
            LogHandler::warning("⚠️ RabbitMQ count failed: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * CLEAR - purge all messages from the queue
     */
    public function clear(): int
    {
        if (!$this->reconnectIfNeeded()) return 0;

        try {
            $result = $this->channel->queue_declare(
                $this->queue,
                true,
                true,
                false,
                false
            );

            $count = (int)($result[1] ?? 0);

            if ($count > 0) {
                $this->channel->queue_purge($this->queue);
            }

            return $count;

        } catch (Throwable $e) {
            LogHandler::warning("RabbitMQ clear failed: {$e->getMessage()}");
            return 0;
        }
    }

    public function isConnected(): bool
    {
        try {
            return $this->channel !== null
                && $this->channel->getConnection()
                && $this->channel->getConnection()->isConnected();
        } catch (Throwable) {
            return false;
        }
    }
}