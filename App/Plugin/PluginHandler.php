<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Plugin;

use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use alirezax5\TelegramBase\App\Cache\CacheManager;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Plugin\Contract\FallbackInterface;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use alirezax5\TelegramBase\App\Update\UpdateState;
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
        // Bot API 9.6 (2026-04-03) — Managed Bots: fired when a child bot is
        // created for (or its token/owner changes under) this manager bot.
        // Plugins implement onManagedBot($managedBot, $tg); payload is the
        // ManagedBotUpdated object {user, bot}.
        'managed_bot',
        // Bot API 10.0 (2026-05-08) — Guest Mode: fired when a user invokes
        // the bot in a chat it is NOT a member of (requires
        // supports_guest_queries=true from BotFather). Plugins implement
        // onGuestMessage($message, $tg); payload is a Message carrying
        // guest_query_id + guest_bot_caller_user/guest_bot_caller_chat.
        // Answer with $tg->bot('answerGuestQuery', ['guest_query_id' => ...,
        // 'result' => InlineQueryResult]) — same result shapes as inline mode.
        'guest_message',
    ];

    private const CACHE_KEY_PLUGINS = 'plugin_handler:plugins_index';

    /** @var array<string,array<int,array{class:string,priority:int,chatTypes:array}>> */
    private array $plugins = [];

    /**
     * Plugins implementing FallbackInterface, indexed for the fallback
     * phase (runs when no plugin marked the update handled).
     *
     * @var array<int,array{class:string,priority:int,chatTypes:array}>
     */
    private array $fallbacks = [];

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

    /** FQCNs of registered core plugins (CORE_PLUGINS; outside Plugin/ dir scan) */
    private array $corePlugins = [];

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
        $this->fallbacks = [];
        $this->instances = [];
        $this->fileHashes = [];
        $this->corePlugins = [];

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
                    // Loud diagnostic for the FallbackInterface trap: the
                    // contract requires fallbacks to implement BOTH
                    // interfaces; a fallback-only class used to be silently
                    // counted as "skipped" and the bot shipped with
                    // "0 fallback(s)" and no explanation (found on a
                    // production deployment, 2026-10-13).
                    if ($ref->implementsInterface(FallbackInterface::class)) {
                        LogHandler::warning(
                            "Plugin {$fqcn} implements FallbackInterface but not PluginInterface — skipped."
                            . " Fallbacks must implement BOTH (see FallbackInterface docblock)."
                        );
                    }
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

        // ── Core plugins (framework-shipped, outside Plugin/) ──
        // Registered from the CORE_PLUGINS env (comma-separated FQCNs).
        // These live outside the scanned plugins dir — e.g. the Conversation
        // engine App\Conversation\ConversationManager — so the file scan
        // cannot find them. Priorities are re-sorted below, so insertion
        // order does not matter.
        foreach ($this->corePluginFqcns() as $coreFqcn) {
            try {
                if (!class_exists($coreFqcn)) {
                    LogHandler::warning("Core plugin not found: {$coreFqcn}");
                    continue;
                }

                $ref = new \ReflectionClass($coreFqcn);

                if (
                    !$ref->isInstantiable() ||
                    !$ref->isSubclassOf(PluginInterface::class)
                ) {
                    continue;
                }

                /** @var PluginInterface $plugin */
                $plugin = $ref->newInstance();
                $this->instances[$coreFqcn] = $plugin;

                $this->indexPlugin($plugin, $ref, $coreFqcn);

                $this->corePlugins[] = $coreFqcn;

                $loaded++;

                LogHandler::debug("loaded core {$coreFqcn}");
            } catch (\Throwable $e) {
                LogHandler::error("Core plugin load error: {$coreFqcn} | {$e->getMessage()}");
            }
        }

        foreach ($this->plugins as &$group) {
            usort($group, fn($a, $b) =>
                $a['priority'] <=> $b['priority']
            );
        }

        usort($this->fallbacks, fn($a, $b) =>
            $a['priority'] <=> $b['priority']
        );

        $this->saveToCache();
        $this->lastReloadTime = time();

        $fallbackCount = count($this->fallbacks);
        LogHandler::info("Plugins: {$loaded} loaded, {$skipped} skipped, {$fallbackCount} fallback(s)");
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

        // Register the fallback phase ("command not found" hook).
        // onFallback is NOT an update-type hook, so it never lands in
        // $this->plugins — it lives in its own priority-sorted list.
        if (
            $ref->implementsInterface(FallbackInterface::class)
            && $ref->hasMethod('onFallback')
        ) {
            $this->fallbacks[] = [
                'class' => $fqcn,
                'priority' => $priority,
                'chatTypes' => $chatTypes,
            ];
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

    /**
     * FQCNs of core plugins from the CORE_PLUGINS env (comma-separated).
     *
     * Core plugins are framework-shipped (or app-registered) plugin classes
     * that live outside the scanned Plugin/ directory — e.g. the Conversation
     * engine App\Conversation\ConversationManager — and therefore cannot be
     * discovered by the file scan. Leading backslashes are tolerated.
     *
     * @return string[]
     */
    private function corePluginFqcns(): array
    {
        $raw = (string)EnvHandler::get('CORE_PLUGINS', '');

        if (trim($raw) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $raw) as $fqcn) {
            $fqcn = trim($fqcn, " \t\n\r\\");
            if ($fqcn !== '') {
                $out[] = $fqcn;
            }
        }

        return $out;
    }

    private function loadFromCache(): bool
    {
        $cached = CacheManager::get(self::CACHE_KEY_PLUGINS);

        // 'fallbacks' was added with the fallback phase — cache entries
        // written by the previous handler version lack the key and are
        // treated as invalid, forcing a one-time re-scan after deploy.
        if (!is_array($cached) || !isset($cached['plugins'], $cached['hashes'], $cached['fallbacks'])) {
            return false;
        }

        // Core plugins are tracked separately from the file-hash set — a
        // changed CORE_PLUGINS env must invalidate the cache.
        if (($cached['core'] ?? null) !== $this->corePluginFqcns()) {
            LogHandler::info("Core plugin set changed -> cache invalid");
            return false;
        }

        $files = $this->getPluginFiles();

        // Compare the SCANNED FILE LIST (helpers included), never the
        // loaded-plugin count: a project may keep non-plugin helper classes
        // under Plugin/ (e.g. Plugin/X/Support/User.php). Comparing
        // count($files) against count($cached['hashes']) made the cache
        // invalidate on EVERY request in that layout — full rescan + resave
        // per hit plus "Plugin set changed" log spam (found on a production
        // deployment, 2026-10-13).
        $cachedFiles = $cached['files'] ?? null;
        $current = $files;
        sort($current);

        if (!is_array($cachedFiles) || count($cachedFiles) !== count($current)) {
            LogHandler::info("Plugin set changed -> cache invalid");
            return false;
        }

        $stored = $cachedFiles;
        sort($stored);

        if ($stored !== $current) {
            LogHandler::info("Plugin set changed -> cache invalid");
            return false;
        }

        foreach ($files as $file) {
            $fqcn = $this->pathToClass($file);
            $hash = (string)filemtime($file);

            // Edits are tracked per LOADED plugin only; helper files are
            // require_once'd fresh every request, so their mtime does not
            // participate in cache validation (opcache serves their current
            // definition regardless).
            if (isset($cached['hashes'][$fqcn]) && $cached['hashes'][$fqcn] !== $hash) {
                LogHandler::info("Plugin change detected -> cache invalid");
                return false;
            }

            require_once $file;
        }

        $this->plugins = $cached['plugins'];
        $this->fallbacks = $cached['fallbacks'];
        $this->fileHashes = $cached['hashes'];
        $this->corePlugins = is_array($cached['core'] ?? null) ? $cached['core'] : [];
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
            'fallbacks' => $this->fallbacks,
            'hashes' => $this->fileHashes,
            // Scanned file list (helpers included) — the cache-validity
            // contract compares THIS against the current scan, not the
            // loaded-plugin count. A project keeping non-plugin helper
            // classes under Plugin/ (e.g. Plugin/X/Support/User.php) used
            // to invalidate the cache on every single request.
            'files' => $this->getPluginFiles(),
            'core' => $this->corePlugins,
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

        // Fresh handled-flag for every update (webhook, polling and queue
        // workers all dispatch one update per runAll() call).
        UpdateState::reset();

        $method = 'on' . str_replace(' ', '', ucwords(str_replace('_', ' ', $type)));

        // chatType() may return null for updates without a chat (e.g.
        // poll, poll_answer). Treat null as "unknown chat type" —
        // plugins without a ChatType attribute still run; plugins
        // restricted to a specific type are skipped.
        $chatType = $Telegram->chatType() ?? '';

        $this->run('before', $update->$type ?? null, $Telegram, $chatType);
        $this->run($method, $update->$type ?? null, $Telegram, $chatType);

        // Fallback phase ("command not found"): fire only when NO plugin
        // took responsibility for the update via UpdateState::markHandled().
        if (!UpdateState::isHandled()) {
            $this->runFallbacks($update->$type ?? null, $Telegram, $chatType);
        }

        $this->run('after', $update->$type ?? null, $Telegram, $chatType);
    }

    /**
     * Run the fallback phase: every FallbackInterface plugin receives the
     * same payload as on{UpdateType}, in priority order, filtered by the
     * ChatType attribute exactly like normal hooks.
     *
     * @param mixed   $data     Update payload for the detected type
     * @param Telegram $Telegram Bot API instance
     * @param string  $chatType Chat type ('private', 'group', ...)
     */
    private function runFallbacks(
        mixed $data,
        Telegram $Telegram,
        string $chatType
    ): void {

        foreach ($this->fallbacks as $pluginData) {

            if (!$this->canRun($pluginData, $chatType)) {
                continue;
            }

            $fqcn = $pluginData['class'];

            $plugin = $this->instances[$fqcn] ??= new $fqcn();

            try {

                $plugin->onFallback($data, $Telegram);

            } catch (\Throwable $e) {

                LogHandler::error(
                    "Fallback error [onFallback] | " .
                    "Plugin: {$fqcn} | " .
                    "Message: {$e->getMessage()} | " .
                    "File: {$e->getFile()} | " .
                    "Line: {$e->getLine()} | " .
                    "Code: {$e->getCode()}"
                );
            }
        }
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

            // TM-48: a gate plugin (haltPropagation) can stop the whole
            // dispatch chain for this update — later hooks and the
            // fallback phase are then skipped as well.
            if (UpdateState::isHalted()) {
                break;
            }

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
            'fallbacks' => count($this->fallbacks),
            'core' => count($this->corePlugins),
        ];
    }
}
