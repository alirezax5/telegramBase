# Boutons — Constructeur Keyboard & ButtonManager

Deux outils complémentaires :

- **`Keyboard`** — markup de réponse fluide construit en code pour les menus *dynamiques* (les lignes dépendent des données à l'exécution).
- **`ButtonManager` / `btn()`** — menus fixes *définis en fichier* dans `Button/`, libellés traduits, mettables en cache, partageables entre plugins.

```php
use alirezax5\TelegramBase\App\Button\Keyboard;
```

## Constructeur Keyboard

### Clavier inline (boutons callback)

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

### Clavier reply (le clavier virtuel de l'utilisateur)

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

Retirez un clavier reply avec une charge utile brute (ou une définition `btn()`, voir ci-dessous) :

```php
$tg->sendMessage($chatId, 'باشه', ['reply_markup' => ['remove_keyboard' => true]]);
```

### API du builder

| Méthode | S'applique à | Effet |
|---|---|---|
| `inline()` / `reply()` | constructeur | clavier callback / clavier personnalisé |
| `button($text, $value = '', $type = CALLBACK)` | les deux | ajoute à la ligne courante ; `$type` vaut l'un de `Keyboard::CALLBACK` (`callback_data`), `Keyboard::URL` (`url`), `Keyboard::SWITCH_INLINE`, `Keyboard::SWITCH_INLINE_CURRENT` |
| `row()` | les deux | commence une nouvelle ligne |
| `resize($v)` | reply | `resize_keyboard` |
| `oneTime($v)` | reply | `one_time_keyboard` |
| `selective($v)` | reply | `selective` |
| `inputPlaceholder($text)` | inline | `input_field_placeholder` |
| `toArray()` / `toJson()` | les deux | valeur finale de `reply_markup` (tableau) / chaîne JSON |

Les lignes vides sont retirées automatiquement ; `toArray()` renvoie exactement la forme que l'API Bot attend pour `reply_markup`.

### Gérer les pressions

Les boutons inline arrivent comme des updates de type `callback_query` — implémentez `onCallback_query()` dans votre plugin et routez sur `callback_data` (voir [plugins.md](plugins.md)) ; répondez-y avec `$tg->answerCallbackQuery($callbackQueryId)`. Les pressions sur un clavier reply sont des messages texte ordinaires.

## ButtonManager — boutons définis en fichier

Les définitions vivent dans `BUTTONS_DIR` (défaut `/Button`) — le fichier principal `BUTTONS_FILE` (défaut `btn.php`) plus tout autre fichier `*.php` de ce répertoire, qui reçoit un préfixe de clé `'<nomfichier>.'` (même pattern que le chargeur de langue).

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

Les définitions sont exactement « ce que le fichier retourne » — la plupart sont des tableaux `reply_markup` prêts à envoyer, donc `$tg->sendMessage($chatId, 'متن', ['reply_markup' => btn('start')])` est toute l'histoire.

## Mise en cache (vérifiée)

Les définitions compilées sont mises en cache sous la clé `buttons:all` avec `BUTTONS_CACHE_TTL` (défaut 300 s) afin que les fichiers soient lus une fois par fenêtre TTL, pas à chaque update. `BUTTONS_CACHE_ENABLED=false` désactive entièrement le cache (définitions relues depuis les fichiers à chaque fois) — cet interrupteur est câblé de bout en bout depuis P-CACHE-F002 (auparavant il était lu dans la config mais jamais consulté ; la correction a été vérifiée en conditions réelles dans les deux états). `ButtonManager::reload()` force un rafraîchissement à l'exécution.

La clé/la portée du cache suivent [caching.md](caching.md) : sur `array` le cache est par processus ; sur `file`/`redis`/`memcached` les définitions sont partagées entre les workers.

## Commandes de test (integration/TestBot)

`/btn` (si présent dans votre ensemble de plugins) — le `StartPlugin` fourni montre `btn('start')` en action ; la suite P-CACHE a vérifié que `btn('start')` se résout (`rows=1`) et que sa charge utile persiste entre processus sous `buttons:all`.
