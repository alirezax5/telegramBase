# Webhook — recebendo updates do Telegram

## Configuração

```dotenv
# .env
BOT_MODE="webhook_direct"          # or webhook_queue
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="a-long-random-string"   # openssl rand -hex 32
WEBHOOK_CHECK_IP=true
```

Registre uma única vez (o Telegram exige HTTPS com certificado válido):

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # verify: pending_update_count=0, no last_error
```

Desregistre (ao voltar para polling — faça isso *antes* de iniciar o `bot.php`, duas fontes de update disputam o mesmo fluxo):

```bash
curl "https://api.telegram.org/bot<TOKEN>/deleteWebhook"
```

## As cinco camadas de validação

Toda requisição que chega ao `webhook.php` passa pelo `WebhookDispatcher`, nesta ordem:

1. **Tamanho do corpo** — acima de 1 MB → `413`. (O Telegram nunca envia payloads maiores; qualquer coisa maior não é o Telegram.)
2. **Whitelist de IPs** — com `WEBHOOK_CHECK_IP=true`, requisições de IPs fora das sub-redes oficiais do Telegram → `403`. As faixas são as publicadas na documentação do Telegram (`149.154.160.0/20`, `91.108.4.0/22`, `91.108.8.0/22`, `91.108.56.0/22`, `91.108.16.0/22`, `95.161.64.0/20`).
3. **Secret token** — com `BOT_WEBHOOK_SECRET` definido, o header `X-Telegram-Bot-Api-Secret-Token` deve corresponder, comparado com `hash_equals()` (seguro contra ataques de timing). Divergência → `403`.
4. **Validação de JSON** — o corpo deve ser interpretado como um objeto contendo `update_id`. Caso contrário → `400`.
5. **Roteamento** — `webhook_direct` executa os plugins de forma síncrona e responde `200` apenas se nada lançou exceção; `webhook_queue` enfileira e responde `200` imediatamente.

Falhar na validação nunca consome o update de uma forma que o perca — o Telegram reenvia updates para os quais não recebeu um `2xx`.

## Atrás de Cloudflare / proxies nginx (importante)

Se as requisições do Telegram chegam ao PHP por meio do Cloudflare (ou qualquer reverse proxy), o `REMOTE_ADDR` do PHP é o IP do *proxy*, não o do Telegram — a verificação de IP rejeitaria todas as requisições. Duas configurações que funcionam:

- **Proxy na frente:** mantenha `WEBHOOK_CHECK_IP=false` e conte com o **secret token** como sua autenticação de entrada (faça-o longo — 32+ caracteres hex). É exatamente assim que o framework foi validado nos testes de produção.
- **Proxy repassa o IP real:** configure seu proxy para enviar `X-Forwarded-For` / `X-Real-IP` *e* faça o PHP confiar nele — então `WEBHOOK_CHECK_IP=true` funciona. Faça isso apenas se você controla o proxy, caso contrário o header pode ser falsificado.

Regra prática: **verificação de IP = exposição direta; secret = exposição via proxy.** Usar os dois também é ok.

## Comportamento dos modos na entrada de webhook

| `BOT_MODE` | O que o `webhook.php` faz |
|------------|--------------------------|
| `webhook_direct` | validar → `Processor::handle()` → `200` quando concluído |
| `webhook_queue` | validar → enfileirar → `200` imediatamente |
| `update_direct` | validar → `Processor::handle()` → `200` (funciona, mas registra um aviso — prefira `webhook_direct` explicitamente) |
| `update_queue`, `cronjob_*` | `500` + mensagem explicativa — esses modos são apenas-CLI |

A última linha é a proteção de SAPI da v2.4.0 ([bot-modes.md](bot-modes.md)): um modo CLI na entrada web não pode mais iniciar um loop infinito dentro do seu worker PHP-FPM (o que antes terminava como `Read timeout expired` no lado do Telegram e um 502 do seu servidor web).

## Semântica de resposta — por que o 200 importa

O Telegram repete qualquer entrega de webhook que não receba um `2xx`, com backoff, por até várias horas. O framework explora isso de forma honesta:

- O `200` é enviado **somente depois** de o update ter sido processado (direct) ou enfileirado com segurança (modo fila).
- Uma exceção durante o processamento → `500` → o Telegram reentrega mais tarde → o update **não é perdido**, mas *será processado duas vezes* se a primeira tentativa realmente concluiu antes da travada. Essa é a definição de entrega at-least-once — veja [reliability.md](reliability.md) para tornar os handlers idempotentes.
- Um worker que demora minutos para responder é um bug, não uma funcionalidade: o timeout do cliente do Telegram é de ~60s. Mova o trabalho lento para o `webhook_queue`.

## Registro com allowed updates (opcional)

Restrinja o que o Telegram lhe envia aos tipos de update que seus plugins realmente tratam:

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=...&secret_token=...&allowed_updates=[\"message\",\"edited_message\",\"callback_query\",\"channel_post\",\"edited_channel_post\"]"
```

## Checagens de saúde

O `getWebhookInfo` é o seu painel — observe:

- `pending_update_count` — updates em fila no Telegram porque seu endpoint está falhando/lento. Crescimento sustentado = incidente.
- `last_error_message` / `last_error_date` — a falha exata de entrega (timeouts, erros TLS, secret errado).

Uma checklist de implantação para o modo webhook:

1. `webhook.php` acessível via HTTPS (teste com um navegador: um GET vazio deve dar `400 empty` — é o dispatcher respondendo).
2. `BOT_WEBHOOK_SECRET` ≥ 32 caracteres aleatórios, idêntico no `.env` e no `setWebhook`.
3. `WEBHOOK_CHECK_IP` corresponde à sua topologia de rede (veja a seção de proxies).
4. `getWebhookInfo` limpo após uma mensagem de teste; o efeito colateral do seu plugin visível.
