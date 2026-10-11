# Cache — CacheManager

Uma única API estática sobre quatro drivers intercambiáveis, configurada inteiramente pelo `.env`. Sessões, traduções, definições de botões e (opcionalmente) a descoberta de plugins rodam sobre esta camada — trocar o driver muda a história de persistência do framework inteiro sem tocar em código.

```php
use alirezax5\TelegramBase\App\Cache\CacheManager;
```

> Cada afirmação neste documento foi verificada ao vivo (2026-10-11) contra backends reais em um servidor de produção: a suíte de 17 checagens rodou verde em `array`, `file`, `memcached` (PHP 8.5) e `redis`, mais uma matriz de persistência cross-processo de 9 pontos para cada driver persistente. Veja a seção *Verificação ao vivo* no fim e o [CHANGELOG](../../CHANGELOG.md).

## Drivers

| `CACHE_DRIVER` | Backend | Cross-processo | Tags | Observações |
|---|---|---|---|---|
| `array` (padrão) | memória por processo | ✗ | ✗ | zero-config; o estado vive exatamente enquanto o processo |
| `file` | disco, `CACHE_PATH` | ✓ | ✗ | sem serviços extras; diretórios de 2 níveis com hash; payloads serializados em PHP |
| `redis` | extensão phpredis (`redis.so`) | ✓ | ✗ | payloads JSON; recomendado para implantações multi-processo |
| `memcached` | extensão `memcached` + servidor | ✓ | ✗ | payloads JSON; volátil por natureza |

**Cadeia de fallback (verificada):** se o driver solicitado estiver indisponível — extensão ausente, servidor inalcançável, autenticação falhou, caminho não gravável — o framework registra um aviso e **volta ao `array`** em vez de falhar o boot. Solicitar `memcached` em um PHP sem a extensão produziu exatamente isto: `driver=array`, todo o resto inalterado. Verifique o que você realmente recebeu com `CacheManager::getDriver()`.

> ⚠️ O driver `array` é local ao processo. Um daemon de polling é *um* processo de longa duração, então `array` "funciona" ali; mas o webhook (workers FPM por requisição) e os workers de fila (um processo por worker) nunca compartilham estado. Se sessões, contadores ou `remember()` precisarem sobreviver entre mensagens/processos, use `file`, `redis` ou `memcached`. Isso foi demonstrado ao vivo: valores escritos no processo A e lidos no processo B — persistem nos três, `<MISS>` no `array` (por design).

## Configuração (.env)

```dotenv
CACHE_DRIVER=file            # array | file | redis | memcached
CACHE_PREFIX=tgbase_         # key namespace (also the flush() safety boundary)
CACHE_TTL=3600               # default TTL seconds when put() has no explicit ttl
CACHE_PATH=/tmp/tgbase-cache # file driver only
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=              # required if the server has requirepass (NOAUTH otherwise)
REDIS_DATABASE=0
MEMCACHED_HOST=127.0.0.1
MEMCACHED_PORT=11211
```

## API

| Método | Comportamento |
|---|---|
| `get($key, $default = null)` | valor ou padrão em miss/expiração |
| `put($key, $value, ?int $ttl = null)` | armazena; ttl `null` → `CACHE_TTL` |
| `remember($key, callable $cb, ?int $ttl = null)` | retorna o valor em cache ou executa `$cb` e cacheia o resultado |
| `has($key)` | existe e não expirou |
| `forget($key)` | apaga uma chave |
| `flush()` | apaga **todas as chaves do namespace deste framework** — veja as notas por driver |
| `increment($key, $by = 1)` / `decrement($key, $by = 1)` | contadores |
| `getDriver()` / `isInitialized()` / `tagsSupported()` | introspecção |
| `putWithTags()` / `getWithTags()` / `rememberWithTags()` / `flushTags()` | API estilo tags que funciona em todos os drivers (fallback baseado em prefixo) |
| `tags([...])` | cache com tags nativo — **lança exceção** a menos que o driver suporte tags (nenhum dos quatro suporta) |

### Semântica de TTL (verificada com esperas reais)

`put('k', 'x', 2)` → `has` true → após 3 s → `has` false, `get` retorna o padrão. Os TTLs dos backends correspondem à configuração exatamente: uma entrada de 300 s mostrou `ttl=297s` no `redis-cli`, uma sessão de 3600 s `ttl=3597s`, um cache de traduções de 60 s `ttl=57s`. Entradas expiradas em `file`/`redis` são removidas de forma lazy (no acesso) ou pelo backend (`redis` setex).

### Formatos de armazenamento

- **redis** — `setex` com `json_encode(JSON_UNESCAPED_UNICODE)`. Os payloads precisam ser JSON-round-trippable: escalares e arrays sim; objetos PHP voltam como arrays; sem resources.
- **memcached** — JSON pela mesma família de store; valores (des)serializados de forma transparente pela extensão.
- **file** — payloads com `serialize()` do PHP, marca de expiração primeiro, sob `CACHE_PATH/ab/cd/<sha1>`.
- **array** — variáveis PHP simples; nada toca disco ou rede.

### Layout das chaves (visão bruta do backend)

| Driver | Chave bruta para `put('lang:fa', …)` com `CACHE_PREFIX=tgbase_` |
|---|---|
| redis | `tgbase_lang:fa` |
| memcached | `tgbase_:lang:fa` (o Illuminate acrescenta `:` ao prefixo) |
| file | `CACHE_PATH/<h1>/<h2>/<sha1>` |

### Contadores — uma nuance (verificada)

`increment()` em uma chave **ausente**: `array`/`file`/`redis` a criam (`incBy`), `memcached` retorna `false` (o Illuminate não envia valor inicial). Padrão portátil: semeie primeiro — `CacheManager::put($k, 0, $ttl); CacheManager::increment($k);`

### flush() — alcance por driver

- `array` — apenas este processo.
- `file` — tudo dentro de `CACHE_PATH` (diretório dedicado; seguro).
- `redis` — **escopo por prefixo**: itera com `SCAN` `{CACHE_PREFIX}*` e dá `UNLINK` apenas nessas chaves. Seguro em instâncias compartilhadas (verificado em um Redis compartilhado com object caches do WordPress — outras chaves intocadas). Recusa-se a rodar com prefixo vazio.
- `memcached` — ⚠️ `MemcachedStore::flush()` emite um `flush_all` em todo o servidor. Em um memcached **compartilhado** isso destrói o cache de outras aplicações — pule-o ali (a suíte de testes deliberadamente pula).

## O que roda sobre o cache

| Consumidor | Chave | Controle de TTL |
|---|---|---|
| [Sessões](sessions.md) | `tgbase:session:{chatId}` | `SESSION_TTL` (padrão 3600, mín 60) |
| [Traduções](i18n.md) | `lang:{lang}`, `lang:missing_keys` | `LANG_CACHE_TTL` (padrão 60) |
| [Definições de botões](buttons.md) | `buttons:all` | `BUTTONS_CACHE_TTL` (padrão 300), interruptor `BUTTONS_CACHE_ENABLED` |
| Descoberta de plugins | (interno) | `PLUGIN_CACHE_ENABLED` + `PLUGIN_CACHE_TTL` |

## Verificação ao vivo (P-CACHE, 2026-10-11)

| Suíte | Resultado |
|---|---|
| Suíte in-processo de 17 checagens × `array` / `file` / `memcached` (PHP 8.5) / `redis` | **PASS** em todas |
| memcached solicitado em PHP 8.4 (sem a extensão) → fallback gracioso para `array` | **PASS** (cadeia documentada) |
| Matriz cross-processo (`set` proc → `get` proc): escalar, array aninhado, contador 5→6, `remember` retorna o aleatório *plantado*, continuidade de id + payload da sessão, payload de `lang:fa`, payload de `buttons:all` | **9/9 PASS** em `file`, `redis`, `memcached`; `array` perde exatamente como documentado |
| Inspeção dos backends | chaves redis com contagem regressiva de TTL ao vivo; chaves brutas do memcached sob `tgbase_:`; store de arquivos com marcas de expiração visíveis |
| Pipeline real: `/cache demo hello` (dispatch 1) → `/cache demo` (dispatch 2, processo novo) → `demo = hello`; `/session-set`/`/session-get` o mesmo | **PASS** |

Um defeito real foi encontrado e corrigido por este exercício: **`CacheRedisStore::flush()` encerrava seu loop de SCAN na primeira página MATCH vazia** (comum em keyspaces compartilhados/grandes) — não apagando nada enquanto retornava `true` (P-CACHE-F001 no CHANGELOG).
