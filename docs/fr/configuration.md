# Configuration — la référence complète de `.env`

Copiez [.env.example](../../.env.example) vers `.env` et éditez. Les valeurs affichées sont les valeurs par défaut. La validation au démarrage (`EnvironmentValidator`) vérifie les valeurs critiques et échoue avec un message lisible plutôt qu'un plantage mystérieux.

> Configurer uniquement par de vraies variables d'environnement (systemd `Environment=`, `docker -e`, k8s) est tout aussi pris en charge — `.env` est optionnel. Quand les deux existent, l'environnement du processus l'emporte (Dotenv est immuable).

## Bot

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `BOT_TOKEN` | — | **obligatoire** ; fourni par @BotFather. Format validé ; jamais renvoyé en écho dans les messages d'erreur. `TGBASE_SKIP_BOT_VALIDATION=1` permet aux commandes CLI réservées à la DB de s'exécuter sans token |
| `BOT_MODE` | `update_direct` | l'un des six modes — voir [bot-modes.md](bot-modes.md) |
| `BOT_API_URL` | `https://api.telegram.org` | surcharge pour des serveurs Bot API locaux |
| `POLLING_TIMEOUT` | `30` | secondes de long-poll par appel `getUpdates` |
| `POLLING_RESTART_INTERVAL` | `100` | séries par auto-redémarrage (hygiène mémoire ; `0` désactive) |
| `BOT_WEBHOOK_URL` | — | URL de l'endpoint webhook (HTTPS) |
| `BOT_WEBHOOK_SECRET` | — | secret pour `X-Telegram-Bot-Api-Secret-Token` ; utilisez 32+ caractères hexadécimaux aléatoires (`openssl rand -hex 32`) |
| `WEBHOOK_CHECK_IP` | `true` | valide `REMOTE_ADDR` contre les sous-réseaux de Telegram — mettez `false` derrière Cloudflare/proxys (le secret devient alors votre couche d'authentification) |
| `DATA_DIR` | `AppData` | état à l'exécution (offset, fichiers de file, verrous) |

## Plugins

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `PLUGINS_DIR` | `/Plugin/` | relatif à la base du projet ; chemins absolus pris en charge |
| `PLUGIN_CACHE_ENABLED` | `true` | met en cache les plugins découverts entre les démarrages |
| `PLUGINS_RELOAD_INTERVAL` | `0` | secondes entre les ré-analyses de découverte (confort de dev) |

## Langue & boutons

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `LANG_DRIVER` | `json` | driver des fichiers de traduction — ⚠️ les fichiers fournis sont des tableaux PHP, donc `.env.example` définit `php` ; une discordance retombe silencieusement sur le nom de la clé (voir [i18n.md](i18n.md)) |
| `DEFAULT_LANG` | `fa` | code de langue de repli |
| `LANG_DIR` | `/Language` | emplacement des fichiers de traduction |
| `LANG_CACHE_TTL` | `60` | secondes de cache des traductions |
| `BUTTONS_DIR` | `/Button` | fichiers de définition des libellés de clavier |
| `BUTTONS_FILE` | `btn.php` | nom du fichier de base |
| `BUTTONS_CACHE_TTL` | `60` | secondes de cache des libellés |
| `BUTTONS_CACHE_ENABLED` | `true` | mettez `false` pour relire toujours les définitions de boutons depuis les fichiers |

## File d'attente

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `QUEUE_SAVE_TYPE` | `json` | `json` \| `redis` \| `rabbitmq` \| `memcached` |
| `QUEUE_PATH` | `AppData/queue` | répertoire de stockage du driver json |
| `QUEUE_MAX_SIZE` | `0` | plafond de file bornée (0 = illimité) ; file pleine → refus + journalisation |
| `QUEUE_MAX_RETRIES` | `3` | retries d'opération broker par push/pop |
| `QUEUE_RETRY_DELAY` | `2` | secondes entre les retries broker |
| `QUEUE_REDIS_KEY` / `QUEUE_MEMCACHED_KEY` | `bot_updates` | clé de liste par driver |
| `RABBITMQ_QUEUE` | `bot_updates` | nom de la file RabbitMQ |

### Connexion Redis

| Clé | Défaut |
|-----|---------|
| `REDIS_HOST` | `127.0.0.1` |
| `REDIS_PORT` | `6379` |
| `REDIS_PASSWORD` | *(vide)* |
| `REDIS_DATABASE` | `0` |
| `REDIS_TIMEOUT` | `60` |

### Connexion RabbitMQ

| Clé | Défaut |
|-----|---------|
| `RABBITMQ_HOST` | `127.0.0.1` |
| `RABBITMQ_PORT` | `5672` |
| `RABBITMQ_USER` | `guest` |
| `RABBITMQ_PASSWORD` | `guest` |
| `RABBITMQ_VHOST` | `/` |

### Connexion Memcached

| Clé | Défaut |
|-----|---------|
| `MEMCACHED_HOST` | `127.0.0.1` |
| `MEMCACHED_PORT` | `11211` |
| `MEMCACHED_USERNAME` / `MEMCACHED_PASSWORD` | *(vide — SASL)* |

## File de retry

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `RETRY_ENABLED` | `true` | interrupteur principal |
| `RETRY_QUEUE_DRIVER` | `json` | `json` \| `redis` |
| `RETRY_MAX_ATTEMPTS` | `3` | puis abandon + journalisation |
| `RETRY_BASE_DELAY` | `2` | secondes ; doublées à chaque tentative ; remplacé par le `retry_after` de Telegram |
| `RETRY_MAX_SIZE` | `1000` | plafond de file bornée |
| `RETRY_REDIS_KEY` | `bot_retry_queue` | quand le driver est redis |

## Cache & session

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `CACHE_DRIVER` | `array` | `array` \| `file` \| `redis` \| `memcached` — les drivers non pris en charge/indisponibles retombent sur `array` avec un avertissement journalisé |
| `CACHE_PREFIX` | `tgbase_` | préfixe de clé pour tous les stores de cache |
| `CACHE_PATH` | temp système | répertoire du driver file |
| `CACHE_TTL` | `300` | TTL par défaut en secondes quand `put()`/`remember()` ne reçoivent pas de TTL explicite |
| `SESSION_TTL` | `3600` | durée de vie de session en secondes |
| `SESSION_PREFIX` | `tgbase:session:` | préfixe des clés de session |

## Base de données

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `DATABASE_ENABLE` | `false` | conditionne tout le bootstrap DB (la CLI fonctionne sans DB) |
| `DB_DRIVER` | `mysql` | n'importe quel driver PDO (`sqlite` pour le dev local) |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | — | |

## Multi-bot

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `BOT_EXTRA_BOTS` | — | bots supplémentaires dans un même processus — `name1:token1,name2:token2` (coupure au premier deux-points) ; voir [multi-bot.md](multi-bot.md). Fonctionne dans `.env` et dans l'environnement du processus |

## Planificateur & cron

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `SCHEDULER_LOCK_TTL` | `300` | secondes de verrou de tâche |
| `SCHEDULER_LOCK_TABLE` | `task_locks` | table du backend de verrouillage |
| `SCHEDULE_FILE` | `bin/tasks.php` | fichier de tâches utilisé par `bin/scheduler.php` (surcharge de chemin) |
| `CRON_WORKER` | `10` | slots concurrents pour `cronjob_queue` (verrou fichier) |
| `CRON_MAX_TIME` | `50` | secondes max par tick cron pour le worker |

## Journalisation

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `LOG_ENABLED` | `true` | interrupteur principal |
| `LOG_LEVEL` | `debug` | niveaux Monolog |
| `LOG_DIR` | `./logs` | **noms relatifs uniquement** — les chemins absolus et `..` sont rejetés (protection path traversal) |
| `LOG_FILE` | `log.txt` | |

## Admins

| Clé | Défaut | Remarques |
|-----|---------|-------|
| `ADMINS_WHITELIST` | — | ids d'utilisateurs Telegram séparés par des virgules, utilisé par `AdminGuardMiddleware` |

## Notes de sécurité

- Ne commitez jamais `.env` ; le `.gitignore` fourni l'exclut (`*.secret` et `secrets.json` aussi).
- Les messages d'erreur d'`EnvironmentValidator` nomment la clé manquante, jamais sa valeur.
- Les secrets webhook sont comparés avec `hash_equals()` (temps constant).
- Il n'existe délibérément **aucun bouton `VERIFY_TLS=false`** — la vérification TLS vers api.telegram.org est toujours active.
