# Modes du bot — les six façons d'exécuter un bot TelegramBase

`BOT_MODE` dans `.env` est la valeur de configuration la plus importante. Elle décide *comment les updates arrivent* (polling vs webhook) et *comment ils sont traités* (inline vs en file).

## Les modes

| Mode | Source des updates | Traitement | Processus à lancer |
|------|--------------|------------|-------------------|
| `update_direct` *(par défaut)* | long-poll `getUpdates` | inline, même processus | `php bot.php` sous un superviseur |
| `update_queue` | long-poll `getUpdates` | asynchrone via file | `php queue.php` (fetcher) + un ou plusieurs `php bot.php` (workers) |
| `webhook_direct` | POST webhook Telegram | inline, dans la requête HTTP | serveur web uniquement (`webhook.php`) |
| `webhook_queue` | POST webhook Telegram | asynchrone via file | serveur web (`webhook.php`) + worker(s) `php bot.php` |
| `cronjob_update` | `getUpdates` (polling) | inline, **démon auto-réparant** — le premier tick démarre une boucle sans fin, les ticks suivants ne font rien tant qu'il vit | crontab `* * * * * php bin/scheduler.php` |
| `cronjob_queue` | file uniquement (pas de polling Telegram) | lot asynchrone — chaque tick consomme pendant au plus `CRON_MAX_TIME` (50 s par défaut), puis sort | crontab + slots `CRON_WORKER` |

Modèle mental :

- Les modes **`update_*` / `cronjob_*`** récupèrent eux-mêmes les updates (votre serveur parle à Telegram).
- Les modes **`webhook_*`** reçoivent les updates (Telegram parle à votre serveur).
- Les modes **`*_direct`** traitent l'update là où il arrive — le plus simple, et suffisant pour la plupart des bots.
- Les modes **`*_queue`** découplent la réception du traitement — utilisez-les quand les handlers sont lents (uploads, API externes) ou quand vous voulez passer à l'échelle horizontalement.
- Les modes **`cronjob_*`** fonctionnent depuis un simple crontab — ni superviseur, ni systemd, ni Docker requis.

## Les modes cron en détail

Les deux modes cron partagent le même mécanisme de sécurité : à chaque tick, le framework tente d'acquérir un **slot de worker** — un `flock()` exclusif sur un fichier de verrou sous `data/cron-slots/` (`WorkerLocker`). Si tous les slots `CRON_WORKER` sont occupés, le tick journalise `All N worker slots busy` et sort en quelques millisecondes — des ticks concurrents ne peuvent jamais traiter deux fois quoi que ce soit.

### `cronjob_update` — un démon auto-réparant, pas un lot

Malgré son nom, un tick ne lance **pas** « un lot ». Le premier tick acquiert le slot et démarre la même boucle de polling sans fin que `update_direct` (`set_time_limit(0)` + long-poll), et le tick ne se termine jamais — c'est voulu :

1. Tick 1 → le démon démarre, le slot reste verrouillé.
2. Ticks 2..N → trouvent le slot occupé, sortent en quelques millisecondes (mesuré : ~30–45 ms). C'est *normal*, pas une erreur.
3. Le démon meurt (plantage, OOM, redémarrage) → le prochain tick cron démarre automatiquement un nouveau démon. **Cron agit comme superviseur.**

Vérifié en conditions réelles : un démon démarré à 09:24:01 a traité un vrai message inline, a survécu à plusieurs frontières de minute, s'est arrêté proprement sur SIGTERM en moins d'un cycle de long-poll (~12 s — le signal d'arrêt est honoré dès que le long-poll en cours retourne), a relâché le verrou, et le tick suivant l'a relancé immédiatement.

### `cronjob_queue` — un worker de lot borné

Chaque tick acquiert un slot et consomme depuis la file pendant au plus `CRON_MAX_TIME` secondes (50 par défaut), puis sort proprement avec `Limited worker finished: N updates`. Rien ne poll Telegram dans ce mode — la file doit être alimentée par un producteur (`webhook_queue`, ou votre propre exécution de `queue.php`).

Vérifié en conditions réelles : un vrai message poussé dans RabbitMQ à 09:22:49 a été dépilé et dispatché par le tick qui était déjà dans sa fenêtre de 50 secondes (`onMessage` du plugin à 09:22:49.6, `Limited worker finished: 1 updates` à 09:22:51), et la file est retournée à 0/0. Les sémantiques de livraison sont héritées du driver de file — at-least-once avec RabbitMQ depuis la v2.4.0.

### Configuration cron

| Env | Défaut | Signification |
|-----|---------|---------|
| `CRON_WORKER` | `1` | nombre de slots de workers concurrents (fichiers flock dans `data/cron-slots/`) |
| `CRON_MAX_TIME` | `50` | fenêtre de lot de `cronjob_queue` en secondes — gardez-la sous l'intervalle de votre cron pour que les ticks ne se chevauchent pas |

## Points d'entrée et garde SAPI

| Fichier | S'exécute en | Rôle |
|------|---------|---------|
| `bot.php` | CLI | polling (`update_direct`), consommateur de file (`*_queue`), callbacks cron |
| `queue.php` | CLI | *producteur* de file — long-poll Telegram et pousse les updates dans la file (`update_queue`) |
| `webhook.php` | HTTP | reçoit les POST webhook de Telegram (`webhook_*`) |
| `bin/tgbase` | CLI | diagnostics, scaffolding, migrations |
| `bin/scheduler.php` | CLI | exécutant des ticks cron (`cronjob_*` + tâches planifiées) |

**Important — la garde SAPI (v2.4.0) :** une requête HTTP ne peut servir qu'un travail de type webhook. Si `webhook.php` est exécuté alors que `BOT_MODE` est un mode CLI uniquement, le framework refuse de lancer une boucle infinie dans votre worker web :

| `BOT_MODE` sur `webhook.php` | Comportement |
|------------------------------|-----------|
| `webhook_direct` | valider → traiter inline → `200` |
| `webhook_queue` | valider → mettre en file → `200` |
| `update_direct` | valider → traiter inline → `200` (un avertissement est journalisé — vous vouliez probablement `webhook_direct`) |
| `update_queue`, `cronjob_*` | **HTTP 500** avec un message explicatif — pas de boucle, pas de suspension |

Contexte : avant l'existence de cette garde, pointer le webhook vers un bot configuré pour `update_direct` lançait silencieusement une boucle `getUpdates` infinie dans le worker PHP-FPM. Telegram ne recevait jamais de réponse (`Read timeout expired` → retries sans fin) et FPM tuait le worker à `request_terminate_timeout` (502). La garde transforme cette suspension silencieuse en un échec bruyant et rapide. Vous pouvez tester directement la logique de détection avec `Core::isHttpSapi($sapi)`.

> Règle simple : **les modes CLI s'exécutent depuis le terminal, les modes webhook depuis le web.** Le framework fait respecter cette frontière pour vous.

## Sémantiques de livraison en un coup d'œil

| Chemin | Garantie | Fenêtre de duplication |
|------|-----------|------------------|
| `webhook_direct` | at-least-once (Telegram retente jusqu'à obtenir un 2xx) | plantage *après* `handle()` mais avant le 200 |
| `update_direct` | at-least-once (l'offset n'avance qu'après le traitement) | plantage *après* les effets de bord mais avant l'écriture de l'offset |
| `webhook_queue` (côté producteur) | l'update est dans le broker dès que vous avez obtenu votre 200 | retries d'enfilement en cas d'échec du broker |
| consommateur de file — **RabbitMQ** | at-least-once (ack après traitement, v2.4.0) | plantage entre le traitement et l'ack |
| consommateur de file — Redis / JSON / Memcached | at-most-once (pop destructif — la tâche disparaît au moment du pop) | un plantage en cours de traitement perd la tâche |

Preuves complètes des tests de plantage et conseils d'idempotence : [reliability.md](reliability.md).

`cronjob_update` se comporte exactement comme `update_direct` (un message traité inline avant que l'offset n'avance) ; `cronjob_queue` se comporte exactement comme le consommateur de file de son driver (at-least-once avec RabbitMQ, at-most-once avec Redis/JSON/Memcached).

## Choisir un mode — conseils pratiques

- **Petits bots, tableaux de bord, prototypes** → `update_direct`. Un processus, zéro infrastructure.
- **Handlers lents (traitement de fichiers, API tierces), instances multiples** → `webhook_queue` ou `update_queue` avec Redis/RabbitMQ.
- **Hébergement mutualisé sans démons CLI** → `cronjob_update` (le crontab devient le superviseur) ou `webhook_direct`.
- **Vous voulez du exactly-once ?** Personne ne l'a. TelegramBase vous donne des chemins *at-least-once* qui ne perdent jamais d'updates, et des chemins *at-most-once* qui ne dupliquent jamais — choisissez selon le cas d'usage, et rendez vos handlers idempotents (voir [reliability.md](reliability.md)).
