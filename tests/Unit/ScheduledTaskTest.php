<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Scheduler\ScheduledTask;

class ScheduledTaskTest extends TgBaseTestCase
{
    public function test_wildcard_expression_matches_any_time(TgAssert $a): void
    {
        $task = new ScheduledTask('* * * * *', fn() => null, 'wildcard');
        $a->true($task->isDue(time()));
    }

    public function test_specific_minute_matches(TgAssert $a): void
    {
        // Find a timestamp whose minute is exactly 30
        $now = strtotime('12:30:00');
        $task = new ScheduledTask('30 * * * *', fn() => null, 'min30');
        $a->true($task->isDue($now));
    }

    public function test_specific_minute_does_not_match(TgAssert $a): void
    {
        $now = strtotime('12:31:00');
        $task = new ScheduledTask('30 * * * *', fn() => null, 'min30');
        $a->false($task->isDue($now));
    }

    public function test_every_5_minutes_step(TgAssert $a): void
    {
        $task = new ScheduledTask('*/5 * * * *', fn() => null, 'every5');

        // 12:00, 12:05, 12:10 should match; 12:03, 12:07 should not.
        $a->true($task->isDue(strtotime('12:00:00')));
        $a->true($task->isDue(strtotime('12:05:00')));
        $a->true($task->isDue(strtotime('12:10:00')));
        $a->false($task->isDue(strtotime('12:03:00')));
        $a->false($task->isDue(strtotime('12:07:00')));
    }

    public function test_comma_list(TgAssert $a): void
    {
        $task = new ScheduledTask('0,15,30,45 * * * *', fn() => null, 'quarters');
        $a->true($task->isDue(strtotime('12:00:00')));
        $a->true($task->isDue(strtotime('12:15:00')));
        $a->true($task->isDue(strtotime('12:30:00')));
        $a->true($task->isDue(strtotime('12:45:00')));
        $a->false($task->isDue(strtotime('12:20:00')));
    }

    public function test_range(TgAssert $a): void
    {
        $task = new ScheduledTask('0-5 * * * *', fn() => null, 'first6min');
        $a->true($task->isDue(strtotime('12:00:00')));
        $a->true($task->isDue(strtotime('12:05:00')));
        $a->false($task->isDue(strtotime('12:06:00')));
    }

    public function test_invalid_expression_never_matches(TgAssert $a): void
    {
        $task = new ScheduledTask('not a cron', fn() => null, 'bogus');
        $a->false($task->isDue(time()));
    }

    public function test_run_invokes_callback(TgAssert $a): void
    {
        $called = false;
        $task = new ScheduledTask('* * * * *', function () use (&$called) {
            $called = true;
        }, 'invoke');
        $task->run();
        $a->true($called);
    }

    public function test_default_name_is_random(TgAssert $a): void
    {
        $task1 = new ScheduledTask('* * * * *', fn() => null);
        $task2 = new ScheduledTask('* * * * *', fn() => null);
        $a->true($task1->name() !== $task2->name(), 'default names must be unique');
    }

    public function test_named_task_returns_name(TgAssert $a): void
    {
        $task = new ScheduledTask('* * * * *', fn() => null, 'my_task');
        $a->equals('my_task', $task->name());
    }
}
