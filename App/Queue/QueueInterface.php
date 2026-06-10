<?php

namespace alirezax5\TelegramBase\App\Queue;

interface QueueInterface
{
    public function push(array $update): bool;
    public function pop();
    public function count(): int;
    public function isConnected(): bool;
}
