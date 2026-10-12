<?php
/**
 * Standalone validation of the users migrations on a throwaway sqlite DB:
 *   A) FRESH path:  create (full schema) -> extend (no-op)
 *   B) LEGACY path: old id-only table    -> extend (adds all columns)
 * Run from the repo root:  php tests/migration_check.php
 */
require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use alirezax5\TelegramBase\App\Database\MigrationRunner;

$db = '/tmp/users_migrate_' . getmypid() . '.sqlite';
@unlink($db);
touch($db); // sqlite connector requires the file to exist

$fails = 0;
function check(bool $cond, string $label, int &$fails): void {
    echo ($cond ? '  PASS ' : '  FAIL ') . $label . PHP_EOL;
    if (!$cond) { $fails++; }
}

function boot(string $db): array {
    $capsule = new Capsule();
    // Users model declares protected $connection = 'main'
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $db, 'prefix' => ''], 'main');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    return [$capsule, $capsule->getConnection('main')];
}

$repo = dirname(__DIR__);
$migDir = $repo . '/Database/Migrations';

echo '== A) FRESH: create(full) then extend ==', PHP_EOL;
[$capsule, $conn] = boot($db);
$runner = new MigrationRunner($migDir, $conn);
$runner->up();
$schema = $conn->getSchemaBuilder();
foreach (['id','chatid','username','first_name','last_name','lang','role','active','status','command','data','type','registered_at','created_at','updated_at'] as $col) {
    check($schema->hasColumn('users', $col), "fresh users.$col exists", $fails);
}
$idx = array_map(fn($i) => $i['name'], $schema->getIndexes('users'));
check((bool) array_filter($idx, fn($n) => str_contains($n, 'chatid')), 'unique index on chatid exists (' . implode(',', $idx) . ')', $fails);

// model roundtrip on fresh schema
\Database\Users::query()->insert(['chatid' => 111, 'lang' => 'fa', 'role' => 'admin', 'registered_at' => date('Y-m-d H:i:s')]);
check(\Database\Users::where('chatid', 111)->value('role') === 'admin', 'model write/read on fresh schema', $fails);
check(\Database\Users::checkAndInsert(111) === false, 'checkAndInsert on existing chatid -> false (no dup)', $fails);
check(\Database\Users::checkAndInsert(222, 'en', ['username' => 'tester', 'first_name' => 'Ali']) === true, 'checkAndInsert seeds lang+extra', $fails);
check(\Database\Users::where('chatid', 222)->value('lang') === 'en', 'seeded lang persisted', $fails);
check(\Database\Users::where('chatid', 222)->value('username') === 'tester', 'seeded username persisted', $fails);

// role API against real rows
check(\Database\Users::roleOf(222) === 'user', 'roleOf default user', $fails);
check(\Database\Users::setRole(222, 'moderator') === true, 'setRole moderator', $fails);
check(\Database\Users::isAtLeast(222, 'moderator') === true, 'isAtLeast moderator (granted)', $fails);
check(\Database\Users::isAtLeast(222, 'admin') === false, 'isAtLeast admin (denied)', $fails);
check(\Database\Users::setRole(222, 'superadmin') === false, 'setRole superadmin rejected (env-only)', $fails);
check(\Database\Users::setRole(222, 'hacker') === false, 'setRole unknown rejected', $fails);
check(\Database\Users::roleOf(999) === 'user', 'roleOf missing row -> user', $fails);

echo '== B) LEGACY: old id-only table then extend ==', PHP_EOL;
$db2 = $db . '.legacy';
@unlink($db2);
touch($db2);
[$capsule2, $conn2] = boot($db2);
$conn2->getSchemaBuilder()->create('users', function ($t) { $t->id(); $t->timestamps(); });
// real legacy installs already have the create migration recorded:
$conn2->getSchemaBuilder()->create('migrations', function ($t) { $t->string('migration')->primary(); $t->integer('batch'); });
$conn2->table('migrations')->insert(['migration' => '2026_08_05_203306_create_users_table.php', 'batch' => 1]);
// legacy rows only have id + timestamps (old create migration had NO chatid)
$conn2->table('users')->insert(['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
$runner2 = new MigrationRunner($migDir, $conn2);
$runner2->up();
$schema2 = $conn2->getSchemaBuilder();
foreach (['chatid','lang','role','active','status','command','data','type','registered_at'] as $col) {
    check($schema2->hasColumn('users', $col), "legacy users.$col added", $fails);
}
check($schema2->hasTable('users'), 'legacy users table survived migration', $fails);
check(\Database\Users::checkAndInsert(444, 'fa') === true, 'insert works on extended legacy table', $fails);
check(\Database\Users::where('chatid', 444)->value('lang') === 'fa', 'lang seeded on extended legacy table', $fails);
check(\Database\Users::roleOf(444) === 'user', 'roleOf safe on extended legacy table', $fails);

echo '== C) idempotency: run extend-style up() twice ==', PHP_EOL;
$runner3 = new MigrationRunner($migDir, $conn2);
try {
    $runner3->rollback(); // down() of batch
} catch (\Throwable $e) {
    echo '  (rollback note: ' . $e->getMessage() . ')' . PHP_EOL;
}
try {
    $runner3->up();
    check(true, 'second full up() cycle completed', $fails);
} catch (\Throwable $e) {
    check(false, 'second up() cycle threw: ' . $e->getMessage(), $fails);
}

@unlink($db); @unlink($db2);
echo $fails === 0 ? 'ALL MIGRATION CHECKS PASSED' : "FAILURES: {$fails}", PHP_EOL;
exit($fails === 0 ? 0 : 1);
