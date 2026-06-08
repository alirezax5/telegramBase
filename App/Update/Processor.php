<?php

namespace alirezax5\TelegramBase\App\Update;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Plugin\PluginHandler;
use telegramBotApiPhp\Telegram;

class Processor
{
    private ?PluginHandler $pluginHandler;
    private Telegram $Telegram;

    public function __construct(?PluginHandler $pluginHandler, Telegram $Telegram)
    {
        $this->pluginHandler = $pluginHandler;
        $this->Telegram = $Telegram;
    }

    /*
     * اجرای چند آپدیت
     *  موقع اجرا حالت updates اجرا میشه
     */
    public function handleBatch( $updates, ?callable $afterEach = null): void
    {
        foreach ($updates as $update) {

            $this->handle($update);

            if ($afterEach !== null) {
                $afterEach($update);
            }
        }
    }

    /*
     * اجرا و ست اپدیت ها
     */
    public function handle( $update): void
    {
        if (empty($update)) {
            return;
        }

        try {

            $this->Telegram->setInputData($update);

            $this->runPlugins($update);

        } catch (\Throwable $e) {
            LogHandler::error('Update failed', [
                'message'     => $e->getMessage(),
                'code'        => $e->getCode(),
                'file'        => $e->getFile(),
                'line'        => $e->getLine(),

                'update_id'   => $update->update_id ?? null,
            ]);
        }
    }

    /*
     * اجرای پلاگین ها و پاس دادن اپدیت ها به آنها
     */
    private function runPlugins( $update): void
    {
        if ($this->pluginHandler === null) {
            return;
        }

        $start = 0;

        $debug = Config::bot()->appDebug;

        if ($debug) {
            $start = microtime(true);
        }

        $this->pluginHandler->runAll($update, $this->Telegram);

        if ($debug) {
            $duration = (microtime(true) - $start) * 1000;

            if ($duration > 300) {
                LogHandler::warning('⚠️ Slow update detected: ' . round($duration, 2) . 'ms', [
                    'update_id' => $update->update_id ?? null
                ]);
            }
        }
    }

    /*
     *  ا جرای وبهوک
     */
    public function handleWebhook(): void
    {
        $input = $this->Telegram->getInputData();

        if (empty($input)) {
            LogHandler::warning('⚠️ Empty webhook payload');
            return;
        }

        $this->handle($input);
    }
}
