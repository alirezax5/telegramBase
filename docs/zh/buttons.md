# 按钮 — Keyboard 构建器与 ButtonManager

两个互补的工具：

- **`Keyboard`** —— 流式的、用代码构建的回复标记，适用于*动态*菜单（行取决于运行时数据）。
- **`ButtonManager` / `btn()`** —— `Button/` 中*由文件定义*的固定菜单，标签可翻译、可缓存、可在插件间共享。

```php
use alirezax5\TelegramBase\App\Button\Keyboard;
```

## Keyboard 构建器

### 内联键盘（回调按钮）

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

### 回复键盘（用户的虚拟键盘）

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

用一个原始载荷移除回复键盘（或一个 `btn()` 定义，见下文）：

```php
$tg->sendMessage($chatId, 'باشه', ['reply_markup' => ['remove_keyboard' => true]]);
```

### 构建器 API

| 方法 | 适用于 | 效果 |
|---|---|---|
| `inline()` / `reply()` | 构造 | 回调键盘 / 自定义键盘 |
| `button($text, $value = '', $type = CALLBACK)` | 两者 | 追加到当前行；`$type` 为 `Keyboard::CALLBACK`（`callback_data`）、`Keyboard::URL`（`url`）、`Keyboard::SWITCH_INLINE`、`Keyboard::SWITCH_INLINE_CURRENT` 之一 |
| `row()` | 两者 | 开启新的一行 |
| `resize($v)` | reply | `resize_keyboard` |
| `oneTime($v)` | reply | `one_time_keyboard` |
| `selective($v)` | reply | `selective` |
| `inputPlaceholder($text)` | inline | `input_field_placeholder` |
| `toArray()` / `toJson()` | 两者 | 最终的 `reply_markup` 值（数组）/ JSON 字符串 |

空行会被自动丢弃；`toArray()` 返回的形状恰好是 Bot API 对 `reply_markup` 所期望的。

### 处理点击

内联按钮以 `callback_query` 类型的更新到达——在你的插件中实现 `onCallback_query()`，并按 `callback_data` 路由（见 [plugins.md](plugins.md)）；用 `$tg->answerCallbackQuery($callbackQueryId)` 应答它们。回复键盘的点击就是普通文本消息。

## ButtonManager — 文件定义的按钮

定义位于 `BUTTONS_DIR`（默认 `/Button`）——主文件 `BUTTONS_FILE`（默认 `btn.php`）加上该目录中的任何其他 `*.php` 文件，后者会获得 `'<filename>.'` 键前缀（与语言加载器相同的模式）。

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

定义就是"文件返回什么便是什么"——大多数都是可直接发送的 `reply_markup` 数组，因此 `$tg->sendMessage($chatId, 'متن', ['reply_markup' => btn('start')])` 就是全部所需。

## 缓存（已验证）

编译后的定义被缓存在键 `buttons:all` 下，TTL 为 `BUTTONS_CACHE_TTL`（默认 300 秒），因此文件每个 TTL 窗口只读一次，而不是每次更新都读。`BUTTONS_CACHE_ENABLED=false` 会完全关闭缓存（每次都从文件重新读取定义）——自 P-CACHE-F002 起该开关已端到端接通（此前它只是被读入配置但从未被查询；该修复已在两种状态下实测验证）。`ButtonManager::reload()` 会在运行时强制刷新。

缓存键/覆盖范围遵循 [caching.md](caching.md)：在 `array` 上缓存是按进程的；在 `file`/`redis`/`memcached` 上定义在工作进程之间共享。

## 测试命令（integration/TestBot）

`/btn`（如果你的插件集中存在）——随附的 `StartPlugin` 展示了 `btn('start')` 的实际使用；P-CACHE 套件验证了 `btn('start')` 可正常解析（`rows=1`）且其载荷在 `buttons:all` 下跨进程持久存续。
