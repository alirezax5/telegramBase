# Multi-bot — exécuter deux bots dans un seul processus

TelegramBase prend en charge le fonctionnement multi-bot à deux niveaux : la
[librairie `telegramBotPhp`](https://github.com/alirezax5/telegramBotPhp) fournit les
primitives (instances indépendantes, pile de tokens, appels bruts), et le framework ajoute un
registre **`BotManager`** pour que les plugins puissent atteindre les bots secondaires par nom.

Les deux scénarios demandés par le propriétaire ont été vérifiés en conditions réelles (2026-10-11) avec deux vrais bots :

| Scénario | Ce qui se passe | Résultat vérifié |
|---|---|---|
| **A — vérification d'adhésion via le bot n°2** | l'utilisateur demande au bot n°1 ; le bot n°1 exécute `getChatMember()` **avec le token du bot n°2** | `ok=true`, `status=member`, 13 ms |
| **B — envoi délégué** | l'utilisateur demande au bot n°1 ; le bot n°1 fait **envoyer le message par le bot n°2**, dans le processus du bot n°1 | livré, 239 ms |

---

## Enregistrer des bots supplémentaires : `BOT_EXTRA_BOTS`

Ajoutez la variable d'env avant le démarrage (dans `.env` ou l'environnement du processus) :

```dotenv
BOT_EXTRA_BOTS="second:398172019:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

Règles de format :

- les entrées sont **séparées par des virgules** ; chaque entrée est `name:token` ;
- coupure sur le **premier** deux-points uniquement (un token de bot contient lui-même un deux-points) ;
- le nom `main` est réservé au bot primaire et ignoré dans cette liste ;
- les entrées mal formées sont journalisées (avertissement) et ignorées — le démarrage n'échoue jamais.

`BotManager` est un singleton créé à la première utilisation ; le bot par défaut (`main`) est construit
à partir de `Config::bot()` (c'est-à-dire `BOT_TOKEN`), puis les bots supplémentaires sont enregistrés.

## Utiliser les bots depuis un plugin

```php
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

class MultiBotPlugin implements PluginInterface
{
    public function getPriority(): int { return 1; }

    public function onMessage($message, Telegram $tg): void
    {
        if ($message->text !== '/inchannel') {
            return;
        }

        $bm  = BotManager::getInstance();
        $userId = $tg->fromId();
        $chatId = $tg->chatId();

        // Scenario A — membership check executed by bot #2's identity
        if ($bm->has('second')) {
            $member = $bm->get('second')->getChatMember('@mychannel', $userId);
            $status = $member->result->status ?? 'left';   // member|administrator|creator|left|kicked
            $tg->sendMessage($chatId, "وضعیت عضویت شما (چک توسط ربات ۲): {$status}");
        } else {
            $tg->sendMessage($chatId, 'ربات دوم ثبت نشده — BOT_EXTRA_BOTS را تنظیم کنید');
        }

        // Scenario B — bot #1 sends AS bot #2 (token stack on the main instance)
        if ($bm->has('second')) {
            $tg->switchToken($bm->get('second')->getToken());
            try {
                $tg->sendMessage($chatId, 'این پیام با هویت ربات ۲ ارسال شد (داخل پروسه ربات ۱)');
            } finally {
                $tg->restoreToken();
            }
        }
    }
}
```

## API de `BotManager`

| Méthode | Comportement |
|---|---|
| `getInstance(): BotManager` | accesseur singleton |
| `get(?string $name): Telegram` | renvoie le bot nommé ; **les noms inconnus retombent sur `main`** (avertissement journalisé) |
| `has(string $name): bool` | le nom est-il enregistré ? — **vérifiez toujours avant un `get()` d'un bot non par défaut** |
| `add(string $name, Telegram $bot): void` | enregistre/écrase un bot à l'exécution (l'écrasement journalise un avertissement) |
| `names(): string[]` | tous les noms enregistrés (`main` en premier) |
| `setDefaultToken(string $token): Telegram` | remplace le token du bot **main** globalement — affecte chaque appelant |

## Pourquoi `has()` compte

`get('nom-faute')` ne lève pas d'exception — il renvoie silencieusement le bot **main**. Un
`getChatMember()` destiné au bot n°2 serait alors exécuté avec l'identité du bot n°1 et
renverrait `chat member not found` ou pire, une réponse fausse mais plausible. Protégez chaque
accès non par défaut avec `has()`.

## Gestion des tokens : plusieurs bots dans un seul fichier

Un déploiement (un fichier d'entrée + un `.env`) peut servir un nombre quelconque
d'identités de bots. Trois stratégies sont prises en charge — classées par ordre
de recommandation :

| # | Stratégie | Emplacement des tokens | Idéal pour | Vérifié |
|---|---|---|---|---|
| 1 | **Registre statique** — `BOT_TOKEN` + `BOT_EXTRA_BOTS` dans `.env` | un `.env`, paires `name:token` | un ensemble fixe de bots déployés ensemble | 23/23 vérifications (parse / identity / degrade / scale) |
| 2 | **Registre dynamique** — `BotManager::add($name, new Telegram($token))` à l'exécution | votre BD/panneau de configuration ; transmis au processus | bots provisionnés par un panneau d'admin, bots par locataire | vérifications d'identité + envoi délégué |
| 3 | **Bots gérés** — tokens enfants récupérés à la volée auprès de Telegram (section suivante) | **nulle part sur disque** | un bot gestionnaire créant des bots enfants pour le compte des utilisateurs | suite TM-2, 8/8 |

Règles prouvées par la suite de tests en conditions réelles (2026-10-11, deux vrais bots + entrées synthétiques) :

- les entrées mal formées de `BOT_EXTRA_BOTS` sont journalisées et **ignorées — le démarrage n'échoue jamais** ;
- un token mort/révoqué échoue proprement (`ok=false`, `Unauthorized`) et n'empoisonne jamais
  les autres bots : `get('dead')` est en erreur, `get('alive')` et `main` restent sains ;
- `main` est réservé ; les noms en doublon se résolvent en « dernier gagnant » avec un avertissement ;
- les deux-points du token lui-même survivent à la coupure `name:token` (premier deux-points uniquement) ;
- isolation d'identité : le `getMe()` de chaque instance enregistrée correspond au préfixe d'id de
  son token ; la pile de tokens est propre à chaque instance (un switch/restore sur `main` laisse
  `second` intact) ;
- les recherches dans le registre sont en O(1) : 10 000 appels `get()` avec 32 bots enregistrés en ~1 ms.

Note webhook : dans les modes `webhook_*`, chaque update arrivant sur `webhook.php` est
traité sous l'identité du bot **main** (un seul `BOT_WEBHOOK_SECRET` partagé). Pour servir
plusieurs bots via des webhooks, vous n'avez **pas** besoin d'un répertoire par bot — le
schéma `secret_token` de la section suivante fait tourner **un nombre quelconque de bots
depuis un seul et même fichier webhook**, comme prouvé en conditions réelles.

## Un seul fichier webhook, plusieurs bots — le schéma `secret_token`

Vous n'avez PAS besoin d'un dossier par bot. **Un seul et même fichier webhook peut servir
un nombre quelconque de bots.** L'astuce réside dans le paramètre `secret_token` de
`setWebhook` :

1. Choisissez un **secret aléatoire différent pour chaque bot** (lettres, chiffres, `_` et
   `-` uniquement).
2. Enregistrez la **même URL de webhook** pour chaque bot, chacun avec son propre secret :
   `setWebhook?url=https://example.com/hook.php&secret_token=<this-bots-secret>`
3. À chaque update, Telegram renvoie ce secret dans l'en-tête
   `X-Telegram-Bot-Api-Secret-Token`.
4. Votre fichier lit l'en-tête, recherche le secret dans un petit registre
   (`secret → token de bot`) et traite l'update sous l'identité de ce bot.

```text
             ┌─ bot A: setWebhook(url=hook.php, secret=SECRET_A)
 Telegram ───┤
             └─ bot B: setWebhook(url=hook.php, secret=SECRET_B)
                         │   POST + header X-Telegram-Bot-Api-Secret-Token
                         ▼
               ┌───────────────────┐   SECRET_A → token A → agir en tant que bot A
               │     hook.php      │
               │  registre :       │
               │  secret → token   │   SECRET_B → token B → agir en tant que bot B
               └───────────────────┘
```

Pourquoi c'est la méthode recommandée pour faire tourner une ferme de bots :

- **le token du bot n'apparaît jamais dans une URL** — il ne peut pas fuiter dans les
  journaux d'accès, l'historique du navigateur ou les proxys ;
- le secret sert aussi de **vérification d'authenticité** — une requête sans le bon secret
  ne vient pas de Telegram ; répondez `403` et abandonnez-la ;
- **ajouter le bot n°N+1 coûte un appel `setWebhook` et une ligne de registre** — aucun
  nouveau fichier, aucun nouveau déploiement.

Preuve en conditions réelles (2026-10-11, deux vrais bots, UN seul fichier, trafic
utilisateur réel) :

| Événement | Identité routée | Résultat |
|---|---|---|
| `/start` réel sur le bot B | @instaroonxbot | a répondu, `ok:true` |
| appui réel sur le bouton inline du bot B (×2) | @instaroonxbot | `answerCallbackQuery` + réponse, `ok:true` |
| appui réel sur le bouton inline du bot A | @emojiCustom_bot | `answerCallbackQuery` + réponse, `ok:true` |
| POST synthétique avec secret A / secret B | le bon bot à chaque fois | a répondu, `ok:true` |

7/7 livraisons — chaque update a atteint le même fichier et chaque réponse portait la
bonne identité de bot. Le token du bot n'a jamais figuré dans une URL ou un en-tête.

Règles de durcissement pour la production :

- le secret **est la clé de routage** — il doit être unique par bot ;
- générez-le aléatoirement, 32 caractères ou plus parmi `[A-Za-z0-9_-]` (Telegram rejette
  les autres caractères) ; un secret de 2 caractères équivaut à l'absence de secret ;
- rejetez avec `403` les secrets inconnus ou absents avant de toucher à quoi que ce soit
  d'autre ;
- ne journalisez jamais les tokens ; journalisez au plus un court préfixe du secret ;
- la rotation d'un secret = relancer `setWebhook` pour ce bot (quelques secondes).

## Bots gérés (Managed Bots — Bot API 9.6)

La Bot API 9.6 de Telegram (2026-04-03) a ajouté les **Managed Bots** (bots gérés) : un bot avec
`can_manage_bots` (activé dans la Mini App @BotFather) peut voir d'autres bots créés et contrôlés
sous lui. Le token enfant n'a jamais besoin d'être stocké — le gestionnaire le récupère à la
volée avec `getManagedBotToken(user_id)`.

Prise en charge par le framework (v2.4.x) :

| Élément d'API (Bot API 9.6) | Surface du framework |
|---|---|
| `User.can_manage_bots` (getMe) | `$tg->callRaw('getMe')->result->can_manage_bots` |
| update `managed_bot` (`ManagedBotUpdated`) | dispatché vers `onManagedBot($managedBot, $tg)` — ajouté à la liste blanche des plugins |
| `KeyboardButtonRequestManagedBot` | `Keyboard::reply()->requestManagedBot($text, $requestId, $suggestedName, $suggestedUsername)` |
| `getManagedBotToken` / `replaceManagedBotToken` / `get·setManagedBotAccessSettings` | `BotManager::registerManagedBot()` / `refreshManagedBot()` / `managedBotAccessSettings()` |
| champ `ManagedBotCreated` du message | visible dans la charge utile au sein de `onMessage` |

Flux de bout en bout (esquisse de plugin) :

```php
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Button\Keyboard;

// 1) offer creation (private chats only)
$tg->sendMessage($chatId, 'Create your own bot:', [
    'reply_markup' => Keyboard::reply()
        ->requestManagedBot('➕ Create my bot', 1, 'MyHelperBot', 'my_helper_bot')
        ->toArray(),
]);

// 2) the user completes the flow in Telegram → managed_bot update arrives
public function onManagedBot($managedBot, $tg): void
{
    // fetches the child token live (getManagedBotToken) and registers it
    BotManager::getInstance()->registerManagedBotFromUpdate($managedBot);
}

// 3) use the child bot — registered under its bot id
BotManager::getInstance()->get((string) $managedBot->bot->id)
    ->sendMessage($chatId, 'hello from the child bot');
```

Prérequis (actions du propriétaire, une seule fois) :

1. Activez la **gestion des autres bots** pour le bot gestionnaire dans la Mini App
   @BotFather — jusque-là `getMe` indique `can_manage_bots: false` et la
   demande de création échoue.
2. La création du bot enfant elle-même se déroule toujours du côté de Telegram
   (consentement de l'utilisateur) ; le framework reçoit seulement l'update
   `managed_bot` et récupère le token.

Hygiène des tokens : les tokens gérés ne vivent qu'en mémoire (le registre) — rien n'est
écrit dans `.env` ni dans les journaux. La rotation d'un token enfant via
`BotManager::refreshManagedBot($userId, $name)` révoque immédiatement l'ancien token
et réenregistre le nouveau.

### Managed Bots — de bout en bout, en conditions réelles (2026-10-11)

Après que le propriétaire a activé la **gestion des autres bots** dans la Mini App
@BotFather, `getMe` est passé à `can_manage_bots: true` sur les deux bots de test et le
flux complet a été exercé en conditions réelles :

- le bouton de création construit par `Keyboard::reply()->requestManagedBot(...)` a été
  envoyé dans un chat privé — Telegram a accepté la forme de clavier du framework ;
- détail de champ bon à savoir : pour `KeyboardButtonRequestManagedBot`, le `request_id`
  doit être un **Number** (contrairement à `request_chat` / `request_users`, où il
  s'agit d'une String — Telegram rejette une chaîne avec `Bad Request: can't parse
  KeyboardButton: Field "request_id" must be a valid Number`) ;
- une fois que l'utilisateur a rempli le formulaire de création, le gestionnaire reçoit
  un update `managed_bot` et récupère le token enfant à la volée avec
  `getManagedBotToken(user_id)` — la démo a ensuite fait **parler le bot enfant dans le
  chat du propriétaire** avec son propre token fraîchement récupéré (zéro sur disque).

L'activation des mêmes options de la Mini App a aussi fait basculer, sur les deux bots
de test : `supports_guest_queries`, `has_topics_enabled`,
`supports_join_request_queries`, `can_connect_to_business` → tous à `true`.

- Forme réelle de l'update (capturée le 2026-10-11) : `managed_bot.user` = la personne qui a créé le bot enfant (un utilisateur ordinaire), `managed_bot.bot` = le bot enfant lui-même (`{id, is_bot: true, username, ...}`). Passez `managed_bot.bot.id` à `getManagedBotToken` — l'id du créateur déclenche `Bad Request: user is not a bot`. Dans la démo, le token du bot enfant a été récupéré en direct et l'enfant a livré un vrai message à son créateur — zéro token sur disque.

### Où conserver les tokens et les secrets

| Stockage | Quand c'est adapté | Points d'attention |
|---|---|---|
| **`.env`** (`BOT_TOKEN` + `BOT_EXTRA_BOTS`) | un petit ensemble fixe de bots | lisible uniquement par root ; toute modification exige un redémarrage |
| **table de registre en BD** (`bots` : id, name, token, secret, status) | bots ajoutés/retirés à l'exécution, panneaux d'admin | chiffrez la colonne du token au repos (ex. sodium) ; mettez le registre en cache pour qu'un update ≠ une requête BD |
| **Managed Bots (zéro sur disque)** | un bot gestionnaire créant des enfants pour les utilisateurs | exige `can_manage_bots` ; token récupéré à la volée via `getManagedBotToken` |
| **secrets dérivés (HMAC)** — rien n'est stocké | vous contrôlez une clé maîtresse | `secret = base64url(hmac_sha256(master_key, 'bot:' . bot_id))` ; la rotation de la clé maîtresse implique de réenregistrer chaque webhook |

Recommandation pour une ferme de bots en croissance : **registre en BD (token chiffré
au repos) + recherche en cache + routage `secret_token`**, et **Managed Bots** pour les
enfants créés par les utilisateurs. Gardez `.env` pour le premier bot gestionnaire
uniquement.

### Garder les données de chaque bot séparées

Quand plusieurs bots partagent un même déploiement, les données de chaque bot doivent
rester exclusives à ce bot. Placez **chaque couche de stockage dans l'espace de noms de
l'id de bot** (`b{bot_id}`) :

| Couche | Règle |
|---|---|
| Cache | préfixe de clé `b{bot_id}:` (définissez `CACHE_PREFIX` par bot au démarrage) |
| Sessions | étendue par **id de bot + id d'utilisateur** — la même personne qui parle à deux bots constitue deux sessions différentes |
| offset getUpdates | **un fichier/une clé d'offset par bot** — un offset partagé nourrirait un bot avec les updates d'un autre |
| File | un nom de file par bot (`tgbase_b{bot_id}_updates`) ou étiquetez les charges utiles avec `bot_id` |
| Stockage de fichiers | `data/bots/{bot_id}/…` |
| Journaux | un fichier de journal par bot (`logs/bot_{id}.log`) |
| Base de données | les tables partagées reçoivent une colonne `bot_id` ; isolation réelle = une BD séparée par bot |

Le contrôleur frontal du webhook résout d'abord `secret → bot`, puis démarre le
framework avec le token et l'espace de noms de ce bot — toute lecture/écriture
ultérieure reste alors automatiquement dans le bac à sable propre à ce bot.

## Sémantiques de livraison

Les appels multi-bot empruntent le même client HTTP que tout autre appel API :

- retries/backoff et gestion de `retry_after` s'appliquent par instance ;
- la pile de tokens est un **état par instance** — dans les workers de file chaque update est
  traité par un dispatch frais, donc les paires switch/restore vivent dans la durée de vie d'un seul
  update ; ne comptez jamais sur l'état de la pile entre les updates ;
- dans les modes `webhook_*` les mêmes règles s'appliquent au sein de la seule requête FPM.

## Harnais de vérification en conditions réelles

`integration/TestBot/Plugin/MultiBotTestPlugin.php` (harnais de test, chats privés) :

- `/multibot` — suite complète : identité, isolation, envoi croisé (Scénario B),
  adhésion via le bot n°2 (Scénario A), switch/restore de la pile de tokens ;
- `/mbchannel <chat>` — `getChatMember` du bot n°2 sur un `@channel`/id de chat explicite
  (le bot n°2 doit être admin/membre de ce chat).

Journal de preuves : `runtime/p331/multibot.log` — dernière exécution réelle
(2026-10-11 12:26:19) : identité ✔, isolation ✔, envoi croisé ✔ (239 ms),
adhésion ✔ (13 ms), pile de tokens ✔ — **5/5 PASS en 393 ms**.

Exécutions supplémentaires du harnais (2026-10-11, runtime réel post-synchronisation) :

- **Suite TM-1 de gestion des tokens** — 23/23 PASS sur quatre cas :
  `parse` (7), `identity` (8, deux vrais bots), `degrade` (5, token révoqué),
  `scale` (3, 32 bots / 10k recherches).
- **Suite TM-2 des Managed Bots** — 8/8 PASS : le `getMe` en conditions réelles expose
  `can_manage_bots` ; `getManagedBotToken` / `getManagedBotAccessSettings`
  atteignables avec des contrats d'erreur propres ; un update `managed_bot`
  synthétique dispatche `before → onManagedBot` ; un `registerManagedBot()`
  invalide lève une exception proprement sans empoisonner le registre ; la
  forme du clavier de `requestManagedBot()` correspond à
  `KeyboardButtonRequestManagedBot`.
