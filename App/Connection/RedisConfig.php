<?php
namespace alirezax5\TelegramBase\App\Connection;

final  class RedisConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $password,
        public int $database,
        public float $timeout,
    ) {}
}