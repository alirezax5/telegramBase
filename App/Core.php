<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App;

use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Environment\EnvironmentValidator;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Queue\QueueManager;
use alirezax5\TelegramBase\App\Shared\SharedManagement;
use telegramBotApiPhp\telegram;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Core
{
    private Telegram $telegram;
    private  $updates = [];
    private EnvironmentValidator $envValidator;
    private string $appDataFolder;
    private string $lastUpdateFile;
    private string $queueJsonDir;
    private ?QueueManager $queueManager = null;
    private ?PluginHandler $pluginHandler = null;
    private Filesystem $filesystem;

    public function __construct($justUpdates = false)
    {
        LogHandler::info('Bot initializing...');
        $this->filesystem = new Filesystem();

        $this->setupPaths();
        $this->ensureAppData();

        $this->envValidator = new EnvironmentValidator();
        $this->envValidator->validate();
        LogHandler::info('Environment validated successfully');

        $this->telegram = new Telegram(
            EnvHandler::get('BOT_TOKEN'),
            EnvHandler::get('BOT_API_URL'),
            EnvHandler::get('BOT_API_URL_FILE')
        );

        $this->queueManager = new QueueManager([
            'type' => strtolower(EnvHandler::get('QUEUE_SAVE_TYPE', 'json')),
            'path' => $this->queueJsonDir,
            'redis' => [
                'host' => EnvHandler::get('REDIS_HOST', '127.0.0.1'),
                'port' => (int)EnvHandler::get('REDIS_PORT', 6379),
                'password' => EnvHandler::get('REDIS_PASSWORD', ''),
                'key' => EnvHandler::get('QUEUE_REDIS_KEY', 'bot_updates')
            ],
            'rabbitmq' => [
                'host' => EnvHandler::get('RABBITMQ_HOST', '127.0.0.1'),
                'port' => (int)EnvHandler::get('RABBITMQ_PORT', 5672),
                'user' => EnvHandler::get('RABBITMQ_USER', 'guest'),
                'password' => EnvHandler::get('RABBITMQ_PASSWORD', 'guest'),
                'queue' => EnvHandler::get('RABBITMQ_QUEUE', 'bot_updates')
            ],

        ]);

        LogHandler::info('Bot Type: ' . (EnvHandler::get('BOT_MODE') ?? 'unknown'));
        if ($justUpdates)
            return;

        $pluginsPath = Path::join(APP_BASE_PATH, EnvHandler::get('PLUGINS_DIR', 'plugins'));
        $this->pluginHandler = new PluginHandler($pluginsPath, (int)(EnvHandler::get('PLUGINS_RELOAD_INTERVAL', '60')));

        if ($this->getBotMode() === 'update') {
            $this->handleUpdates();
        }
    }

    // ------------------------------------------
    // مسیرها و آماده‌سازی پوشه‌ها
    // ------------------------------------------
    private function setupPaths(): void
    {
        $this->appDataFolder = Path::join(APP_BASE_PATH, EnvHandler::get('DATA_DIR', 'AppData'));
        $this->lastUpdateFile = Path::join($this->appDataFolder, EnvHandler::get('POLLING_STATE_FILE', 'lastupdate.txt'));
        $this->queueJsonDir = Path::join($this->appDataFolder, 'updates');
    }

    private function ensureAppData(): void
    {
        foreach ([$this->appDataFolder, $this->queueJsonDir] as $dir) {
            if (!$this->filesystem->exists($dir)) {
                $this->filesystem->mkdir($dir, 0777);
                LogHandler::info("Created folder: {$dir}");
            }
        }

        if (!$this->filesystem->exists($this->lastUpdateFile)) {
            $this->filesystem->dumpFile($this->lastUpdateFile, '');
            LogHandler::info("Created file: {$this->lastUpdateFile}");
        }
    }

    // ------------------------------------------
    // حالت ربات
    // ------------------------------------------
    private function getBotMode(): string
    {
        return EnvHandler::get('BOT_MODE', 'webhook');
    }

    private function getUpdateMode(): string
    {
        return EnvHandler::get('UPDATE_MODE', 'normal');
    }

    // ------------------------------------------
    // دریافت آپدیت‌ها
    // ------------------------------------------
    private function handleUpdates(): void
    {
        $offset = (int)$this->getLastUpdateValue();
        $limit = (int)(EnvHandler::get('POLLING_LIMIT', 50));
        $allowedUpdates = $this->getAllowedUpdates();

        $updatesResponse = $this->telegram->getUpdates($offset, $limit, 30, $allowedUpdates);

        if (($updatesResponse?->ok ?? false) && !empty($updatesResponse?->result)) {
            $this->updates = $updatesResponse->result;
            LogHandler::info("Fetched " . count($this->updates) . " updates successfully");
        }
    }

    private function getAllowedUpdates(): ?array
    {
        $allowed = EnvHandler::get('ALLOWED_UPDATES', 'all');
        return $allowed === 'all' ? null : array_map('trim', explode(',', $allowed));
    }

    // ------------------------------------------
    // فایل آخرین آپدیتش
    // ------------------------------------------
    private function updateLastUpdateFile(string|int $value): bool
    {
        $this->filesystem->dumpFile($this->lastUpdateFile, (string)$value);
        return true;
    }

    private function getLastUpdateValue(): string
    {
        if (!$this->filesystem->exists($this->lastUpdateFile)) {
            return '0';
        }

        return trim((string)file_get_contents($this->lastUpdateFile));
    }

    // ------------------------------------------
    // مدیریت Worker و Queue
    // ------------------------------------------
    private function startWorkerLoop(int $workerId): void
    {
        $queue = $this->queueManager;

        while (true) {
            if (!$queue->getDriver()->isConnected()) {
                LogHandler::warning("Worker #{$workerId}: Queue connection lost. Retrying...");
                sleep(1);
                continue;
            }

            $update = $queue->pop();

            if ($update) {
                $this->telegram->setInputData($update);
                $this->pluginHandler->runAll($update, $this->telegram);
            } else {
                usleep(100000);
            }
        }
    }


    // ------------------------------------------
    // ذخیره در Queue
    // ------------------------------------------
    private function saveToQueue(array $update): void
    {
        $queue = $this->queueManager;
        if (!$queue->getDriver()->isConnected()) {
            LogHandler::error("Queue connection lost. Cannot save update.");
            return;
        }

        $queue->push($update);
        LogHandler::info("Queued update successfully via ");
    }

    // ------------------------------------------
    // اجرای Worker
    // ------------------------------------------
    private function runQueueProcessor(): void
    {


        LogHandler::info("Queue Processor started");

        $this->startWorkerLoop(0);

    }

    // ------------------------------------------
    // اجرای نهایی
    // ------------------------------------------
    public function runFetchQueueUpdate(): void
    {
        if ($this->getBotMode() === 'webhook') {
            $this->saveToQueue($this->telegram->getInputData());
        } else {
            foreach ($this->updates as $update) {
                $this->saveToQueue($update->toArray());
//                $this->pluginHandler->runAll($update->toArray(), $this->telegram);
                $this->updateLastUpdateFile($update->update_id + 1);
            }
        }
    }

    public function run(): void
    {
        $updateMode = $this->getUpdateMode();

        if ($updateMode === 'normal') {
            if ($this->getBotMode() === 'update') {
                $this->runUpdateMode();
            } else {
                $this->runWebhookMode();
            }
        } elseif ($updateMode === 'queue') {
            $this->runQueueProcessor();
        } else {
            LogHandler::warning("Unknown update mode: {$updateMode}");
        }

        SharedManagement::clear();
        LogHandler::info('Execution completed and shared memory cleared.');
    }


    private function runUpdateMode(): void
    {
        foreach ($this->updates as $update) {
            $this->telegram->setInputData($update->toArray());
            $this->pluginHandler->runAll($update->toArray(), $this->telegram);
            $this->updateLastUpdateFile($update->update_id + 1);
        }
    }

    private function runWebhookMode(): void
    {
        $this->pluginHandler->runAll($this->telegram->getInputData(), $this->telegram);
    }
}
