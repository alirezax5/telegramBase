# Sessões — SessionManager

Estado por chat que sobrevive entre mensagens: assistentes de formulário de múltiplas etapas, "pressione 1 para…", carrinhos de compras, alternadores de modo por usuário. As sessões são armazenadas por meio do [CacheManager](caching.md), chaveadas pelo id do chat, com um TTL para que conversas ociosas expirem automaticamente.

```php
use alirezax5\TelegramBase\App\Session\SessionManager;
```

## API

```php
// Start (or resume) a session — creates one when none exists
$session = SessionManager::start($chatId);

$session->set('step', 'waiting_email');        // persists immediately
$session->setMany(['cart' => ['item1'], 'lang' => 'fa']);
$step = $session->get('step');                  // null when unset
$step = $session->get('step', 'default');       // with fallback
$session->has('step');                          // true even when value is null
$session->all();                                // whole payload as array
$session->forget('cart');                       // remove one key
$session->regenerate();                         // new random session id (after login!)
$session->touch();                              // refresh last-activity
$session->destroy();                            // delete entirely

// Read WITHOUT creating (null when absent — use in read-only paths)
$existing = SessionManager::get($chatId);
SessionManager::has($chatId);
SessionManager::flush($chatId);                 // delete
```

Por baixo dos panos, todo o payload é uma única entrada de cache:

| Chave de cache | `SESSION_PREFIX` (padrão `tgbase:session:`) + chat id |
|---|---|
| TTL | `SESSION_TTL` (padrão 3600 s, piso 60 s) — **cada `set()` re-persiste e reinicia a janela** |
| Payload | `['id' => sessionId, 'data' => […], 'last_activity' => ts]` |

## Um fluxo de múltiplas etapas

```php
public function onMessage($message, Telegram $tg): void
{
    $text = trim($message->text ?? '');
    $chatId = $tg->chatId();

    $session = SessionManager::start($chatId);

    switch ($session->get('step')) {
        case null:
            if ($text === '/register') {
                $session->set('step', 'ask_email');
                $resp = $tg->sendMessage($chatId, 'ایمیلت را بفرست:');
                tg_retryable($resp, 'sendMessage');
            }
            return;

        case 'ask_email':
            if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $resp = $tg->sendMessage($chatId, 'ایمیل معتبر نیست — دوباره بفرست:');
                tg_retryable($resp, 'sendMessage');
                return;                              // stay on the same step
            }
            $session->setMany(['email' => $text, 'step' => 'done']);
            $resp = $tg->sendMessage($chatId, "ثبت شد: {$text}");
            tg_retryable($resp, 'sendMessage');
            return;
    }
}
```

Cada usuário tem uma máquina de estados independente porque a sessão é chaveada pelo id do chat.

## De qual driver de cache eu preciso?

- **Daemon de polling (`update_direct` / fetcher do `update_queue`):** um único processo PHP de longa duração — até o `array` mantém o estado vivo entre mensagens. Mas o estado morre no reinício.
- **Webhook (`webhook_*`) e workers de fila:** o estado precisa cruzar fronteiras de processo → `file`, `redis` ou `memcached`.

Verificado ao vivo: uma sessão escrita no processo A (`sid d5dfbf11…`, step + carrinho) foi lida de volta em um processo B novo em `file`, `redis` e `memcached` — **mesmo id de sessão, payload idêntico**; no `array` o processo B não viu nada (documentado).

## Notas de segurança

- `regenerate()` após qualquer mudança de privilégio (login) para prevenir session fixation — o id é um aleatório novo de 32 caracteres hex.
- As sessões não carregam identidade própria: o id do chat é a chave. Em chats de grupo, todos os membros compartilham a sessão do chat — para estado por usuário em grupos, chaveie por `$tg->fromId()` nas suas próprias chaves de cache.
- Sessões ociosas por muito tempo expiram em silêncio (`SESSION_TTL`); desenhe os fluxos para tolerar uma sessão ausente (`SessionManager::get() === null` → começar de novo).

## Comandos de teste (integration/TestBot)

`/session` despeja id + chaves + dados · `/session-set <k> <v>` · `/session-get <k>` · `/session-clear`. O par set/get foi executado pelo pipeline real de plugins em dois processos separados como parte da verificação P-CACHE.
