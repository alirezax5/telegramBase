<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class ChatType
{
    public function __construct(
        public array $types
    ) {
    }
}