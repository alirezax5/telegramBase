<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Enum;

/**
 * Core startup mode.
 *
 * - FULL: boot bot + plugins + processor.
 * - UPDATES_ONLY: skip plugin bootstrap, only run the fetch-queue phase
 *   (used by the standalone queue.php entry point).
 */
enum CoreMode: string
{
    case FULL = 'full';
    case UPDATES_ONLY = 'updates_only';
}
