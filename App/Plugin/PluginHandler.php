<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Plugin;

use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Plugin\PluginBase;
use ReflectionClass;
use telegramBotApiPhp\Telegram;

class PluginHandler
{
    private const UPDATE_TYPES = [
        'message', 'edited_message', 'channel_post', 'edited_channel_post',
        'business_connection', 'business_message', 'edited_business_message',
        'deleted_business_messages', 'message_reaction', 'message_reaction_count',
        'inline_query', 'chosen_inline_result', 'callback_query', 'shipping_query',
        'pre_checkout_query', 'purchased_paid_media', 'poll', 'poll_answer',
        'my_chat_member', 'chat_member', 'chat_join_request', 'chat_boost', 'removed_chat_boost',
    ];

    /** @var PluginBase[] */
    private array $plugins = [];
    private ?string $pluginsDir = null;
    private int $lastReloadTime = 0;
    private int $reloadInterval;

    public function __construct(string $pluginsPath, int $reloadInterval = 60)
    {
        $this->reloadInterval = $reloadInterval;

        if (!is_dir($pluginsPath)) {
            LogHandler::error("❌ Plugins directory does not exist: {$pluginsPath}");
        } else {
            $this->pluginsDir = realpath($pluginsPath);
            LogHandler::info("📁 Plugins directory set: {$this->pluginsDir}");
        }

        $this->loadPlugins();
    }

    public function loadPlugins(): void
    {
        if ($this->pluginsDir === null) {
            LogHandler::warning("⚠️ Plugin directory not set, skipping plugin loading.");
            return;
        }

        LogHandler::info("🔄 Scanning plugin directory: {$this->pluginsDir}");

        $pluginFiles = glob($this->pluginsDir . '/*.php') ?: [];
        $this->plugins = [];

        if (empty($pluginFiles)) {
            LogHandler::warning("⚠️ No plugin files found in directory.");
        }

        foreach ($pluginFiles as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $fullClassName = "\\alirezax5\\TelegramBase\\Plugin\\{$className}";

            require_once $file;

            if (!class_exists($fullClassName)) {
                LogHandler::warning("⚠️ Plugin class not found: {$fullClassName}", ['file' => $file]);
                continue;
            }

            $reflection = new ReflectionClass($fullClassName);

            if (!$reflection->isInstantiable() || !$reflection->isSubclassOf(PluginBase::class)) {
                LogHandler::warning("⛔ Invalid plugin (must extend PluginBase): {$fullClassName}");
                continue;
            }

            $plugin = $reflection->newInstance();
            $this->plugins[] = $plugin;

            LogHandler::info("✅ Plugin loaded: {$fullClassName}");
        }

        usort($this->plugins, fn(PluginBase $a, PluginBase $b) => $a->getPriority() <=> $b->getPriority());

        LogHandler::info("✅ Total plugins loaded: " . count($this->plugins));
        $this->lastReloadTime = time();
    }

    public function runAll(array $update, Telegram $telegram): void
    {
        if (time() - $this->lastReloadTime > $this->reloadInterval) {
            LogHandler::info("🔁 Reloading plugins after {$this->reloadInterval}s...");
            $this->loadPlugins();
        }

        $updateType = $this->detectUpdateType($update);
        if ($updateType === null) {
            LogHandler::debug("⚪ Update type not recognized, skipping.");
            return;
        }

        LogHandler::info("📥 Update received: {$updateType}");

        $handlerMethod = $this->buildHandlerMethod($updateType);
        LogHandler::debug("🔧 Handler resolved: {$handlerMethod}");

        $updateData = $this->convertToObject($update[$updateType]);
        LogHandler::debug("📦 Update data converted to object");

        foreach ($this->plugins as $plugin) {
            $pluginClass = get_class($plugin);
            LogHandler::info("🚀 Running plugin: " . $pluginClass);

            $this->executePluginMethod($plugin, 'before', $updateData, $telegram);
            $this->executePluginMethod($plugin, $handlerMethod, $updateData, $telegram);
            $this->executePluginMethod($plugin, 'after', $updateData, $telegram);

            LogHandler::info("✅ Completed plugin: " . $pluginClass);
        }
    }

    private function detectUpdateType(array $update): ?string
    {
        foreach (self::UPDATE_TYPES as $type) {
            if (isset($update[$type])) {
                LogHandler::debug("🔍 Detected update type: {$type}");
                return $type;
            }
        }

        LogHandler::debug("❓ No matching update type detected.");
        return null;
    }

    private function buildHandlerMethod(string $type): string
    {
        $method = 'on' . str_replace(' ', '', ucwords(str_replace('_', ' ', $type)));
        LogHandler::debug("🛠 Building handler method: {$method}");
        return $method;
    }

    private function convertToObject(mixed $data): object
    {
        return json_decode(json_encode($data), false);
    }

    private function executePluginMethod(PluginBase $plugin, string $method, object $data, Telegram $telegram): void
    {
        $pluginClass = get_class($plugin);

        if (!method_exists($plugin, $method)) {
            LogHandler::debug("⏭ Method not found: {$pluginClass}::{$method}");
            return;
        }

        $start = microtime(true);

        LogHandler::info(
            "▶️ Executing {$pluginClass}::{$method}",
            ['plugin' => $pluginClass, 'method' => $method]
        );

        try {
            $plugin->{$method}($data, $telegram);
        } catch (\Throwable $e) {
            LogHandler::error(
                "❌ Error executing {$pluginClass}::{$method} | message: {$e->getMessage()} | line {$e->getLine()}" ,
                [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        } finally {
            $duration = round((microtime(true) - $start) * 1000, 2);
            LogHandler::debug("⏱ Execution time {$pluginClass}::{$method} ({$duration} ms)");
        }
    }
}
