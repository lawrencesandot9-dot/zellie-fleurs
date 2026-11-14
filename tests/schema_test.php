<?php
declare(strict_types=1);

/**
 * Isolated schema-bootstrap test suite (Batch 3).
 *
 * Every scenario runs against throwaway SQLite databases in a temp
 * directory (ZF_DB_PATH + ZF_LOG_PATH overrides) — never against the
 * production data/shop.db. Run: php tests/schema_test.php
 *
 * Scenarios:
 *  1. Fresh initialization (schema, indexes, seeds, marker, credential file)
 *  2. Repeated initialization without duplicate seeds
 *  3. Existing-database migration (marker 1 → current)
 *  4. Partial schema recovery (dropped table / dropped column)
 *  5. Concurrent first-boot initialization (4 parallel processes)
 *  6. Fast path performs zero writes on an initialized database
 *  7. Production database remains byte-identical throughout
 */

$failures = 0;
$passes = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        echo "  PASS  {$name}\n";
    } else {
        $failures++;
        echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n{$title}\n";
}

// Locate the project root from this file's position.
$projectRoot = dirname(__DIR__);

// Snapshot the production database before any test touches anything.
$shopDb = $projectRoot . '/data/shop.db';
$shopDbBefore = is_file($shopDb) ? sha1_file($shopDb) : 'absent';

$tmpDir = sys_get_temp_dir() . '/zf-schema-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0775, true);

$fixturePath = $tmpDir . '/bootstrap_fixture.php';
$logPath = $tmpDir . '/schema-test.log';

// Standalone fixture: a fresh PHP process that bootstraps the application
// against a per-scenario database path. Optional actions (drop table/column,
// legacy downgrade, checkpoint) run AFTER the normal bootstrap.
file_put_contents($fixturePath, <<<'FIXTURE'
<?php
declare(strict_types=1);
$action = $argv[1] ?? 'bootstrap';
$dir = $argv[2];
$dbPath = $dir . '/shop.db';
putenv('ZF_DB_PATH=' . $dbPath);
putenv('ZF_LOG_PATH=' . $argv[3]);
putenv('ZF_DEBUG');
putenv('ZF_ADMIN_PASSWORD=');            // force generated credential file
putenv('ZF_ADMIN_USER');                 // default username
require $argv[4] . '/config/database.php';
switch ($action) {
    case 'legacy':                       // emulate current production marker
        $db->exec('PRAGMA user_version = 1');
        break;
    case 'droptable':
        $db->exec('DROP TABLE lookup_attempts');
        break;
    case 'dropcol':
        if (version_compare((string) $db->query('SELECT sqlite_version()')->fetchColumn(), '3.35', '<')) {
            echo 'drop-column-unsupported';
            exit(0);
        }
        $db->exec('ALTER TABLE admin_users DROP COLUMN session_invalid_before');
        break;
    case 'checkpoint':                   // flush WAL so file hashes are stable
        $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        break;
}
echo 'db:' . $dbPath;
FIXTURE);

/**
 * Run one bootstrap scenario in an isolated subprocess.
 * Returns [exit code, stdout, stderr].
 */
function run_bootstrap(string $action, string $dir): array
{
    global $fixturePath, $logPath, $projectRoot;

    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        PHP_BINARY . ' ' . escapeshellarg($fixturePath) . ' '
            . escapeshellarg($action) . ' '
            . escapeshellarg($dir) . ' '
            . escapeshellarg($logPath) . ' '
            . escapeshellarg($projectRoot),
        $spec,
        $pipes
    );
    if (!is_resource($process)) {
        return [-1, '', 'proc_open failed'];
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

/** Open a read handle on a scenario database. */
function open_db(string $dir): PDO
{
    $db = new PDO('sqlite:' . $dir . '/shop.db', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');
    return $db;
}

function product_count(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
}

function admin_count(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
}

function marker(PDO $db): int
{
    return (int) $db->query('PRAGMA user_version')->fetchColumn();
}

function expected_tables(): array
{
    return ['products', 'orders', 'admin_users', 'login_attempts', 'lookup_attempts', 'settings'];
}

function tables_of(PDO $db): array
{
    return $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
}

function indexes_of(PDO $db): array
{
    $names = $db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'idx_%'")->fetchAll(PDO::FETCH_COLUMN);
    sort($names);
    return $names;
}

$defaultSettings = null;
{
    define('ZF_ROOT', $projectRoot);
    define('ZF_DATA', $tmpDir);
    define('ZF_DEBUG', false);
    require $projectRoot . '/src/database.php'; // brings ZF_SCHEMA_VERSION
    require $projectRoot . '/src/helpers.php';
    $defaultSettings = zf_setting_defaults();
}

// ============================================================
section('1. Fresh database initialization');
$freshDir = $tmpDir . '/fresh';
mkdir($freshDir, 0775, true);
[$exit, $out, $err] = run_bootstrap('bootstrap', $freshDir);
check('fresh bootstrap exits cleanly', $exit === 0 && str_contains($out, 'db:'), "exit {$exit} err {$err}");
$fresh = open_db($freshDir);
check('all six tables created', !array_diff(expected_tables(), tables_of($fresh)), 'missing: ' . implode(',', array_diff(expected_tables(), tables_of($fresh))));
check('all six indexes created', count(indexes_of($fresh)) === 6, 'found: ' . implode(',', indexes_of($fresh)));
check('seed products inserted exactly once', product_count($fresh) === 6, 'found ' . product_count($fresh));
check('exactly one admin user seeded', admin_count($fresh) === 1, 'found ' . admin_count($fresh));
check('schema marker stamped to current version', marker($fresh) === ZF_SCHEMA_VERSION, 'marker ' . marker($fresh));
check('migrated admin column present', (bool) $fresh->query("SELECT COUNT(*) FROM pragma_table_info('admin_users') WHERE name = 'session_invalid_before'")->fetchColumn());
$settingRows = $fresh->query('SELECT key, COUNT(*) AS c FROM settings GROUP BY key HAVING c > 1')->fetchAll(PDO::FETCH_COLUMN);
check('settings seeded without duplicates', $settingRows === [] && (int) $fresh->query('SELECT COUNT(*) FROM settings')->fetchColumn() === count($defaultSettings), json_encode($settingRows));
$settingValues = $fresh->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_ASSOC);
$defaultsMatch = true;
foreach ($defaultSettings as $key => $value) {
    $row = array_values(array_filter($settingValues, static fn ($r) => $r['key'] === $key));
    if ($row === [] || (string) $row[0]['value'] !== (string) $value) {
        $defaultsMatch = false;
    }
}
check('settings carry default values', $defaultsMatch);
check('generated admin credential file created with 0600', is_file($freshDir . '/initial_admin_credentials.txt') && (fileperms($freshDir . '/initial_admin_credentials.txt') & 0777) === 0600);
$fresh = null;

// ============================================================
section('2. Repeated initialization is a no-op (no duplicate seeds)');
[$exit2, $out2, $err2] = run_bootstrap('bootstrap', $freshDir);
check('second bootstrap exits cleanly', $exit2 === 0, "exit {$exit2} err {$err2}");
$repeat = open_db($freshDir);
check('no duplicate seed products after repeat', product_count($repeat) === 6, 'found ' . product_count($repeat));
check('no duplicate admin after repeat', admin_count($repeat) === 1, 'found ' . admin_count($repeat));
$dupSettings = $repeat->query('SELECT COUNT(*) FROM (SELECT key FROM settings GROUP BY key HAVING COUNT(*) > 1)')->fetchColumn();
check('no duplicate settings after repeat', (int) $dupSettings === 0);
check('marker unchanged after repeat', marker($repeat) === ZF_SCHEMA_VERSION);
$repeat = null;

// ============================================================
section('3. Existing database migration (legacy marker 1 → current)');
$legacyDir = $tmpDir . '/legacy';
mkdir($legacyDir, 0775, true);
[$exit3, $out3, $err3] = run_bootstrap('legacy', $legacyDir);
check('legacy bootstrap exits cleanly', $exit3 === 0, "exit {$exit3} err {$err3}");
$legacy = open_db($legacyDir);
check('legacy marker emulated as 1', marker($legacy) === 1, 'marker ' . marker($legacy));
// Add application data that MUST survive the one-time re-initialization.
$legacy->exec("INSERT INTO products (name, category, price, description, image_url, stock_quantity, is_active) VALUES ('Test Custom Bloom', 'Bouquets', 999, 'custody probe', '', 3, 1)");
$legacy->exec("INSERT INTO orders (order_code, client_token, customer_name, contact_number, delivery_address, delivery_date, payment_method, total_amount, items_json) VALUES ('ORD-TEST-1', 'tok-1', 'Ada', '0917', 'Somewhere', '2026-12-01', 'COD', 999, '[]')");
$legacy = null;
[$exit3b, $out3b, $err3b] = run_bootstrap('bootstrap', $legacyDir);
check('bootstrap over legacy DB exits cleanly', $exit3b === 0, "exit {$exit3b} err {$err3b}");
$legacy = open_db($legacyDir);
check('legacy DB upgraded to current marker', marker($legacy) === ZF_SCHEMA_VERSION, 'marker ' . marker($legacy));
check('custom product survived upgrade', (bool) $legacy->query("SELECT COUNT(*) FROM products WHERE name = 'Test Custom Bloom'")->fetchColumn());
check('custom order survived upgrade', (bool) $legacy->query("SELECT COUNT(*) FROM orders WHERE order_code = 'ORD-TEST-1'")->fetchColumn());
check('no re-seed of products on legacy upgrade', product_count($legacy) === 7, 'found ' . product_count($legacy));
check('no duplicate admin on legacy upgrade', admin_count($legacy) === 1);
check('no duplicate settings on legacy upgrade', (int) $legacy->query('SELECT COUNT(*) FROM (SELECT key FROM settings GROUP BY key HAVING COUNT(*) > 1)')->fetchColumn() === 0);
$legacy = null;

// ============================================================
section('4. Partial schema recovery (dropped table / column)');
$droppedDir = $tmpDir . '/droptable';
mkdir($droppedDir, 0775, true);
run_bootstrap('droptable', $droppedDir);
$probe = open_db($droppedDir);
check('lookup_attempts really dropped', !in_array('lookup_attempts', tables_of($probe), true));
check('marker still current after drop', marker($probe) === ZF_SCHEMA_VERSION);
$probe = null;
[$exit4, $out4, $err4] = run_bootstrap('bootstrap', $droppedDir);
check('bootstrap recovers from dropped table', $exit4 === 0, "exit {$exit4} err {$err4}");
$probe = open_db($droppedDir);
check('lookup_attempts recreated', in_array('lookup_attempts', tables_of($probe), true));
check('other tables untouched during recovery', !array_diff(expected_tables(), tables_of($probe)));
check('no re-seed during recovery', product_count($probe) === 6);
$probe = null;

$droppedColDir = $tmpDir . '/dropcol';
mkdir($droppedColDir, 0775, true);
[$exit4c, $out4c] = run_bootstrap('dropcol', $droppedColDir);
$dropSupported = !str_contains($out4c, 'drop-column-unsupported') && $exit4c === 0;
if ($dropSupported) {
    $probe = open_db($droppedColDir);
    check('session_invalid_before really dropped', (int) $probe->query("SELECT COUNT(*) FROM pragma_table_info('admin_users') WHERE name = 'session_invalid_before'")->fetchColumn() === 0);
    $probe = null;
    [$exit4d] = run_bootstrap('bootstrap', $droppedColDir);
    check('bootstrap recovers from dropped column', $exit4d === 0);
    $probe = open_db($droppedColDir);
    check('session_invalid_before restored via ALTER guard', (int) $probe->query("SELECT COUNT(*) FROM pragma_table_info('admin_users') WHERE name = 'session_invalid_before'")->fetchColumn() === 1);
    check('admin data preserved through column recovery', admin_count($probe) === 1);
    $probe = null;
} else {
    check('drop-column recovery skipped (SQLite < 3.35 in this runtime)', true, 'runtime: ' . ($out4c ?: "exit {$exit4c}"));
}

section('4b. Dropped index recovery');
$droppedIdxDir = $tmpDir . '/dropindex';
mkdir($droppedIdxDir, 0775, true);
run_bootstrap('bootstrap', $droppedIdxDir);
$probe = open_db($droppedIdxDir);
$probe->exec('DROP INDEX idx_products_category');
check('idx_products_category really dropped', !in_array('idx_products_category', indexes_of($probe), true));
$probe = null;
[$exit4e] = run_bootstrap('bootstrap', $droppedIdxDir);
check('bootstrap recovers from dropped index', $exit4e === 0);
$probe = open_db($droppedIdxDir);
check('idx_products_category recreated', in_array('idx_products_category', indexes_of($probe), true));
check('no re-seed during index recovery', product_count($probe) === 6);
$probe = null;

section('4c. Credential-write failure aborts cleanly and retries');
$credDir = $tmpDir . '/credblock';
mkdir($credDir, 0775, true);
// Obstruct exactly the credential file: a DIRECTORY where the file must go.
mkdir($credDir . '/initial_admin_credentials.txt', 0775, true);
[$exit4f, $out4f, $err4f] = run_bootstrap('bootstrap', $credDir);
check('bootstrap fails loudly when credentials cannot be written', $exit4f !== 0, "exit {$exit4f}");
$probe = open_db($credDir);
// The whole transaction rolled back: on a fresh DB even the DDL is gone.
check('transaction fully rolled back (no tables committed)', tables_of($probe) === [] || tables_of($probe) === ['sqlite_sequence'], 'tables: ' . implode(',', tables_of($probe)));
check('schema marker not stamped on credential failure', marker($probe) < ZF_SCHEMA_VERSION, 'marker ' . marker($probe));
$probe = null;
check('failure is logged, not silent', is_file($logPath) && str_contains((string) file_get_contents($logPath), 'aborting bootstrap so the generated administrator password'));
check('generated password never appears in the log', !str_contains((string) file_get_contents($logPath), 'Password:'));
rmdir($credDir . '/initial_admin_credentials.txt');
[$exit4g] = run_bootstrap('bootstrap', $credDir);
check('bootstrap succeeds after the obstruction is removed', $exit4g === 0);
$probe = open_db($credDir);
check('admin seeded on the retry', admin_count($probe) === 1);
check('products seeded exactly once across the retry', product_count($probe) === 6);
$probe = null;
check('credential file written and 0600 on retry', is_file($credDir . '/initial_admin_credentials.txt') && (fileperms($credDir . '/initial_admin_credentials.txt') & 0777) === 0600);

section('4d. Sandbox: credential tracking survives rollback (filesystem is not part of the transaction)');
// Direct unit-level check of the seed/tracking/discard trio: seed inside an
// explicit transaction, roll it back, discard the tracked file.
$fixtureSource2 = <<<'FIXTURE2'
<?php
declare(strict_types=1);
$dir = $argv[2];
$logPath = $argv[3];
$projectRoot = $argv[4];
$dbPath = $dir . '/shop.db';
define('ZF_ROOT', $projectRoot);
define('ZF_DATA', $dir);
define('ZF_DEBUG', false);
putenv('ZF_LOG_PATH=' . $logPath);
require $projectRoot . '/src/helpers.php';
require $projectRoot . '/src/database.php';
$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\')))');
$db->exec('BEGIN IMMEDIATE');
$created = null;
zf_seed_admin($db, $dbPath, $created);
echo 'created:' . ($created ?? 'null');
echo '|perms:' . decoct(fileperms((string) $created) & 0777);
echo '|present:' . (is_file((string) $created) ? '1' : '0');
$db->exec('ROLLBACK');                       // simulate a later transaction failure
zf_discard_credential_file($created);
echo '|after-rollback:' . (is_file((string) $created) ? 'still-there' : 'discarded');
FIXTURE2;
$sandboxFixture = $tmpDir . '/sandbox_fixture.php';
file_put_contents($sandboxFixture, $fixtureSource2);
$sandboxDir = $tmpDir . '/sandbox';
mkdir($sandboxDir, 0775, true);
$sandboxLog = $tmpDir . '/sandbox.log';
$spec2 = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$sandboxProc = proc_open(
    PHP_BINARY . ' ' . escapeshellarg($sandboxFixture) . ' run '
        . escapeshellarg($sandboxDir) . ' ' . escapeshellarg($sandboxLog) . ' ' . escapeshellarg($projectRoot),
    $spec2,
    $sandboxPipes
);
$sandboxOut = is_resource($sandboxProc) ? (stream_get_contents($sandboxPipes[1]) ?: '') : 'proc-failed';
if (is_resource($sandboxProc)) {
    fclose($sandboxPipes[1]);
    fclose($sandboxPipes[2]);
    proc_close($sandboxProc);
}
check('seed_admin reports the file it created', str_contains($sandboxOut, 'created:'), $sandboxOut);
check('credential file created at 0600 inside the transaction', str_contains($sandboxOut, 'perms:600'), $sandboxOut);
check('credential file exists before rollback', str_contains($sandboxOut, 'present:1'), $sandboxOut);
check('rolled-back transaction discards the tracked credential file', str_contains($sandboxOut, 'after-rollback:discarded'), $sandboxOut);

section('4e. Discard helper never touches untracked files');
$untracked = $sandboxDir . '/initial_admin_credentials.txt';
file_put_contents($untracked, 'pre-existing credential file');
$untrackedFixture = $tmpDir . '/discard_fixture.php';
file_put_contents($untrackedFixture, <<<'FIXTURE3'
<?php
declare(strict_types=1);
define('ZF_ROOT', $argv[4]);
define('ZF_DATA', $argv[2]);
define('ZF_DEBUG', false);
require $argv[4] . '/src/database.php';
zf_discard_credential_file(null);            // untracked — must be a no-op
echo is_file($argv[2] . '/initial_admin_credentials.txt') ? 'untouched' : 'DELETED';
FIXTURE3);
$spec3 = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$discardProc = proc_open(
    PHP_BINARY . ' ' . escapeshellarg($untrackedFixture) . ' run '
        . escapeshellarg($sandboxDir) . ' ' . escapeshellarg($sandboxLog) . ' ' . escapeshellarg($projectRoot),
    $spec3,
    $discardPipes
);
$discardOut = is_resource($discardProc) ? (stream_get_contents($discardPipes[1]) ?: '') : 'proc-failed';
if (is_resource($discardProc)) {
    fclose($discardPipes[1]);
    fclose($discardPipes[2]);
    proc_close($discardProc);
}
check('zf_discard_credential_file(null) leaves the filesystem untouched', $discardOut === 'untouched', $discardOut);

section('4f. Stale credential file replaced deliberately only when admin table is empty');
$staleDir = $tmpDir . '/stale';
mkdir($staleDir, 0775, true);
run_bootstrap('bootstrap', $staleDir);      // fresh install: admin seeded
$probe = open_db($staleDir);
$probe->exec('DELETE FROM admin_users');    // simulate the admin row being lost
$probe->exec('PRAGMA user_version = 1');    // force the full init path
$probe = null;
clearstatcache();
$staleHashBefore = sha1_file($staleDir . '/initial_admin_credentials.txt');
[$exit4h] = run_bootstrap('bootstrap', $staleDir);
check('bootstrap replaces stale credential file', $exit4h === 0, "exit {$exit4h}");
$probe = open_db($staleDir);
check('admin re-seeded after stale replacement', admin_count($probe) === 1);
check('no duplicate products across replacement', product_count($probe) === 6);
$probe = null;
clearstatcache();
$staleHashAfter = sha1_file($staleDir . '/initial_admin_credentials.txt');
check('stale file content actually replaced (new random password)', $staleHashAfter !== $staleHashBefore, 'hash unchanged');
check('replacement file is 0600', (fileperms($staleDir . '/initial_admin_credentials.txt') & 0777) === 0600);

section('4g. Credential path occupied by a directory is an initialization failure');
$dirBlockDir = $tmpDir . '/dirblock';
mkdir($dirBlockDir, 0775, true);
mkdir($dirBlockDir . '/initial_admin_credentials.txt', 0775, true);
[$exit4i] = run_bootstrap('bootstrap', $dirBlockDir);
check('bootstrap fails when the credential path is a directory', $exit4i !== 0, "exit {$exit4i}");
$probe = open_db($dirBlockDir);
check('transaction rolled back when credential path is blocked', tables_of($probe) === [] || tables_of($probe) === ['sqlite_sequence']);
$probe = null;

// ============================================================
section('5. Concurrent first-boot initialization');
$concurrentDir = $tmpDir . '/concurrent';
mkdir($concurrentDir, 0775, true);
$workers = [];
$specs = [];
for ($i = 0; $i < 4; $i++) {
    $specs[$i] = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipesTmp = [];
    $workers[$i] = proc_open(
        PHP_BINARY . ' ' . escapeshellarg($fixturePath) . ' bootstrap '
            . escapeshellarg($concurrentDir) . ' '
            . escapeshellarg($logPath) . ' '
            . escapeshellarg($projectRoot),
        $specs[$i],
        $pipesTmp
    );
    $specs[$i]['pipes'] = $pipesTmp;
}
$workerOut = [];
$workerErr = [];
$workerExit = [];
foreach ($workers as $i => $proc) {
    if (is_resource($proc)) {
        $workerOut[$i] = stream_get_contents($specs[$i]['pipes'][1]) ?: '';
        $workerErr[$i] = stream_get_contents($specs[$i]['pipes'][2]) ?: '';
        fclose($specs[$i]['pipes'][1]);
        fclose($specs[$i]['pipes'][2]);
        $workerExit[$i] = proc_close($proc);
    } else {
        $workerOut[$i] = 'proc-failed';
        $workerExit[$i] = -1;
    }
}
$allClean = true;
foreach ($workerExit as $i => $code) {
    if ($code !== 0 || !str_contains($workerOut[$i], 'db:')) {
        $allClean = false;
        check("worker $i completed cleanly", false, "exit {$code} out {$workerOut[$i]} err {$workerErr[$i]}");
    }
}
if ($allClean) {
    check('all four workers completed', true);
}
$concurrent = open_db($concurrentDir);
check('concurrent init: exactly six products', product_count($concurrent) === 6, 'found ' . product_count($concurrent));
check('concurrent init: exactly one admin', admin_count($concurrent) === 1, 'found ' . admin_count($concurrent));
check('concurrent init: all tables present', !array_diff(expected_tables(), tables_of($concurrent)));
check('concurrent init: marker stamped once', marker($concurrent) === ZF_SCHEMA_VERSION);
check('concurrent init: no duplicate settings', (int) $concurrent->query('SELECT COUNT(*) FROM (SELECT key FROM settings GROUP BY key HAVING COUNT(*) > 1)')->fetchColumn() === 0);
$concurrent = null;

// ============================================================
section('6. Fast path performs zero writes on an initialized DB');
$stableDir = $tmpDir . '/stable';
mkdir($stableDir, 0775, true);
run_bootstrap('checkpoint', $stableDir); // initialize + checkpoint WAL
$hashBefore = sha1_file($stableDir . '/shop.db');
$walBefore = is_file($stableDir . '/shop.db-wal') ? sha1_file($stableDir . '/shop.db-wal') : 'absent';
[$exit6] = run_bootstrap('checkpoint', $stableDir); // ordinary request + flush
check('ordinary-request bootstrap exits cleanly', $exit6 === 0);
$hashAfter = sha1_file($stableDir . '/shop.db');
$walAfter = is_file($stableDir . '/shop.db-wal') ? sha1_file($stableDir . '/shop.db-wal') : 'absent';
check('database bytes unchanged after ordinary bootstrap (zero writes)', $hashBefore === $hashAfter, sha1($hashBefore . '→' . $hashAfter));
check('WAL unchanged after ordinary bootstrap', $walBefore === $walAfter, 'wal delta detected');

// ============================================================
section('7. Production database remains untouched');
$shopDbAfter = is_file($shopDb) ? sha1_file($shopDb) : 'absent';
check('data/shop.db byte-identical before and after suite', $shopDbBefore === $shopDbAfter);

// ============================================================
section('8. Temporary artifacts cleaned up');
/**
 * Recursive delete restricted to the suite's own uniquely generated temp
 * directory. Never touches anything outside $tmpDir — in particular
 * data/shop.db and data/shop.db.pre-batch3-backup are outside its scope by
 * construction (temp dirs live under sys_get_temp_dir() with a
 * 'zf-schema-test-' prefix).
 */
function rrmdir_tree(string $dir, string $allowedPrefix): void
{
    if (!is_dir($dir) || !str_starts_with($dir, $allowedPrefix)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $dir . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            rrmdir_tree($child, $allowedPrefix);
        } else {
            @unlink($child);
        }
    }
    @rmdir($dir);
}

rrmdir_tree($tmpDir, sys_get_temp_dir() . '/zf-schema-test-');
check('suite temp directory fully removed (DBs, WAL/SHM, logs, fixtures, credential files)', !is_dir($tmpDir), $tmpDir);
check('production data directory untouched by cleanup', is_file($shopDb) && is_file($projectRoot . '/data/shop.db.pre-batch3-backup'));

// ============================================================
echo "\nResult: {$passes} passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
