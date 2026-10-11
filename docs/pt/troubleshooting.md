# Solução de problemas — sintomas, causas, correções

## Boot e configuração

| Sintoma | Causa provável | Correção |
|---------|--------------|-----|
| `BOT_TOKEN is not set or empty` no `migrate:status` | comportamento antigo | corrigido na versão atual — os comandos de migração não exigem mais um token. Se você ainda o vir, execute com `TGBASE_SKIP_BOT_VALIDATION=1` |
| `could not find driver` no `migrate` | `DATABASE_ENABLE=true` mas sem driver PDO/DB configurado | defina `DATABASE_ENABLE=false` se não precisar de um DB, ou instale o driver PDO |
| Boot falha com erro de validação relacionado ao modo | `BOT_MODE` grafado errado | use exatamente um de: `update_direct`, `update_queue`, `webhook_direct`, `webhook_queue`, `cronjob_update`, `cronjob_queue` |
| Logs aparecem no CWD em vez de `logs/` | comportamento antigo — caminho de log relativo resolvido contra o CWD | corrigido: o `LogHandler` usa `LoggerConfig::absolutePath()` (relativo à base do projeto). Também afetava a execução do `bin/tgbase` de outro diretório |
| Fatal em `Session::regenerate()`: "Cannot modify readonly property" | comportamento antigo | corrigido: `Session` não declara mais `$sessionId` como readonly |
| `tests/smoke.php` falha em todos os casos negativos | lógica de match antiga e quebrada | corrigido na versão atual |
| Plugins não carregados | `PLUGINS_DIR` errado, ou cache de plugins obsoleto | o padrão é `/Plugin/` (relativo à base do projeto; caminhos absolutos suportados). Em desenvolvimento defina `PLUGIN_CACHE_ENABLED=false`, depois `php bin/tgbase plugins` para verificar a descoberta |
| Rate limiter / admin guard quebram em callback queries | acesso antigo não-nullsafe `->message->chat->id` | corrigido: cadeias nullsafe + `edited_message` / `channel_post` / `chat_member` suportados |

## Webhook

| Sintoma | Causa provável | Correção |
|---------|--------------|-----|
| Webhook retorna `403` para cada entrega | whitelist de IP ativa mas um proxy/CDN reescreve o IP de origem | atrás de Cloudflare/nginx defina `WEBHOOK_CHECK_IP=false` e conte com um `BOT_WEBHOOK_SECRET` forte — veja [webhook.md](webhook.md) |
| Webhook retorna `403`, `getWebhookInfo` diz secret mismatch | `BOT_WEBHOOK_SECRET` no `.env` ≠ `secret_token` passado ao `setWebhook` | torne-os idênticos; faça rotação com `openssl rand -hex 32` |
| Webhook retorna `500` com "BOT_MODE ... is CLI-only" | modo apenas-CLI na entrada web (a proteção de SAPI da v2.4.0) | defina `BOT_MODE=webhook_direct` / `webhook_queue` para a entrada web, ou execute o modo pela CLI (`bot.php` / `queue.php`) |
| `getWebhookInfo`: `last_error_message="Read timeout expired"` | endpoint não respondeu a tempo (pré-v2.4.0 isso podia ser o bug de loop infinito; pós-v2.4.0 são handlers lentos) | atualize para ≥ v2.4.0; verifique se há handlers lentos; mova o trabalho pesado para o `webhook_queue` |
| `pending_update_count` cresce continuamente | seu endpoint está fora do ar/falhando continuamente | verifique `last_error_message`, a validade do certificado TLS, e se o `webhook.php` está acessível; um GET puro retornando `400 empty` prova que o dispatcher está vivo |
| Respostas do webhook atrasadas por segundos | handlers rodam inline durante a requisição HTTP | mude para `webhook_queue` — o endpoint então responde imediatamente e os workers processam de forma assíncrona |

## Filas e workers

| Sintoma | Causa provável | Correção |
|---------|--------------|-----|
| `RabbitMQ ... "Broken pipe or closed connection"` na conexão | bug antigo de login-method (`default` não é um mecanismo AMQP válido) | corrigido na v2.4.0 (o driver agora usa `AMQPLAIN`); verifique de qualquer forma seu usuário/vhost do RabbitMQ |
| Updates inseridos mas nunca processados | nenhum consumidor rodando | os modos `*_queue` precisam dos dois lados: produtor (`queue.php` ou `webhook.php`) **e** consumidor (`php bot.php`) |
| Fila cresce sem limite enquanto os workers estão fora | | defina `QUEUE_MAX_SIZE` (filas limitadas rejeitam + registram quando cheias) |
| Processamento duplicado após a travada de um worker | esperado com at-least-once (ack-late do RabbitMQ, reentrega de webhook/polling) | torne os handlers idempotentes — veja [reliability.md](reliability.md) |
| Worker sai silenciosamente sob carga | supervisor ausente | rode todos os processos longos sob systemd/supervisord com `Restart=always`; o framework reinicia o polling a cada `POLLING_RESTART_INTERVAL` lotes, o supervisor cobre todo o resto |
| `409 Conflict` do `getUpdates` | dois processos fazendo polling do mesmo token de bot (ou webhook + polling simultaneamente) | apenas uma fonte de update: execute um único fetcher, e `deleteWebhook` antes de iniciar o polling |

## Notas de implantação que valem ouro

- **Permissões do arquivo de log (FPM):** se a entrada de webhook não consegue escrever logs, geralmente é o dono do arquivo — o PHP-FPM roda como `www-data`/`www`, então os arquivos/diretórios de log precisam ser graváveis por esse usuário (`chown www-data:www-data logs -R`). Uma escrita de log silenciosamente inócua é difícil de notar de outra forma.
- **`request_terminate_timeout` do PHP-FPM:** qualquer coisa que seu handler de webhook faça por mais tempo que esse limite morre com um 502. Mantenha o tratamento do webhook rápido; use a fila para o resto.
- **`php bin/tgbase doctor`** é a ferramenta de triagem mais rápida — verifica versão/extensões do PHP, legibilidade do `.env`, gravabilidade do `AppData/`, gravabilidade dos logs e o formato do token sem tocar no Telegram.
- **Duas fontes de update disputando:** após alternar entre polling e webhook, verifique se a troca foi concluída (`deleteWebhook` ou uma parada limpa do polling) antes de iniciar o outro lado. Ambos rodando ao mesmo tempo = conflitos de offset e 409s.

## Referência rápida de diagnósticos

```bash
php bin/tgbase doctor              # environment triage
php bin/tgbase plugins             # what the framework actually discovered
php tests/smoke.php                # 6-case bootstrap check
php tests/run.php --filter=Queue   # subsystem deep-dive
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # Telegram-side view
```
