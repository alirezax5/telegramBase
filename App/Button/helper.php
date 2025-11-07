<?php


use alirezax5\TelegramBase\App\Button\ButtonManager;

if (!function_exists('btn')) {

    /**
     * گرفتن دکمه از ButtonManager
     *
     * مثال:
     *   btn("profile", ["ID" => 12])
     *
     * @param string $name نام دکمه در btn.php
     * @param array $replace مقدار جایگزینی placeholder ها مثلا ["ID" => 12]
     * @return array|null
     */
    function btn(string $name, array $replace = []): ?array
    {
        return ButtonManager::get($name, $replace);
    }
}
