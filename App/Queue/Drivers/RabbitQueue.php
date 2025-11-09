<?php

namespace alirezax5\TelegramBase\App\Queue\Drivers;

use alirezax5\TelegramBase\App\Queue\QueueInterface;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use Exception;

class RabbitQueue implements QueueInterface
{
    protected ?AMQPStreamConnection $connection = null;
    protected $channel;
    protected string $queue;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->queue = $config['queue'] ?? 'bot_queue';

        $this->connect();
    }

    private function connect(): void
    {
        try {
            $this->connection = new AMQPStreamConnection(
                $this->config['host'] ?? '127.0.0.1',
                $this->config['port'] ?? 5672,
                $this->config['user'] ?? 'guest',
                $this->config['password'] ?? 'guest',
                $this->config['vhost'] ?? '/' // ✅ اضافه شده
            );

            $this->channel = $this->connection->channel();
            $this->channel->queue_declare(
                $this->queue,
                false,  // passive
                true,   // durable
                false,  // exclusive
                false   // auto-delete
            );

            LogHandler::info("✅ Connected to RabbitMQ queue '{$this->queue}'");

        } catch (Exception $e) {
            $this->connection = null;
            LogHandler::error("❌ RabbitMQ connection failed: " . $e->getMessage());
        }
    }

    private function reconnectIfNeeded(): bool
    {
        if (!$this->isConnected()) {
            LogHandler::warning("🔄 Reconnecting to RabbitMQ...");
            $this->connect();
        }

        return $this->isConnected();
    }

    public function push(array $update): bool
    {
        if (!$this->reconnectIfNeeded()) return false;

        try {
            $msg = new AMQPMessage(json_encode($update), ['delivery_mode' => 2]);
            $this->channel->basic_publish($msg, '', $this->queue);
            return true;
        } catch (Exception $e) {
            LogHandler::error("❌ RabbitMQ push failed: " . $e->getMessage());
            return false;
        }
    }

    public function pop(): ?array
    {
        if (!$this->isConnected()) return null;

        $msg = $this->channel->basic_get($this->queue);
        if ($msg) {
            $deliveryTag = $msg->get('delivery_tag');
            if ($deliveryTag) {
                $this->channel->basic_ack($deliveryTag);
            }
            return json_decode($msg->body, true);
        }

        return null;
    }

    public function count(): int
    {
        if (!$this->reconnectIfNeeded()) return 0;

        try {
            [$queueName, $messageCount] = $this->channel->queue_declare($this->queue, true);
            return (int)$messageCount;
        } catch (Exception $e) {
            LogHandler::error("⚠️ RabbitMQ count failed: " . $e->getMessage());
            return 0;
        }
    }

    public function isConnected(): bool
    {
        return $this->connection && $this->connection->isConnected();
    }
}
