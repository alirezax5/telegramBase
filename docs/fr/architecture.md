# Architecture — comment les pièces s'emboîtent

## La séquence de démarrage

Chaque point d'entrée (CLI ou web) construit un seul objet : `Core`.

```php
$core = new Core(CoreMode::FULL);   // FULL = boot plugins too
$core->run();
```

Le constructeur exécute la chaîne de démarrage, dans l'ordre :

| Étape | Classe | Ce qui se passe |
|------|-------|--------------|
| 1 | `Bootstrap::boot()` | Charge `.env` (via `EnvHandler`), valide l'environnement (`EnvironmentValidator`), prépare les chemins (`Paths`, `AppData/`), connecte le cache |
| 2 | `BotManager::getInstance()->get()` | Crée l'instance du SDK `Telegram` à partir de `BOT_TOKEN` (singleton — chaque composant partage une seule instance) |
| 3 | `PluginsBootstrap::boot()` *(FULL uniquement)* | Analyse `PLUGINS_DIR`, instancie les classes de plugins, trie par priorité, construit le registre `PluginHandler` |

`CoreMode::UPDATES_ONLY` (utilisé par `queue.php`) saute l'étape 3 — le fetcher se contente de pousser les updates dans la file, il ne dispatche jamais lui-même les plugins.

## Le flux d'un update

```
                 ┌──────────────────────────────────────────────┐
                 │                  Core::run()                 │
                 └──────────────────────────────────────────────┘
                                    │
              route by BOT_MODE + SAPI (CLI vs HTTP) — see bot-modes.md
                                    │
        ┌───────────────┬───────────┴──────────┬─────────────────┐
        ▼               ▼                      ▼                 ▼
   PollingLoop    WebhookDispatcher        QueueWorker       CronManager
   (getUpdates    (validate + dispatch     (pop from queue   (cron slots →
    in a loop)     one webhook payload)     + process)        poll/worker)
        │               │                      │
        └───────────────┴──────────┬───────────┘
                                   ▼
                              Processor::handle($update)
                                   │
                     normalize to object (shape-preserving —
                     list arrays like photo sizes stay arrays)
                                   │
                                   ▼
                       MiddlewarePipeline (optional)
                                   │
                                   ▼
                     PluginHandler::runAll($update, $telegram)
```

Points clés :

- **Un `Processor` par étape du pipeline.** Les chemins polling, webhook et file d'attente en construisent chacun un ; tous attachent le même pipeline de middleware que vous avez enregistré sur `Core`.
- **Normalisation qui préserve la forme.** Les updates qui arrivent sous forme de tableaux (depuis les drivers de file) sont convertis récursivement en objets — les tableaux *list* (tailles de photo, entités, lignes de clavier inline) restent des tableaux, les maps associatives deviennent des objets. Cela compte car Telegram modélise des champs comme `photo` sous forme de tableaux et les plugins font des `foreach` dessus.
- **Les échecs à l'intérieur de `handle()`** sont interceptés, journalisés avec le contexte de l'update et — quand l'erreur semble transitoire (`429`, `5xx`, timeout, erreur curl) — poussés dans la `RetryQueue` avec un backoff exponentiel.

## Carte des composants de `App/`

| Répertoire | Domaine | Classes clés |
|-----------|---------|-------------|
| `Attributes/` | Attributs PHP 8 | `ChatType` (filtre les plugins par private/group/channel) |
| `Bootstrap/` | Chaîne de démarrage | `Bootstrap`, `PluginsBootstrap`, `QueueBootstrap` |
| `Bot/` | Instance du SDK | `BotManager` (singleton), `BotConfig` |
| `Button/` | Claviers | `Keyboard` (builder fluide), `ButtonManager` (libellés i18n) |
| `Cache/` | Cache | `CacheManager` — drivers array / file / redis / memcached / database |
| `Cli/` | Outils CLI | `Console`, `Maker` (scaffolding), `Migrate`, `Doctor`, `PluginsList`, `RetryDrain` |
| `Config/` | Configuration typée | `Config` (registre), `AppConfig`, `SessionConfig`, ... |
| `Connection/` | Brokers | `ConnectionManager` (Redis / Memcached / RabbitMQ, singletons avec tests de vivacité) |
| `Core.php` | Entrée | Routage des modes, enregistrement des middleware, `run()`, `runFetchQueueUpdate()` |
| `Cron/` | Modes cron | `CronManager`, `CronWorker`, `WorkerLocker` (slots à verrou fichier) |
| `Database/` | Persistance | `DatabaseManager` (Eloquent), `MigrationRunner` |
| `Enum/` | Enums | `CoreMode` (FULL / UPDATES_ONLY) |
| `Environment/` | Environnement | `EnvHandler` (getters typés), `EnvironmentValidator` (validation au démarrage) |
| `Language/` | i18n | Chargeur `Language` + helper `__()` |
| `Logger/` | Journalisation | `LogHandler` (wrapper Monolog), `LoggerConfig` (protégé contre le path traversal) |
| `Middleware/` | Pipeline | `MiddlewareInterface`, `MiddlewarePipeline`, intégrés : `RateLimitMiddleware`, `AdminGuardMiddleware`, `AuditLogMiddleware` |
| `Plugin/` | Dispatch | `PluginHandler` (découverte + exécution), `PluginInterface` (vos plugins l'implémentent) |
| `Queue/` | Files d'attente | `QueueManager` (façade des drivers), drivers dans `Drivers/` (json/redis/rabbitmq/memcached), `RetryQueue`, `RetryableException`, `QueueWorker` |
| `Scheduler/` | Tâches | `Scheduler` (expressions cron), `TaskLock` |
| `Session/` | État | `SessionManager::start($chatId)` → `Session` (adossé au cache, TTL) |
| `Shared/` | Brouillon par update | `SharedManagement` (vidé entre les itérations) |
| `Storage/` | Fichiers d'état | `OffsetStorage` (offset de getUpdates, écritures `LOCK_EX`) |
| `Update/` | Flux des updates | `Fetcher`, `PollingLoop`, `Processor`, `WebhookDispatcher`, `WebhookResponse` |

## Les trois formes de processus longue durée

1. **Worker de polling** (`bot.php`, mode `update_direct`) — boucle `getUpdates` → `Processor::handle()` → avance l'offset. Se redémarre lui-même toutes les `POLLING_RESTART_INTERVAL` séries pour garder la mémoire bornée ; exécutez-le sous un superviseur.
2. **Worker webhook** (`webhook.php`, FPM/Apache) — sans état, par requête ; Telegram n'a besoin que d'un code de statut rapide. Le travail lourd appartient à une file (mode `webhook_queue`).
3. **Workers de file d'attente** (producteur `queue.php` + consommateur `bot.php`, modes `*_queue`) — le fetcher pousse, les workers dépilent. N'importe quel nombre de processus consommateurs ; le broker répartit les updates entre eux.

## Où vivent les états

| État | Emplacement | Remarques |
|-------|----------|-------|
| Offset de getUpdates | `AppData/lastupdate.txt` | `OffsetStorage`, écritures atomiques ; seuls les modes polling y touchent |
| Charges utiles de la file | broker (ou `AppData/queue/` pour le driver json) | |
| Éléments de retry | `AppData/retry/` ou Redis (selon `RETRY_QUEUE_DRIVER`) | |
| Sessions | driver de cache (selon `CACHE_DRIVER`) | TTL via `SESSION_TTL` |
| Journaux | `logs/` (selon `LOG_DIR`) | path traversal rejeté par la validation de configuration |

`AppData/` est réservé à l'exécution et gitignoré — recréez-le en lançant simplement le bot.
