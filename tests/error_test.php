<?php
declare(strict_types=1);

ob_start();

/**
 * Zellie Fleurs — isolated error-handling tests.
 * Run: php tests/error_test.php
 *
 * Everything runs against a temporary SQLite database and a temporary
 * protected log file (ZF_LOG_PATH). data/shop.db is never touched.
 *
 * Uncaught-error behaviour is exercised in isolated `php` subprocesses
 * through generated fixtures, so a failing fixture cannot poison this
 * runner. HTTP-level error behaviour (404/500/API JSON) is covered by the
 * dedicated section appended to tests/http_test.sh.
 */

$tmpDir = sys_get_temp_dir() . '/zellie-error-tests-' . getmypid();
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}

$dbPath = $tmpDir . '/shop.db';
$logPath = $tmpDir . '/error.log';
$fixturePath = $tmpDir . '/fixture.php';
@unlink($dbPath);
@unlink($dbPath . '-wal');
@unlink($dbPath . '-shm');
@unlink($logPath);

putenv('ZF_DB_PATH=' . $dbPath);
putenv('ZF_LOG_PATH=' . $logPath);
putenv('ZF_ADMIN_USER=admin');
putenv('ZF_ADMIN_PASSWORD=ErrorTest123!');
putenv('ZF_TESTING=1');
putenv('ZF_DEBUG=1');

require_once __DIR__ . '/../config/database.php';
require_once ZF_ROOT . '/src/auth.php';
require_once ZF_ROOT . '/src/products.php';
require_once ZF_ROOT . '/src/orders.php';
require_once ZF_ROOT . '/src/settings.php';

$passed = 0;
$failed = 0;

function section(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('-', strlen($title)) . "\n";
}

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

file_put_contents($fixturePath, <<<'PHP'
<?php
declare(strict_types=1);

require_once getenv('ZF_BOOTSTRAP');

switch ($argv[1]) {
    case 'warning':
        trigger_error('zf-warning-marker-value', E_USER_WARNING);
        echo 'after-warning';
        break;

    case 'exception':
        throw new RuntimeException('zf-exception-secret-31337');

    case 'fatal':
        zf_nonexistent_function_xyz();
        break;

    case 'memory':
        // A true E_ERROR fatal: memory exhaustion reaches the shutdown
        // handler (unlike most PHP 8 runtime errors, which are Exceptions).
        ini_set('memory_limit', '2M');
        $ballast = [];
        for (;;) {
            $ballast[] = str_repeat('x', 100000);
        }

    case 'logscrub':
        zf_error_log('probe', 'password=letmein csrf_token=abc123 session_id=xyz789 gcash_ref=GC12345678 cookie=SESSID99 api_key=AKIA123456');
        echo 'scrubbed-and-logged';
        break;

    default:
        echo 'unknown mode';
}
PHP
);

/**
 * Run one fixture case in an isolated PHP subprocess.
 * Returns [exit code, stdout, stderr].
 * $logOverride optionally points ZF_LOG_PATH elsewhere for the child.
 */
function run_fixture(string $mode, bool $debug, ?string $logOverride = null): array
{
    global $tmpDir, $fixturePath, $logPath;

    @unlink($logPath);

    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $command = PHP_BINARY . ' ' . escapeshellarg($fixturePath) . ' ' . escapeshellarg($mode);
    if ($debug) {
        putenv('ZF_DEBUG=1');
    } else {
        putenv('ZF_DEBUG');
    }
    putenv('ZF_BOOTSTRAP=' . ZF_ROOT . '/config/database.php');
    putenv('ZF_LOG_PATH=' . ($logOverride ?? $logPath));

    $process = proc_open($command, $spec, $pipes, $tmpDir);
    if (!is_resource($process)) {
        putenv('ZF_LOG_PATH=' . $logPath);
        return [-1, '', 'proc_open failed'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    putenv('ZF_LOG_PATH=' . $logPath);

    return [$exit, $stdout, $stderr];
}

function read_log(): string
{
    global $logPath;

    return (string) file_get_contents($logPath);
}

putenv('ZF_DEBUG=1');
putenv('ZF_BOOTSTRAP=' . ZF_ROOT . '/config/database.php');

section('Production mode: PHP warnings are never exposed');
[$exit, $out, $err] = run_fixture('warning', false);
check('page still completes after a warning', $exit === 0 && str_contains($out, 'after-warning'), "exit {$exit} out {$out}");
check('warning text is not exposed', !str_contains($out . $err, 'zf-warning-marker-value'), ($out . $err));
check('no PHP Warning label in output', !str_contains($out . $err, 'Warning:'), ($out . $err));
check('warning is recorded in the protected log', str_contains(read_log(), '[User warning]') && str_contains(read_log(), 'zf-warning-marker-value'));
check('log entry carries a timestamp', preg_match('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [+-]\d{2}:\d{2}\]/', read_log()) === 1);
check('log entry carries the source location', preg_match('/fixture\.php:\d+/', read_log()) === 1);

section('Production mode: exception messages and traces are never exposed');
[$exit, $out, $err] = run_fixture('exception', false);
check('fixture exits nonzero on uncaught exception', $exit !== 0, "exit {$exit}");
check('exception message is not exposed', !str_contains($out . $err, 'zf-exception-secret-31337'), ($out . $err));
check('exception class name is not exposed', !str_contains($out . $err, 'RuntimeException'), ($out . $err));
check('no stack trace is exposed', !str_contains($out . $err, 'Stack trace:'), ($out . $err));
check('generic error text is shown instead', str_contains($out . $err, 'unexpected error'), ($out . $err));
$log = read_log();
check('exception is recorded in the protected log', str_contains($log, 'RuntimeException') && str_contains($log, 'uncaught-exception'), $log);
check('log records the exception source location', (bool) preg_match('/fixture\.php:\d+/', $log), $log);

section('Production mode: fatal errors are contained');
[$exit, $out, $err] = run_fixture('fatal', false);
check('fixture exits nonzero on fatal error', $exit !== 0, "exit {$exit}");
check('fatal details are not exposed', !str_contains($out . $err, 'zf_nonexistent_function_xyz') && !str_contains($out . $err, 'Call to undefined'), ($out . $err));
// PHP 8 surfaces an undefined function call as an uncaught Error exception;
// it must still be recorded with its source either way.
check('fatal error is recorded in the protected log', str_contains(read_log(), 'Call to undefined function'), read_log());

section('Production mode: true E_ERROR fatals hit the shutdown handler');
[$exit, $out, $err] = run_fixture('memory', false);
check('fixture exits nonzero on memory exhaustion', $exit !== 0, "exit {$exit}");
check('memory fatal details are not exposed', !str_contains($out . $err, 'Allowed memory size') && !str_contains($out . $err, 'ballast'), ($out . $err));
check('memory fatal is recorded as Fatal error in the log', str_contains(read_log(), '[Fatal error]'), read_log());
check('memory fatal log entry carries its source', (bool) preg_match('/fixture\.php:\d+/', read_log()), read_log());

section('Protected log never contains deliberately supplied secrets');
[$exit, $out, $err] = run_fixture('logscrub', false);
check('probe log write succeeds', $exit === 0 && str_contains($out, 'scrubbed-and-logged'), "exit {$exit}");
$log = read_log();
foreach (['letmein', 'abc123', 'xyz789', 'GC12345678', 'SESSID99', 'AKIA123456'] as $secret) {
    check("log does not contain supplied secret '{$secret}'", !str_contains($log, $secret), $log);
}
check('redacted markers are present in the log', substr_count($log, '[redacted]') >= 5, $log);

section('Development mode retains useful diagnostics');
[$exit, $out, $err] = run_fixture('exception', true);
check('debug mode surfaces the exception class', str_contains($out . $err, 'RuntimeException'), ($out . $err));
check('debug mode surfaces the exception message', str_contains($out . $err, 'zf-exception-secret-31337'), ($out . $err));
check('debug mode surfaces the stack trace', str_contains($out . $err, 'Stack trace:'), ($out . $err));
[$exit, $out, $err] = run_fixture('warning', true);
check('debug mode still displays native warnings', str_contains($out . $err, 'zf-warning-marker-value'), ($out . $err));

section('Secret scrubbing (in-process unit checks)');
check('password values are redacted', zf_scrub_secrets('failed for password=hunter2x') === 'failed for password=[redacted]', zf_scrub_secrets('failed for password=hunter2x'));
check('csrf tokens are redacted', zf_scrub_secrets('posted csrf_token=deadbeef01') === 'posted csrf_token=[redacted]', zf_scrub_secrets('posted csrf_token=deadbeef01'));
check('session ids are redacted', zf_scrub_secrets('session_id=abc123xyz') === 'session_id=[redacted]', zf_scrub_secrets('session_id=abc123xyz'));
check('payment references are redacted', zf_scrub_secrets('ref gcash_ref=GC99999999') === 'ref gcash_ref=[redacted]', zf_scrub_secrets('ref gcash_ref=GC99999999'));
check('JSON-style secret keys are redacted', !str_contains(zf_scrub_secrets('payload {"password":"s3cretvalue"}'), 's3cretvalue'), zf_scrub_secrets('payload {"password":"s3cretvalue"}'));
check('PHP-array-style secret keys are redacted', !str_contains(zf_scrub_secrets('dump array("csrf_token" => "deadbeef99")'), 'deadbeef99'), zf_scrub_secrets('dump array("csrf_token" => "deadbeef99")'));
check('bearer tokens are redacted', !str_contains(zf_scrub_secrets('Authorization: Bearer sk-live-abcdef123456'), 'abcdef123456'), zf_scrub_secrets('Authorization: Bearer sk-live-abcdef123456'));
check('url-encoded secret keys are redacted', !str_contains(zf_scrub_secrets('q=%70assword=s3cretvalue'), 's3cretvalue'), zf_scrub_secrets('q=%70assword=s3cretvalue'));
check('ordinary error text survives scrubbing', zf_scrub_secrets('SQLSTATE[23000] at line 12') === 'SQLSTATE[23000] at line 12');

section('Log injection guard (in-process unit checks)');
@unlink($logPath);
zf_error_log('probe', "line-one\n[2020-01-01] [forged] fake entry (request: GET /x)");
$log = (string) file_get_contents($logPath);
check('newline in detail cannot forge a second log line', substr_count(trim($log), "\n") === 0, $log);
check('only the genuine timestamped entry exists (no forged line)', preg_match_all('/^\[\d{4}-\d{2}-\d{2} /m', $log) === 1, $log);

// URL-encoded CRLF must not survive scrubbing either: the scrubber
// urldecodes the text, so "%0d%0a" becomes a real newline inside the
// scrubber — the strip must catch it after that point.
@unlink($logPath);
zf_error_log('probe2', 'user input %0d%0a[2020-01-01] [forged] fake entry');
$log = (string) file_get_contents($logPath);
check('url-encoded CRLF cannot forge a second log line', substr_count(trim($log), "\n") === 0, $log);
check('url-encoded forged entry carries no second timestamp', preg_match_all('/^\[\d{4}-\d{2}-\d{2} /m', $log) === 1, $log);

// Double-encoded payload: scrubbing must run exactly once, so "%250d%250a"
// can never be decoded again into a real CRLF after the strip.
@unlink($logPath);
zf_error_log('probe3', 'aa%250d%250a[2020-01-01] [forged] fake entry');
$log = (string) file_get_contents($logPath);
check('double-encoded CRLF cannot forge a second log line', substr_count(trim($log), "\n") === 0, $log);
check('double-encoded payload leaves no forged bracketed line', preg_match_all('/^\[\d{4}-\d{2}-\d{2} /m', $log) === 1, $log);

section('API request detection (in-process unit checks)');
$_SERVER['REQUEST_URI'] = '/api/products.php';
check('/api/ paths are detected as API requests', zf_request_is_api() === true);
$_SERVER['REQUEST_URI'] = '/checkout.php';
check('storefront paths are not API requests', zf_request_is_api() === false);

section('Error type naming');
check('E_WARNING maps to Warning', zf_error_type_name(E_WARNING) === 'Warning');
check('E_ERROR maps to Fatal error', zf_error_type_name(E_ERROR) === 'Fatal error');
check('unknown codes still produce a name', zf_error_type_name(99999) === 'Error 99999');

section('Protected log permissions');
check('log file was created', is_file($logPath));
$perms = fileperms($logPath) & 0777;
check('log file permissions are owner-only (0600)', $perms === 0600, 'got ' . decoct($perms));

section('Logging failure is never silent (stderr fallback)');
$roDir = $tmpDir . '/ro-fallback';
@mkdir($roDir, 0775, true);
chmod($roDir, 0555);
[$exit, $out, $err] = run_fixture('logscrub', false, $roDir . '/unwritable/error.log');
chmod($roDir, 0755);
check('fixture still completes when the log is unwritable', $exit === 0 && str_contains($out, 'scrubbed-and-logged'), "exit {$exit} out {$out}");
check('unwritable log falls back to STDERR, not silence', str_contains($err, '[Zellie Fleurs]') && str_contains($err, '[probe]'), $err);
check('no log file was created in the unwritable directory', !is_file($roDir . '/unwritable/error.log'));

section('Result');
echo "\n{$passed} passed, {$failed} failed.\n";

foreach (glob($tmpDir . '/*') as $file) {
    @unlink($file);
}
@rmdir($tmpDir);

ob_end_flush();
exit($failed === 0 ? 0 : 1);
