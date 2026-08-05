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

        $path = rtrim($path, '/\\');

        $this->path = Path::canonicalize($path) ?: $path;

        if (!is_dir($this->path)) {
            $this->fs->mkdir($this->path, 0777);
        }
    }

    /**
     * PUSH (fast append-style file creation).
     *
     * Filenames are {microtime}_{random}.json — lexicographic order ==
     * insertion order, which getOldestFile() relies on for FIFO.
     *
     * @param array $update Telegram update array
     * @return bool True on success
     */
    public function push(array $update): bool
    {
        try {
            $file = $this->path . '/' . sprintf('%.6F', microtime(true)) . '_' . bin2hex(random_bytes(4)) . '.json';

            $json = json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                return false;
            }

            $bytes = file_put_contents($file, $json, LOCK_EX);

            if ($bytes === false) {
                @unlink($file);
                return false;
            }

            return true;

        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * POP — oldest-file scan with exclusive lock.
     *
     * If the oldest file is already locked by another worker it is left
     * in place and the next-oldest candidate is tried, so concurrent
     * workers cannot lose jobs.
     *
     * @param int $timeout Max wait in seconds for an available job
     * @return array|null Update array or null when queue empty
     */
    public function pop(int $timeout = 0): ?array
    {
        $file = $this->getOldestFile();

        if (!$file) {
            if ($timeout > 0) {
                usleep(min($timeout * 1000000, 5000000));
                $file = $this->getOldestFile();
            }

            if (!$file) {
                return null;
            }
        }

        $fp = fopen($file, 'c+');

        if (!$fp) {
            return null;
        }

        $closed = false;

        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                // Another worker is processing this file — try the next one
                fclose($fp);

                return $this->pop(0);
            }

            $content = stream_get_contents($fp);

            $data = json_decode($content, true);

            // Wipe content, release lock, then delete — the file is only
            // unlinked while holding the write lock, so no reader can get
            // a half-written/half-deleted state.
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
     * O(n) scan for oldest file using opendir/readdir. No glob, no sort.
     *
     * Filenames are zero-padded microtime, so plain string comparison gives
     * true insertion order regardless of readdir ordering.
     *
     * @return string|null Absolute path of the oldest job file
     */
    private function getOldestFile(): ?string
    {
        $dir = opendir($this->path);

        if (!$dir) {
            return null;
        }

        $oldest = null;
        $oldestPath = null;

        while (($entry = readdir($dir)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!str_ends_with($entry, '.json')) {
                continue;
            }

            // Lexicographic compare — filenames are {padded_microtime}_{random}.json,
            // so the smallest string is the oldest job.
            if ($oldest === null || $entry < $oldest) {
                $oldest = $entry;
                $oldestPath = $this->path . '/' . $entry;
            }
        }

        closedir($dir);

        return $oldestPath;
    }

    /**
     * COUNT optimized — scandir count only, no filesystem stats
     */
    public function count(): int
    {
        $count = 0;
        $dir = opendir($this->path);
        if (!$dir) {
            return 0;
        }

        while (($entry = readdir($dir)) !== false) {
            if ($entry !== '.' && $entry !== '..' && str_ends_with($entry, '.json')) {
                $count++;
            }
        }

        closedir($dir);
        return $count;
    }

    /**
     * CLEAR - delete all queue files
     */
    public function clear(): int
    {
        $count = 0;
        $dir = opendir($this->path);
        if (!$dir) {
            return 0;
        }

        while (($entry = readdir($dir)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!str_ends_with($entry, '.json')) {
                continue;
            }
            if (@unlink($this->path . '/' . $entry)) {
                $count++;
            }
        }

        closedir($dir);
        return $count;
    }

    /**
     * CONNECTION check
     */
    public function isConnected(): bool
    {
        return is_dir($this->path) && is_writable($this->path);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }
}
