# Fiabilité — ce qui se passe réellement quand les choses plantent

Ce document est inhabituel pour une documentation de framework : tout ce qu'il contient a été **mesuré, pas supposé**. Pendant les tests d'intégration (phases 3.2–3.3), des workers ont été tués avec `SIGKILL` à chaque étape de chaque pipeline — pendant la récupération (fetch), pendant la poussée en file, au milieu d'un handler, après le handler mais avant l'ack, avant/après les écritures d'offset — et l'issue de chaque scénario a été enregistrée. Cette page est le résultat distillé, et elle guide à la fois la conception du framework et les conseils ci-dessous.

## Garanties de livraison par chemin

| Chemin | Garantie | Pourquoi | Fenêtre de duplication |
|------|-----------|-----|------------------|
| Polling (`update_direct`) | **at-least-once** | l'offset `getUpdates` n'est persisté qu'*après* le retour de `Processor::handle()` | plantage après les effets de bord, avant l'écriture de l'offset |
| Webhook (`webhook_direct`) | **at-least-once** | le `200` n'est envoyé qu'après le retour de `handle()` ; Telegram retente tout le reste | plantage après les effets de bord, avant le 200 |
| Webhook → file (`webhook_queue`) | **at-least-once vers le broker** | `200` seulement après un enfilement réussi | panne du broker entre l'enfilement et le 200 |
| Consommateur de file — **RabbitMQ** (v2.4.0) | **at-least-once** | ack-après-traitement : les messages non acquittés sont re-livrés par le broker | plantage après le traitement, avant l'ack |
| Consommateur de file — Redis / JSON / Memcached | **at-most-once** | pop destructif — le message est retiré au moment du pop | aucune (un plantage en cours de traitement perd la tâche) |
| File de retry | **at-least-once, bornée** | éléments re-dispatchés avec backoff jusqu'à `RETRY_MAX_ATTEMPTS` | identique à son chemin parent |

Deux observations honnêtes issues des tests de plantage :

- **Le exactly-once n'existe pas.** Ni dans TelegramBase, ni dans aucun système construit au-dessus d'un réseau. Vous choisissez entre « ne jamais perdre » (at-least-once, doublons possibles en cas de plantage) et « ne jamais dupliquer » (at-most-once, pertes possibles en cas de plantage). Le framework vous donne les deux familles et documente laquelle est laquelle.
- **Les fenêtres de duplication sont étroites mais réelles.** Elles ne s'ouvrent que sur un plantage à l'instant précis le plus défavorable. Elles se ferment avec des handlers idempotents.

## Rendre les handlers idempotents (les 90 % pratiques)

La livraison at-least-once n'est pénible que lorsque le même update *fait l'objet d'une action* deux fois. Une seule garde rend chaque fenêtre inoffensive :

```php
public function onMessage($message, Telegram $tg): void
{
    $update  = $tg->getInputData();
    $uId     = $update->update_id ?? null;

    // Cheap, atomic, per-update marker — works with any cache driver.
    if (!empty(cache()->get("upd:{$uId}"))) {
        return;                       // already processed (redelivery) — skip
    }

    // ... do the actual work ...

    cache()->set("upd:{$uId}", 1, 3600);   // remember for an hour
}
```

Stockez le marqueur *avant* les effets de bord irréversibles quand c'est possible, et acceptez qu'un plantage entre le marqueur et l'effet de bord transforme un doublon en perte — choisissez le compromis adapté à l'action (envoyer un message deux fois est généralement moins coûteux que ne jamais l'envoyer).

## Comportement en cas de plantage, par composant

| Composant | Scénario de plantage | Comportement observé |
|-----------|---------------|--------------------|
| Worker de polling (`update_direct`) | `SIGKILL` au milieu d'une série | offset inchangé → mêmes updates re-livrés au redémarrage → retraités (l'idempotence compte) |
| Worker de polling | `SIGKILL` entre les séries | propre ; au pire l'update en cours est re-livré |
| Fetcher de file (`queue.php`) | `SIGKILL` après le pop, avant le push | l'update est toujours du côté de Telegram (offset non avancé) → re-livré |
| Fetcher de file | `SIGKILL` après le push, avant l'écriture de l'offset | l'update est dans le broker **et** toujours du côté de Telegram → un redémarrage du producteur le dupliquerait dans le broker (borné par `QUEUE_MAX_SIZE` ; l'idempotence des consommateurs l'absorbe) |
| Worker RabbitMQ (avant v2.4.0) | `SIGKILL` en cours de traitement | tâche **perdue définitivement** (l'ack avait déjà été envoyé) — corrigé en v2.4.0 |
| Worker RabbitMQ (v2.4.0+) | `SIGKILL` en cours de traitement | tâche **re-livrée** au worker suivant (non acquittée) |
| Worker Redis/JSON | `SIGKILL` en cours de traitement | tâche définitivement perdue (pop destructif) — par conception, documenté |
| Webhook (FPM) | le handler dépasse ~60 s | Telegram expire et retente ; si le handler finit par se terminer, la nouvelle tentative le duplique — gardez le traitement webhook rapide ou utilisez `webhook_queue` |
| Broker indisponible (tout mode en file) | connexion refusée | les workers reculent exponentiellement (1 s → 60 s), se reconnectent et reprennent ; le fetcher conserve l'offset tant que les poussées n'aboutissent pas |

## Ressources bornées

- **Les files sont bornées** (`QUEUE_MAX_SIZE`, `RETRY_MAX_SIZE`) : quand elles sont pleines, les poussées sont refusées et journalisées — une panne ne peut pas remplir votre disque en silence.
- **La mémoire est bornée** : le worker de polling se redémarre lui-même toutes les `POLLING_RESTART_INTERVAL` séries (configurable, `0` désactive).
- **Les journaux sont protégés contre le path traversal** : `LOG_DIR`/`LOG_FILE` ne peuvent pas pointer en dehors du projet.

## Taxonomie des erreurs utilisée par la file de retry

`RetryableException::isRetryable()` classe les erreurs afin que seuls les échecs *transitoires* soient retentés :

- HTTP `429 Too Many Requests` (en honorant `parameters.retry_after` de Telegram quand présent)
- HTTP `5xx` / « erreur serveur »
- timeouts (`timeout`, `timed out`, `connect`)
- `cURL error N`
- toute sous-classe de `RetryableException` que vous levez vous-même

Tout le reste (400 « chat not found », 403 « bot blocked », ...) est permanent — journalisé, pas retenté.

## Tester votre propre posture de fiabilité

La suite de tests fournie inclut les primitives liées aux plantages (atomicité du stockage d'offset, drivers de file, cycle de vie du retry, chemins de validation du dispatcher webhook) :

```bash
php tests/run.php --filter=Queue     # queue driver behaviour
php tests/run.php --filter=Offset    # offset storage
php tests/run.php --filter=Retry     # retry lifecycle
php tests/run.php --filter=Webhook   # webhook validation layers
```

Pour une confiance de bout en bout, répétez l'approche d'intégration sur votre propre déploiement : enregistrez le webhook, envoyez un message marqueur, `kill -9` le worker en plein vol, et vérifiez la re-livraison dans `getWebhookInfo` / les journaux.
