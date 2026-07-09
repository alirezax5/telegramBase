<?php
namespace alirezax5\TelegramBase\App\Connection;
final  class MemcachedConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
    ) {}
}