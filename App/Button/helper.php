<?php

use alirezax5\TelegramBase\App\Button\ButtonManager;

if (!function_exists('btn')) {
    /**
     * Get a button definition from ButtonManager.
     *
     * Example:
     *   btn("profile", ["ID" => 12])
     *
     * @param string $name    Button name defined in btn.php
     * @param array  $replace Placeholder replacement values e.g. ["ID" => 12]
     * @return array|null
     */
    function btn(string $name, array $replace = []): ?array
    {
        return ButtonManager::get($name, $replace);
    }
}