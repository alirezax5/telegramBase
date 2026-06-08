<?php
namespace alirezax5\TelegramBase\App\Connection;
final  class RabbitMQConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public string $password,
        public string $vhost,
    ) {}
}