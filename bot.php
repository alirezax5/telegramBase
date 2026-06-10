<?php
ini_set('error_log',   '/logs/php_errors.log');

use alirezax5\TelegramBase\App\Core;
include './vendor/autoload.php';
$core = new Core();
$core->run();
