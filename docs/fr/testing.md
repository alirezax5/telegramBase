# Tests — la suite hors ligne, les tests en conditions réelles et la CI

## La suite de tests hors ligne

Le framework est livré avec un exécutant de tests sans dépendance (pas besoin de PHPUnit) :

```bash
composer install        # vendor/ must exist (the suite boots the framework)
php tests/run.php
```

Structure :

| Répertoire | Ce qui s'y trouve |
|-----------|------------------|
| `tests/Unit/` | tests unitaires purs — env handler, config, drivers de file, stockage d'offset, validation du dispatcher webhook, câblage des middleware, cycle de vie du retry, ... (pas de réseau) |
| `tests/Integration/` | tests multi-composants (toujours hors ligne) |
| `tests/Live/` | **tests sur la vraie API Telegram — sur inscription uniquement** (voir ci-dessous) |
| `tests/smoke.php` | test de fumée de bootstrap autonome (token mauvais, token vide, mode mauvais, boot valide) |
| `tests/benchmark.php` | micro-benchmarks des chemins chauds |

Filtrer par nom :

```bash
php tests/run.php --filter=Keyboard
php tests/run.php --filter=Queue
```

## Dérive connue de la suite — RÉSOLUE

Jusqu'à la v2.4.0, l'exécution hors ligne rapportait **196 pass / 18 fail / 12 skip**. Les 18 échecs (`SessionTest` ×10, `BackwardCompatTest` ×4, `MiddlewareWiringTest` ×4) étaient préexistants et avaient été prouvés sans lien avec les corrections du framework en annulant chaque correction et en ré-exécutant.

La cause racine a été trouvée pendant la revue de pré-livraison (P-REL-B05) : `tests/run.php` amorce les défauts d'env et appelle `Bootstrap::boot()`, mais `EnvironmentBootstrap` utilisait le `load()` de Dotenv, qui **lève une exception quand le dépôt n'a pas de `.env`** (un clone frais ne livre que `.env.example`). Le boot s'interrompait, `Config` restait non initialisé, et chaque test dépendant de la config échouait. Avec le passage à `safeLoad()`, la suite passe au vert dans un clone frais sans aucune préparation :

```
Pass: 224  Fail: 0  Skip: 12
```

## Tests Telegram en conditions réelles

Les tests en conditions réelles frappent la vraie API Bot et sont ignorés sauf activation explicite :

```bash
RUN_TELEGRAM_LIVE_TESTS=1 \
TELEGRAM_BOT_TOKEN="<your-token>" \
TELEGRAM_TEST_CHAT_ID="<your-chat-id>" \
php tests/run.php
```

Règles de bonne conduite (faites respecter par les tests eux-mêmes) :

- chaque message envoyé est étiqueté `[telegramBase automated test]`
- ils suppriment leurs propres messages après vérification quand c'est possible — et ne suppriment jamais les messages qu'ils n'ont pas créés
- chemins négatifs inclus : chat invalide, token invalide, 429 simulé
- le token est lu **uniquement** depuis les variables d'environnement

## CI (GitHub Actions)

`.github/workflows/tests.yml` s'exécute à chaque push/PR sur PHP **8.1 – 8.4** : validation composer, `composer audit`, et la suite hors ligne.

`.github/workflows/live-tests.yml` est un workflow **manuel** (`workflow_dispatch`) pour les tests en conditions réelles ; il lit le token depuis le secret de dépôt `TGBASE_LIVE_BOT_TOKEN` et une entrée `chat_id` — aucun secret dans le fichier de workflow, et la CI par défaut ne touche jamais aux secrets.

## Test de fumée

```bash
php tests/smoke.php
```

Six cas : token mauvais / token vide / tentative de path traversal des journaux / chemin absolu des journaux / mode mauvais / boot valide. Utilisez-le après les déploiements comme contrôle de santé de 5 secondes.

## Ce que couvre la suite (et pourquoi cela compte pour la confiance)

Les affirmations de fiabilité de [reliability.md](reliability.md) sont étayées par des tests plus des scénarios d'intégration éprouvés par plantages (SIGKILL à chaque étape du pipeline, vérifications réelles de re-livraison du broker, tests de transition webhook avec vérification `getWebhookInfo`). Les suites les plus pertinentes pour auditer ces affirmations :

```bash
php tests/run.php --filter=Queue      # driver behaviour (json/redis/rabbitmq contracts)
php tests/run.php --filter=Offset     # offset storage atomicity
php tests/run.php --filter=Retry      # retry queue lifecycle + retry_after
php tests/run.php --filter=Webhook    # the 5 webhook validation layers
php tests/run.php --filter=Security   # env/log hardening rules
```

Le harnais d'intégration utilisé pendant les tests de plantage (un vrai bot + scripts de scénarios) vit dans `integration/TestBot/` — consultez son README pour ce qu'il fait et comment l'adapter à votre propre déploiement.
