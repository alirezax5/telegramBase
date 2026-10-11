<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Queue\Drivers\JsonQueue;
use alirezax5\TelegramBase\App\Queue\RetryQueue;
use alirezax5\TelegramBase\App\Queue\RetryConfig;
use alirezax5\TelegramBase\App\Queue\RetryableException;

class RetryQueueTest extends TgBaseTestCase
{
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/tgbase_retry_test_' . uniqid();
        return $dir;
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public function test_push_increments_count(TgAssert $a): void
    {
        $dir = $this->tempDir();
        try {
            $config = new RetryConfig(
                driver: 'json',
                path: $dir,
                maxAttempts: 3,
                baseDelay: 1,
                maxSize: 100,
                redisKey: 'unused',
                redis: [],
            );
            $q = new RetryQueue($config);

            $a->equals(0, $q->count());
            $q->push(['update_id' => 1], 'first failure');
            $a->equals(1, $q->count());
            $q->push(['update_id' => 2], 'second failure');
            $a->equals(2, $q->count());
        } finally {
            $this->cleanDir($dir);
        }
    }

    public function test_max_size_drops_overflow(TgAssert $a): void
    {
        $dir = $this->tempDir();
        try {
            $config = new RetryConfig(
                driver: 'json',
                path: $dir,
                maxAttempts: 3,
                baseDelay: 1,
                maxSize: 2,
                redisKey: 'unused',
                redis: [],
            );
            $q = new RetryQueue($config);

            $a->true($q->push(['id' => 1]));
            $a->true($q->push(['id' => 2]));
            // Third push should be rejected (queue full).
            $a->false($q->push(['id' => 3]));
            $a->equals(2, $q->count());
        } finally {
            $this->cleanDir($dir);
        }
    }

    public function test_process_drops_after_max_attempts(TgAssert $a): void
    {
        $dir = $this->tempDir();
        try {
            $config = new RetryConfig(
                driver: 'json',
                path: $dir,
                maxAttempts: 2,
                baseDelay: 1,
                maxSize: 0,
                redisKey: 'unused',
                redis: [],
            );
            $q = new RetryQueue($config);

            $q->push(['update_id' => 1], 'failure');

            // First process() pass: handler throws, item gets re-queued
            // with next_retry_at = now + (baseDelay * 2^1) = now + 2s.
            $attempts = 0;
            $processed1 = $q->process(function ($update) use (&$attempts): void {
                $attempts++;
                throw new RetryableException('always fails');
            });

            $a->equals(1, $attempts, 'first pass should attempt once');
            $a->equals(1, $q->count(), 'item should be re-queued with backoff');

            // Second pass immediately: item is not due yet (next_retry_at
            // is in the future), so process() should pop it, see it's not
            // due, push it back, and break — no new attempt.
            $processed2 = $q->process(function ($update) use (&$attempts): void {
                $attempts++;
                throw new RetryableException('always fails');
            });
            $a->equals(1, $attempts, 'second pass should not attempt (not due)');

            // Wait long enough for the backoff to expire, then process again.
            // Item becomes due, handler throws, attempts=2 → max reached → drop.
            sleep(3);
            $q->process(function ($update) use (&$attempts): void {
                $attempts++;
                throw new RetryableException('always fails');
            });

            $a->equals(2, $attempts, 'third pass should attempt and drop');
            $a->equals(0, $q->count(), 'queue should be empty after drop');
        } finally {
            $this->cleanDir($dir);
        }
    }

    public function test_process_succeeds_first_try(TgAssert $a): void
    {
        $dir = $this->tempDir();
        try {
            $config = new RetryConfig(
                driver: 'json',
                path: $dir,
                maxAttempts: 3,
                baseDelay: 1,
                maxSize: 100,
                redisKey: 'unused',
                redis: [],
            );
            $q = new RetryQueue($config);
            $q->push(['update_id' => 42]);

            $processed = $q->process(function ($update) use ($a): void {
                $a->equals(42, $update['update_id']);
            });

            $a->equals(0, $q->count());
        } finally {
            $this->cleanDir($dir);
        }
    }
}
