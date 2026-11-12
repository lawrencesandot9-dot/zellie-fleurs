<?php
declare(strict_types=1);

/**
 * Zellie Fleurs bootstrap.
 *
 * The SQLite database lives outside the public document root. The schema is
 * created idempotently and sample data is only inserted into a brand-new
 * database. Nothing is reset on normal page loads.
 */

define('ZF_ROOT', dirname(__DIR__));
define('ZF_PUBLIC', ZF_ROOT . '/public');
define('ZF_DATA', ZF_ROOT . '/data');
define('ZF_UPLOADS', ZF_PUBLIC . '/uploads');
define('ZF_DEBUG', getenv('ZF_DEBUG') === '1');

if (!is_dir(ZF_DATA)) {
    mkdir(ZF_DATA, 0775, true);
}
if (!is_dir(ZF_UPLOADS)) {
    mkdir(ZF_UPLOADS, 0775, true);
}

$zfDbPath = getenv('ZF_DB_PATH') ?: ZF_DATA . '/shop.db';
$zfDbDir = dirname($zfDbPath);
if (!is_dir($zfDbDir)) {
    mkdir($zfDbDir, 0775, true);
}

date_default_timezone_set(getenv('ZF_TZ') ?: 'Asia/Manila');

error_reporting(E_ALL);
ini_set('display_errors', ZF_DEBUG ? '1' : '0');

require_once ZF_ROOT . '/src/helpers.php';
// Centralized production-safe error/exception handling, protected log,
// output buffering. Must be installed before the database connects so even
// bootstrap failures route through the clean error response.
require_once ZF_ROOT . '/src/error_handler.php';
require_once ZF_ROOT . '/src/database.php';

/**
 * Baseline security headers for every request. Sent before any output and
 * only when headers are still sendable (CLI neighbours and streamed
 * responses are left untouched).
 */
if (!function_exists('zf_send_security_headers')) {
    function zf_send_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // Report-Only (never enforced): the storefront views rely on inline
        // attributes such as onerror handlers that an enforced CSP would break.
        header("Content-Security-Policy-Report-Only: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'");
    }
}

zf_send_security_headers();

try {
    $db = new PDO('sqlite:' . $zfDbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA journal_mode = WAL');

    zf_ensure_schema($db, $zfDbPath);
} catch (Throwable $e) {
    zf_error_log('bootstrap-failure', 'Database bootstrap failed: ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database bootstrap failed: {$e->getMessage()}\n");
        exit(1);
    }
    zf_discard_output_buffers();
    zf_emit_error_response(500, $e);
    exit;
}
