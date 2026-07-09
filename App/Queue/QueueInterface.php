<?php

namespace alirezax5\TelegramBase\App\Queue;

interface QueueInterface
{
    public function push(array $update): bool;
    public function pop(int $timeout = 0): ?array;
    public function count(): int;
    public function isConnected(): bool;
    public function clear(): int;
}
