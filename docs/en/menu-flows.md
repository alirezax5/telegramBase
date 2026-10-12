# MenuFlow — panel-style screens with built-in Back/Cancel

The Conversation engine (see [conversations.md](conversations.md)) is built
for linear FORMS. Admin panels are a different animal: a graph of screens
the user jumps between with a persistent Reply Keyboard, a few input-taking
screens, and the universal expectation that «بازگشت» / «منوی اصلی» always
work — plus no dead keyboard left behind when the flow ends.

`App\Conversation\MenuFlow` is a tiny, DB-free state machine for exactly
that shape. It was extracted from the live support-relay panel after the
owner reported (TM-25) that hand-rolled panels kept shipping input screens
with **no visible cancel** and abandoned keyboards.

## What you get for free

- named **screens** with text + reply keyboard (Keyboard builder)
- **label → screen navigation** — no hand-written switch/case chains
- built-in **«↩️ بازگشت»** (walks to the screen's `parent`) and
  **«🏠 منوی اصلی»** (jumps home) handling — labels customizable
- **input screens** (`'parse' => true`) routing typed text to `onInput()`
- per-chat state via **CacheManager** (no DB schema, no migrations)
- **`exit()`** sends `remove_keyboard` so no dead keyboard survives (TM-25)

## Minimal panel

```php
use alirezax5\TelegramBase\App\Button\Keyboard;
use alirezax5\TelegramBase\App\Conversation\MenuFlow;
use telegramBotApiPhp\Telegram;

$this->menu = MenuFlow::make('myapp.panel')
    ->screen('main', [
        'text' => '🛠 پنل مدیریت',
        'kb'   => fn () => Keyboard::reply()
            ->button('🚫 بلاک')->row()
            ->button('🏠 منوی اصلی')
            ->resize()->toArray(),
    ])
    ->screen('block', [
        'parent' => 'main',                 // «بازگشت» target
        'text'   => 'آیدی عددی کاربر را بفرستید:',
        'parse'  => true,                   // waits for typed input
        'kb'     => fn () => Keyboard::reply()
            ->button('↩️ بازگشت')->row()
            ->button('🏠 منوی اصلی')
            ->resize()->toArray(),
        'onInput' => function (Telegram $tg, int|string $chatId, string $text, MenuFlow $flow): void {
            if (!ctype_digit($text)) {
                $flow->say($tg, $chatId, '❌ فقط آیدی عددی.');
                return;                      // stay — cancel is one tap away
            }
            // ...do the block...
            $flow->say($tg, $chatId, '✅ بلاک شد.');
            $flow->go($tg, $chatId, 'main');
        },
    ])
    ->nav('🚫 بلاک', 'block');               // label → screen
```

Plugin side — one line feeds every incoming message to the flow:

```php
#[ChatType(['private'])]
public function onMessage($message, Telegram $tg): void
{
    if ($this->menu->handle($message, $tg)) {
        \alirezax5\TelegramBase\App\Update\UpdateState::markHandled('myapp.panel');
    }
}
```

`handle()` returns `true` when the message was consumed (navigation label,
back/home label, or input) and `false` when the plugin should keep
handling it. There is deliberately **no permission model** — put your own
admin gate inside `onInput()` callbacks and before `->go()`.

## API reference

| Method | Purpose |
|---|---|
| `make($name)` | create a flow (name spaces the state cache key) |
| `screen($key, $spec)` | define a screen — `text`, `kb`, `parent`, `parse`, `onInput`, `onEnter`; text/kb accept closures |
| `nav($label, $screen)` | reply-keyboard label jumps to a screen |
| `home($key)` | main screen key (default `main`) |
| `backLabel()/homeLabel($text)` | customize the reserved nav labels |
| `handle($message, $tg): bool` | route one message; true = consumed |
| `go($tg, $chatId, $screen)` | render a screen (new markup replaces the old keyboard) |
| `say($tg, $chatId, $text)` | message without leaving the screen |
| `exit($tg, $chatId, $text)` | leave the flow — sends text with `remove_keyboard`, clears state |
| `stateOf()/setData()/data()` | per-chat state helpers |

## Keyboard-cycle notes

- Jumping home / back **replaces** the keyboard (the new screen's markup
  takes over) — no extra `remove_keyboard` needed mid-flow.
- Only `exit()` actually removes the keyboard, because the user is leaving
  the flow entirely — that is the TM-25 contract.
- State TTL is `MENUFLOW_STATE_TTL` (default 86400s).

## Working example

See [examples/PanelBot.php](../../examples/PanelBot.php) — a complete
admin panel with a guarded input screen, back/home navigation and an
`ADMIN_IDS` gate.
