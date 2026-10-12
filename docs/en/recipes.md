# Recipes — patterns from a production deployment

Short, opinionated solutions for the questions that actually came up while
building and operating a real support bot (user↔admin relay, admin panel,
group relay) on telegramBase. Every recipe links a runnable example in
[examples/](../../examples/).

## Recipe 1 — Support relay with live edit sync

*Users write in private → copy appears in a relay group → admins reply by
replying to the copy → replies land in the user's chat. If the user edits
their message, the group copy updates; if an admin edits their group reply,
the user's copy updates.*

Key points:

1. Webhook MUST include `edited_message` — `php bin/tgbase webhook:info`
   warns when it doesn't (Telegram only delivers allowed update types).
2. PluginHandler routes `edited_message` to `onEditedMessage($msg, $tg)`
   automatically — same priority/ChatType rules as `onMessage`.
3. Persist a small id map: `user_msg_id → group_copy_id` and
   `group_msg_id → {user_chat, user_copy_id}`. A JSON file (LOCK_EX) is
   fine to start; move to a DB table for volume.
4. When editing a copy that carries an inline keyboard, pass the original
   `reply_markup` back — edits strip keyboards otherwise.

Full runnable example: [examples/RelayEditSyncBot.php](../../examples/RelayEditSyncBot.php).
The production implementation (payam) uses the same shape with a `pm_messages`
DB row instead of JSON.

## Recipe 2 — Admin panel that cannot strand the admin

*Every input-taking state must have a visible exit; leaving a flow must
clean the keyboard.*

1. Build panel screens with **MenuFlow** (see [menu-flows.md](menu-flows.md))
   — back/home come for free.
2. Hand-rolled instead? Then: render a «↩️ بازگشت / ❌ کنسل» row on every
   prompt keyboard, match those labels BEFORE your state machine, and send
   `Keyboard::remove()` attached to a message when a state is abandoned.
3. Reply-keyboard label matching should go through the translator
   (`Conversation::textMatches()`) so it is locale-safe.

Full runnable example: [examples/PanelBot.php](../../examples/PanelBot.php).

## Recipe 3 — Panel visible in private only, group is reply-only

*The product contract of the support bot: menus live in private (admins
only); the relay group is for answering, not for menus; `/panel` typed in
the group is deliberately unanswered.*

1. `#[ChatType(['private'])]` on the panel plugin + an explicit admin gate
   (`ADMIN_IDS` env or your users table) — never rely on the group for
   admin actions.
2. In the group plugin, only act when `$msg->reply_to_message` exists AND
   the replier is an admin; everything else falls through.
3. Do NOT register a `/panel` command handler for groups — silence is the
   documented behavior.

## Recipe 4 — Debugging "the message never arrived"

1. `LOG_API_TRACE=1` → one line per outgoing API call:
   `📡 sendMessage → FAIL 91ms chat 123 | 403 Forbidden: bot was blocked…`
2. `php bin/tgbase webhook:info` → is the webhook set? pending count?
   last_error? does `allowed_updates` include the types you need?
3. Replay the exact update offline: save it as JSON and run
   `php bin/tgbase simulate fixture.json --dry` (parse-only) or without
   `--dry` against a TEST bot to dispatch it through the real pipeline.
4. Changed `.env` by hand? Re-check ownership: the FPM user must be able to
   read it (`chown www:www .env` on typical aaPanel/BT setups) — a 640
   root-owned `.env` produces "BOT_TOKEN is not set" 500s that never reach
   your app log.

## Recipe 5 — Keyboard hygiene checklist (TM-25 contract)

- [ ] every prompt keyboard shows a back/cancel row
- [ ] cancel label matches through the translator, then exits cleanly
- [ ] abandoning a flow sends `remove_keyboard` (attach to the next message)
- [ ] `editMessageText()` re-sends the original `reply_markup`
- [ ] when a screen replaces another, the new markup replaces the old
      keyboard automatically — no double-remove needed

## Recipe 6 — Adding a Mini App entry point

1. Build the button: `Keyboard::reply()->webApp('فروشگاه', $url)` or the
   inline variant (see [buttons.md](buttons.md)).
2. The Mini App receives `WebApp.initData` on load — validate it
   server-side (HMAC-SHA256 per Telegram docs) before trusting the user.
3. Keep the bot backend as the source of truth; the Mini App is a view.
   (Native initData validation support is evaluated for the framework —
   see the repo README for the current status.)
