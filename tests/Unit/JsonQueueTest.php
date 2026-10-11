<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Queue\Drivers\JsonQueue;

class JsonQueueTest extends TgBaseTestCase
{
    private string $tempDir = '';

    private function freshQueue(): JsonQueue
    {
        $this->tempDir = sys_get_temp_dir() . '/tgbase_queue_test_' . uniqid();
        return new JsonQueue($this->tempDir);
    }

    public function tearDown(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
    }

    public function test_push_and_pop_roundtrip(TgAssert $a): void
    {
        $q = $this->freshQueue();
        $payload = ['update_id' => 1001, 'message' => ['text' => 'hi']];

        $a->true($q->push($payload));
        $a->equals(1, $q->count());

        $popped = $q->pop();
        $a->equals($payload, $popped);
        $a->equals(0, $q->count());
    }

    public function test_pop_returns_null_when_empty(TgAssert $a): void
    {
        $q = $this->freshQueue();
        $a->true($q->isEmpty());
        $a->true($q->pop() === null);
    }

    public function test_fifo_order(TgAssert $a): void
    {
        $q = $this->freshQueue();

        for ($i = 1; $i <= 5; $i++) {
            $q->push(['order' => $i]);
        }

        $order = [];
        while (($item = $q->pop()) !== null) {
            $order[] = $item['order'];
        }

        $a->equals([1, 2, 3, 4, 5], $order, 'FIFO order must be preserved');
    }

    public function test_clear_removes_all(TgAssert $a): void
    {
        $q = $this->freshQueue();
        $q->push(['x' => 1]);
        $q->push(['x' => 2]);
        $a->equals(2, $q->count());

        $removed = $q->clear();
        $a->equals(2, $removed);
        $a->equals(0, $q->count());
    }

    public function test_unicode_payload_preserved(TgAssert $a): void
    {
        $q = $this->freshQueue();
        $payload = ['text' => 'سلام دنیا 🌍'];

        $q->push($payload);
        $popped = $q->pop();

        $a->equals($payload, $popped);
    }

    public function test_is_connected(TgAssert $a): void
    {
        $q = $this->freshQueue();
        $a->true($q->isConnected());
    }

    public function test_large_payload(TgAssert $a): void
    {
        $q = $this->freshQueue();
        $big = str_repeat('x', 100_000);
        $payload = ['big' => $big];

        $q->push($payload);
        $popped = $q->pop();

        $a->equals($payload, $popped);
    }
}
