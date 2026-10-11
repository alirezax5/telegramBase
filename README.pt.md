# TelegramBase

[English](README.md) · [فارسی](README.fa.md) · [中文](README.zh.md) · [Русский](README.ru.md) · [Français](README.fr.md) · **Português** · [العربية](README.ar.md)

Um mini-framework modular PHP 8.1+ para construir bots do Telegram — com sistema de plugins, backends de fila, validação de webhook, sessões, middleware, agendador, migrações e scaffolding de CLI. Construído sobre componentes do Illuminate (Laravel) em vez de reinventá-los.

## O que é isto, em um parágrafo?

O TelegramBase cuida do *encanamento* de que todo bot do Telegram precisa — receber updates (polling ou webhook), validar requisições de webhook, despachar updates para o seu código, repetir chamadas de API que falharam e conversar com backends de fila como Redis e RabbitMQ — para que você escreva apenas a parte *interessante*: pequenas classes PHP chamadas **plugins** que reagem às mensagens. Coloque uma classe em `Plugin/` e o framework a descobre e executa. Essa é toda a ideia.

## Por que ele existe?

Escrever um bot "diretamente contra a API" é fácil até chegar tráfego real. Aí você precisa de respostas para perguntas como: *O que acontece se o meu worker travar no meio de um update? Onde as requisições de webhook são validadas? Como repito um `429 Too Many Requests` sem perder o update? Consigo tirar o trabalho pesado da requisição HTTP?* O TelegramBase responde tudo isso de antemão:

- **Seis modos de operação** (`update_direct`, `update_queue`, `webhook_direct`, `webhook_queue`, `cronjob_update`, `cronjob_queue`) — alterne entre polling e webhook, processamento inline e em fila, mudando um único valor no `.env`.
- **Semântica de entrega honesta** — o framework foi testado contra travadas de ponta a ponta (SIGKILL dentro de cada estágio do pipeline). A garantia de cada modo está documentada: os caminhos webhook/direct são *at-least-once*, o pipeline RabbitMQ é *at-least-once* (ack-after-process), os drivers destrutivos (Redis/JSON/Memcached) são *at-most-once*. Veja [docs/reliability.md](docs/pt/reliability.md).
- **Protegido contra configurações erradas reais** — executar um modo apenas-CLI atrás do ponto de entrada de webhook é rejeitado com HTTP 500 em vez de travar o seu worker web em um loop infinito de polling (um bug que realmente encontramos nos testes de produção).
- **Baterias incluídas** — sessões, construtor de teclados, i18n, middleware (rate-limit / admin-guard / audit-log), agendador, migrações Eloquent, filas limitadas, retentativa automática com o `retry_after` do Telegram.

## Requisitos

- PHP 8.1+
- Composer
- Extensões: `json`, `mbstring`, `curl`
- Opcional: `redis`, `memcached`, `amqp` (backends de fila/cache)
- Opcional: MySQL/MariaDB (banco de dados, migrações Eloquent)

## Início rápido

```bash
git clone https://github.com/alirezax5/telegramBase.git
cd telegramBase
composer install
cp .env.example .env
# Edit .env — set BOT_TOKEN (get one from @BotFather)
php bot.php
```

Envie `/start` ao seu bot — o plugin inicial em `Plugin/start.php` responde. Depois escreva o seu próprio plugin (veja [docs/plugins.md](docs/pt/plugins.md)).

## Como um update flui

```
Telegram
   │
   ├─ getUpdates long-poll ──► bot.php ──┐
   │                                     │
   └─ webhook POST ──► webhook.php ──────┤
                                         ▼
                                    Core::run()
                                         │
                       validate (webhook)│mode routing
                                         ▼
                                    Processor
                                         │
                             middleware pipeline
                                         ▼
                              your plugins (Plugin/)
```

## Documentação

Tudo vive em `docs/` — comece pela visão geral:

| Documento | O que tem dentro |
|----------|---------------|
| [docs/overview.md](docs/pt/overview.md) | O que o framework é, o que faz, o que deliberadamente não faz |
| [docs/architecture.md](docs/pt/architecture.md) | Sequência de boot, fluxo de update, mapa de componentes de `App/` |
| [docs/getting-started.md](docs/pt/getting-started.md) | Instalar → primeiro plugin → implantar, passo a passo |
| [docs/bot-modes.md](docs/pt/bot-modes.md) | Todos os seis `BOT_MODE`s, pontos de entrada, quais processos executar |
| [docs/plugins.md](docs/pt/plugins.md) | Escrevendo plugins: handlers, prioridade, `#[ChatType]`, descoberta |
| [docs/queue-pipeline.md](docs/pt/queue-pipeline.md) | Backends de fila, workers, fila de retentativa, semântica de ack |
| [docs/webhook.md](docs/pt/webhook.md) | Configuração de webhook, as 5 camadas de validação, notas sobre proxy/CDN |
| [docs/reliability.md](docs/pt/reliability.md) | Resultados dos testes de travada: garantias de entrega por modo, orientação sobre idempotência |
| [docs/configuration.md](docs/pt/configuration.md) | Referência completa do `.env` |
| [docs/features.md](docs/pt/features.md) | Sessões, teclados, middleware, cache, i18n, agendador, banco de dados |
| [docs/caching.md](docs/pt/caching.md) | Aprofundamento no CacheManager: drivers, TTLs, alcance do flush, matriz cross-processo (verificado ao vivo) |
| [docs/sessions.md](docs/pt/sessions.md) | Estado de sessão por chat: API, fluxos de múltiplas etapas, notas de segurança |
| [docs/i18n.md](docs/pt/i18n.md) | Traduções: layout de arquivos, helper `__()`, idioma por usuário, cache |
| [docs/buttons.md](docs/pt/buttons.md) | Construtor de teclados + menus definidos em arquivos (`btn()`), tratamento de callbacks |
| [docs/multi-bot.md](docs/pt/multi-bot.md) | Vários bots em um processo: `BotManager`, `BOT_EXTRA_BOTS`, pilha de tokens (verificado ao vivo) |
| [docs/testing.md](docs/pt/testing.md) | Suíte offline, testes ao vivo do Telegram, teste de fumaça, CI |
| [docs/troubleshooting.md](docs/pt/troubleshooting.md) | Sintomas comuns e correções, armadilhas de implantação |

Outros arquivos úteis:

- [CHANGELOG.md](CHANGELOG.md) — o que mudou em cada versão
- [llms.txt](llms.txt) / [llms-ctx.txt](llms-ctx.txt) — índice de documentação orientado a LLMs e contexto em arquivo único
- [.env.example](.env.example) — todas as chaves de configuração com os padrões
- [examples/HelloBot.php](examples/HelloBot.php) — um plugin de exemplo completo

## CLI

```bash
php bin/tgbase list              # all commands
php bin/tgbase make:plugin admin/ban   # scaffold a plugin
php bin/tgbase doctor            # environment diagnostics
php bin/tgbase plugins           # list discovered plugins
php bin/tgbase migrate           # run database migrations
```

## Licença

MIT
