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

    /**
     * P3.3.1-F006 fix — ack-late delivery tracking.
     *
     * pop() no longer acknowledges the message it returns. The delivery
     * tag is held here until the consumer calls ack() AFTER processing.
     * If the worker dies before the ack, the broker redelivers the
     * message on reconnect (at-least-once) instead of losing it forever
     * (the old ack-at-pop behaviour was at-most-once).
     */
    private $pendingTag = null;
    private $pendingTagChannel = null;

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

    /**
     * POP a message from the RabbitMQ queue.
     *
     * P3.3.1-F006 fix: the returned message is NOT acknowledged here —
     * only after the payload decodes to a valid array is the delivery
     * tag held for a later ack(). A corrupted message is acknowledged
     * immediately (discarded) to avoid redelivery loops.
     *
     * Call ack() after the message has been processed. If the process
     * dies before ack(), the broker redelivers the message.
     *
     * @param int $timeout Ignored — RabbitMQ has no blocking-scan pop
     * @return array|null Decoded update array or null when empty
     */
    public function pop(int $timeout = 0): ?array
    {
        if (!$this->reconnectIfNeeded()) return null;

        try {
            $msg = $this->channel->basic_get($this->queue);

            if (!$msg) {
                return null;
            }

            $deliveryTag = $msg->delivery_info['delivery_tag'] ?? null;

            if (!$deliveryTag) {
                LogHandler::error("Missing delivery tag");
                return null;
            }

            $data = json_decode($msg->body, true);

            if (!is_array($data)) {
                // Corrupted payload: discard immediately (no redelivery loop).
                $this->channel->basic_ack($deliveryTag);
                return null;
            }

            // Hold the tag — ack happens in ack() after processing.
            $this->pendingTag = $deliveryTag;
            $this->pendingTagChannel = $this->channel;

            return $data;
        } catch (\Throwable $e) {
            LogHandler::error("❌ RabbitMQ pop failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * P3.3.1-F006 fix: acknowledge the in-flight message AFTER processing.
     *
     * A no-op when nothing is pending. The ack is only sent while the
     * channel that delivered the message is still the current one — after
     * a reconnect the broker has already requeued the unacked message, so
     * re-acking on the new channel would be invalid.
     *
     * If the ack cannot be delivered, the message is redelivered by the
     * broker later (at-least-once, duplicate processing is possible and
     * handlers should be idempotent).
     */
    public function ack(): void
    {
        $tag = $this->pendingTag;
        $channel = $this->pendingTagChannel;

        $this->pendingTag = null;
        $this->pendingTagChannel = null;

        if ($tag === null) {
            return;
        }

        if ($channel === null || $channel !== $this->channel) {
            // Channel was recreated: the unacked message was already
            // requeued by the broker when the old channel died.
            return;
        }

        try {
            $channel->basic_ack($tag);
        } catch (Throwable $e) {
            LogHandler::warning(
                "⚠️ RabbitMQ ack failed (message will be redelivered): {$e->getMessage()}"
            );
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

    /**
     * Drop ack-late tracking without acknowledging (used on channel loss).
     * The broker requeues unacked messages automatically when a channel
     * dies, so nothing else is needed here.
     */
    public function __destruct()
    {
        $this->pendingTag = null;
        $this->pendingTagChannel = null;
    }
}