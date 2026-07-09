<?php

namespace alirezax5\TelegramBase\App\Storage;

use alirezax5\TelegramBase\App\Paths;

class OffsetStorage
{
    private int $cached = 0;
    private bool $loaded = false;
    private string $filePath;

    public function __construct()
    {
        $this->filePath = Paths::lastUpdateFile();
        if (!is_file($this->filePath)) {
            file_put_contents($this->filePath, '0');
        }
    }

    public function get(): int
    {
        if ($this->loaded) {
            return $this->cached;
        }

        $this->loaded = true;

        $this->cached = is_file($this->filePath)
            ? (int)trim(file_get_contents($this->filePath) ?: '0')
            : 0;

        return $this->cached;
    }

    public function set(int $offset): void
    {
        if ($offset === $this->cached) {
            return;
        }

        $this->cached = $offset;

        file_put_contents(
            $this->filePath,
            (string)$offset,
            LOCK_EX
        );
    }
}