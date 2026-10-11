# Testes — a suíte offline, testes ao vivo e CI

## A suíte de testes offline

O framework vem com um executor de testes sem dependências (não precisa de PHPUnit):

```bash
composer install        # vendor/ must exist (the suite boots the framework)
php tests/run.php
```

Estrutura:

| Diretório | O que vive ali |
|-----------|------------------|
| `tests/Unit/` | testes unitários puros — env handler, config, drivers de fila, armazenamento de offset, validação do dispatcher de webhook, fiação de middleware, ciclo de vida de retry, ... (sem rede) |
| `tests/Integration/` | testes de múltiplos componentes (ainda offline) |
| `tests/Live/` | **testes reais da API do Telegram — apenas opt-in** (veja abaixo) |
| `tests/smoke.php` | teste de fumaça standalone do bootstrap (token ruim, token vazio, modo ruim, boot válido) |
| `tests/benchmark.php` | micro-benchmarks para os caminhos quentes |

Filtre por nome:

```bash
php tests/run.php --filter=Keyboard
php tests/run.php --filter=Queue
```

## Desvio conhecido da suíte — RESOLVIDO

Até a v2.4.0, a execução offline reportava **196 pass / 18 fail / 12 skip**. As 18 falhas (`SessionTest` ×10, `BackwardCompatTest` ×4, `MiddlewareWiringTest` ×4) eram preexistentes e haviam sido provadas não relacionadas às correções do framework, revertendo cada correção e executando novamente.

A causa raiz foi encontrada durante a revisão de pré-lançamento (P-REL-B05): o `tests/run.php` semeia padrões de env e chama `Bootstrap::boot()`, mas o `EnvironmentBootstrap` usava o `load()` do Dotenv, que **lança exceção quando o repositório não tem `.env`** (um clone novo inclui apenas `.env.example`). O boot abortava, o `Config` permanecia não inicializado, e todos os testes dependentes de configuração falhavam. Com a mudança para `safeLoad()` a suíte roda verde em um clone novo sem nenhuma configuração:

```
Pass: 224  Fail: 0  Skip: 12
```

## Testes ao vivo do Telegram

Os testes ao vivo atingem a Bot API real e são pulados a menos que habilitados explicitamente:

```bash
RUN_TELEGRAM_LIVE_TESTS=1 \
TELEGRAM_BOT_TOKEN="<your-token>" \
TELEGRAM_TEST_CHAT_ID="<your-chat-id>" \
php tests/run.php
```

Regras da casa (aplicadas pelos próprios testes):

- toda mensagem que enviam é marcada com `[telegramBase automated test]`
- eles apagam as próprias mensagens após a verificação quando possível — e nunca apagam mensagens que não criaram
- caminhos negativos incluídos: chat inválido, token inválido, 429 simulado
- o token é lido **apenas** de variáveis de ambiente

## CI (GitHub Actions)

O `.github/workflows/tests.yml` roda a cada push/PR no PHP **8.1 – 8.4**: validação do composer, `composer audit` e a suíte offline.

O `.github/workflows/live-tests.yml` é um workflow **manual** (`workflow_dispatch`) para os testes ao vivo; ele lê o token do secret de repositório `TGBASE_LIVE_BOT_TOKEN` e um input `chat_id` — nenhum secret no arquivo do workflow, e o CI padrão nunca toca em secrets.

## Teste de fumaça

```bash
php tests/smoke.php
```

Seis casos: token ruim / token vazio / tentativa de log-traversal / caminho absoluto de log / modo ruim / boot válido. Use-o após implantações como uma checagem de saúde de 5 segundos.

## O que a suíte cobre (e por que isso importa para a confiança)

As afirmações de confiabilidade em [reliability.md](reliability.md) são sustentadas por testes mais cenários de integração testados contra travadas (SIGKILL em cada estágio do pipeline, checagens reais de reentrega do broker, testes de transição de webhook com verificação via `getWebhookInfo`). As suítes mais relevantes para auditar essas afirmações:

```bash
php tests/run.php --filter=Queue      # driver behaviour (json/redis/rabbitmq contracts)
php tests/run.php --filter=Offset     # offset storage atomicity
php tests/run.php --filter=Retry      # retry queue lifecycle + retry_after
php tests/run.php --filter=Webhook    # the 5 webhook validation layers
php tests/run.php --filter=Security   # env/log hardening rules
```

A ferramenta de integração usada durante os testes de travada (um bot real + scripts de cenário) vive em `integration/TestBot/` — veja o README dela para o que ela faz e como adaptá-la à sua própria implantação.
