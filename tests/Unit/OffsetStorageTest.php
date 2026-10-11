<?php

declare(strict_types=1);

use alirezax5\TelegramBase\App\Storage\OffsetStorage;
use alirezax5\TelegramBase\App\Paths;
use alirezax5\TelegramBase\App\Environment\EnvHandler;

class OffsetStorageTest extends TgBaseTestCase
{
    private string $tempFile = '';
    private string $absDataDir = '';

    private function makeStorageWithTempFile(): OffsetStorage
    {
        // Use a relative DATA_DIR under the repo so Paths::dataDirectory()
        // (which joins basePath + DATA_DIR) resolves inside the project.
        $relDataDir = 'tests/_tmp_offset_' . uniqid();
        $this->absDataDir = dirname(__DIR__, 2) . '/' . $relDataDir;
        @mkdir($this->absDataDir, 0755, true);
        $this->tempFile = $this->absDataDir . '/lastupdate.txt';

        putenv("DATA_DIR={$relDataDir}");
        $_ENV['DATA_DIR'] = $relDataDir;
        putenv('POLLING_STATE_FILE=lastupdate.txt');
        $_ENV['POLLING_STATE_FILE'] = 'lastupdate.txt';

        // EnvHandler caches values per-request. Clear it so the new
        // DATA_DIR is picked up by Paths::dataDirectory().
        EnvHandler::clearCache();
        Paths::initialize(dirname(__DIR__, 2));

        return new OffsetStorage();
    }

    public function tearDown(): void
    {
        if ($this->tempFile !== '' && is_file($this->tempFile)) {
            @unlink($this->tempFile);
        }
        if ($this->absDataDir !== '' && is_dir($this->absDataDir)) {
            @rmdir($this->absDataDir);
        }
        // Restore defaults so subsequent tests get a sane environment.
        putenv('DATA_DIR=' . sys_get_temp_dir() . '/tgbase_testdata_' . getmypid());
        $_ENV['DATA_DIR'] = sys_get_temp_dir() . '/tgbase_testdata_' . getmypid();
        EnvHandler::clearCache();
        Paths::initialize(dirname(__DIR__, 2));
    }

    public function test_initial_offset_is_zero(TgAssert $a): void
    {
        $storage = $this->makeStorageWithTempFile();
        $a->equals(0, $storage->get());
    }

    public function test_set_then_get_roundtrip(TgAssert $a): void
    {
        $storage = $this->makeStorageWithTempFile();

        $storage->set(12345);
        $a->equals(12345, $storage->get());

        // New instance, same file → should read back the persisted value.
        $storage2 = new OffsetStorage();
        $a->equals(12345, $storage2->get());
    }

    public function test_set_same_value_is_noop(TgAssert $a): void
    {
        $storage = $this->makeStorageWithTempFile();
        $storage->set(100);
        $storage->set(100); // No file write should happen — internal cache short-circuits.

        $a->equals(100, $storage->get());
    }

    public function test_persistence_to_disk(TgAssert $a): void
    {
        $storage = $this->makeStorageWithTempFile();
        $storage->set(99);

        // Read the file directly to verify the value was persisted.
        $contents = file_get_contents($this->tempFile);
        $a->equals('99', $contents);
    }
}
