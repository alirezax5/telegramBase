<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Shared\SharedManagement;

class SharedManagementTest extends TgBaseTestCase
{
    public function setUp(): void
    {
        SharedManagement::flush();
    }

    public function test_set_get_roundtrip(TgAssert $a): void
    {
        SharedManagement::set('k1', 'v1');
        $a->equals('v1', SharedManagement::get('k1'));
    }

    public function test_get_returns_default(TgAssert $a): void
    {
        $a->equals('def', SharedManagement::get('nope', 'def'));
    }

    public function test_has(TgAssert $a): void
    {
        SharedManagement::set('exists', 1);
        $a->true(SharedManagement::has('exists'));
        $a->false(SharedManagement::has('missing'));
    }

    public function test_remove_respects_protected(TgAssert $a): void
    {
        SharedManagement::set('protected', 'keep', true);
        SharedManagement::set('normal', 'remove');

        SharedManagement::remove('protected'); // should NOT remove
        SharedManagement::remove('normal');    // should remove

        $a->true(SharedManagement::has('protected'));
        $a->false(SharedManagement::has('normal'));
    }

    public function test_remove_force_overrides_protected(TgAssert $a): void
    {
        SharedManagement::set('protected', 'keep', true);
        SharedManagement::remove('protected', true);
        $a->false(SharedManagement::has('protected'));
    }

    public function test_clear_keeps_protected(TgAssert $a): void
    {
        SharedManagement::set('protected', 'keep', true);
        SharedManagement::set('normal', 'remove');

        SharedManagement::clear();

        $a->true(SharedManagement::has('protected'));
        $a->false(SharedManagement::has('normal'));
    }

    public function test_flush_clears_everything(TgAssert $a): void
    {
        SharedManagement::set('protected', 'keep', true);
        SharedManagement::set('normal', 'remove');

        SharedManagement::flush();

        $a->false(SharedManagement::has('protected'));
        $a->false(SharedManagement::has('normal'));
    }

    public function test_all_returns_data(TgAssert $a): void
    {
        SharedManagement::set('k', 'v');
        $all = SharedManagement::all();
        $a->true(isset($all['k']));
        $a->equals('v', $all['k']);
    }
}
