# Pipeline de file d'attente — quand un processus ne suffit plus

## Pourquoi une file d'attente ?

Le traitement inline a une faiblesse simple : le processus qui *reçoit* l'update doit aussi *finir* de le traiter. Si un handler téléverse une vidéo de 50 Mo ou attend une API tierce lente, le récepteur est bloqué (et en mode webhook, Telegram attend son 200). Le pipeline de file d'attente sépare le travail :

```
producer                          broker                    consumers (N processes)
──────                            ──────                    ────────
queue.php / webhook.php  ──push──► Redis / RabbitMQ ──pop──► bot.php workers
(pick up updates fast,            (holds updates while       (do the slow work,
 advance offset / 200)             nobody is around)          ack after processing)
```

Avantages : réception rapide (Telegram n'attend jamais vos handlers), passage à l'échelle horizontal (lancez plus de workers), et isolation des plantages (un worker qui meurt n'affecte pas le récepteur).

## Choisir un driver

| Driver | `QUEUE_SAVE_TYPE` | Requiert | Comportement du pop | Garantie en cas de plantage du worker |
|--------|-------------------|-------|---------------|---------------------------|
| JSON | `json` *(par défaut)* | rien | lecture destructive du fichier | **at-most-once** — tâche perdue si plantage en cours de traitement |
| Redis | `redis` | extension `redis` + serveur | `LPOP` (destructif) | **at-most-once** — tâche perdue si plantage en cours de traitement |
| Memcached | `memcached` | extension `memcached` + serveur | get/delete destructif | **at-most-once** |
| RabbitMQ | `rabbitmq` | extension `amqp` + serveur | `basic_get` + **ack-après-traitement** (v2.4.0) | **at-least-once** — la tâche non acquittée est re-livrée |

Choisissez RabbitMQ quand la perte d'un seul update est inacceptable ; choisissez Redis/JSON pour le débit et la simplicité quand une perte occasionnelle est tolérable (ou quand les updates sont peu coûteux à reconstruire).

## Le contrat d'ack de RabbitMQ (v2.4.0)

Depuis la v2.4.0, le driver RabbitMQ utilise des sémantiques **ack-late** :

1. `pop()` récupère un message et retient son delivery tag — le message n'est *pas* encore acquitté.
2. Votre handler s'exécute.
3. Le worker appelle `ack()` **après** le traitement. Ce n'est qu'alors que le broker supprime le message.
4. Si le worker meurt n'importe quand entre 1 et 3, le broker re-livre le message au worker suivant.

Au niveau du code, le worker fait exactement ceci :

```php
$update = $queue->pop();
try {
    $processor->handle($update);
} finally {
    $queue->ack();   // success → done; handled exception → discarded (no poison loops)
}
```

Conséquences à connaître :

- **Le traitement en double est possible** (plantage après le traitement mais avant l'ack — le broker ne peut pas le savoir). Rendez les handlers idempotents : stockez `update_id` avant d'agir, ignorez si déjà vu. Voir [reliability.md](reliability.md).
- Un message qui échoue au décodage est immédiatement acquitté (jeté) afin qu'il ne puisse pas empoisonner la file.
- Les drivers de file personnalisés n'ont pas besoin d'implémenter `ack()` — `QueueManager::ack()` est un no-op pour les drivers sans acquittement côté broker.

La configuration se trouve dans la section `rabbitmq` de `.env` (hôte, port, utilisateur, mot de passe, vhost, nom de file) — voir [.env.example](../../.env.example).

## Exécuter le pipeline

`update_queue` (producteur par polling) :

```bash
# terminal 1 — fetcher: long-polls Telegram, pushes to the broker
php queue.php

# terminal 2..N — consumers: pop + process + ack
php bot.php
```

`webhook_queue` (producteur webhook) :

```bash
# web server receives Telegram POSTs → validates → enqueues → 200
# (webhook.php handles this automatically when BOT_MODE=webhook_queue)

# terminal 1..N — consumers
php bot.php
```

Exécutez-les tous sous un superviseur. Les workers se reconnectent automatiquement au broker avec un backoff exponentiel (`BACKOFF_INITIAL=1s` … `BACKOFF_MAX=60s`), si bien que vous pouvez redémarrer Redis/RabbitMQ sans toucher aux workers.

## Files bornées

`QUEUE_MAX_SIZE` (valeur par défaut dans [.env.example](../../.env.example)) plafonne le nombre d'updates pouvant attendre dans le broker. Quand la limite est atteinte, les nouvelles poussées sont **refusées et journalisées** (WARNING), jamais silencieusement entassées dans un pile illimitée. Cela protège le disque/la RAM quand les workers sont indisponibles longtemps. Une taille de 0 désactive le plafond.

## La file de retry (distincte de la file principale)

La file de retry gère les *échecs transitoires des handlers* (HTTP `429`, `5xx`, timeouts, erreurs curl) pour tous les modes, pas seulement ceux en file :

- `Processor::handle()` intercepte une erreur réessayable → pousse l'update dans la `RetryQueue`.
- Les workers longue durée draineront les éléments arrivés à échéance lors de leur tick de maintenance (à chaque itération de boucle dans les workers de file).
- Le backoff double à chaque tentative ; quand Telegram fournit `retry_after`, c'est exactement cette valeur qui est honorée (`RetryableException::retryAfter`).
- Après `RETRY_MAX_ATTEMPTS` l'élément est jeté et journalisé.

Vos handlers y adhèrent avec le helper `tg_retryable()` :

```php
use function alirezax5\TelegramBase\App\Queue\tg_retryable;

$resp = tg_retryable($tg->sendMessage($chatId, 'Hello!'), 'sendMessage');
// ok=true            → returned unchanged
// 429 / 5xx          → RetryableException thrown → retry queue
// other 4xx          → returned unchanged (permanent errors are not retried)
```

Configuration : `RETRY_ENABLED`, `RETRY_QUEUE_DRIVER` (`json`|`redis`), `RETRY_MAX_ATTEMPTS`, `RETRY_BASE_DELAY`, `RETRY_MAX_SIZE` — valeurs par défaut dans [.env.example](../../.env.example).
