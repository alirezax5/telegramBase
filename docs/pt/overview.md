# Visão geral — o que é o TelegramBase

## A versão em uma frase

TelegramBase é um framework PHP que transforma "conversar com a Telegram Bot API" em "escrever pequenas classes de plugin", tratando tudo que bots confiáveis precisam: busca de updates, validação de webhook, enfileiramento, retentativas e ciclo de vida dos processos.

## O que ele faz

Todo bot do Telegram — por mais simples que seja — precisa do mesmo esqueleto:

1. **Receber updates.** Ou você pergunta ao Telegram "há algo novo?" (long-polling via `getUpdates`), ou o Telegram chama sua URL HTTPS (webhook). O TelegramBase suporta ambos, selecionados por um único valor no `.env`.
2. **Validar requisições de webhook.** Qualquer pessoa pode enviar POSTs falsos de updates para sua URL. O TelegramBase verifica o tamanho do corpo, o IP de origem (sub-redes oficiais do Telegram), um secret token opcional e a integridade do JSON antes que seu código seja executado.
3. **Roteamento dos updates para o seu código.** Um `Processor` normaliza o objeto de update bruto e o entrega a todos os plugins cujos filtros correspondem (tipo de chat, ordem de prioridade), opcionalmente por meio de um pipeline de middleware.
4. **Sobreviver a falhas.** Chamadas de API podem retornar `429 Too Many Requests`. Handlers podem lançar exceções. Workers podem ser mortos no meio de um update. Brokers de fila podem cair. O TelegramBase tem uma resposta para cada caso: uma fila de retentativas com backoff exponencial (respeitando o `retry_after` do Telegram), tamanhos de fila limitados, reconexão do broker com backoff e garantias de entrega documentadas por modo.
5. **Escalar quando necessário.** Comece com um único processo `php bot.php`. Quando o volume aumentar, migre para um pipeline de fila: um fetcher leve coloca os updates em Redis/RabbitMQ e processos workers separados os consomem.

O TelegramBase faz tudo isso e expõe uma superfície de API pequena, simples e testável.

## O que ele deliberadamente não faz

- **Não é um SDK de bot.** As chamadas reais à API do Telegram vêm de [`alirezax5/telegram-bot-php`](https://github.com/alirezax5/telegram-bot-php) (o objeto `Telegram` injetado em seus plugins). O TelegramBase é a camada *ao redor* desse SDK.
- **Não escreve a lógica do seu bot.** Não há "construtor de conversas" nem painel administrativo. Você escreve classes PHP comuns; o framework as agenda e as alimenta.
- **Não esconde o PHP.** Sem DSL customizada, sem definições de bot em YAML. Se você conhece PHP 8 e a Telegram Bot API, você sabe como o TelegramBase funciona.

## Filosofia de design

- **Configuração em vez de código.** Modo de operação, backend de fila, driver de cache, idioma — tudo alternado via `.env`, sem editar classes.
- **Componentes consolidados.** Sessões, cache e locks são construídos sobre o Illuminate (`illuminate/cache`, `illuminate/database`). São os mesmos componentes que o Laravel usa em produção; o TelegramBase não adiciona dependências exóticas.
- **Honesto quanto a falhas.** Cada caminho de processamento tem uma garantia de entrega documentada (at-least-once ou at-most-once) — medida matando workers reais com SIGKILL durante os testes de integração, não estimada. Veja [reliability.md](reliability.md).
- **Falhar de forma explícita, não silenciosa.** Um modo apenas-CLI em um ponto de entrada web retorna HTTP 500 com uma explicação, em vez de ficar travado silenciosamente. Uma fila cheia rejeita e registra novas inserções em vez de consumir seu disco.

## O formato do código

```
App/          the framework (one concern per subdirectory)
Plugin/       YOUR plugins (auto-discovered)
Button/       keyboard label definitions
Language/     translation files
Database/     Eloquent models + migrations
bin/          CLI tools (tgbase, scheduler)
docs/         documentation (you are here)
tests/        offline + live test suite
```

Tudo em `App/` é entregue a você por meio de algumas classes de entrada: `Core` (modos de execução), `Processor` (despacho de updates), `QueueManager` (backends de fila), `SessionManager` (estado por chat), `Keyboard` (teclados fluentes), `LogHandler` (logging). O resto é a infraestrutura de que esses cinco precisam.

## Onde ir a partir daqui

- [getting-started.md](getting-started.md) — instale e execute seu primeiro bot
- [architecture.md](architecture.md) — como as peças se encaixam
- [bot-modes.md](bot-modes.md) — escolhendo entre os seis modos de operação
