<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Plugin;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Cache\CacheManager;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

class PluginHandler
{
    private const UPDATE_TYPES = [
        'message', 'edited_message', 'channel_post', 'edited_channel_post',
        'business_connection', 'business_message', 'edited_business_message',
        'deleted_business_messages', 'message_reaction', 'message_reaction_count',
        'inline_query', 'chosen_inline_result', 'callback_query',
        'shipping_query', 'pre_checkout_query', 'purchased_paid_media',
        'poll', 'poll_answer', 'my_chat_member', 'chat_member',
        'chat_join_request', 'chat_boost', 'removed_chat_boost',
    ];

    private const CACHE_KEY_PLUGINS = 'plugin_handler:plugins_index';

    /** @var array<string,array<int,array{class:string,priority:int,chatTypes:array}>> */
    private array $plugins = [];

    /** @var array<string,PluginInterface> */
    private array $instances = [];

    private ?string $pluginsDir = null;
    private int $lastReloadTime = 0;
    private int $reloadInterval;
    private bool $enableCache;
    private int $cacheTtl;

    /** @var array<string,true> */
    private array $updateTypesFlip = [];

    /** @var array<string,string> */
    private array $fileHashes = [];

    public function __construct()
    {
        $this->reloadInterval = Config::plugins()->reloadInterval;
        $this->enableCache = (bool)Config::plugins()->cacheEnabled;
        $this->cacheTtl = (int)EnvHandler::get('PLUGIN_CACHE_TTL', 300);
        $pluginsPath = Config::plugins()->path;

        $this->updateTypesFlip = array_fill_keys(self::UPDATE_TYPES, true);

        if (!is_dir($pluginsPath)) {
            LogHandler::error("Plugins directory not found: {$pluginsPath}");
            return;
        }
        $this->pluginsDir = realpath($pluginsPath) ?: $pluginsPath;

        LogHandler::info("Plugins dir: {$this->pluginsDir}");

        $this->loadPlugins();
    }

    public function loadPlugins(): void
    {
        if (!$this->pluginsDir) {
            return;
        }
        if ($this->enableCache && CacheManager::isInitialized()) {
            if ($this->loadFromCache()) {
                $this->lastReloadTime = time();
                LogHandler::debug("Plugins loaded from cache");
                return;
            }
        }

        $this->loadFromFiles();
    }

    private function getPluginFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->pluginsDir,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function pathToClass(string $file): string
    {
        $relative = str_replace($this->pluginsDir, '', $file);
        $relative = trim($relative, DIRECTORY_SEPARATOR);
        $relative = str_replace('.php', '', $relative);

        return '\\alirezax5\\TelegramBase\\Plugin\\' .
            str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
    }

    private function loadFromFiles(): void
    {
        LogHandler::info("Scanning plugins...");

        $files = $this->getPluginFiles();

        $this->plugins = [];
        $this->instances = [];
        $this->fileHashes = [];

        if (!$files) {
            LogHandler::warning("No plugin files found");
            return;
        }

        $loaded = 0;
        $skipped = 0;

        foreach ($files as $file) {
            try {
                require_once $file;

                $fqcn = $this->pathToClass($file);

                if (!class_exists($fqcn)) {
                    $skipped++;
                    continue;
                }

                $ref = new \ReflectionClass($fqcn);

                if (
                    !$ref->isInstantiable() ||
                    !$ref->isSubclassOf(PluginInterface::class)
                ) {
                    $skipped++;
                    continue;
                }

                /** @var PluginInterface $plugin */
                $plugin = $ref->newInstance();
                $this->instances[$fqcn] = $plugin;

                $this->indexPlugin($plugin, $ref, $fqcn);

                $this->fileHashes[$fqcn] = (string)filemtime($file);

                $loaded++;

                LogHandler::debug("loaded {$fqcn}");
            } catch (\Throwable $e) {
                $skipped++;
                LogHandler::error("Plugin load error: {$file} | {$e->getMessage()}");
            }
        }

        foreach ($this->plugins as &$group) {
            usort($group, fn($a, $b) =>
                $a['priority'] <=> $b['priority']
            );
        }
        $this->saveToCache();
        $this->lastReloadTime = time();

        LogHandler::info("Plugins: {$loaded} loaded, {$skipped} skipped");
    }

    private function indexPlugin(
        PluginInterface $plugin,
        \ReflectionClass $ref,
        string $fqcn
    ): void
    {
        $chatTypes = [];

        $attributes = $ref->getAttributes(
            \alirezax5\TelegramBase\App\Attributes\ChatType::class
        );

        if ($attributes !== []) {
            /** @var \alirezax5\TelegramBase\App\Attributes\ChatType $attr */
            $attr = $attributes[0]->newInstance();
            $chatTypes = $attr->types;
        }

        $priority = $plugin->getPriority();

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {

            $name = $method->getName();

            if (
                str_starts_with($name, 'on')
                || $name === 'before'
                || $name === 'after'
            ) {
                $this->plugins[$name][] = [
                    'class' => $fqcn,
                    'priority' => $priority,
                    'chatTypes' => $chatTypes,
                ];
            }
        }
    }

    private function canRun(array $pluginData, string $chatType): bool
    {
        $chatTypes = $pluginData['chatTypes'];

        if ($chatTypes === []) {
            return true;
        }

        return in_array($chatType, $chatTypes, true);
    }

    private function loadFromCache(): bool
    {
        $cached = CacheManager::get(self::CACHE_KEY_PLUGINS);

        if (!is_array($cached) || !isset($cached['plugins'], $cached['hashes'])) {
            return false;
        }

        $files = $this->getPluginFiles();

        if (count($files) !== count($cached['hashes'])) {
            LogHandler::info("Plugin set changed -> cache invalid");
            return false;
        }

        foreach ($files as $file) {
            $fqcn = $this->pathToClass($file);
            $hash = (string)filemtime($file);

            if (($cached['hashes'][$fqcn] ?? null) !== $hash) {
                LogHandler::info("Plugin change detected -> cache invalid");
                return false;
            }

            require_once $file;
        }

        $this->plugins = $cached['plugins'];
        $this->fileHashes = $cached['hashes'];
        $this->instances = [];

        return true;
    }

    private function saveToCache(): void
    {
        if (!$this->enableCache || !CacheManager::isInitialized()) {
            return;
        }

        CacheManager::put(self::CACHE_KEY_PLUGINS, [
            'plugins' => $this->plugins,
            'hashes' => $this->fileHashes,
            'time' => time(),
        ], $this->cacheTtl);

        LogHandler::debug("plugin cache saved");
    }

    public function runAll($update, Telegram $Telegram): void
    {
        if (time() - $this->lastReloadTime > $this->reloadInterval) {
            $this->loadPlugins();
        }

        $type = $this->detectType($update);

        if (!$type) {
            return;
        }

        $method = 'on' . str_replace(' ', '', ucwords(str_replace('_', ' ', $type)));

        $chatType = $Telegram->chatType();

        $this->run('before', $update->$type ?? null, $Telegram, $chatType);
        $this->run($method, $update->$type ?? null, $Telegram, $chatType);
        $this->run('after', $update->$type ?? null, $Telegram, $chatType);
    }

    private function detectType($update): ?string
    {
        foreach ($update as $key => $_) {
            if (isset($this->updateTypesFlip[$key])) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Run a plugin hook (before / on{Type} / after) for every registered plugin.
     *
     * @param string  $method   Hook name
     * @param mixed   $data     Update payload for the detected type
     * @param Telegram $Telegram Bot API instance
     * @param string  $chatType Chat type ('private', 'group', ...)
     */
    private function run(
        string $method,
        mixed $data,
        Telegram $Telegram,
        string $chatType
    ): void {

        foreach ($this->plugins[$method] ?? [] as $pluginData) {

            if (!$this->canRun($pluginData, $chatType)) {
                continue;
            }

            $fqcn = $pluginData['class'];

            $plugin = $this->instances[$fqcn] ??= new $fqcn();

            try {

                $plugin->{$method}($data, $Telegram);

            } catch (\Throwable $e) {

                LogHandler::error(
                    "Plugin error [{$method}] | " .
                    "Plugin: {$fqcn} | " .
                    "Message: {$e->getMessage()} | " .
                    "File: {$e->getFile()} | " .
                    "Line: {$e->getLine()} | " .
                    "Code: {$e->getCode()}"
                );
            }
        }
    }

    public function clearPluginCache(): void
    {
        if (!CacheManager::isInitialized()) {
            return;
        }

        CacheManager::forget(self::CACHE_KEY_PLUGINS);

        LogHandler::info("plugin cache cleared");
    }

    public function reload(): void
    {
        $this->clearPluginCache();
        $this->loadPlugins();
    }

    public function getStats(): array
    {
        $classes = [];

        foreach ($this->plugins as $group) {
            foreach ($group as $item) {
                $classes[$item['class']] = true;
            }
        }

        return [
            'plugins' => count($classes),
            'handlers' => array_sum(array_map('count', $this->plugins)),
            'events' => array_keys($this->plugins),
        ];
    }
}
