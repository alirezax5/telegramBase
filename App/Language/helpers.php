<?php

use alirezax5\TelegramBase\App\Language\Language;

if (!function_exists('__')) {

    function __($key, $default = null, $replacements = [])
    {

        return Language::getInstance()->get($key, $default, $replacements);
    }
}