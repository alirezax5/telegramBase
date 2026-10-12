# Mini Apps — validação do `initData`

O frontend de um Mini App recebe do Telegram uma string de identidade **assinada** — `Telegram.WebApp.initData` — que prova qual usuário Telegram está vendo a página. O backend deve verificar essa assinatura antes de servir qualquer dado do usuário.

- Framework: `alirezax5\TelegramBase\App\MiniApp\InitData`
- Projetos apenas com a biblioteca (SDK): `telegramBotApiPhp\WebApp\InitData`

> **Nunca confie no `initDataUnsafe`** — mesmos campos, mas sem assinatura; apenas o `initData` assinado pode ser verificado contra o token do bot. O tráfego do Mini App **não passa** pelo webhook — é o navegador do usuário chamando seu domínio diretamente.

## Uso no backend

```php
use alirezax5\TelegramBase\App\MiniApp\InitData;

$raw = $_POST['initData'] ?? null;
if (!is_string($raw) || $raw === '') {
    $json = json_decode((string) file_get_contents('php://input'), true);
    $raw  = is_array($json) ? (string) ($json['initData'] ?? '') : '';
}

// token detectado automaticamente de BOT_TOKEN; em multi-bot, passe explicitamente:
$res = InitData::validate((string) $raw, null, ['max_age' => 3600]);

if (!$res['ok']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $res['error']]);
    exit;
}

$user       = $res['user'];        // ['id' => ..., 'first_name' => ...]
$startParam = $res['start_param']; // vindo de ?startapp=
```

## API

| Método | Função |
|---|---|
| `validate()` | Resultado completo: `ok`, `user`, `auth_date`, `start_param`, `fields`, `hash` |
| `valid()` | Apenas booleano |
| `user()` | Array do usuário ou `null` |
| `build()` | Gera initData assinada (apenas para testes e desenvolvimento) |

Opções: `max_age` — idade máxima do `auth_date` em segundos (padrão `86400`, `0` desativa a checagem). Erros: `empty_init_data`, `bot_token_missing`, `malformed_init_data`, `hash_missing`, `bad_signature`, `auth_date_missing`, `auth_date_expired`.

## Checklist de segurança

- Valide no servidor **cada** requisição privilegiada; mantenha `max_age` curto (`3600` ou menos).
- Comparação via `hash_equals` (tempo constante) — nunca use `===`.
- Não registre `initData` nem tokens de bots em logs.
- Algoritmo: `secret = HMAC_SHA256("WebAppData", token)` e depois `HMAC_SHA256(secret, data_check_string)` — guia completo: [mini-apps.md](mini-apps.md) (em inglês).
