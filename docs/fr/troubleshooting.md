# Dépannage — symptômes, causes, corrections

## Démarrage & configuration

| Symptôme | Cause probable | Correction |
|---------|--------------|-----|
| `BOT_TOKEN is not set or empty` sur `migrate:status` | ancien comportement | corrigé dans la version actuelle — les commandes de migration n'exigent plus de token. Si vous le voyez encore, exécutez avec `TGBASE_SKIP_BOT_VALIDATION=1` |
| `could not find driver` sur `migrate` | `DATABASE_ENABLE=true` mais aucun driver PDO/DB configuré | mettez `DATABASE_ENABLE=false` si vous n'avez pas besoin de DB, ou installez le driver PDO |
| Le boot échoue avec une erreur de validation liée au mode | `BOT_MODE` mal orthographié | utilisez exactement l'un de : `update_direct`, `update_queue`, `webhook_direct`, `webhook_queue`, `cronjob_update`, `cronjob_queue` |
| Les journaux apparaissent dans le CWD au lieu de `logs/` | ancien comportement — chemin de journal relatif résolu par rapport au CWD | corrigé : `LogHandler` utilise `LoggerConfig::absolutePath()` (relatif à la base du projet). Affectait aussi l'exécution de `bin/tgbase` depuis un autre répertoire |
| `Session::regenerate()` fatal : « Cannot modify readonly property » | ancien comportement | corrigé : `Session` ne déclare plus `$sessionId` readonly |
| `tests/smoke.php` échoue sur tous les cas négatifs | ancienne logique match défectueuse | corrigé dans la version actuelle |
| Plugins non chargés | mauvais `PLUGINS_DIR`, ou cache de plugins périmé | le défaut est `/Plugin/` (relatif à la base du projet ; chemins absolus pris en charge). En dev, mettez `PLUGIN_CACHE_ENABLED=false`, puis `php bin/tgbase plugins` pour vérifier la découverte |
| Plantage du limiteur de débit / garde admin sur les callback queries | ancien accès non-nullsafe `->message->chat->id` | corrigé : chaînes nullsafe + `edited_message` / `channel_post` / `chat_member` pris en charge |

## Webhook

| Symptôme | Cause probable | Correction |
|---------|--------------|-----|
| Le webhook renvoie `403` pour chaque livraison | liste blanche IP active mais un proxy/CDN réécrit l'IP source | derrière Cloudflare/nginx mettez `WEBHOOK_CHECK_IP=false` et appuyez-vous sur un `BOT_WEBHOOK_SECRET` solide — voir [webhook.md](webhook.md) |
| Le webhook renvoie `403`, `getWebhookInfo` indique une discordance de secret | `BOT_WEBHOOK_SECRET` dans `.env` ≠ `secret_token` passé à `setWebhook` | rendez-les identiques ; rotation avec `openssl rand -hex 32` |
| Le webhook renvoie `500` avec « BOT_MODE ... is CLI-only » | mode CLI uniquement sur le point d'entrée web (la garde SAPI v2.4.0) | mettez `BOT_MODE=webhook_direct` / `webhook_queue` pour le point d'entrée web, ou exécutez le mode depuis la CLI (`bot.php` / `queue.php`) |
| `getWebhookInfo` : `last_error_message="Read timeout expired"` | l'endpoint n'a pas répondu à temps (avant v2.4.0 cela pouvait être le bug de boucle infinie ; après v2.4.0 ce sont des handlers lents) | passez à ≥ v2.4.0 ; cherchez les handlers lents ; déplacez le travail lourd vers `webhook_queue` |
| `pending_update_count` ne cesse de croître | votre endpoint est en panne/échoue continuellement | vérifiez `last_error_message`, la validité du certificat TLS, et que `webhook.php` est joignable ; un GET nu renvoyant `400 empty` prouve que le dispatcher est vivant |
| Les réponses du webhook sont retardées de plusieurs secondes | les handlers s'exécutent inline pendant la requête HTTP | passez à `webhook_queue` — l'endpoint répond alors immédiatement et les workers traitent de façon asynchrone |

## Files d'attente & workers

| Symptôme | Cause probable | Correction |
|---------|--------------|-----|
| `RabbitMQ ... "Broken pipe or closed connection"` à la connexion | ancien bug de méthode de login (`default` n'est pas un mécanisme AMQP valide) | corrigé en v2.4.0 (le driver utilise désormais `AMQPLAIN`) ; vérifiez quand même votre utilisateur/vhost RabbitMQ |
| Updates poussés mais jamais traités | aucun consommateur en exécution | les modes `*_queue` ont besoin des deux côtés : producteur (`queue.php` ou `webhook.php`) **et** consommateur (`php bot.php`) |
| La file croît sans limite pendant que les workers sont à l'arrêt | | définissez `QUEUE_MAX_SIZE` (les files bornées refusent + journalisent quand elles sont pleines) |
| Traitement en double après un plantage de worker | attendu avec at-least-once (ack-late RabbitMQ, re-livraison webhook/polling) | rendez les handlers idempotents — voir [reliability.md](reliability.md) |
| Le worker sort silencieusement sous charge | superviseur absent | exécutez tous les processus longs sous systemd/supervisord avec `Restart=always` ; le framework se redémarre lui-même en polling toutes les `POLLING_RESTART_INTERVAL` séries, le superviseur couvre tout le reste |
| `409 Conflict` depuis `getUpdates` | deux processus pollent le même token de bot (ou webhook + polling simultanément) | une seule source d'updates : lancez un seul fetcher, et `deleteWebhook` avant de démarrer le polling |

## Notes de déploiement qui valent de l'or

- **Permissions des fichiers de journal (FPM) :** si le point d'entrée webhook ne peut pas écrire les journaux, c'est généralement le propriétaire du fichier — PHP-FPM tourne sous `www-data`/`www`, donc les fichiers/répertoires de journaux doivent être inscriptibles par cet utilisateur (`chown www-data:www-data logs -R`). Une écriture de journal silencieusement sans effet est difficile à remarquer autrement.
- **PHP-FPM `request_terminate_timeout` :** tout ce que votre handler webhook fait au-delà de cette limite meurt avec un 502. Gardez le traitement webhook rapide ; utilisez la file pour le reste.
- **`php bin/tgbase doctor`** est l'outil de triage le plus rapide — il vérifie la version/les extensions PHP, la lisibilité de `.env`, l'inscriptibilité de `AppData/`, l'inscriptibilité des journaux et le format du token sans toucher à Telegram.
- **Deux sources d'updates qui se disputent le flux :** après une bascule entre polling et webhook, vérifiez que la bascule est terminée (`deleteWebhook` ou un arrêt propre du polling) avant de démarrer l'autre côté. Les deux en même temps = conflits d'offset et 409.

## Référence rapide des diagnostics

```bash
php bin/tgbase doctor              # environment triage
php bin/tgbase plugins             # what the framework actually discovered
php tests/smoke.php                # 6-case bootstrap check
php tests/run.php --filter=Queue   # subsystem deep-dive
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"   # Telegram-side view
```
