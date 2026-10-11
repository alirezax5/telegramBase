# Sessions — SessionManager

Un état par chat qui survit entre les messages : assistants de formulaire multi-étapes, « tapez 1 pour… », paniers d'achat, bascules de mode par utilisateur. Les sessions sont stockées via [CacheManager](caching.md), indexées par l'id du chat, avec un TTL afin que les conversations inactives expirent automatiquement.

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

Sous le capot, toute la charge utile est une seule entrée de cache :

| Clé de cache | `SESSION_PREFIX` (défaut `tgbase:session:`) + id du chat |
|---|---|
| TTL | `SESSION_TTL` (défaut 3600 s, plancher 60 s) — **chaque `set()` re-persiste et réinitialise la fenêtre** |
| Charge utile | `['id' => sessionId, 'data' => […], 'last_activity' => ts]` |

## Un flux multi-étapes

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

Chaque utilisateur dispose d'une machine à états indépendante parce que la session est indexée par l'id du chat.

## Quel driver de cache me faut-il ?

- **Démon de polling (`update_direct` / fetcher `update_queue`) :** un seul processus PHP de longue durée — même `array` garde l'état vivant entre les messages. Mais l'état meurt au redémarrage.
- **Webhook (`webhook_*`) et workers de file :** l'état doit franchir les frontières de processus → `file`, `redis` ou `memcached`.

Vérifié en conditions réelles : une session écrite dans le processus A (`sid d5dfbf11…`, étape + panier) a été relue dans un processus B frais sur `file`, `redis` et `memcached` — **même id de session, charge utile identique** ; sur `array` le processus B n'a rien vu (documenté).

## Notes de sécurité

- `regenerate()` après tout changement de privilège (login) pour prévenir la fixation de session — l'id est un aléatoire frais de 32 hexadécimaux.
- Les sessions ne portent aucune identité qui leur soit propre : l'id du chat est la clé. Dans les chats de groupe, chaque membre partage la session du chat — pour un état par utilisateur dans les groupes, indexez plutôt par `$tg->fromId()` dans vos propres clés de cache.
- Les sessions longuement inactives expirent en silence (`SESSION_TTL`) ; concevez vos flux pour tolérer une session absente (`SessionManager::get() === null` → recommencer).

## Commandes de test (integration/TestBot)

`/session` affiche id + clés + données · `/session-set <k> <v>` · `/session-get <k>` · `/session-clear`. La paire set/get a été exécutée à travers le vrai pipeline de plugins dans deux processus séparés dans le cadre de la vérification P-CACHE.
