# Arquitetura — como as peças se encaixam

## A sequência de boot

Todo ponto de entrada (CLI ou web) constrói um único objeto: `Core`.

```php
$core = new Core(CoreMode::FULL);   // FULL = boot plugins too
$core->run();
```

O construtor executa a cadeia de boot, nesta ordem:

| Passo | Classe | O que acontece |
|------|-------|--------------|
| 1 | `Bootstrap::boot()` | Carrega o `.env` (via `EnvHandler`), valida o ambiente (`EnvironmentValidator`), prepara os caminhos (`Paths`, `AppData/`), conecta o cache |
| 2 | `BotManager::getInstance()->get()` | Cria a instância do SDK `Telegram` a partir de `BOT_TOKEN` (singleton — todos os componentes compartilham uma única instância) |
| 3 | `PluginsBootstrap::boot()` *(somente FULL)* | Varre `PLUGINS_DIR`, instancia as classes de plugin, ordena por prioridade, constrói o registro do `PluginHandler` |

`CoreMode::UPDATES_ONLY` (usado pelo `queue.php`) pula o passo 3 — o fetcher apenas coloca updates na fila, ele mesmo nunca despacha plugins.

## O fluxo de update

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

Pontos-chave:

- **Um `Processor` por estágio do pipeline.** Os caminhos de polling, webhook e fila constroem cada um o seu; todos eles anexam o mesmo pipeline de middleware que você registrou no `Core`.
- **Normalização que preserva a forma.** Updates que chegam como arrays (dos drivers de fila) são convertidos em objetos recursivamente — arrays do tipo *lista* (tamanhos de foto, entities, linhas de teclado inline) permanecem arrays, mapas associativos viram objetos. Isso importa porque o Telegram modela campos como `photo` como arrays e os plugins fazem `foreach` sobre eles.
- **Falhas dentro de `handle()`** são capturadas, registradas em log com o contexto do update e — quando o erro parece transitório (`429`, `5xx`, timeout, erro de curl) — enviadas para a `RetryQueue` com backoff exponencial.

## Mapa de componentes de `App/`

| Diretório | Responsabilidade | Classes principais |
|-----------|---------|-------------|
| `Attributes/` | Atributos do PHP 8 | `ChatType` (filtra plugins por private/group/channel) |
| `Bootstrap/` | Cadeia de boot | `Bootstrap`, `PluginsBootstrap`, `QueueBootstrap` |
| `Bot/` | Instância do SDK | `BotManager` (singleton), `BotConfig` |
| `Button/` | Teclados | `Keyboard` (builder fluente), `ButtonManager` (rótulos i18n) |
| `Cache/` | Cache | `CacheManager` — drivers array / file / redis / memcached / database |
| `Cli/` | Ferramentas CLI | `Console`, `Maker` (scaffolding), `Migrate`, `Doctor`, `PluginsList`, `RetryDrain` |
| `Config/` | Config tipada | `Config` (registro), `AppConfig`, `SessionConfig`, ... |
| `Connection/` | Brokers | `ConnectionManager` (Redis / Memcached / RabbitMQ, singletons com checagens de liveness) |
| `Core.php` | Entrada | Roteamento de modos, registro de middleware, `run()`, `runFetchQueueUpdate()` |
| `Cron/` | Modos cron | `CronManager`, `CronWorker`, `WorkerLocker` (slots com file-lock) |
| `Database/` | Persistência | `DatabaseManager` (Eloquent), `MigrationRunner` |
| `Enum/` | Enums | `CoreMode` (FULL / UPDATES_ONLY) |
| `Environment/` | Env | `EnvHandler` (getters tipados), `EnvironmentValidator` (validação no boot) |
| `Language/` | i18n | Loader `Language` + helper `__()` |
| `Logger/` | Logging | `LogHandler` (wrapper do Monolog), `LoggerConfig` (protegido contra path traversal) |
| `Middleware/` | Pipeline | `MiddlewareInterface`, `MiddlewarePipeline`, built-ins: `RateLimitMiddleware`, `AdminGuardMiddleware`, `AuditLogMiddleware` |
| `Plugin/` | Despacho | `PluginHandler` (descoberta + execução), `PluginInterface` (seus plugins o implementam) |
| `Queue/` | Filas | `QueueManager` (facade de driver), drivers em `Drivers/` (json/redis/rabbitmq/memcached), `RetryQueue`, `RetryableException`, `QueueWorker` |
| `Scheduler/` | Tarefas | `Scheduler` (expressões cron), `TaskLock` |
| `Session/` | Estado | `SessionManager::start($chatId)` → `Session` (com backup em cache, TTL) |
| `Shared/` | Rascunho por update | `SharedManagement` (limpo entre iterações) |
| `Storage/` | Arquivos de estado | `OffsetStorage` (offset do getUpdates, escritas com `LOCK_EX`) |
| `Update/` | Fluxo de updates | `Fetcher`, `PollingLoop`, `Processor`, `WebhookDispatcher`, `WebhookResponse` |

## Os três formatos de processos de longa duração

1. **Worker de polling** (`bot.php`, modo `update_direct`) — faz o loop `getUpdates` → `Processor::handle()` → avança o offset. Reinicia a si mesmo a cada `POLLING_RESTART_INTERVAL` lotes para manter a memória limitada; execute-o sob um supervisor.
2. **Worker de webhook** (`webhook.php`, FPM/Apache) — sem estado por requisição; o Telegram só precisa de um código de status em tempo hábil. Trabalho pesado pertence a uma fila (modo `webhook_queue`).
3. **Workers de fila** (`queue.php` produtor + `bot.php` consumidor, modos `*_queue`) — o fetcher insere, os workers consomem. Qualquer número de processos consumidores; o broker distribui os updates entre eles.

## Onde o estado vive

| Estado | Local | Observações |
|-------|----------|-------|
| Offset do getUpdates | `AppData/lastupdate.txt` | `OffsetStorage`, escritas atômicas; apenas os modos de polling o tocam |
| Payloads da fila | broker (ou `AppData/queue/` para o driver json) | |
| Itens de retry | `AppData/retry/` ou Redis (conforme `RETRY_QUEUE_DRIVER`) | |
| Sessões | driver de cache (conforme `CACHE_DRIVER`) | TTL via `SESSION_TTL` |
| Logs | `logs/` (conforme `LOG_DIR`) | path traversal rejeitado pela validação de configuração |

`AppData/` é apenas de execução e está no gitignore — recrie-o simplesmente executando o bot.
