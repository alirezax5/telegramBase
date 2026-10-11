# Aperçu — ce qu'est TelegramBase

## La version en une phrase

TelegramBase est un framework PHP qui transforme « dialoguer avec l'API Bot de Telegram » en « écrire de petites classes de plugin », tout en gérant tout ce dont les bots fiables ont besoin : récupération des updates, validation du webhook, mise en file d'attente, tentatives de nouvelle exécution (retries) et cycle de vie des processus.

## Ce qu'il fait

Chaque bot Telegram — aussi simple soit-il — a besoin du même squelette :

1. **Recevoir les updates.** Soit vous demandez à Telegram « du nouveau ? » (long-polling via `getUpdates`), soit Telegram appelle votre URL HTTPS (webhook). TelegramBase prend en charge les deux, le choix se faisant par une seule valeur dans `.env`.
2. **Valider les requêtes webhook.** N'importe qui peut envoyer en POST de faux updates vers votre URL. TelegramBase vérifie la taille du corps, l'IP source (sous-réseaux officiels de Telegram), un jeton secret optionnel et la bonne formation du JSON avant même que votre code ne s'exécute.
3. **Router les updates vers votre code.** Un `Processor` normalise l'objet update brut et le remet à chaque plugin dont les filtres correspondent (type de chat, ordre de priorité), le cas échéant via un pipeline de middleware.
4. **Survivre aux pannes.** Les appels API peuvent renvoyer `429 Too Many Requests`. Les handlers peuvent lever des exceptions. Les workers peuvent être tués en plein milieu d'un update. Les brokers de file d'attente peuvent tomber. TelegramBase a une réponse pour chacun de ces cas : une file de retries avec backoff exponentiel (en respectant le `retry_after` de Telegram), des tailles de files bornées, une reconnexion au broker avec backoff, et des garanties de livraison documentées par mode.
5. **Passer à l'échelle quand c'est nécessaire.** Commencez avec un unique processus `php bot.php`. Quand la charge devient importante, passez à un pipeline de file d'attente : un fetcher léger pousse les updates dans Redis/RabbitMQ et des processus workers séparés les consomment.

TelegramBase fait tout cela et expose une surface d'API petite, sans surprise et testable.

## Ce qu'il ne fait délibérément pas

- **Ce n'est pas un SDK de bot.** Les appels effectifs à l'API Telegram proviennent de [`alirezax5/telegram-bot-php`](https://github.com/alirezax5/telegram-bot-php) (l'objet `Telegram` injecté dans vos plugins). TelegramBase est la couche *autour* de ce SDK.
- **Il n'écrit pas la logique de votre bot.** Il n'y a ni « constructeur de conversations » ni panneau d'administration. Vous écrivez des classes PHP ordinaires ; le framework les planifie et les alimente.
- **Il ne masque pas PHP.** Pas de DSL maison, pas de définitions de bot en YAML. Si vous connaissez PHP 8 et l'API Bot de Telegram, vous savez comment fonctionne TelegramBase.

## Philosophie de conception

- **La configuration plutôt que le code.** Mode de fonctionnement, backend de file d'attente, driver de cache, langue — tout se change via `.env`, sans modifier les classes.
- **Des composants ennuyeux (au bon sens du terme).** Les sessions, le cache et les verrous s'appuient sur Illuminate (`illuminate/cache`, `illuminate/database`). Ce sont les mêmes composants que Laravel utilise en production ; TelegramBase n'ajoute aucune dépendance exotique.
- **Honnête face aux pannes.** Chaque chemin de traitement possède une garantie de livraison documentée (at-least-once ou at-most-once) — mesurée en tuant réellement des workers avec SIGKILL pendant les tests d'intégration, pas en devinant. Voir [reliability.md](reliability.md).
- **Échouer bruyamment, pas en silence.** Un mode CLI sur un point d'entrée web renvoie une HTTP 500 avec une explication, au lieu de rester suspendu en silence. Une file pleine refuse et journalise les nouvelles poussées plutôt que de remplir votre disque.

## La forme du code

```
App/          the framework (one concern per subdirectory)
Plugin/       YOUR plugins (auto-discovered)
Button/       keyboard label definitions
Language/     translation files
Database/     Eloquent models + migrations
bin/          CLI tools (tgbase, scheduler)
docs/         documentation (you are here)
tests/        offline + live test suite
```

Tout ce qui se trouve dans `App/` vous est servi à travers une poignée de classes d'entrée : `Core` (modes d'exécution), `Processor` (dispatch des updates), `QueueManager` (backends de file d'attente), `SessionManager` (état par chat), `Keyboard` (claviers fluides), `LogHandler` (journalisation). Le reste est la plomberie dont ces cinq-là ont besoin.

## Pour aller plus loin

- [getting-started.md](getting-started.md) — installer et lancer votre premier bot
- [architecture.md](architecture.md) — comment les pièces s'emboîtent
- [bot-modes.md](bot-modes.md) — choisir entre les six modes de fonctionnement
