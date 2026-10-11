# Configuração — a referência completa do `.env`

Copie [.env.example](../../.env.example) para `.env` e edite. Os valores mostrados são os padrões. A validação no boot (`EnvironmentValidator`) verifica os críticos e falha com uma mensagem legível em vez de uma travada misteriosa.

> Configurar apenas por meio de variáveis de ambiente reais (systemd `Environment=`, `docker -e`, k8s) é igualmente suportado — o `.env` é opcional. Quando ambos existem, o ambiente do processo vence (o Dotenv é imutável).

## Bot

| Chave | Padrão | Observações |
|-----|---------|-------|
| `BOT_TOKEN` | — | **obrigatório**; vindo do @BotFather. Validado quanto ao formato; nunca ecoado em mensagens de erro. `TGBASE_SKIP_BOT_VALIDATION=1` permite que comandos CLI apenas-de-DB rodem sem um token |
| `BOT_MODE` | `update_direct` | um dos seis modos — veja [bot-modes.md](bot-modes.md) |
| `BOT_API_URL` | `https://api.telegram.org` | sobrescrita para servidores locais da Bot API |
| `POLLING_TIMEOUT` | `30` | segundos de long-poll por chamada `getUpdates` |
| `POLLING_RESTART_INTERVAL` | `100` | lotes por auto-reinício (higiene de memória; `0` desativa) |
| `BOT_WEBHOOK_URL` | — | URL do endpoint de webhook (HTTPS) |
| `BOT_WEBHOOK_SECRET` | — | secret para `X-Telegram-Bot-Api-Secret-Token`; use 32+ caracteres hex aleatórios (`openssl rand -hex 32`) |
| `WEBHOOK_CHECK_IP` | `true` | valida o `REMOTE_ADDR` contra as sub-redes do Telegram — defina `false` atrás de Cloudflare/proxies (então o secret é sua camada de autenticação) |
| `DATA_DIR` | `AppData` | estado de execução (offset, arquivos de fila, locks) |

## Plugins

| Chave | Padrão | Observações |
|-----|---------|-------|
| `PLUGINS_DIR` | `/Plugin/` | relativo à base do projeto; caminhos absolutos suportados |
| `PLUGIN_CACHE_ENABLED` | `true` | faz cache dos plugins descobertos entre boots |
| `PLUGINS_RELOAD_INTERVAL` | `0` | segundos entre re-varreduras de descoberta (conveniência de desenvolvimento) |

## Idioma e botões

| Chave | Padrão | Observações |
|-----|---------|-------|
| `LANG_DRIVER` | `json` | driver de arquivos de tradução — ⚠️ os arquivos incluídos são arrays PHP, então o `.env.example` define `php`; uma divergência silenciosamente volta ao nome da chave (veja [i18n.md](i18n.md)) |
| `DEFAULT_LANG` | `fa` | código do idioma de fallback |
| `LANG_DIR` | `/Language` | local dos arquivos de tradução |
| `LANG_CACHE_TTL` | `60` | segundos de cache das traduções |
| `BUTTONS_DIR` | `/Button` | arquivos de definição de rótulos de teclado |
| `BUTTONS_FILE` | `btn.php` | nome do arquivo base |
| `BUTTONS_CACHE_TTL` | `60` | segundos de cache dos rótulos |
| `BUTTONS_CACHE_ENABLED` | `true` | defina `false` para sempre reler as definições de botões dos arquivos |

## Fila

| Chave | Padrão | Observações |
|-----|---------|-------|
| `QUEUE_SAVE_TYPE` | `json` | `json` \| `redis` \| `rabbitmq` \| `memcached` |
| `QUEUE_PATH` | `AppData/queue` | diretório de armazenamento do driver json |
| `QUEUE_MAX_SIZE` | `0` | limite de fila limitada (0 = ilimitado); fila cheia rejeita + registra em log |
| `QUEUE_MAX_RETRIES` | `3` | retentativas de operação de broker por push/pop |
| `QUEUE_RETRY_DELAY` | `2` | segundos entre retentativas do broker |
| `QUEUE_REDIS_KEY` / `QUEUE_MEMCACHED_KEY` | `bot_updates` | chave de lista por driver |
| `RABBITMQ_QUEUE` | `bot_updates` | nome da fila RabbitMQ |

### Conexão Redis

| Chave | Padrão |
|-----|---------|
| `REDIS_HOST` | `127.0.0.1` |
| `REDIS_PORT` | `6379` |
| `REDIS_PASSWORD` | *(vazio)* |
| `REDIS_DATABASE` | `0` |
| `REDIS_TIMEOUT` | `60` |

### Conexão RabbitMQ

| Chave | Padrão |
|-----|---------|
| `RABBITMQ_HOST` | `127.0.0.1` |
| `RABBITMQ_PORT` | `5672` |
| `RABBITMQ_USER` | `guest` |
| `RABBITMQ_PASSWORD` | `guest` |
| `RABBITMQ_VHOST` | `/` |

### Conexão Memcached

| Chave | Padrão |
|-----|---------|
| `MEMCACHED_HOST` | `127.0.0.1` |
| `MEMCACHED_PORT` | `11211` |
| `MEMCACHED_USERNAME` / `MEMCACHED_PASSWORD` | *(vazio — SASL)* |

## Fila de retentativa

| Chave | Padrão | Observações |
|-----|---------|-------|
| `RETRY_ENABLED` | `true` | chave mestra |
| `RETRY_QUEUE_DRIVER` | `json` | `json` \| `redis` |
| `RETRY_MAX_ATTEMPTS` | `3` | depois descarta + registra |
| `RETRY_BASE_DELAY` | `2` | segundos; dobrado por tentativa; sobrescrito pelo `retry_after` do Telegram |
| `RETRY_MAX_SIZE` | `1000` | limite da fila limitada |
| `RETRY_REDIS_KEY` | `bot_retry_queue` | quando o driver é redis |

## Cache e sessão

| Chave | Padrão | Observações |
|-----|---------|-------|
| `CACHE_DRIVER` | `array` | `array` \| `file` \| `redis` \| `memcached` — drivers não suportados/indisponíveis voltam ao `array` com um aviso registrado |
| `CACHE_PREFIX` | `tgbase_` | prefixo de chave para todos os stores de cache |
| `CACHE_PATH` | temp do sistema | diretório do driver file |
| `CACHE_TTL` | `300` | segundos de TTL padrão quando `put()`/`remember()` não recebem TTL explícito |
| `SESSION_TTL` | `3600` | segundos de vida da sessão |
| `SESSION_PREFIX` | `tgbase:session:` | prefixo das chaves de sessão |

## Banco de dados

| Chave | Padrão | Observações |
|-----|---------|-------|
| `DATABASE_ENABLE` | `false` | controla todo o bootstrap de DB (a CLI funciona sem um DB) |
| `DB_DRIVER` | `mysql` | qualquer driver PDO (`sqlite` para desenvolvimento local) |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | — | |

## Multi-bot

| Chave | Padrão | Observações |
|-----|---------|-------|
| `BOT_EXTRA_BOTS` | — | bots extras em um processo — `name1:token1,name2:token2` (dividido no primeiro dois-pontos); veja [multi-bot.md](multi-bot.md). Funciona no `.env` e no ambiente do processo |

## Agendador e cron

| Chave | Padrão | Observações |
|-----|---------|-------|
| `SCHEDULER_LOCK_TTL` | `300` | segundos do lock de tarefa |
| `SCHEDULER_LOCK_TABLE` | `task_locks` | tabela do backend de locks |
| `SCHEDULE_FILE` | `bin/tasks.php` | arquivo de tarefas usado pelo `bin/scheduler.php` (sobrescrita de caminho) |
| `CRON_WORKER` | `10` | slots concorrentes para o `cronjob_queue` (com file-lock) |
| `CRON_MAX_TIME` | `50` | segundos máximos por tick de cron para o worker |

## Logging

| Chave | Padrão | Observações |
|-----|---------|-------|
| `LOG_ENABLED` | `true` | chave mestra |
| `LOG_LEVEL` | `debug` | níveis do Monolog |
| `LOG_DIR` | `./logs` | **apenas nomes relativos** — caminhos absolutos e `..` são rejeitados (proteção contra path traversal) |
| `LOG_FILE` | `log.txt` | |

## Admins

| Chave | Padrão | Observações |
|-----|---------|-------|
| `ADMINS_WHITELIST` | — | ids de usuários do Telegram separados por vírgula, usados pelo `AdminGuardMiddleware` |

## Notas de segurança

- Nunca faça commit do `.env`; o `.gitignore` fornecido o exclui (assim como `*.secret` e `secrets.json`).
- As mensagens de erro do `EnvironmentValidator` nomeiam a chave ausente, nunca o seu valor.
- Secrets de webhook são comparados com `hash_equals()` (tempo constante).
- Deliberadamente **não existe o botão `VERIFY_TLS=false`** — a verificação TLS para api.telegram.org está sempre ativa.
