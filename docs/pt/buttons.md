# Botões — construtor Keyboard e ButtonManager

Duas ferramentas complementares:

- **`Keyboard`** — reply markup fluente, construído em código, para menus *dinâmicos* (as linhas dependem de dados de execução).
- **`ButtonManager` / `btn()`** — menus fixos *definidos em arquivos* em `Button/`, com rótulos traduzidos, cacheáveis e compartilháveis entre plugins.

```php
use alirezax5\TelegramBase\App\Button\Keyboard;
```

## Construtor Keyboard

### Teclado inline (botões de callback)

```php
$kb = Keyboard::inline()
    ->button('✅ تایید', 'order_confirm')      // text, callback payload
    ->button('❌ انصراف', 'order_cancel')
    ->row()                                     // next buttons go on a new row
    ->button('راهنما', 'https://example.com', Keyboard::URL)
    ->toArray();

$resp = $tg->sendMessage($chatId, 'سفارش را تایید می‌کنید؟', ['reply_markup' => $kb]);
tg_retryable($resp, 'sendMessage');
```

### Teclado de resposta (o teclado virtual do usuário)

```php
$kb = Keyboard::reply()
    ->button('🔍 جستجو')
    ->button('💳 خرید')
    ->row()
    ->button('⚙️ تنظیمات')
    ->resize()        // fit text height
    ->oneTime()       // hide after first tap
    // ->selective()  // show only to mentioned users
    ->toArray();
```

Remova um teclado de resposta com um payload bruto (ou uma definição `btn()`, veja abaixo):

```php
$tg->sendMessage($chatId, 'باشه', ['reply_markup' => ['remove_keyboard' => true]]);
```

### API do builder

| Método | Aplica-se a | Efeito |
|---|---|---|
| `inline()` / `reply()` | construtor | teclado de callback / teclado customizado |
| `button($text, $value = '', $type = CALLBACK)` | ambos | anexa à linha atual; `$type` um de `Keyboard::CALLBACK` (`callback_data`), `Keyboard::URL` (`url`), `Keyboard::SWITCH_INLINE`, `Keyboard::SWITCH_INLINE_CURRENT` |
| `row()` | ambos | inicia uma nova linha |
| `resize($v)` | reply | `resize_keyboard` |
| `oneTime($v)` | reply | `one_time_keyboard` |
| `selective($v)` | reply | `selective` |
| `inputPlaceholder($text)` | inline | `input_field_placeholder` |
| `toArray()` / `toJson()` | ambos | valor final de `reply_markup` (array) / string JSON |

Linhas vazias são descartadas automaticamente; `toArray()` retorna exatamente a forma que a Bot API espera para `reply_markup`.

### Tratando os toques

Botões inline chegam como updates do tipo `callback_query` — implemente `onCallback_query()` no seu plugin e faça o roteamento por `callback_data` (veja [plugins.md](plugins.md)); responda-os com `$tg->answerCallbackQuery($callbackQueryId)`. Toques no teclado de resposta são mensagens de texto comuns.

## ButtonManager — botões definidos em arquivos

As definições vivem em `BUTTONS_DIR` (padrão `/Button`) — o arquivo principal `BUTTONS_FILE` (padrão `btn.php`) mais qualquer outro arquivo `*.php` naquele diretório, que recebe um prefixo de chave `'<filename>.'` (mesmo padrão do loader de idiomas).

```php
// Button/btn.php
return [
    "remove" => ['KeyboardRemove' => [], 'remove_keyboard' => true],

    "start" => [
        'keyboard' => [
            [
                ['text' => __('start')],           // translations work inside definitions
                ['text' => __('messages.help')],
            ],
        ],
        'resize_keyboard' => true,
    ],
];
```

```php
use function alirezax5\TelegramBase\App\Button\btn;   // or the global btn() helper

$kb  = btn('start');                       // whole definition → reply_markup
$kb2 = btn('profile', ['ID' => 12]);       // {ID} and {{ID}} placeholders replaced everywhere
$raw = btn('remove');                      // raw Telegram payload (KeyboardRemove)
ButtonManager::has('start');               // exists?
ButtonManager::getAll();                   // every definition
ButtonManager::reload();                   // re-read files, refresh cache
```

As definições são exatamente "o que o arquivo retorna" — a maioria são arrays `reply_markup` prontos para envio, então `$tg->sendMessage($chatId, 'متن', ['reply_markup' => btn('start')])` é toda a história.

## Cache (verificado)

As definições compiladas são cacheadas sob a chave `buttons:all` com `BUTTONS_CACHE_TTL` (padrão 300 s) para que os arquivos sejam lidos uma vez por janela de TTL, não a cada update. `BUTTONS_CACHE_ENABLED=false` desliga o cache inteiramente (as definições são relidas dos arquivos toda vez) — esse interruptor está ligado de ponta a ponta a partir do P-CACHE-F002 (antes ele era lido para a configuração mas nunca consultado; a correção foi verificada ao vivo em ambos os estados). `ButtonManager::reload()` força uma atualização em tempo de execução.

A chave/alcance do cache seguem o [caching.md](caching.md): no `array` o cache é por processo; em `file`/`redis`/`memcached` as definições são compartilhadas entre os workers.

## Comandos de teste (integration/TestBot)

`/btn` (se presente no seu conjunto de plugins) — o `StartPlugin` incluído mostra o `btn('start')` em ação; a suíte P-CACHE verificou que o `btn('start')` resolve (`rows=1`) e que seu payload persiste cross-processo sob `buttons:all`.
