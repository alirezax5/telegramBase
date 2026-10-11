# Cache — CacheManager

Une API statique unique par-dessus quatre drivers interchangeables, configurés entièrement via `.env`. Les sessions, les traductions, les définitions de boutons et (optionnellement) la découverte des plugins s'appuient tous sur cette couche — changer de driver change le mode de persistance de tout le framework sans toucher au code.

```php
use alirezax5\TelegramBase\App\Cache\CacheManager;
```

> Chaque affirmation de ce document a été vérifiée en conditions réelles (2026-10-11) contre de vrais backends sur un serveur de production : la suite à 17 vérifications est passée au vert sur `array`, `file`, `memcached` (PHP 8.5) et `redis`, plus une matrice de persistance inter-processus à 9 points pour chaque driver persistant. Voir la section *Vérification en conditions réelles* en bas et le [CHANGELOG](../../CHANGELOG.md).

## Drivers

| `CACHE_DRIVER` | Backend | Inter-processus | Tags | Remarques |
|---|---|---|---|---|
| `array` (par défaut) | mémoire par processus | ✗ | ✗ | zéro configuration ; l'état vit exactement aussi longtemps que le processus |
| `file` | disque, `CACHE_PATH` | ✓ | ✗ | aucun service supplémentaire ; répertoires à 2 niveaux hachés ; charges utiles sérialisées en PHP |
| `redis` | extension phpredis (`redis.so`) | ✓ | ✗ | charges utiles JSON ; recommandé pour les déploiements multi-processus |
| `memcached` | extension `memcached` + serveur | ✓ | ✗ | charges utiles JSON ; volatile par nature |

**Chaîne de repli (vérifiée) :** si le driver demandé est indisponible — extension manquante, serveur injoignable, échec d'authentification, chemin non inscriptible — le framework journalise un avertissement et **retombe sur `array`** au lieu de faire échouer le démarrage. Demander `memcached` sur un PHP sans l'extension a produit exactement cela : `driver=array`, tout le reste inchangé. Vérifiez ce que vous avez réellement obtenu avec `CacheManager::getDriver()`.

> ⚠️ Le driver `array` est local au processus. Un démon de polling est *un* processus de longue durée, donc `array` « fonctionne » là ; mais le webhook (workers FPM par requête) et les workers de file (un processus par worker) ne partagent jamais l'état. Si les sessions, les compteurs ou `remember()` doivent survivre entre les messages/processus, utilisez `file`, `redis` ou `memcached`. Cela a été démontré en conditions réelles : des valeurs écrites dans le processus A et lues dans le processus B — persistantes sur les trois, `<MISS>` sur `array` (par conception).

## Configuration (.env)

```dotenv
CACHE_DRIVER=file            # array | file | redis | memcached
CACHE_PREFIX=tgbase_         # key namespace (also the flush() safety boundary)
CACHE_TTL=3600               # default TTL seconds when put() has no explicit ttl
CACHE_PATH=/tmp/tgbase-cache # file driver only
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=              # required if the server has requirepass (NOAUTH otherwise)
REDIS_DATABASE=0
MEMCACHED_HOST=127.0.0.1
MEMCACHED_PORT=11211
```

## API

| Méthode | Comportement |
|---|---|
| `get($key, $default = null)` | valeur ou défaut en cas d'absence/expiration |
| `put($key, $value, ?int $ttl = null)` | stocke ; ttl `null` → `CACHE_TTL` |
| `remember($key, callable $cb, ?int $ttl = null)` | renvoie la valeur en cache ou exécute `$cb`, met le résultat en cache |
| `has($key)` | existe et non expiré |
| `forget($key)` | supprime une clé |
| `flush()` | supprime **toutes les clés du namespace de ce framework** — voir les notes par driver |
| `increment($key, $by = 1)` / `decrement($key, $by = 1)` | compteurs |
| `getDriver()` / `isInitialized()` / `tagsSupported()` | introspection |
| `putWithTags()` / `getWithTags()` / `rememberWithTags()` / `flushTags()` | API par tags qui fonctionne sur chaque driver (repli par préfixe) |
| `tags([...])` | cache tagué natif — **lève une exception** sauf si le driver prend en charge les tags (aucun des quatre ne le fait) |

### Sémantique du TTL (vérifiée avec de vraies attentes)

`put('k', 'x', 2)` → `has` vrai → après 3 s → `has` faux, `get` renvoie le défaut. Les TTL des backends correspondent exactement à la configuration : une entrée de 300 s montrait `ttl=297s` sous `redis-cli`, une session de 3600 s `ttl=3597s`, un cache de traduction de 60 s `ttl=57s`. Les entrées expirées dans `file`/`redis` sont supprimées paresseusement (à l'accès) ou par le backend (`setex` de `redis`).

### Formats de stockage

- **redis** — `setex` avec `json_encode(JSON_UNESCAPED_UNICODE)`. Les charges utiles doivent être aller-retour compatibles JSON : scalaires et tableaux oui ; les objets PHP reviennent sous forme de tableaux ; pas de resources.
- **memcached** — JSON via la même famille de stockage ; valeurs (dé)sérialisées de façon transparente par l'extension.
- **file** — charges utiles `serialize()` de PHP, estampille d'expiration en premier, sous `CACHE_PATH/ab/cd/<sha1>`.
- **array** — variables PHP ordinaires ; rien ne touche le disque ou le réseau.

### Disposition des clés (vue brute du backend)

| Driver | Clé brute pour `put('lang:fa', …)` avec `CACHE_PREFIX=tgbase_` |
|---|---|
| redis | `tgbase_lang:fa` |
| memcached | `tgbase_:lang:fa` (Illuminate ajoute `:` au préfixe) |
| file | `CACHE_PATH/<h1>/<h2>/<sha1>` |

### Compteurs — une subtilité (vérifiée)

`increment()` sur une clé **absente** : `array`/`file`/`redis` la créent (`incBy`), `memcached` renvoie `false` (Illuminate n'envoie pas de valeur initiale). Pattern portable : amorcez d'abord — `CacheManager::put($k, 0, $ttl); CacheManager::increment($k);`

### flush() — portée par driver

- `array` — ce processus uniquement.
- `file` — tout ce qui est dans `CACHE_PATH` (répertoire dédié ; sûr).
- `redis` — **cadré par préfixe** : itère en `SCAN` sur `{CACHE_PREFIX}*` et ne fait `UNLINK` que ces clés. Sûr sur des instances partagées (vérifié sur un Redis partagé avec des caches d'objets WordPress — les autres clés intactes). Refuse de s'exécuter avec un préfixe vide.
- `memcached` — ⚠️ `MemcachedStore::flush()` émet un `flush_all` sur tout le serveur. Sur un memcached **partagé**, cela anéantit le cache des autres applications — évitez-le là (la suite de tests le fait délibérément).

## Ce qui repose sur le cache

| Consommateur | Clé | Molette TTL |
|---|---|---|
| [Sessions](sessions.md) | `tgbase:session:{chatId}` | `SESSION_TTL` (défaut 3600, min 60) |
| [Traductions](i18n.md) | `lang:{lang}`, `lang:missing_keys` | `LANG_CACHE_TTL` (défaut 60) |
| [Définitions de boutons](buttons.md) | `buttons:all` | `BUTTONS_CACHE_TTL` (défaut 300), interrupteur `BUTTONS_CACHE_ENABLED` |
| Découverte des plugins | (interne) | `PLUGIN_CACHE_ENABLED` + `PLUGIN_CACHE_TTL` |

## Vérification en conditions réelles (P-CACHE, 2026-10-11)

| Suite | Résultat |
|---|---|
| Suite en-process à 17 vérifications × `array` / `file` / `memcached` (PHP 8.5) / `redis` | **PASS** sur tous |
| memcached demandé sur PHP 8.4 (sans l'extension) → repli gracieux sur `array` | **PASS** (chaîne documentée) |
| Matrice inter-processus (`set` proc → `get` proc) : scalaire, tableau imbriqué, compteur 5→6, `remember` renvoie l'aléatoire *planté*, continuité de l'id de session + charge utile, charge utile `lang:fa`, charge utile `buttons:all` | **9/9 PASS** sur `file`, `redis`, `memcached` ; `array` manque exactement comme documenté |
| Inspection des backends | clés redis avec compte à rebours TTL vivant ; clés brutes memcached sous `tgbase_:` ; store fichier avec estampilles d'expiration visibles |
| Pipeline réel : `/cache demo hello` (dispatch 1) → `/cache demo` (dispatch 2, processus frais) → `demo = hello` ; `/session-set`/`/session-get` pareil | **PASS** |

Un vrai défaut a été trouvé et corrigé par cet exercice : **`CacheRedisStore::flush()` terminait sa boucle SCAN à la première page MATCH vide** (fréquent sur des espaces de clés partagés/grands) — ne supprimant rien tout en renvoyant `true` (P-CACHE-F001 dans le CHANGELOG).
