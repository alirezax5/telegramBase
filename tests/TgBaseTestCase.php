<?php

declare(strict_types=1);

/**
 * Base class for all TelegramBase test cases.
 *
 * Subclasses get a TgAssert instance via constructor injection and
 * define test_* methods. The runner in tests/run.php discovers them.
 */
abstract class TgBaseTestCase
{
    public function __construct(public readonly TgAssert $assert)
    {
    }
}
