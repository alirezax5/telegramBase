<?php
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

use alirezax5\TelegramBase\App\Core;
use alirezax5\TelegramBase\App\Enum\CoreMode;

include './vendor/autoload.php';
$core = new Core(CoreMode::UPDATES_ONLY);
$core->runFetchQueueUpdate();