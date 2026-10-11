<?php

namespace alirezax5\TelegramBase\App\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Role
{
    public function __construct(
        public array $roles
    )
    {
    }
}