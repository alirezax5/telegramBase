# Pipeline de fila — quando um processo não basta

## Por que usar fila?

O processamento inline tem uma fraqueza simples: o processo que *recebe* o update também precisa *terminar* de tratá-lo. Se um handler envia um vídeo de 50 MB ou espera uma API de terceiros lenta, o receptor fica bloqueado (e no modo webhook, o Telegram está esperando pelo seu 200). O pipeline de fila divide o trabalho:

```
producer                          broker                    consumers (N processes)
──────                            ──────                    ────────
queue.php / webhook.php  ──push──► Redis / RabbitMQ ──pop──► bot.php workers
(pick up updates fast,            (holds updates while       (do the slow work,
 advance offset / 200)             nobody is around)          ack after processing)
```

Benefícios: recebimento rápido (o Telegram nunca espera pelos seus handlers), escalonamento horizontal (execute mais workers) e isolamento de falhas (um worker morrendo não afeta o receptor).

## Escolhendo um driver

| Driver | `QUEUE_SAVE_TYPE` | Precisa de | Comportamento do pop | Garantia em travada do worker |
|--------|-------------------|-------|---------------|---------------------------|
| JSON | `json` *(padrão)* | nada | leitura destrutiva de arquivo | **at-most-once** — job perdido se houver travada no meio do processamento |
| Redis | `redis` | extensão `redis` + servidor | `LPOP` (destrutivo) | **at-most-once** — job perdido se houver travada no meio do processamento |
| Memcached | `memcached` | extensão `memcached` + servidor | get/delete destrutivo | **at-most-once** |
| RabbitMQ | `rabbitmq` | extensão `amqp` + servidor | `basic_get` + **ack-após-processar** (v2.4.0) | **at-least-once** — job não confirmado é reentregue |

Escolha RabbitMQ quando perder um único update for inaceitável; escolha Redis/JSON por throughput e simplicidade quando a perda ocasional for tolerável (ou quando os updates forem baratos de reconstruir).

## O contrato de ack do RabbitMQ (v2.4.0)

Desde a v2.4.0 o driver RabbitMQ usa semântica de **ack-late**:

1. `pop()` busca uma mensagem e retém sua delivery tag — a mensagem *ainda não* é confirmada.
2. Seu handler executa.
3. O worker chama `ack()` **depois** do processamento. Só então o broker apaga a mensagem.
4. Se o worker morrer em qualquer ponto entre 1 e 3, o broker reentrega a mensagem ao próximo worker.

Em nível de código, o worker faz exatamente isto:

```php
$update = $queue->pop();
try {
    $processor->handle($update);
} finally {
    $queue->ack();   // success → done; handled exception → discarded (no poison loops)
}
```

Consequências que vale conhecer:

- **Processamento duplicado é possível** (travada após o processamento mas antes do ack — o broker não consegue distinguir). Torne os handlers idempotentes: armazene o `update_id` antes de agir, pule se já visto. Veja [reliability.md](reliability.md).
- Uma mensagem que falha ao ser decodificada recebe ack imediatamente (descartada) para que não possa envenenar a fila.
- Drivers de fila customizados não precisam implementar `ack()` — `QueueManager::ack()` é um no-op para drivers sem confirmação no lado do broker.

A configuração fica na seção `rabbitmq` do `.env` (host, porta, usuário, senha, vhost, nome da fila) — veja [.env.example](../../.env.example).

## Executando o pipeline

`update_queue` (produtor por polling):

```bash
# terminal 1 — fetcher: long-polls Telegram, pushes to the broker
php queue.php

# terminal 2..N — consumers: pop + process + ack
php bot.php
```

`webhook_queue` (produtor por webhook):

```bash
# web server receives Telegram POSTs → validates → enqueues → 200
# (webhook.php handles this automatically when BOT_MODE=webhook_queue)

# terminal 1..N — consumers
php bot.php
```

Execute todos sob um supervisor. Os workers se reconectam ao broker automaticamente com backoff exponencial (`BACKOFF_INITIAL=1s` … `BACKOFF_MAX=60s`), então você pode reiniciar o Redis/RabbitMQ sem tocar nos workers.

## Filas limitadas

`QUEUE_MAX_SIZE` (padrão conforme [.env.example](../../.env.example)) limita quantos updates podem ficar parados no broker. Quando o limite é atingido, novas inserções são **rejeitadas e registradas em log** (WARNING), nunca descartadas silenciosamente em um monte ilimitado. Isso protege o disco/RAM quando os workers ficam fora por muito tempo. Tamanho 0 desativa o limite.

## A fila de retentativa (separada da fila principal)

A fila de retentativa trata *falhas transitórias de handler* (HTTP `429`, `5xx`, timeouts, erros de curl) para todos os modos, não apenas os em fila:

- `Processor::handle()` captura um erro retentável → coloca o update na `RetryQueue`.
- Workers de longa duração drenam os itens vencidos em seu tick de manutenção (a cada iteração do loop nos workers de fila).
- O backoff dobra por tentativa; quando o Telegram fornece `retry_after`, esse valor exato é honrado (`RetryableException::retryAfter`).
- Após `RETRY_MAX_ATTEMPTS` o item é descartado e registrado em log.

Seus handlers optam in com o helper `tg_retryable()`:

```php
use function alirezax5\TelegramBase\App\Queue\tg_retryable;

$resp = tg_retryable($tg->sendMessage($chatId, 'Hello!'), 'sendMessage');
// ok=true            → returned unchanged
// 429 / 5xx          → RetryableException thrown → retry queue
// other 4xx          → returned unchanged (permanent errors are not retried)
```

Configuração: `RETRY_ENABLED`, `RETRY_QUEUE_DRIVER` (`json`|`redis`), `RETRY_MAX_ATTEMPTS`, `RETRY_BASE_DELAY`, `RETRY_MAX_SIZE` — padrões em [.env.example](../../.env.example).
