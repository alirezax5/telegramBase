<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App;

use alirezax5\TelegramBase\App\Bootstrap\Bootstrap;
use alirezax5\TelegramBase\App\Bootstrap\PluginsBootstrap;
use alirezax5\TelegramBase\App\Bootstrap\QueueBootstrap;
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Update\Processor;
use alirezax5\TelegramBase\App\Update\Fetcher;
use alirezax5\TelegramBase\App\Update\PollingLoop;
use telegramBotApiPhp\Telegram;
use alirezax5\TelegramBase\App\Enum\CoreMode;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use alirezax5\TelegramBase\App\Queue\QueueWorker;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Shared\SharedManagement;
use alirezax5\TelegramBase\App\Language\Language;
use alirezax5\TelegramBase\App\Cron\CronManager;

class Core
{
    public ?Telegram $Telegram;
    private ?PluginHandler $pluginHandler = null;
    private CronManager $cronManager;

    public function __construct(CoreMode $mode = CoreMode::FULL, ?string $customToken = null)
    {
        Bootstrap::boot();

        $this->initBot($customToken);
        $this->cronManager = new CronManager();

        if ($mode === CoreMode::FULL) {
            PluginsBootstrap::boot();
            $this->pluginHandler = PluginsBootstrap::getPlugins();
        }
    }

    private function initBot(?string $customToken = null): void
    {
        if ($customToken !== null) {
            BotManager::getInstance()->setDefaultToken($customToken);
        }

        $this->Telegram = BotManager::getInstance()->get();
    }

    public function run(): void
    {
        try {

            switch (Config::bot()->mode) {

                case 'update_queue':
                case 'webhook_queue':
                    $this->queueWorker()?->startInfinite();
                    break;

                case 'webhook_direct':
                    $this->webhookDispatcher()->dispatch();
                    break;

                case 'cronjob_update':
                    $this->cronManager->run(
                        1,
                        fn() => $this->pollingLoop()->start()
                    );
                    break;

                case 'cronjob_queue':
                    $this->cronManager->run(
                        Config::cron()->cronWorker,
                        fn() => $this->queueWorker()?->runLimited(
                            Config::cron()->cronMaxTime
                        )
                    );
                    break;

                default:
                    $this->pollingLoop()->start();
            }

        } catch (\Throwable $e) {

            LogHandler::error(
                'Runtime error: ' . $e->getMessage()
            );
        }
    }

    /**
     * Run the update-fetch phase (polling or webhook) and push updates
     * into the configured queue.
     *
     * - webhook_queue mode: reads the raw webhook payload once and pushes it.
     * - other queue modes: long-polls Telegram for new updates and pushes
     *   each one, advancing the local offset only on successful push.
     */
    public function runFetchQueueUpdate(): void
    {
        if (Config::bot()->mode === 'webhook_queue') {

            $input = $this->Telegram->getInputData();

            if ($input) {
                QueueBootstrap::getQueue()?->push($input);
            }

            $this->cleanup();

            return;
        }

        $fetcher = new Fetcher($this->Telegram);
        while (true) {
            foreach ($fetcher->fetch() as $update) {

                if (QueueBootstrap::getQueue()?->push($update)) {

                    $fetcher->updateLastId(
                        $update->update_id + 1
                    );
                }
            }
            $this->cleanup();
        }


    }

    /**
     * Clean up per-iteration state (shared data + missing language keys).
     */
    private function cleanup(): void
    {
        SharedManagement::clear();
        Language::getInstance()->flushMissingKeys();
    }

    private function processor(): Processor
    {
        return new Processor(
            $this->pluginHandler,
            $this->Telegram
        );
    }

    private function webhookDispatcher(): WebhookDispatcher
    {
        return new WebhookDispatcher($this->processor());
    }

    private function pollingLoop(): PollingLoop
    {
        return new PollingLoop(
            new Fetcher($this->Telegram),
            $this->processor()
        );
    }

    private function queueWorker(): QueueWorker
    {
        return new QueueWorker(
            QueueBootstrap::getQueue(),
            $this->pluginHandler,
            $this->Telegram
        );
    }
}