# Confiabilidade — o que realmente acontece quando as coisas travam

Este documento é incomum para uma documentação de framework: tudo nele foi **medido, não presumido**. Durante os testes de integração (fases 3.2–3.3), workers foram mortos com `SIGKILL` em cada estágio de cada pipeline — durante o fetch, durante o push na fila, no meio do handler, após o handler mas antes do ack, antes/depois das escritas de offset — e o resultado de cada cenário foi registrado. Esta página é o resultado destilado, e ela orienta tanto o design do framework quanto as recomendações abaixo.

## Garantias de entrega por caminho

| Caminho | Garantia | Por quê | Janela de duplicação |
|------|-----------|-----|------------------|
| Polling (`update_direct`) | **at-least-once** | o offset do `getUpdates` é persistido somente *depois* de `Processor::handle()` retornar | travada após efeitos colaterais, antes da escrita do offset |
| Webhook (`webhook_direct`) | **at-least-once** | o `200` é enviado somente após `handle()` retornar; o Telegram repete qualquer outra coisa | travada após efeitos colaterais, antes do 200 |
| Webhook → fila (`webhook_queue`) | **at-least-once até o broker** | `200` somente após um enfileiramento bem-sucedido | indisponibilidade do broker entre o enfileiramento e o 200 |
| Consumidor da fila — **RabbitMQ** (v2.4.0) | **at-least-once** | ack-após-processar: mensagens não confirmadas são reentregues pelo broker | travada após o processamento, antes do ack |
| Consumidor da fila — Redis / JSON / Memcached | **at-most-once** | pop destrutivo — a mensagem é removida no momento do pop | nenhuma (uma travada no meio do processamento perde o job) |
| Fila de retentativa | **at-least-once, limitada** | itens re-despachados com backoff até `RETRY_MAX_ATTEMPTS` | igual ao caminho pai |

Duas observações honestas dos testes de travada:

- **Exactly-once não existe.** Nem no TelegramBase, nem em qualquer sistema construído sobre uma rede. Você escolhe entre "nunca perder" (at-least-once, duplicações possíveis em travadas) e "nunca duplicar" (at-most-once, perdas possíveis em travadas). O framework lhe dá ambas as famílias e documenta qual é qual.
- **As janelas de duplicação são estreitas, mas reais.** Elas só se abrem em uma travada no momento exato errado. Elas se fecham com handlers idempotentes.

## Tornando handlers idempotentes (os 90% práticos)

At-least-once só é doloroso quando o mesmo update é *alvo de ação* duas vezes. Uma única proteção torna toda janela inofensiva:

```php
public function onMessage($message, Telegram $tg): void
{
    $update  = $tg->getInputData();
    $uId     = $update->update_id ?? null;

    // Cheap, atomic, per-update marker — works with any cache driver.
    if (!empty(cache()->get("upd:{$uId}"))) {
        return;                       // already processed (redelivery) — skip
    }

    // ... do the actual work ...

    cache()->set("upd:{$uId}", 1, 3600);   // remember for an hour
}
```

Armazene a marcação *antes* dos efeitos colaterais irreversíveis sempre que possível, e aceite que uma travada entre a marcação e o efeito colateral converte uma duplicação em uma perda — escolha o trade-off que se adequa à ação (enviar uma mensagem duas vezes geralmente é mais barato do que nunca enviá-la).

## Comportamento em travada por componente

| Componente | Cenário de travada | Comportamento observado |
|-----------|---------------|--------------------|
| Worker de polling (`update_direct`) | `SIGKILL` no meio de um lote | offset inalterado → os mesmos updates são reentregues no reinício → reprocessados (a idempotência importa) |
| Worker de polling | `SIGKILL` entre lotes | limpo; no máximo o update atual é reentregue |
| Fetcher de fila (`queue.php`) | `SIGKILL` após o pop, antes do push | update ainda do lado do Telegram (offset não avançou) → reentregue |
| Fetcher de fila | `SIGKILL` após o push, antes da escrita do offset | update no broker **e** ainda do lado do Telegram → o reinício do produtor o duplicaria no broker (limitado por `QUEUE_MAX_SIZE`; a idempotência dos consumidores o absorve) |
| Worker RabbitMQ (pré-v2.4.0) | `SIGKILL` no meio do processamento | job **permanentemente perdido** (o ack já havia sido enviado) — corrigido na v2.4.0 |
| Worker RabbitMQ (v2.4.0+) | `SIGKILL` no meio do processamento | job **reentregue** ao próximo worker (não confirmado) |
| Worker Redis/JSON | `SIGKILL` no meio do processamento | job permanentemente perdido (pop destrutivo) — por design, documentado |
| Webhook (FPM) | handler excede ~60 s | o Telegram expira o tempo e repete; se o handler eventualmente conclui, a repetição o duplica — mantenha o tratamento do webhook rápido ou use `webhook_queue` |
| Broker fora do ar (qualquer modo de fila) | conexão recusada | os workers recuam exponencialmente (1 s → 60 s), reconectam e retomam; o fetcher mantém o offset até que os pushes tenham sucesso |

## Recursos limitados

- **As filas são limitadas** (`QUEUE_MAX_SIZE`, `RETRY_MAX_SIZE`): quando cheias, as inserções são rejeitadas e registradas — uma indisponibilidade não pode encher seu disco silenciosamente.
- **A memória é limitada**: o worker de polling reinicia a si mesmo a cada `POLLING_RESTART_INTERVAL` lotes (configurável, `0` desativa).
- **Os logs são protegidos contra path traversal**: `LOG_DIR`/`LOG_FILE` não podem apontar para fora do projeto.

## Taxonomia de erros usada pela fila de retentativa

`RetryableException::isRetryable()` classifica os erros para que apenas falhas *transitórias* sejam repetidas:

- HTTP `429 Too Many Requests` (honrando o `parameters.retry_after` do Telegram quando presente)
- HTTP `5xx` / "server error"
- timeouts (`timeout`, `timed out`, `connect`)
- `cURL error N`
- qualquer subclasse de `RetryableException` que você mesmo lance

Todo o resto (400 "chat not found", 403 "bot blocked", ...) é permanente — registrado em log, não repetido.

## Testando sua própria postura de confiabilidade

A suíte de testes incluída contém os primitivos relevantes para travadas (atomicidade do armazenamento de offset, drivers de fila, ciclo de vida de retry, caminhos de validação do dispatcher de webhook):

```bash
php tests/run.php --filter=Queue     # queue driver behaviour
php tests/run.php --filter=Offset    # offset storage
php tests/run.php --filter=Retry     # retry lifecycle
php tests/run.php --filter=Webhook   # webhook validation layers
```

Para confiança de ponta a ponta, repita a abordagem de integração em sua própria implantação: registre o webhook, envie uma mensagem marcadora, dê `kill -9` no worker em pleno voo e verifique a reentrega no `getWebhookInfo` / logs.
