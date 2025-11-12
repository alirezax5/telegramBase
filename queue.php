<?php

use alirezax5\TelegramBase\App\Core;

include './vendor/autoload.php';


echo '<pre>';
$core = new Core(true);
$core->runFetchQueueUpdate();
