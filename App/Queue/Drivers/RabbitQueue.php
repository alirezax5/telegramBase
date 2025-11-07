<?php

namespace alirezax5\TelegramBase\App\Queue\Drivers;

use alirezax5\TelegramBase\App\Queue\QueueInterface;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Exception;

class RabbitQueue implements QueueInterface
{
    protected ?AMQPStreamConnection $connection = null;
    protected $channel;
    protected string $queue;

    public function __construct(array $config)
    {
        $this->queue = $config['queue'] ?? 'bot_queue';
        try {
            $this->connection = new AMQPStreamConnection(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password']
            );
            $this->channel = $this->connection->channel();
            $this->channel->queue_declare($this->queue, false, true, false, false);
        } catch (Exception) {
            $this->connection = null;
        }
    }

    public function push(array $update): bool
    {
        if (!$this->isConnected()) return false;
        $msg = new AMQPMessage(json_encode($update));
        $this->channel->basic_publish($msg, '', $this->queue);
        return true;
    }

    public function pop(): ?array
    {
        if (!$this->isConnected()) return null;

        $msg = $this->channel->basic_get($this->queue);
        if ($msg) {
            $this->channel->basic_ack($msg->getDeliveryTag());
            return json_decode($msg->body, true);
        }

        return null;
    }

    public function count(): int
    {
        return 0; // نیاز به اجرای AMQPQueue دارد (اختیاری)
    }

    public function isConnected(): bool
    {
        return $this->connection && $this->connection->isConnected();
    }
}
