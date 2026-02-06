<?php

namespace alirezax5\TelegramBase\App\Queue\Drivers;

use alirezax5\TelegramBase\App\Queue\QueueInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class JsonQueue implements QueueInterface
{
    private Filesystem $filesystem;
    protected string $path;

    public function __construct(string $path)
    {
        $this->filesystem = new Filesystem();
        $this->path = Path::canonicalize(rtrim($path, '/'));

        if (!$this->filesystem->exists($this->path)) {
            $this->filesystem->mkdir($this->path, 0777);
        }
    }

    public function push(array $update): bool
    {
        $filename = uniqid('', true) . '.json';
        $file = Path::join($this->path, $filename);

        try {
            $json = json_encode(
                $update,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            error_log(sprintf('JsonQueue push failed to encode payload: %s', $exception->getMessage()));
            return false;
        }

        $fp = fopen($file, 'c');
        if (!$fp) return false;

        $written = false;
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            $bytes = fwrite($fp, $json);
            $written = $bytes !== false && $bytes === strlen($json);
            if ($written) {
                fflush($fp);
            }
            flock($fp, LOCK_UN);
        }

        fclose($fp);
        if (!$written) {
            $this->filesystem->remove($file);
            return false;
        }

        return true;
    }

    public function pop(): ?array
    {
        $files = glob(Path::join($this->path, '*.json'));

        if (!$files) {
            return null;
        }

        sort($files);
        $file = $files[0];

        $fp = fopen($file, 'r+');
        if (!$fp) return null;

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return null;
        }

        $content = stream_get_contents($fp);
        $data = json_decode($content, true);

        ftruncate($fp, 0); // خالی کردن فایل
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // بعد از unlock، فایل حذف می‌شود
        $this->filesystem->remove($file);

        return $data;
    }

    public function count(): int
    {
        $files = glob(Path::join($this->path, '*.json'));
        return $files ? count($files) : 0;
    }

    public function isConnected(): bool
    {
        return $this->filesystem->exists($this->path) && is_writable($this->path);
    }
}
