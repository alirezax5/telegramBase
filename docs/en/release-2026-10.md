# What's new — October 2026 (base upgrade batch)

A practical upgrade batch distilled from running a production support bot
on telegramBase. Full English reference: [en/recipes.md](../en/recipes.md).

## New framework capabilities

1. **`edited_message` is first-class.** PluginHandler routes it to
   `onEditedMessage($msg, $tg)`; the library exposes typed accessors and
   `chatType()` works on edits. The recommended webhook update list includes
   `edited_message` by default (see `webhook:info` warning otherwise).
2. **Webhook CLI** — `php bin/tgbase webhook:info | webhook:set | webhook:del`
   (secret + `allowed_updates` defaults from `.env`, edit-type warning).
3. **`LOG_API_TRACE=1`** — one delivery line per Bot API call:
   `📡 sendMessage → ok 128ms chat 82267967`. Never logs payloads.
4. **`tgbase simulate <fixture.json> [--dry]`** — replay saved updates
   through the real pipeline; sample fixtures in `tests/Fixtures/updates/`.
5. **`Keyboard::remove() / forceReply() / webApp()`** — request-shaped
   keyboard payloads + Telegram Mini App buttons; no more hand-written JSON.
6. **Lazy `__()` filename.key loader** — a dot-key miss loads
   `Language/{lang}/{file}.{php,json}` once, transparently.
7. **`App\Shared\Media`** — `download($tg,$fileId)` and `send($tg,$type,...)`
   accepting file_id or local path (see [en/media.md](../en/media.md)).
8. **`App\Conversation\MenuFlow`** — DB-free screen machine with built-in
   Back/Cancel and `remove_keyboard` on exit (see [en/menu-flows.md](../en/menu-flows.md)).

## New recipes & examples

[en/recipes.md](../en/recipes.md) collects six production patterns —
support relay with edit sync, non-stranding admin panels, private-only
panels with reply-only groups, delivery debugging, keyboard hygiene, Mini
App entry points. Runnable scenarios live in `examples/`
(`PanelBot`, `RelayEditSyncBot`, `ConversationFlow`, `KeyboardBuilder`,
`MediaFiles`) plus update fixtures in `examples/fixtures/`.

## Upgrade notes

- Set `LOG_API_TRACE=1` when delivery debugging matters; keep it off for
  quiet high-volume logs.
- After editing `.env` by hand, keep it readable by the FPM user
  (`chown www:www .env`) — a 640 root-owned `.env` shows up as
  "BOT_TOKEN is not set" HTTP 500s.
- New docs: `media.md`, `menu-flows.md`, `recipes.md` (en/fa full; this
  page summarizes for other languages).
