<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Queue\Drivers;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Queue\QueueInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class JsonQueue implements QueueInterface
{
    private Filesystem $fs;
    private string $path;

    public function __construct(string $path)
    {
        $this->fs = new Filesystem();
        $this->path = Path::canonicalize(rtrim($path, '/'));

        if (!is_dir($this->path)) {
            $this->fs->mkdir($this->path, 0777);
        }
    }

    /**
     * PUSH (fast append-style file creation)
     */
    public function push($update): bool
    {
        try {
            $file = $this->path . '/' . microtime(true) . '_' . bin2hex(random_bytes(4)) . '.json';

            return file_put_contents(
                    $file,
                    json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                ) !== false;

        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * POP (optimized - avoids glob+sort each time)
     */
    public function pop(int $timeout = 0): ?array
    {
        $file = $this->getOldestFile();

        if (!$file) {
            return null;
        }

        $fp = fopen($file, 'c+');
        if (!$fp) {
            return null;
        }

        $closed = false;

        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return null;
            }

            $content = stream_get_contents($fp);
            $data = json_decode($content, true);

            ftruncate($fp, 0);
            fflush($fp);

            flock($fp, LOCK_UN);
            fclose($fp);
            $closed = true;

            @unlink($file);

            return is_array($data) ? $data : null;

        } catch (\Throwable $e) {
            LogHandler::error(
                'JsonQueue pop error: ' . $e->getMessage(),
                [
                    'message' => $e->getMessage(),
                    'file'   => $e->getFile(),
                    'line'   => $e->getLine(),
                ]
            );
            if (!$closed) {
                fclose($fp);
            }

            return null;
        }
    }

    /**
     * Faster file selection (NO glob + NO filemtime per file)
     */
    private function getOldestFile(): ?string
    {
        $files = $this->scanJsonFiles();

        if (!$files) {
            return null;
        }

        sort($files);

        return $files[0];
    }

    /**
     * scandir-based scan (faster than glob)
     */
    private function scanJsonFiles(): array
    {
        $entries = @scandir($this->path);

        if ($entries === false) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if ($entry[0] !== '.' && str_ends_with($entry, '.json')) {
                $files[] = $this->path . '/' . $entry;
            }
        }

        return $files;
    }

    /**
     * COUNT optimized
     */
    public function count(): int
    {
        return count($this->scanJsonFiles());
    }

    /**
     * CLEAR - delete all queue files
     */
    public function clear(): int
    {
        $files = $this->scanJsonFiles();
        $count = 0;

        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * CONNECTION check
     */
    public function isConnected(): bool
    {
        return is_dir($this->path) && is_writable($this->path);
    }
}
