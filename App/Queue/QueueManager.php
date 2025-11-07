<?php

namespace alirezax5\TelegramBase\App\Queue;

use alirezax5\TelegramBase\App\Queue\Drivers\JsonQueue;
use alirezax5\TelegramBase\App\Queue\Drivers\RedisQueue;
use alirezax5\TelegramBase\App\Queue\Drivers\RabbitQueue;

class QueueManager
{
    protected QueueInterface $driver;

    public function __construct(array $config)
    {
        switch ($config['type']) {
            case 'redis':
                $this->driver = new RedisQueue($config['redis']);
                break;
            case 'rabbitmq':
                $this->driver = new RabbitQueue($config['rabbitmq']);
                break;
            default:
                $this->driver = new JsonQueue($config['path']);
        }
    }

    public function push(array $update): bool
    {
        if (!$this->driver->isConnected()) {
            return false;
        }
        return $this->driver->push($update);
    }

    public function pop(): ?array
    {
        if (!$this->driver->isConnected()) {
            sleep(2);
            return null;
        }
        return $this->driver->pop();
    }

    public function getDriver(): QueueInterface
    {
        return $this->driver;
    }
}
