# Webhook — recevoir les updates de Telegram

## Mise en place

```dotenv
# .env
BOT_MODE="webhook_direct"          # or webhook_queue
BOT_WEBHOOK_URL="https://yourdomain.com/webhook.php"
BOT_WEBHOOK_SECRET="a-long-random-string"   # openssl rand -hex 32
WEBHOOK_CHECK_IP=true
```

Enregistrez une seule fois (Telegram exige HTTPS avec un certificat valide) :

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/webhook.php&secret_token=<SECRET>"
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # verify: pending_update_count=0, no last_error
```

Désenregistrez (retour au polling — faites-le *avant* de démarrer `bot.php`, deux sources d'updates se disputent le même flux) :

```bash
curl "https://api.telegram.org/bot<TOKEN>/deleteWebhook"
```

## Les cinq couches de validation

Chaque requête arrivant sur `webhook.php` passe par `WebhookDispatcher`, dans l'ordre :

1. **Taille du corps** — au-delà de 1 Mo → `413`. (Telegram n'envoie jamais de charges plus grandes ; quoi que ce soit de plus grand n'est pas Telegram.)
2. **Liste blanche IP** — avec `WEBHOOK_CHECK_IP=true`, les requêtes venant d'IP en dehors des sous-réseaux officiels de Telegram → `403`. Les plages sont celles publiées dans la documentation Telegram (`149.154.160.0/20`, `91.108.4.0/22`, `91.108.8.0/22`, `91.108.56.0/22`, `91.108.16.0/22`, `95.161.64.0/20`).
3. **Jeton secret** — avec `BOT_WEBHOOK_SECRET` défini, l'en-tête `X-Telegram-Bot-Api-Secret-Token` doit correspondre, comparaison faite avec `hash_equals()` (protection contre les attaques temporelles). Discordance → `403`.
4. **Validation JSON** — le corps doit se parser en un objet contenant `update_id`. Sinon → `400`.
5. **Routage** — `webhook_direct` exécute les plugins de façon synchrone, puis répond `200` seulement si rien n'a levé d'exception ; `webhook_queue` enfile et répond `200` immédiatement.

Un échec de validation ne consomme jamais l'update de manière à le perdre — Telegram renvoie les updates pour lesquels il n'a pas obtenu de `2xx`.

## Derrière des proxys Cloudflare / nginx (important)

Si les requêtes de Telegram arrivent à PHP à travers Cloudflare (ou n'importe quel reverse proxy), le `REMOTE_ADDR` de PHP est l'IP du *proxy*, pas celle de Telegram — la vérification IP rejetterait chaque requête. Deux configurations qui fonctionnent :

- **Proxy devant :** gardez `WEBHOOK_CHECK_IP=false` et appuyez-vous sur le **jeton secret** comme authentification entrante (rendez-le long — 32+ caractères hexadécimaux). C'est exactement ainsi que le framework a été validé lors des tests en production.
- **Le proxy transmet la vraie IP :** configurez votre proxy pour envoyer `X-Forwarded-For` / `X-Real-IP` *et* faites-lui confiance côté PHP — alors `WEBHOOK_CHECK_IP=true` fonctionne. Ne faites cela que si vous contrôlez le proxy, sinon l'en-tête peut être usurpé.

Règle simple : **vérification IP = exposition directe ; secret = exposition derrière proxy.** Utiliser les deux est bien aussi.

## Comportement des modes sur le point d'entrée webhook

| `BOT_MODE` | Ce que fait `webhook.php` |
|------------|--------------------------|
| `webhook_direct` | valider → `Processor::handle()` → `200` une fois terminé |
| `webhook_queue` | valider → mettre en file → `200` immédiatement |
| `update_direct` | valider → `Processor::handle()` → `200` (fonctionne, mais journalise un avertissement — préférez `webhook_direct` explicitement) |
| `update_queue`, `cronjob_*` | `500` + message explicatif — ces modes sont CLI uniquement |

La dernière ligne est la garde SAPI de la v2.4.0 ([bot-modes.md](bot-modes.md)) : un mode CLI sur le point d'entrée web ne peut plus démarrer de boucle infinie dans votre worker PHP-FPM (ce qui finissait par un `Read timeout expired` côté Telegram et un 502 de votre serveur web).

## Sémantique des réponses — pourquoi le 200 compte

Telegram retente toute livraison webhook qui n'obtient pas de `2xx`, avec backoff, pendant plusieurs heures. Le framework en tire parti honnêtement :

- `200` n'est envoyé **qu'après** que l'update a été traité (direct) ou enfilé en toute sécurité (mode file).
- Une exception pendant le traitement → `500` → Telegram re-livra plus tard → l'update n'est **pas perdu**, mais *sera traité deux fois* si la première tentative s'était en réalité achevée avant le plantage. C'est la définition de la livraison at-least-once — voir [reliability.md](reliability.md) pour rendre les handlers idempotents.
- Un worker qui met des minutes à répondre est un bug, pas une fonctionnalité : le timeout client de Telegram est ~60s. Déplacez le travail lent vers `webhook_queue`.

## Enregistrement avec allowed updates (optionnel)

Restreignez ce que Telegram vous envoie aux types d'updates que vos plugins traitent réellement :

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=...&secret_token=...&allowed_updates=[\"message\",\"edited_message\",\"callback_query\",\"channel_post\",\"edited_channel_post\"]"
```

## Contrôles de santé

`getWebhookInfo` est votre tableau de bord — surveillez :

- `pending_update_count` — updates en attente chez Telegram parce que votre endpoint échoue/lent. Une croissance soutenue = incident.
- `last_error_message` / `last_error_date` — l'échec de livraison exact (timeouts, erreurs TLS, mauvais secret).

Une liste de vérification de déploiement pour le mode webhook :

1. `webhook.php` accessible en HTTPS (testez avec un navigateur : un GET vide devrait donner `400 empty` — c'est le dispatcher qui répond).
2. `BOT_WEBHOOK_SECRET` ≥ 32 caractères aléatoires, identique dans `.env` et `setWebhook`.
3. `WEBHOOK_CHECK_IP` correspond à votre topologie réseau (voir la section proxys).
4. `getWebhookInfo` propre après un message de test ; l'effet de bord de votre plugin visible.
