# Modos de bot — as seis formas de executar um bot TelegramBase

O `BOT_MODE` no `.env` é o valor de configuração mais importante de todos. Ele decide *como os updates chegam* (polling vs webhook) e *como são processados* (inline vs em fila).

## Os modos

| Modo | Origem dos updates | Processamento | Processos que você executa |
|------|--------------|------------|-------------------|
| `update_direct` *(padrão)* | long-poll `getUpdates` | inline, mesmo processo | `php bot.php` sob um supervisor |
| `update_queue` | long-poll `getUpdates` | assíncrono via fila | `php queue.php` (fetcher) + um ou mais `php bot.php` (workers) |
| `webhook_direct` | POST de webhook do Telegram | inline, dentro da requisição HTTP | apenas o servidor web (`webhook.php`) |
| `webhook_queue` | POST de webhook do Telegram | assíncrono via fila | servidor web (`webhook.php`) + worker(s) `php bot.php` |
| `cronjob_update` | `getUpdates` (polling) | inline, **daemon autoregenerável** — o primeiro tick inicia um loop infinito, os ticks seguintes não fazem nada enquanto ele vive | crontab `* * * * * php bin/scheduler.php` |
| `cronjob_queue` | apenas fila (sem polling do Telegram) | lote assíncrono — cada tick consome por até `CRON_MAX_TIME` (padrão 50 s) e então sai | crontab + slots `CRON_WORKER` |

Modelo mental:

- Os modos **`update_*` / `cronjob_*`** buscam os updates eles mesmos (seu servidor fala com o Telegram).
- Os modos **`webhook_*`** recebem updates (o Telegram fala com seu servidor).
- Os modos **`*_direct`** processam o update assim que ele chega — o mais simples, e suficiente para a maioria dos bots.
- Os modos **`*_queue`** desacoplam o recebimento do processamento — use-os quando os handlers forem lentos (uploads, APIs externas) ou quando você quiser escalonamento horizontal.
- Os modos **`cronjob_*`** rodam a partir de um crontab simples — sem supervisor, sem systemd, sem Docker.

## Modos cron em detalhes

Ambos os modos cron compartilham o mesmo mecanismo de segurança: a cada tick o framework tenta adquirir um **slot de worker** — um `flock()` exclusivo em um arquivo de lock sob `data/cron-slots/` (`WorkerLocker`). Se todos os `CRON_WORKER` slots estiverem ocupados, o tick registra `All N worker slots busy` e sai em milissegundos — ticks concorrentes nunca podem processar nada em duplicidade.

### `cronjob_update` — um daemon autoregenerável, não um lote

Apesar do nome, um tick **não** executa "um lote". O primeiro tick adquire o slot e inicia o mesmo loop infinito de polling do `update_direct` (`set_time_limit(0)` + long-poll), e o tick nunca termina — de propósito:

1. Tick 1 → o daemon inicia, o slot permanece travado.
2. Ticks 2..N → encontram o slot ocupado e saem em milissegundos (medido: ~30–45 ms). Isso é *normal*, não é um erro.
3. O daemon morre (travada, OOM, reboot) → o próximo tick do cron inicia um novo daemon automaticamente. **O cron atua como supervisor.**

Verificado ao vivo: um daemon iniciado às 09:24:01 processou uma mensagem real inline, sobreviveu a várias viradas de minuto, encerrou-se graciosamente ao receber SIGTERM dentro de um ciclo de long-poll (~12 s — o sinal de parada é honrado assim que o long-poll atual retorna), liberou o lock, e o tick imediatamente seguinte o reiniciou.

### `cronjob_queue` — um worker de lote limitado

Cada tick adquire um slot e consome da fila por no máximo `CRON_MAX_TIME` segundos (padrão 50), e então sai limpamente com `Limited worker finished: N updates`. Nada faz polling do Telegram nesse modo — a fila precisa ser alimentada por um produtor (`webhook_queue`, ou sua própria execução do `queue.php`).

Verificado ao vivo: uma mensagem real inserida no RabbitMQ às 09:22:49 foi retirada e despachada pelo tick que já estava dentro da sua janela de 50 segundos (plugin `onMessage` às 09:22:49.6, `Limited worker finished: 1 updates` às 09:22:51), e a fila voltou a 0/0. A semântica de entrega é herdada do driver de fila — at-least-once com RabbitMQ desde a v2.4.0.

### Configuração do cron

| Env | Padrão | Significado |
|-----|---------|---------|
| `CRON_WORKER` | `1` | número de slots de worker concorrentes (arquivos flock em `data/cron-slots/`) |
| `CRON_MAX_TIME` | `50` | janela de lote do `cronjob_queue` em segundos — mantenha-a abaixo do seu intervalo de cron para que os ticks não se sobreponham |

## Pontos de entrada e a proteção de SAPI

| Arquivo | Executa-se como | Finalidade |
|------|---------|---------|
| `bot.php` | CLI | polling (`update_direct`), consumidor de fila (`*_queue`), callbacks do cron |
| `queue.php` | CLI | *produtor* de fila — faz long-poll do Telegram e coloca updates na fila (`update_queue`) |
| `webhook.php` | HTTP | recebe os POSTs de webhook do Telegram (`webhook_*`) |
| `bin/tgbase` | CLI | diagnósticos, scaffolding, migrações |
| `bin/scheduler.php` | CLI | executor de ticks do cron (`cronjob_*` + tarefas agendadas) |

**Importante — a proteção de SAPI (v2.4.0):** uma requisição HTTP só pode servir trabalho do tipo webhook. Se `webhook.php` for executado enquanto `BOT_MODE` é um modo apenas-CLI, o framework se recusa a rodar um loop infinito dentro do seu worker web:

| `BOT_MODE` no `webhook.php` | Comportamento |
|------------------------------|-----------|
| `webhook_direct` | validar → processar inline → `200` |
| `webhook_queue` | validar → enfileirar → `200` |
| `update_direct` | validar → processar inline → `200` (um aviso é registrado — você provavelmente quis dizer `webhook_direct`) |
| `update_queue`, `cronjob_*` | **HTTP 500** com uma mensagem explicativa — sem loop, sem travamento |

Contexto: antes de existir essa proteção, apontar o webhook para um bot configurado com `update_direct` iniciava silenciosamente um loop infinito de `getUpdates` dentro do worker do PHP-FPM. O Telegram nunca recebia resposta (`Read timeout expired` → retentativas infinitas) e o FPM matava o worker em `request_terminate_timeout` (502). A proteção transforma esse travamento silencioso em uma falha alta e rápida. Você pode testar a lógica de detecção diretamente com `Core::isHttpSapi($sapi)`.

> Regra prática: **modos CLI rodam no terminal, modos webhook rodam na web.** O framework impõe essa fronteira para você.

## Semântica de entrega de relance

| Caminho | Garantia | Janela de duplicação |
|------|-----------|------------------|
| `webhook_direct` | at-least-once (o Telegram repete até receber um 2xx) | travada *depois* de `handle()` mas antes do 200 |
| `update_direct` | at-least-once (o offset avança apenas após o processamento) | travada *depois* dos efeitos colaterais mas antes da escrita do offset |
| `webhook_queue` (lado produtor) | o update está no broker assim que você recebeu seu 200 | retentativas de enfileiramento em falha do broker |
| consumidor da fila — **RabbitMQ** | at-least-once (ack após o processamento, v2.4.0) | travada entre o processamento e o ack |
| consumidor da fila — Redis / JSON / Memcached | at-most-once (pop destrutivo — o job desaparece no momento do pop) | uma travada no meio do processamento perde o job |

Evidências completas de testes de travada e orientação sobre idempotência: [reliability.md](reliability.md).

O `cronjob_update` se comporta exatamente como o `update_direct` (uma mensagem processada inline antes de o offset avançar); o `cronjob_queue` se comporta exatamente como o consumidor de fila do seu driver (at-least-once com RabbitMQ, at-most-once com Redis/JSON/Memcached).

## Escolhendo um modo — conselhos práticos

- **Bots pequenos, painéis, protótipos** → `update_direct`. Um processo, zero infraestrutura.
- **Handlers lentos (processamento de arquivos, APIs de terceiros), múltiplas instâncias** → `webhook_queue` ou `update_queue` com Redis/RabbitMQ.
- **Hospedagem compartilhada sem daemons CLI** → `cronjob_update` (o crontab vira o supervisor) ou `webhook_direct`.
- **Quer exactly-once?** Ninguém tem. O TelegramBase lhe dá caminhos *at-least-once* que nunca perdem updates, e caminhos *at-most-once* que nunca duplicam — escolha por caso de uso, e torne os handlers idempotentes (veja [reliability.md](reliability.md)).
