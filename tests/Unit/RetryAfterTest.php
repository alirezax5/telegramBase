<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Queue\RetryQueue;
use alirezax5\TelegramBase\App\Queue\RetryConfig;
use alirezax5\TelegramBase\App\Queue\RetryableException;

/**
 * Phase 2.1 tests: verify RetryQueue honors Telegram's retry_after hint.
 *
 * These tests do NOT sleep for real seconds. Instead they:
 *   1. Push an item
 *   2. Run process() with a handler that throws RetryableException(retryAfter=N)
 *   3. Inspect the re-queued item's next_retry_at to verify it equals now+N
 *
 * This works because process() pushes the item back into the queue on failure,
 * and we can pop it again to inspect the stored next_retry_at.
 */
class RetryAfterTest extends TgBaseTestCase
{
    private string $retryDir = '';

    private function freshQueue(int $maxAttempts = 5, int $baseDelay = 2): RetryQueue
    {
        $this->retryDir = sys_get_temp_dir() . '/tgbase_retryafter_' . uniqid();
        $config = new RetryConfig(
            driver: 'json',
            path: $this->retryDir,
            maxAttempts: $maxAttempts,
            baseDelay: $baseDelay,
            maxSize: 100,
            redisKey: 'unused',
            redis: [],
        );
        return new RetryQueue($config);
    }

    public function tearDown(): void
    {
        if ($this->retryDir !== '' && is_dir($this->retryDir)) {
            foreach (glob($this->retryDir . '/*') as $f) @unlink($f);
            @rmdir($this->retryDir);
        }
    }

    /**
     * Pop the single item from the queue and return its next_retry_at.
     * Used to inspect what delay was stored after a failure.
     */
    private function popNextRetryAt(RetryQueue $q): ?int
    {
        // Access the driver via reflection to peek at the stored item.
        $ref = new ReflectionClass($q);
        $driverProp = $ref->getProperty('driver');
        $driverProp->setAccessible(true);
        $driver = $driverProp->getValue($q);

        $item = $driver->pop();
        if (!is_array($item)) {
            return null;
        }
        return (int)($item['next_retry_at'] ?? 0);
    }

    public function test_429_with_retry_after_uses_telegram_delay(TgAssert $a): void
    {
        $q = $this->freshQueue();
        $q->push(['update_id' => 1]);

        $before = time();

        // Handler throws with retryAfter=7 (simulating Telegram 429).
        $q->process(function (): void {
            throw new RetryableException('429 Too Many Requests', 429, null, 7);
        });

        $storedRetryAt = $this->popNextRetryAt($q);
        $a->true($storedRetryAt !== null, 'item should be re-queued');

        $delta = $storedRetryAt - $before;
        // Should be ~7 seconds (allow ±2 for execution time).
        $a->true($delta >= 6 && $delta <= 9, "expected delay ~7s, got {$delta}s");
    }

    public function test_429_without_retry_after_uses_exponential_backoff(TgAssert $a): void
    {
        // baseDelay=2, so attempt 1 → delay = 2 * 2^1 = 4s
        $q = $this->freshQueue(baseDelay: 2);
        $q->push(['update_id' => 2]);

        $before = time();

        // 429 but no retryAfter parsed.
        $q->process(function (): void {
            throw new RetryableException('429 Too Many Requests', 429, null, null);
        });

        $storedRetryAt = $this->popNextRetryAt($q);
        $delta = $storedRetryAt - $before;
        // Should be ~4 seconds (baseDelay=2 × 2^1).
        $a->true($delta >= 3 && $delta <= 6, "expected delay ~4s, got {$delta}s");
    }

    public function test_500_uses_exponential_backoff(TgAssert $a): void
    {
        $q = $this->freshQueue(baseDelay: 2);
        $q->push(['update_id' => 3]);

        $before = time();

        $q->process(function (): void {
            throw new RetryableException('500 Internal Server Error', 500);
        });

        $storedRetryAt = $this->popNextRetryAt($q);
        $delta = $storedRetryAt - $before;
        $a->true($delta >= 3 && $delta <= 6, "expected exponential backoff ~4s, got {$delta}s");
    }

    public function test_502_uses_exponential_backoff(TgAssert $a): void
    {
        $q = $this->freshQueue(baseDelay: 3);
        $q->push(['update_id' => 4]);

        $before = time();

        $q->process(function (): void {
            throw new RetryableException('502 Bad Gateway', 502);
        });

        $storedRetryAt = $this->popNextRetryAt($q);
        $delta = $storedRetryAt - $before;
        // baseDelay=3 × 2^1 = 6
        $a->true($delta >= 5 && $delta <= 8, "expected ~6s, got {$delta}s");
    }

    public function test_timeout_uses_exponential_backoff(TgAssert $a): void
    {
        $q = $this->freshQueue(baseDelay: 1);
        $q->push(['update_id' => 5]);

        $before = time();

        $q->process(function (): void {
            throw new RetryableException('Operation timed out', 28);
        });

        $storedRetryAt = $this->popNextRetryAt($q);
        $delta = $storedRetryAt - $before;
        // baseDelay=1 × 2^1 = 2
        $a->true($delta >= 1 && $delta <= 4, "expected ~2s, got {$delta}s");
    }

    public function test_retry_after_takes_precedence_over_exponential(TgAssert $a): void
    {
        // baseDelay=10 would give 20s exponential; retryAfter=3 should win.
        $q = $this->freshQueue(baseDelay: 10);
        $q->push(['update_id' => 6]);

        $before = time();

        $q->process(function (): void {
            throw new RetryableException('429', 429, null, 3);
        });

        $storedRetryAt = $this->popNextRetryAt($q);
        $delta = $storedRetryAt - $before;
        // Should be ~3s (retryAfter wins over 20s exponential).
        $a->true($delta >= 2 && $delta <= 5, "expected ~3s (retryAfter wins), got {$delta}s");
    }

    public function test_non_retryable_exception_uses_exponential(TgAssert $a): void
    {
        // A plain RuntimeException (not RetryableException) — no retryAfter.
        $q = $this->freshQueue(baseDelay: 2);
        $q->push(['update_id' => 7]);

        $before = time();

        $q->process(function (): void {
            throw new \RuntimeException('some transient error');
        });

        $storedRetryAt = $this->popNextRetryAt($q);
        $delta = $storedRetryAt - $before;
        $a->true($delta >= 3 && $delta <= 6, "expected exponential ~4s, got {$delta}s");
    }

    public function test_second_attempt_exponential_doubles(TgAssert $a): void
    {
        // Verify exponential backoff grows: attempt 1 → 2s, attempt 2 → 4s.
        $q = $this->freshQueue(baseDelay: 1);
        $q->push(['update_id' => 8]);

        // First failure (attempt 1 → delay = 1 × 2^1 = 2s).
        $before1 = time();
        $q->process(function (): void {
            throw new \RuntimeException('fail 1');
        });
        $storedRetryAt1 = $this->popNextRetryAt($q);
        $delta1 = $storedRetryAt1 - $before1;
        $a->true($delta1 >= 1 && $delta1 <= 3, "attempt 1 delay ~2s, got {$delta1}s");

        // Re-queue with the stored next_retry_at and force it to be "due"
        // by pushing an item with next_retry_at = 0, then fail again.
        // We use a fresh queue item to simulate the second attempt.
        $q->push(['update_id' => 9]);

        // Make it due immediately by manipulating through process with a
        // handler that fails. But we need attempts=1 already. Instead,
        // push a pre-seeded item with attempts=1.
        // Access driver to push a crafted item.
        $ref = new ReflectionClass($q);
        $driverProp = $ref->getProperty('driver');
        $driverProp->setAccessible(true);
        $driver = $driverProp->getValue($q);

        // Clear and push an item already at attempts=1, due now.
        $driver->clear();
        $driver->push([
            'update' => ['update_id' => 10],
            'attempts' => 1,
            'last_error' => 'previous',
            'next_retry_at' => 0,  // due now
        ]);

        $before2 = time();
        $q->process(function (): void {
            throw new \RuntimeException('fail 2');
        });

        $storedRetryAt2 = $this->popNextRetryAt($q);
        $delta2 = $storedRetryAt2 - $before2;
        // attempt 2 → delay = 1 × 2^2 = 4s
        $a->true($delta2 >= 3 && $delta2 <= 6, "attempt 2 delay ~4s, got {$delta2}s");
    }

    public function test_max_attempts_still_drops(TgAssert $a): void
    {
        // Even with retryAfter, max attempts is still enforced.
        $q = $this->freshQueue(maxAttempts: 1, baseDelay: 1);
        $q->push(['update_id' => 11]);

        $processed = $q->process(function (): void {
            throw new RetryableException('429', 429, null, 5);
        });

        // maxAttempts=1 → first failure drops the item.
        $a->equals(0, $q->count(), 'item should be dropped after max attempts');
    }
}
