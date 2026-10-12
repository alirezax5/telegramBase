<?php

declare(strict_types=1);

namespace alirezax5\TelegramBase\App\Conversation;

use alirezax5\TelegramBase\App\Button\Keyboard;
use alirezax5\TelegramBase\App\Cache\CacheManager;
use alirezax5\TelegramBase\App\Environment\EnvHandler;
use alirezax5\TelegramBase\App\Logger\LogHandler;
use telegramBotApiPhp\Telegram;

/**
 * MenuFlow — a tiny, DB-free state machine for panel-style plugins.
 *
 * The full Conversation engine (Step API) is built for multi-step FORMS.
 * Admin panels are different: a graph of screens the user jumps between
 * with a persistent reply keyboard, a few input-taking screens, and the
 * universal expectation of «بازگشت» / «منوی اصلی» buttons that always work.
 * Hand-rolling that in every plugin leads exactly to the bug class the
 * owner reported (TM-25): input screens without a Cancel, dead keyboards
 * left behind after leaving a flow.
 *
 * MenuFlow gives a plugin:
 *
 *   - named SCREENS with text + reply keyboard (Keyboard builder)
 *   - label → screen NAVIGATION («🚫 بلاک» jumps to the block screen)
 *   - built-in «↩️ بازگشت» / «🏠 منوی اصلی» handling (labels customizable)
 *     — the back target comes from each screen's 'parent'
 *   - INPUT screens ('parse' => true) that route typed text to onInput()
 *   - automatic state per chat via CacheManager (no DB schema)
 *   - exit() sends remove_keyboard so no dead keyboard is left behind
 *
 * Minimal panel (plugin side):
 *
 *   private MenuFlow $menu;
 *
 *   public function __construct()
 *   {
 *       $this->menu = MenuFlow::make('support.panel')
 *           ->screen('main', [
 *               'text' => '🛠 پنل مدیریت',
 *               'kb'   => fn() => Keyboard::reply()
 *                   ->button('🚫 بلاک')->row()->button('🏠 منوی اصلی')
 *                   ->resize()->toArray(),
 *           ])
 *           ->screen('block', [
 *               'parent' => 'main',
 *               'text'   => 'آیدی عددی کاربر را بفرستید:',
 *               'parse'  => true,
 *               'kb'     => fn() => Keyboard::reply()
 *                   ->button('↩️ بازگشت')->row()->button('🏠 منوی اصلی')
 *                   ->resize()->toArray(),
 *               'onInput' => function (Telegram $tg, int|string $chatId, string $text, MenuFlow $flow): void {
 *                   // ...do the block, then reply...
 *                   $flow->say($tg, $chatId, '✅ کاربر بلاک شد.');
 *                   $flow->go($tg, $chatId, 'main');
 *               },
 *           ])
 *           ->nav('🚫 بلاک', 'block');
 *   }
 *
 *   #[ChatType(['private'])]
 *   public function onMessage($message, Telegram $tg): void
 *   {
 *       $this->menu->handle($message, $tg);   // returns true if consumed
 *   }
 *
 * Admin-only flows: put your own gate inside the onInput() callbacks and
 * before ->go() — MenuFlow deliberately ships no permission model.
 */
final class MenuFlow
{
    /** @var array<string, array{text:mixed,kb:mixed,parent:?string,parse:bool,onInput:?callable,onEnter:?callable}> */
    private array $screens = [];

    /** @var array<string, string> label → screen key */
    private array $navMap = [];

    private string $homeKey = 'main';
    private string $backLabel = '↩️ بازگشت';
    private string $homeLabel = '🏠 منوی اصلی';

    private function __construct(
        private readonly string $name
    ) {
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    // ------------------------------------------------------------------
    // Definition API
    // ------------------------------------------------------------------

    /**
     * Define a screen.
     *
     * @param array{
     *   text: string|callable(Telegram,int|string):string,
     *   kb: array|callable(Telegram,int|string):array,
     *   parent?: string,
     *   parse?: bool,
     *   onInput?: callable(Telegram,int|string,string,self):void,
     *   onEnter?: callable(Telegram,int|string,self):void
     * } $spec
     */
    public function screen(string $key, array $spec): self
    {
        $spec['parent'] = $spec['parent'] ?? null;
        $spec['parse'] = (bool)($spec['parse'] ?? false);
        $spec['onInput'] = $spec['onInput'] ?? null;
        $spec['onEnter'] = $spec['onEnter'] ?? null;

        $this->screens[$key] = $spec;
        return $this;
    }

    /** Map a reply-keyboard label to a screen (navigation buttons). */
    public function nav(string $label, string $screen): self
    {
        $this->navMap[$label] = $screen;
        return $this;
    }

    public function home(string $key): self
    {
        $this->homeKey = $key;
        return $this;
    }

    public function backLabel(string $label): self
    {
        $this->backLabel = $label;
        return $this;
    }

    public function homeLabel(string $label): self
    {
        $this->homeLabel = $label;
        return $this;
    }

    // ------------------------------------------------------------------
    // Runtime API (called from the plugin)
    // ------------------------------------------------------------------

    /**
     * Feed an incoming message into the flow.
     *
     * Returns true when the message was consumed by MenuFlow (navigation
     * label, back/home label, or input handling). Return false = "not
     * mine" so the plugin can fall through to other handlers.
     */
    public function handle(object $message, Telegram $tg): bool
    {
        $text = trim((string)($message->text ?? ''));
        if ($text === '') {
            return false;
        }

        $chatId = $message->chat->id ?? null;
        if ($chatId === null) {
            return false;
        }

        $state = $this->state((int)$chatId);
        $screen = $state['screen'] ?? null;

        // Universal «منوی اصلی» — always wins. Rendering home replaces the
        // previous reply keyboard automatically (no remove_keyboard needed).
        if ($text === $this->homeLabel) {
            $this->go($tg, $chatId, $this->homeKey);
            return true;
        }

        // Universal «بازگشت» — walk to the parent of the active screen.
        if ($text === $this->backLabel) {
            $parent = $this->screens[$screen]['parent'] ?? null
                ?? $this->homeKey;

            $this->go($tg, $chatId, $parent);
            return true;
        }

        // Known navigation label.
        if (isset($this->navMap[$text])) {
            $this->go($tg, $chatId, $this->navMap[$text]);
            return true;
        }

        // Input screen: typed text goes to onInput.
        if (
            $screen !== null
            && ($this->screens[$screen]['parse'] ?? false)
            && ($cb = $this->screens[$screen]['onInput'] ?? null) !== null
        ) {
            ($cb)($tg, $chatId, $text, $this);
            return true;
        }

        return false;
    }

    /**
     * Render a screen: send its text with its reply keyboard and remember
     * it as the active screen for this chat.
     */
    public function go(Telegram $tg, int|string $chatId, string $screen): void
    {
        if (!isset($this->screens[$screen])) {
            LogHandler::error("MenuFlow[{$this->name}]: unknown screen '{$screen}'");
            $screen = $this->homeKey;
        }

        $spec = $this->screens[$screen];

        $text = is_callable($spec['text'])
            ? (string)($spec['text'])($tg, $chatId)
            : (string)$spec['text'];

        $kb = $spec['kb'] ?? null;
        $kbArray = is_callable($kb) ? $kb($tg, $chatId) : $kb;

        $params = ['chat_id' => $chatId, 'text' => $text];

        if (is_array($kbArray) && $kbArray !== []) {
            $params['reply_markup'] = json_encode(
                $kbArray,
                JSON_UNESCAPED_UNICODE
            );
        }

        $tg->callRaw('sendMessage', $params);

        $this->setState((int)$chatId, ['screen' => $screen]);

        if (($spec['onEnter'] ?? null) !== null) {
            ($spec['onEnter'])($tg, $chatId, $this);
        }
    }

    /** Send a plain text message without leaving the current screen. */
    public function say(Telegram $tg, int|string $chatId, string $text): void
    {
        $tg->callRaw('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    /**
     * Leave the flow: clear the stored screen and, if a farewell text is
     * given, send it with remove_keyboard so no dead custom keyboard is
     * left behind (TM-25). Pass null text to only clear the state — the
     * plugin is then responsible for attaching remove_keyboard to its own
     * next message (Keyboard::remove()).
     */
    public function exit(Telegram $tg, int|string $chatId, ?string $text = '👋 بسته شد'): void
    {
        if ($text !== null) {
            $tg->callRaw('sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode(Keyboard::remove()),
            ]);
        }

        $this->clearState((int)$chatId);
    }

    // ------------------------------------------------------------------
    // State (CacheManager-backed, no DB)
    // ------------------------------------------------------------------

    public function stateOf(int|string $chatId): ?array
    {
        return $this->state((int)$chatId);
    }

    /** Store arbitrary per-chat data alongside the active screen. */
    public function setData(int|string $chatId, array $data): void
    {
        $state = $this->state((int)$chatId);
        $state['data'] = array_merge($state['data'] ?? [], $data);
        $this->setState((int)$chatId, $state);
    }

    public function data(int|string $chatId): array
    {
        return $this->state((int)$chatId)['data'] ?? [];
    }

    private function state(int $chatId): array
    {
        if (!CacheManager::isInitialized()) {
            return [];
        }

        $cached = CacheManager::get($this->key($chatId));

        return is_array($cached) ? $cached : [];
    }

    private function setState(int $chatId, array $state): void
    {
        if (!CacheManager::isInitialized()) {
            return;
        }

        CacheManager::put($this->key($chatId), $state, $this->ttl());
    }

    private function clearState(int $chatId): void
    {
        if (CacheManager::isInitialized()) {
            CacheManager::forget($this->key($chatId));
        }
    }

    private function key(int $chatId): string
    {
        return "menuflow:{$this->name}:{$chatId}";
    }

    private function ttl(): int
    {
        return (int)EnvHandler::get('MENUFLOW_STATE_TTL', 86400);
    }
}
