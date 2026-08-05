<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Plugin\Contract;

interface PluginInterface
{
    public function getPriority(): int;

}
