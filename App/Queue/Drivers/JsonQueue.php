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
    public function pop(): ?array
    {
        $file = $this->getOldestFile();

        if (!$file) {
            return null;
        }

        $fp = fopen($file, 'c+');
        if (!$fp) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return null;
            }

            $content = stream_get_contents($fp);
            $data = json_decode($content, true);

            // mark as consumed (atomic safe)
            ftruncate($fp, 0);
            fflush($fp);

            flock($fp, LOCK_UN);
            fclose($fp);

            @unlink($file);


            return is_array($data) ? $data : null;

        } catch (\Throwable $e) {
            LogHandler::error(
                'Queue worker error: ' . $e->getMessage(),
                [
                    'message'     => $e->getMessage(),
                    'code'        => $e->getCode(),
                    'file'        => $e->getFile(),
                    'line'        => $e->getLine(),

                    'update_id'   => $update->update_id ?? null,
                ]
            );
            fclose($fp);

            return null;
        }
    }

    function toObject(mixed $data): object
    {
        if (is_object($data)) {
            return $data;
        }

        if (!is_array($data)) {
            return (object)[];
        }

        // روش قوی با json (ساده و سریع)
        return json_decode(json_encode($data));
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

        // microtime در ابتدای نام فایل قرار دارد، پس مرتب‌سازی رشته‌ای
        // همان ترتیب زمانی را می‌دهد و از N فراخوانی filemtime جلوگیری می‌کند.
        sort($files);

        return $files[0];
    }

    /**
     * لیست فایل‌های json صف را برمی‌گرداند (scandir از glob سریع‌تر است).
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
     * COUNT optimized (cheap fallback)
     */
    public function count(): int
    {
        return count($this->scanJsonFiles());
    }

    /**
     * CONNECTION check (real safe version)
     */
    public function isConnected(): bool
    {
        return is_dir($this->path) && is_writable($this->path);
    }
}