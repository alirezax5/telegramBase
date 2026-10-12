# Mini Apps — validation d'`initData`

Le frontend d'une Mini App reçoit de Telegram une chaîne d'identité **signée** — `Telegram.WebApp.initData` — qui prouve quel utilisateur Telegram consulte la page. Le backend doit vérifier cette signature avant de servir toute donnée propre à l'utilisateur.

- Framework : `alirezax5\TelegramBase\App\MiniApp\InitData`
- Projets utilisant uniquement la librairie (SDK) : `telegramBotApiPhp\WebApp\InitData`

> **Ne faites jamais confiance à `initDataUnsafe`** — mêmes champs, mais sans signature ; seule l'`initData` signée peut être vérifiée contre le token du bot. Le trafic Mini App ne passe **pas** par le webhook — c'est le navigateur de l'utilisateur qui appelle directement votre domaine.

## Utilisation backend

```php
use alirezax5\TelegramBase\App\MiniApp\InitData;

$raw = $_POST['initData'] ?? null;
if (!is_string($raw) || $raw === '') {
    $json = json_decode((string) file_get_contents('php://input'), true);
    $raw  = is_array($json) ? (string) ($json['initData'] ?? '') : '';
}

// token détecté automatiquement depuis BOT_TOKEN ; en multi-bots, passez-le explicitement :
$res = InitData::validate((string) $raw, null, ['max_age' => 3600]);

if (!$res['ok']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $res['error']]);
    exit;
}

$user       = $res['user'];        // ['id' => ..., 'first_name' => ...]
$startParam = $res['start_param']; // issu de ?startapp=
```

## API

| Méthode | Rôle |
|---|---|
| `validate()` | Résultat complet : `ok`, `user`, `auth_date`, `start_param`, `fields`, `hash` |
| `valid()` | Booléen uniquement |
| `user()` | Tableau utilisateur ou `null` |
| `build()` | Génère une initData signée (tests et développement uniquement) |

Options : `max_age` — âge maximal d'`auth_date` en secondes (défaut `86400`, `0` désactive le contrôle). Erreurs possibles : `empty_init_data`, `bot_token_missing`, `malformed_init_data`, `hash_missing`, `bad_signature`, `auth_date_missing`, `auth_date_expired`.

## Check-list sécurité

- Vérifiez côté serveur **chaque** requête privilégiée ; gardez `max_age` court (`3600` ou moins).
- Comparaison via `hash_equals` (temps constant) — jamais `===`.
- Ne journalisez ni `initData` ni les tokens de bots.
- Algorithme : `secret = HMAC_SHA256("WebAppData", token)` puis `HMAC_SHA256(secret, data_check_string)` — guide complet : [mini-apps.md](mini-apps.md) (en anglais).
