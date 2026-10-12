# Conversations (multi-step flows)

The Conversation engine turns a linear chat flow (registration wizard, ticket
form, order calculator, survey, …) into a small declarative class. It is the
framework-promoted version of the engine that was battle-tested live in the
demo apps (TM-14…TM-16): state persistence, input routing, validation with
retry, skip, branching, navigation (back / main menu), computed display steps
and both keyboard styles (inline and Reply Keyboard) are handled for you.

## Enabling the engine

The engine lives outside the scanned `Plugin/` directory, so it is registered
as a **core plugin**:

```dotenv
CORE_PLUGINS="alirezax5\TelegramBase\App\Conversation\ConversationManager"
```

Then run the idempotent migration that creates the `conversations` table
(`Database/Migrations/2026_10_12_000003_create_conversations_table.php`) with
your usual migration flow. `ConversationManager` runs at priority 2 — after
your language loader (priority 0) and before normal plugins (5+), so an input
consumed by an active conversation never leaks into other plugins or the
fallback.

## Writing a flow

```php
use alirezax5\TelegramBase\App\Conversation\Conversation;
use alirezax5\TelegramBase\App\Conversation\Step;
use alirezax5\TelegramBase\App\Attributes\ChatType;

#[ChatType('private')]
class RegisterWizard extends Conversation
{
    public function flowKey(): string { return 'register'; }

    protected function steps(): array
    {
        return [
            'name' => Step::text()
                ->ask('wiz.ask_name')
                ->validate(fn ($v) => mb_strlen($v) >= 2)
                ->retry('wiz.bad_name'),

            'phone' => Step::text()
                ->ask('wiz.ask_phone')
                ->pattern('#^\+?\d{10,14}$#')
                ->retry('wiz.bad_phone')
                ->skipable(),

            'confirm' => Step::callback()
                ->ask('wiz.confirm')
                ->buttons(['yes' => 'wiz.yes', 'no' => 'wiz.no'])
                ->menu(),          // adds a "main menu" nav button (TM-16)
        ];
    }

    public function onFinish(array $answers, Telegram $tg, int $chatId): void
    {
        // $answers = ['name' => ..., 'phone' => ..., 'confirm' => 'yes'|'no']
    }
}
```

A flow plugin triggers itself from its own `onMessage()` (command or menu
button text) and calls `ConversationManager::begin(new self(), $tg, $chatId)`.

## Step types

| Factory            | Input it waits for                                   |
|--------------------|------------------------------------------------------|
| `Step::text()`     | free text, or a Reply Keyboard tap (`->buttons()`)   |
| `Step::photo()`    | largest `photo` file_id                              |
| `Step::callback()` | inline keyboard tap (`->buttons()`)                  |
| `Step::display()`  | nothing — renders `->compute()` and auto-advances    |

### Step modifiers

| Modifier                  | Effect                                                                 |
|---------------------------|------------------------------------------------------------------------|
| `ask($key)` / `withAsk()` | question text (translation key) / dynamic placeholders from answers    |
| `pattern($regex)`         | regex validation for text steps                                        |
| `validate($fn)`           | custom validator; signature `fn(string $value, array $answers): bool` — the second argument is optional (TM-16) |
| `retry($key)`             | error message key (default `conv.retry_default`)                       |
| `optional()`              | step can be skipped with `/skip` (stored as `null`)                    |
| `when($fn)`               | conditional step: `fn(array $answers): bool` (branching)               |
| `buttons($map)`           | `value => label`; **array or closure** `fn(array $answers): array` for dynamic lists (TM-16) |
| `columns($n)`             | keyboard columns (Reply Keyboard always; Inline when > 1)              |
| `onDone($fn)`             | hook right after this step is stored                                   |
| `backable()`              | renders a **Back** nav button (TM-16)                                  |
| `menu()`                  | renders a **Main menu** nav button (TM-16)                             |
| `compute($fn)`            | text for `display` steps: returns raw string or `[key, replacements]`  |

## Navigation (TM-16)

* **Back** (`backable()`): jumps to the previous *input* step of the effective
  path (skipping `display` steps and steps filtered out by `when`), prunes the
  target answer and everything after it (no orphan answers from `when`
  branches), and re-asks the question. On the first input step there is no
  back target, so the back button is not rendered; the *text* back command
  there exits to the main menu instead.
* **Main menu** (`menu()`): cancels the conversation and renders the main menu.

Nav buttons work identically on both keyboard types:

* inline → `callback_data` = `conv:{flow}:__nav__:{back|menu}`
* Reply Keyboard → the localized label; matching is locale-aware via
  `navBackTexts()` / `exitTexts()` (default keys `conv.nav_back` and the app's
  exit texts). Manual typing of the same label is accepted too.

Labels are reserved during an active conversation: navigation matching runs
before step-button matching.

## Reply Keyboard steps (TM-15)

For a text step with `->buttons(['yes' => 'wiz.yes'])` the question is asked
with a temporary Reply Keyboard. The tapped **label** is reverse-mapped to the
canonical **value** through the translator, so the DB always stores the
logical value regardless of the user's locale. The raw value is also accepted
(manual typing). Unmatched free text gets the retry message.

One Telegram message carries exactly one `reply_markup`; the engine manages
the Reply Keyboard ↔ inline transitions between steps (temporary step keyboard
is removed when the next step has none; the conversation start removes the
main menu keyboard if the first step has none; `/cancel` and timeout remove it
too).

## Control commands

| Command   | Behaviour                                                      |
|-----------|----------------------------------------------------------------|
| `/cancel` | cancel the conversation (explicit, always works)               |
| `/start`  | silently cancel and let the normal start plugin render the menu |
| `/skip`   | skip the current step — only on `optional()` steps             |
| other `/cmds` | busy hint (`conv.busy`) while a conversation is active     |

## Timeout

`timeoutMinutes()` (default 15) of inactivity closes the conversation with the
`conv.timeout` message (includes the flow's trigger command). The next update
after the timeout passes through normally.

## Application hooks

```php
// per-user language for translation inside conversations (optional; default DEFAULT_LANG)
Conversation::$langResolver = fn (int $chatId) => Users::langOf($chatId);

// markup rendered with the "exit to main menu" message (optional)
ConversationManager::$mainMenuMarkup = fn () => ['inline_keyboard' => [...]];
```

## Storage

Active conversations live in the `conversations` table (one row per chat):
`bot_id` (multi-bot isolation via `TGBASE_BOT_ID`), `chatid`, `flow`,
`flow_class`, `step`, `answers` (JSON), `status` (`active|done|cancelled`),
`created_at`, `updated_at`. Finished/cancelled rows are kept for auditing.

## Core language strings

Shipped as `Language/{fa,en}/conv.php` **and** `Language/{fa,en}/conv.json`
(loaded per driver with the `conv.` prefix): `conv.cancelled`, `conv.timeout`,
`conv.busy`, `conv.use_buttons`, `conv.stale`, `conv.skip_not_allowed`,
`conv.retry_default`, `conv.nav_back`, `conv.exit_menu`.
