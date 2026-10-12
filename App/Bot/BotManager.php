<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Bot;

use telegramBotApiPhp\Telegram;
use alirezax5\TelegramBase\App\Config\Config;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;

final class BotManager
{
    private static ?self $instance = null;
    /** @var array<string, Telegram> */
    private array $bots = [];
    private string $defaultBotName = 'main';

    private function __construct()
    {
        $this->initDefaultBot();

        // LOG_API_TRACE=1 → one delivery line per outgoing Bot API call
        // (static hook covers every instance, incl. BOT_EXTRA_BOTS).
        ApiTrace::install();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function initDefaultBot(): void
    {
        $config = Config::bot();
        $this->bots[$this->defaultBotName] = new Telegram(
            $config->token,
            $config->apiUrl,
            $config->apiUrlFile
        );

        $this->registerExtraBotsFromEnv();
    }

    /**
     * Register additional bots from the BOT_EXTRA_BOTS env var.
     *
     * Format: "name1:token1,name2:token2" (a Telegram token contains a colon,
     * so each entry is split on the FIRST colon only; entries are comma-separated).
     *
     * Multi-bot: plugins then fetch a specific bot by name via get($name) —
     * always guard with has($name) first, because get() falls back to the main
     * bot for unknown names (with a warning) rather than throwing.
     */
    private function registerExtraBotsFromEnv(): void
    {
        // P-REL-B01: read via EnvHandler ($_ENV first, real getenv second)
        // so BOT_EXTRA_BOTS defined in .env is honored too — raw getenv()
        // never sees Dotenv values (createImmutable does not putenv).
        $raw = (string)EnvHandler::get('BOT_EXTRA_BOTS', '');
        if (trim($raw) === '') {
            return;
        }

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) {
                LogHandler::warning("BotManager: ignoring malformed BOT_EXTRA_BOTS entry (expected name:token)");
                continue;
            }

            [$name, $token] = explode(':', $pair, 2);
            $name = trim($name);
            $token = trim($token);

            if ($name === '' || $token === '') {
                LogHandler::warning("BotManager: ignoring empty BOT_EXTRA_BOTS name or token");
                continue;
            }

            if ($name === $this->defaultBotName) {
                LogHandler::warning("BotManager: '{$name}' is reserved for the main bot — BOT_EXTRA_BOTS entry ignored");
                continue;
            }

            $this->add($name, new Telegram($token));
            LogHandler::info("BotManager: registered bot '{$name}' from BOT_EXTRA_BOTS");
        }
    }

    public function get(?string $name = null): Telegram
    {
        $name ??= $this->defaultBotName;

        if (isset($this->bots[$name])) {
            return $this->bots[$name];
        }

        LogHandler::warning("Bot '{$name}' not found, falling back to default '{$this->defaultBotName}'");
        return $this->bots[$this->defaultBotName];
    }

    /**
     * True when a bot with this name is registered.
     *
     * Always check this before get($name) for a NON-default bot: get() silently
     * falls back to the main bot, which would run the call under the wrong
     * identity (e.g. a getChatMember meant for bot #2 executed by bot #1).
     */
    public function has(string $name): bool
    {
        return isset($this->bots[$name]);
    }

    /** @return string[] Names of all registered bots (main first). */
    public function names(): array
    {
        return array_keys($this->bots);
    }

    public function setDefaultToken(string $token): Telegram
    {
        $bot = $this->get();
        $bot->setToken($token);
        LogHandler::warning("BotManager: default token overridden — affects all callers");
        return $bot;
    }

    public function add(string $name, Telegram $bot): void
    {
        if (isset($this->bots[$name])) {
            LogHandler::warning("BotManager: bot '{$name}' already registered — overwriting");
        }
        $this->bots[$name] = $bot;
    }

    // ======================================================== Managed Bots ==
    //
    // Bot API 9.6 (2026-04-03): a bot with can_manage_bots=true (enabled in
    // the @BotFather Mini App) may create and control OTHER bots — "managed
    // bots". The child bot's token is NEVER stored in .env: it is fetched
    // live from Telegram via getManagedBotToken(user_id) and registered
    // under a name. This is the recommended pattern for dynamic multi-bot
    // setups (child tokens rotate without touching the deployment).

    /**
     * Fetch a managed bot's token live and register it under $name.
     *
     * @param int    $userId Telegram user id of the USER who created the
     *                       managed bot (the API keys the child bot to its
     *                       creator, not to its bot id).
     * @param string $name   Registry name for BotManager::get()/has().
     *
     * @throws \RuntimeException when the API rejects the call (user has no
     *                           managed bot, manager lacks can_manage_bots, ...).
     */
    public function registerManagedBot(int $userId, string $name): Telegram
    {
        $res = $this->get()->callRaw('getManagedBotToken', ['user_id' => $userId]);

        if (empty($res->ok) || !is_string($res->result ?? null)) {
            $err = (string) ($res->description ?? $res->error ?? 'unknown error');
            LogHandler::error("BotManager: registerManagedBot(user={$userId}) failed — {$err}");
            throw new \RuntimeException(
                "Managed bot token fetch failed for user {$userId}: {$err}"
            );
        }

        return $this->addManagedBot($name, (string) $res->result);
    }

    /**
     * Rotate a managed bot's token (revoke + issue new one via
     * replaceManagedBotToken) and re-register it under $name.
     *
     * Destructive: the child bot's PREVIOUS token stops working immediately.
     */
    public function refreshManagedBot(int $userId, string $name): Telegram
    {
        $res = $this->get()->callRaw('replaceManagedBotToken', ['user_id' => $userId]);

        if (empty($res->ok) || !is_string($res->result ?? null)) {
            $err = (string) ($res->description ?? $res->error ?? 'unknown error');
            LogHandler::error("BotManager: refreshManagedBot(user={$userId}) failed — {$err}");
            throw new \RuntimeException(
                "Managed bot token rotation failed for user {$userId}: {$err}"
            );
        }

        return $this->addManagedBot($name, (string) $res->result);
    }

    /**
     * Access settings of a managed bot (BotAccessSettings object).
     *
     * @return object {is_access_restricted: bool, added_users?: User[]}
     */
    public function managedBotAccessSettings(int $userId): object
    {
        $res = $this->get()->callRaw('getManagedBotAccessSettings', ['user_id' => $userId]);

        if (empty($res->ok)) {
            $err = (string) ($res->description ?? $res->error ?? 'unknown error');
            throw new \RuntimeException(
                "Managed bot access settings fetch failed for user {$userId}: {$err}"
            );
        }

        return $res->result;
    }

    /**
     * Register the managed bot reported by a `managed_bot` update
     * (ManagedBotUpdated payload: {user, bot}) under its bot id.
     *
     * Typical onManagedBot usage:
     *   $bm->registerManagedBotFromUpdate($managedBot);
     *   // later: $bm->get((string) $managedBot->bot->id)
     */
    public function registerManagedBotFromUpdate(object $managedBot): Telegram
    {
        $userId = (int) ($managedBot->user->id ?? 0);
        $name   = (string) ($managedBot->bot->id ?? '');

        if ($userId === 0 || $name === '') {
            throw new \RuntimeException('ManagedBotUpdated payload missing user.id / bot.id');
        }

        return $this->registerManagedBot($userId, $name);
    }

    /**
     * Build the Telegram instance from a live-fetched managed token and put
     * it into the registry. The token is intentionally never logged.
     */
    private function addManagedBot(string $name, string $token): Telegram
    {
        if ($name === $this->defaultBotName) {
            throw new \InvalidArgumentException("'main' is reserved for the primary bot");
        }

        $config = Config::bot();
        $bot = new Telegram($token, $config->apiUrl, $config->apiUrlFile);
        $this->add($name, $bot);

        LogHandler::info(
            "BotManager: managed bot '{$name}' registered — token fetched live from Telegram, not stored on disk"
        );

        return $bot;
    }
}
