# TelegramBase

[English](README.md) · [فارسی](README.fa.md) · [中文](README.zh.md) · [Русский](README.ru.md) · **Français** · [Português](README.pt.md) · [العربية](README.ar.md)

Un mini-framework PHP 8.1+ modulaire pour construire des bots Telegram — avec un système de plugins, des backends de file d'attente, la validation webhook, des sessions, des middleware, un planificateur, des migrations et un scaffolding CLI. Construit sur des composants Illuminate (Laravel) au lieu de les réinventer.

## Qu'est-ce que c'est, en un paragraphe ?

TelegramBase s'occupe de la *plomberie* dont chaque bot Telegram a besoin — recevoir les updates (polling ou webhook), valider les requêtes webhook, dispatcher les updates vers votre code, retenter les appels API échoués, et dialoguer avec des backends de file d'attente comme Redis et RabbitMQ — pour que vous n'ayez à écrire que la partie *intéressante* : de petites classes PHP appelées **plugins** qui réagissent aux messages. Déposez une classe dans `Plugin/`, et le framework la découvre et l'exécute. C'est toute l'idée.

## Pourquoi existe-t-il ?

Écrire un bot « directement contre l'API » est facile jusqu'à l'arrivée du vrai trafic. Ensuite, il vous faut des réponses à des questions comme : *Que se passe-t-il si mon worker plante en plein milieu d'un update ? Où les requêtes webhook sont-elles validées ? Comment retenter un `429 Too Many Requests` sans perdre l'update ? Puis-je décharger le travail lourd hors de la requête HTTP ?* TelegramBase répond d'emblée à toutes ces questions :

- **Six modes de fonctionnement** (`update_direct`, `update_queue`, `webhook_direct`, `webhook_queue`, `cronjob_update`, `cronjob_queue`) — basculez entre polling et webhook, traitement inline et en file, en changeant une seule valeur `.env`.
- **Des sémantiques de livraison honnêtes** — le framework a été éprouvé par plantages de bout en bout (SIGKILL à chaque étape du pipeline). La garantie de chaque mode est documentée : les chemins webhook/direct sont *at-least-once*, le pipeline RabbitMQ est *at-least-once* (ack-après-traitement), les drivers destructifs (Redis/JSON/Memcached) sont *at-most-once*. Voir [docs/reliability.md](docs/fr/reliability.md).
- **Protégé contre les vraies erreurs de configuration** — exécuter un mode CLI uniquement derrière le point d'entrée webhook est rejeté avec une HTTP 500 au lieu de suspendre votre worker web dans une boucle de polling infinie (un bug que nous avons réellement rencontré lors des tests en production).
- **Batteries incluses** — sessions, constructeur de clavier, i18n, middleware (rate-limit / admin-guard / audit-log), planificateur, migrations Eloquent, files bornées, retry automatique avec le `retry_after` de Telegram.

## Prérequis

- PHP 8.1+
- Composer
- Extensions : `json`, `mbstring`, `curl`
- Optionnel : `redis`, `memcached`, `amqp` (backends de file/cache)
- Optionnel : MySQL/MariaDB (base de données, migrations Eloquent)

## Démarrage rapide

```bash
git clone https://github.com/alirezax5/telegramBase.git
cd telegramBase
composer install
cp .env.example .env
# Edit .env — set BOT_TOKEN (get one from @BotFather)
php bot.php
```

Envoyez `/start` à votre bot — le plugin de démarrage dans `Plugin/start.php` répond. Puis écrivez votre propre plugin (voir [docs/plugins.md](docs/fr/plugins.md)).

## Comment circule un update

```
Telegram
   │
   ├─ getUpdates long-poll ──► bot.php ──┐
   │                                     │
   └─ webhook POST ──► webhook.php ──────┤
                                         ▼
                                    Core::run()
                                         │
                       validate (webhook)│mode routing
                                         ▼
                                    Processor
                                         │
                             middleware pipeline
                                         ▼
                              your plugins (Plugin/)
```

## Documentation

Tout se trouve dans `docs/` — commencez par l'aperçu :

| Document | Ce qu'il contient |
|----------|---------------|
| [docs/overview.md](docs/fr/overview.md) | Ce qu'est le framework, ce qu'il fait, ce qu'il ne fait délibérément pas |
| [docs/architecture.md](docs/fr/architecture.md) | Séquence de démarrage, flux d'un update, carte des composants de `App/` |
| [docs/getting-started.md](docs/fr/getting-started.md) | Installer → premier plugin → déployer, étape par étape |
| [docs/bot-modes.md](docs/fr/bot-modes.md) | Les six `BOT_MODE`, points d'entrée, quels processus lancer |
| [docs/plugins.md](docs/fr/plugins.md) | Écrire des plugins : handlers, priorité, `#[ChatType]`, découverte |
| [docs/queue-pipeline.md](docs/fr/queue-pipeline.md) | Backends de file d'attente, workers, file de retry, sémantiques d'ack |
| [docs/webhook.md](docs/fr/webhook.md) | Mise en place du webhook, les 5 couches de validation, notes proxy/CDN |
| [docs/reliability.md](docs/fr/reliability.md) | Résultats des tests de plantage : garanties de livraison par mode, conseils d'idempotence |
| [docs/configuration.md](docs/fr/configuration.md) | Référence complète de `.env` |
| [docs/features.md](docs/fr/features.md) | Sessions, claviers, middleware, cache, i18n, planificateur, base de données |
| [docs/caching.md](docs/fr/caching.md) | Plongée dans CacheManager : drivers, TTL, portée du flush, matrice inter-processus (vérifiée en conditions réelles) |
| [docs/sessions.md](docs/fr/sessions.md) | État de session par chat : API, flux multi-étapes, notes de sécurité |
| [docs/i18n.md](docs/fr/i18n.md) | Traductions : disposition des fichiers, helper `__()`, langue par utilisateur, cache |
| [docs/buttons.md](docs/fr/buttons.md) | Constructeur de clavier + menus définis en fichier (`btn()`), gestion des callbacks |
| [docs/multi-bot.md](docs/fr/multi-bot.md) | Plusieurs bots dans un seul processus : `BotManager`, `BOT_EXTRA_BOTS`, pile de tokens (vérifié en conditions réelles) |
| [docs/testing.md](docs/fr/testing.md) | Suite hors ligne, tests Telegram en conditions réelles, test de fumée, CI |
| [docs/troubleshooting.md](docs/fr/troubleshooting.md) | Symptômes courants et corrections, pièges de déploiement |

Autres fichiers utiles :

- [CHANGELOG.md](CHANGELOG.md) — ce qui a changé dans chaque version
- [llms.txt](llms.txt) / [llms-ctx.txt](llms-ctx.txt) — index de documentation orienté LLM et contexte en fichier unique
- [.env.example](.env.example) — chaque clé de configuration avec ses valeurs par défaut
- [examples/HelloBot.php](examples/HelloBot.php) — un exemple de plugin complet

## CLI

```bash
php bin/tgbase list              # all commands
php bin/tgbase make:plugin admin/ban   # scaffold a plugin
php bin/tgbase doctor            # environment diagnostics
php bin/tgbase plugins           # list discovered plugins
php bin/tgbase migrate           # run database migrations
```

## Licence

MIT
