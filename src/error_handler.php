<?php
declare(strict_types=1);

/**
 * Centralized, production-safe error and exception handling.
 *
 * Loaded by the bootstrap immediately after helpers.php. One module owns all
 * of it so web pages, admin pages, API endpoints and CLI scripts share the
 * exact same behaviour:
 *
 *  - A protected, scrubbed error log outside the public webroot (0600 perms),
 *    located via ZF_LOG_PATH (defaults to data/php-error.log).
 *  - A global exception handler: uncaught exceptions log full detail
 *    server-side and answer with a clean, branded 500 (JSON for /api/ paths).
 *    Customers never see exception messages, stack traces, SQL, filesystem
 *    paths or credentials.
 *  - A global error handler: warnings/notices/deprecations are logged, never
 *    displayed in production. E_USER_ERROR / E_RECOVERABLE_ERROR are promoted
 *    to exceptions so they flow through the same clean 500 path. In
 *    ZF_DEBUG mode the PHP-native behaviour (display + log) is preserved.
 *  - A shutdown handler that converts fatal errors (E_ERROR, E_PARSE, …)
 *    into the same clean 500 and flushes the bootstrap's output buffer on
 *    normal exits — so partial pages are never left behind after a failure.
 *  - Output buffering opened here guarantees headers remain sendable until
 *    the very end of the request; error responses never send duplicate
 *    output or replace headers after output has started.
 *
 * ZF_DEBUG is the single, explicit switch (defined in the bootstrap from the
 * ZF_DEBUG environment variable): when it is off, responses contain only
 * generic text and full diagnostics stay in the protected log; when it is
 * on, the same responses additionally carry developer diagnostics.
 */

const ZF_ERROR_FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

/** Output-buffer level before this module opened its own buffer. */
$GLOBALS['zf_ob_base_level'] = ob_get_level();

// ---------------------------------------------------------------------------
// Protected log configuration
// ---------------------------------------------------------------------------

$zfLogPath = (string) (getenv('ZF_LOG_PATH') ?: '') ?: (ZF_DATA . '/php-error.log');
$zfLogDir = dirname($zfLogPath);
if (!is_dir($zfLogDir)) {
    @mkdir($zfLogDir, 0775, true);
}
// Create the log with owner-only permissions before anything is written, so
// the very first entry is never briefly world-readable.
if (!is_file($zfLogPath)) {
    @touch($zfLogPath);
}
@chmod($zfLogPath, 0600);
define('ZF_LOG_PATH', $zfLogPath);
// Capture the SAPI's own log target BEFORE we repoint the error_log ini, so
// the last-resort leg of the fallback chain writes to a genuinely different
// sink than the primary protected log.
$zfSapiLogBefore = (string) ini_get('error_log');
define('ZF_FALLBACK_LOG', $zfSapiLogBefore === ZF_LOG_PATH ? '' : $zfSapiLogBefore);
ini_set('log_errors', '1');
ini_set('error_log', $zfLogPath);

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------

function zf_error_type_name(int $type): string
{
    static $names = [
        E_ERROR => 'Fatal error',
        E_PARSE => 'Parse error',
        E_CORE_ERROR => 'Core error',
        E_COMPILE_ERROR => 'Compile error',
        E_USER_ERROR => 'User error',
        E_WARNING => 'Warning',
        E_CORE_WARNING => 'Core warning',
        E_COMPILE_WARNING => 'Compile warning',
        E_USER_WARNING => 'User warning',
        E_NOTICE => 'Notice',
        E_USER_NOTICE => 'User notice',
        E_RECOVERABLE_ERROR => 'Recoverable error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User deprecated',
    ];

    return $names[$type] ?? ('Error ' . $type);
}

/**
 * Redact secret-shaped key/value fragments from a message before it is
 * written anywhere. Exception messages can echo supplied data (e.g. a PDO
 * duplicate-key error), so every log line passes through this scrubber.
 * Covers plain key=value, JSON-style "key":"value", PHP array key => value
 * and Authorization: Bearer shapes; nothing secret-shaped is persisted.
 */
function zf_scrub_secrets(string $text): string
{
    $text = urldecode($text);
    // Credential schemes first ("Bearer <token>", "Basic <credentials>") so
    // the scheme word is not consumed as a key=value value below.
    $scrubbed = preg_replace(
        '/(bearer|basic)\s+([A-Za-z0-9._~+\/-]{8,})/i',
        '$1 [redacted]',
        $text
    ) ?? '';
    // Then plain key=value, JSON-style "key":"value" and PHP "key" => value
    // shapes for the known sensitive key list.
    $scrubbed = preg_replace(
        '/["\']?(password|passwd|pwd|pass|passphrase|secret|csrf|csrf_token|session(?:_id)?|cookie|token|gcash_ref|card_number|payment|authorization|api_key)["\']?\s*(?:=>|[=:])\s*("[^"]*"|\'[^\']*\'|[^\s,;&)"\']+)/i',
        '$1=[redacted]',
        $scrubbed
    );

    return $scrubbed !== '' ? $scrubbed : '[unloggable error]';
}

/**
 * Append one structured line to the protected log: timestamp, type, scrubbed
 * detail, source location and (for web requests) the request method and path
 * only — never query strings, POST bodies, cookies or session identifiers.
 * If the primary log cannot be written the line is re-emitted through the
 * SAPI log so a logging failure is never silently discarded.
 */
function zf_error_log(string $type, string $detail, string $source = ''): void
{
    static $permissionsApplied = false;

    if (PHP_SAPI === 'cli') {
        $request = 'cli';
    } else {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        $request = ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($path === '' ? '/' : $path);
    }

    // Scrub and sanitize EXACTLY ONCE here, then interpolate the prepared
    // values. The scrubber urldecodes its input, so a second application
    // would decode an already-decoded "%250d%250a" back into a real CRLF and
    // re-open the log-injection hole the strip below just closed.
    $detail = $detail === '' ? '' : str_replace(["\r", "\n"], ' ', zf_scrub_secrets($detail));
    $source = $source === '' ? '' : str_replace(["\r", "\n"], ' ', zf_scrub_secrets($source));

    $line = sprintf(
        '[%s] [%s] %s (request: %s)%s',
        date('Y-m-d H:i:s P'),
        $type,
        $detail,
        $request,
        $source !== '' ? ' (source: ' . $source . ')' : ''
    );

    // Simple size-capped rotation: keep one previous generation so a runaway
    // error loop can never fill the disk.
    if (is_file(ZF_LOG_PATH) && (int) @filesize(ZF_LOG_PATH) > 2 * 1024 * 1024) {
        @rename(ZF_LOG_PATH, ZF_LOG_PATH . '.1');
        @chmod(ZF_LOG_PATH . '.1', 0600);
        // Recreate the fresh log with owner-only permissions immediately:
        // in persistent SAPIs (built-in server, mod_php) the per-process
        // chmod below would never run again, and a recreated file would
        // otherwise inherit the process umask (commonly 0644).
        @touch(ZF_LOG_PATH);
        @chmod(ZF_LOG_PATH, 0600);
    }

    if (@error_log($line . PHP_EOL, 3, ZF_LOG_PATH) === false) {
        // The primary protected log is unwritable — do NOT discard the entry
        // silently and do NOT just retry the same broken target: fall back
        // to STDERR (Apache error log / built-in server stderr), then to the
        // SAPI log captured before the ini override as a last resort.
        $emitted = @file_put_contents('php://stderr', '[Zellie Fleurs] ' . $line . PHP_EOL);
        if ($emitted === false || $emitted === 0) {
            if (ZF_FALLBACK_LOG !== '') {
                @error_log('[Zellie Fleurs] ' . $line . PHP_EOL, 3, ZF_FALLBACK_LOG);
            } else {
                error_log('[Zellie Fleurs] ' . $line);
            }
        }
    }

    if (!$permissionsApplied) {
        $permissionsApplied = true;
        if (is_file(ZF_LOG_PATH)) {
            @chmod(ZF_LOG_PATH, 0600);
        }
    }
}

// ---------------------------------------------------------------------------
// Output buffering helpers
// ---------------------------------------------------------------------------

function zf_discard_output_buffers(): void
{
    while (ob_get_level() > (int) $GLOBALS['zf_ob_base_level']) {
        if (@ob_end_clean() === false) {
            break;
        }
    }
}

function zf_flush_output_buffers(): void
{
    while (ob_get_level() > (int) $GLOBALS['zf_ob_base_level']) {
        if (@ob_end_flush() === false) {
            break;
        }
    }
}

// ---------------------------------------------------------------------------
// Error responses
// ---------------------------------------------------------------------------

/**
 * A request is treated as an API request by path convention (/api/…), which
 * is how the project already namespaces its JSON endpoints. API consumers
 * always get JSON, never an HTML error page.
 */
function zf_request_is_api(): bool
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $path = is_string($path) ? $path : '';

    return str_starts_with($path, '/api/');
}

/**
 * Emit the clean generic error response. Never replaces headers after output
 * has started — if headers are already sent the failure stays logged-only.
 * With ZF_DEBUG the HTML response additionally carries escaped developer
 * diagnostics; production responses never do.
 */
function zf_emit_error_response(int $status, ?Throwable $e = null): void
{
    if (PHP_SAPI === 'cli') {
        return; // CLI callers report on STDERR themselves.
    }

    if (headers_sent()) {
        return;
    }

    http_response_code($status);

    if (zf_request_is_api()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        $payload = [
            'ok' => false,
            'error' => 'The request could not be completed. Please try again shortly.',
        ];
        if (ZF_DEBUG && $e !== null) {
            $payload['debug'] = zf_e(get_class($e) . ': ' . $e->getMessage());
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Zellie Fleurs</title><link rel="stylesheet" href="/assets/css/style.css"></head>'
        . '<body><main class="error-page"><img src="/assets/img/logo-mark.svg" alt="" width="54" height="54">'
        . '<h1>Something went wrong</h1>'
        . '<p>Something went wrong on our side. Please try again in a few minutes.</p>'
        . '<p><a class="btn btn--primary" href="/index.php">Return to the shop</a></p>';

    if (ZF_DEBUG && $e !== null) {
        echo '<pre style="white-space:pre-wrap;text-align:left;background:#fff;padding:12px;border-radius:8px;overflow:auto;">'
            . zf_e(get_class($e) . ': ' . $e->getMessage()
                . "\n" . $e->getFile() . ':' . $e->getLine()
                . "\n\n" . $e->getTraceAsString())
            . '</pre>';
    }

    echo '</main></body></html>';
}

// ---------------------------------------------------------------------------
// Global handlers
// ---------------------------------------------------------------------------

set_exception_handler(static function (Throwable $e): void {
    try {
        zf_error_log(
            'uncaught-exception',
            get_class($e) . ': ' . $e->getMessage(),
            $e->getFile() . ':' . $e->getLine()
        );
    } catch (Throwable) {
        // Never mask the original failure with a logging failure.
    }

    zf_discard_output_buffers();

    if (PHP_SAPI === 'cli') {
        if (ZF_DEBUG) {
            fwrite(STDERR, 'Uncaught ' . get_class($e) . ': ' . $e->getMessage()
                . "\nStack trace:\n" . $e->getTraceAsString() . "\n");
        } else {
            fwrite(STDERR, 'An unexpected error occurred.' . PHP_EOL);
        }
        exit(255);
    }

    zf_emit_error_response(500, $e);
});

set_error_handler(static function (int $errno, string $message, string $file = '', int $line = 0): bool {
    // Honour @-suppression and any error_reporting() exclusions exactly as
    // the configuration intends: stay silent for those.
    if (!(error_reporting() & $errno)) {
        return true;
    }

    if (in_array($errno, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        // Deliberate fatals flow through the exception handler's clean 500.
        throw new ErrorException($message, 0, $errno, $file, $line);
    }

    zf_error_log(zf_error_type_name($errno), $message, $file . ':' . $line);

    if (ZF_DEBUG) {
        // Development: let PHP's native behaviour display the diagnostic on
        // screen as usual. Native logging also writes the raw message to the
        // protected log alongside the scrubbed entry — accepted, because
        // ZF_DEBUG is an explicit developer opt-in and display is the primary
        // diagnostic here. Production never takes this branch.
        return false;
    }

    // Production: fully handled — nothing is displayed or double-logged.
    return true;
});

register_shutdown_function(static function (): void {
    try {
        $last = error_get_last();
        // Only true unhandled fatals reach here: once the custom error
        // handler above has processed an error, error_get_last() no longer
        // reports it (verified on PHP 8.5) — but re-verify when the minimum
        // PHP version changes, because a stale entry here would wrongly
        // discard a successful page and emit a bogus 500.
        if (is_array($last) && in_array((int) $last['type'], ZF_ERROR_FATAL_TYPES, true)) {
            zf_error_log(
                zf_error_type_name((int) $last['type']),
                (string) $last['message'],
                (string) $last['file'] . ':' . (int) $last['line']
            );
            zf_discard_output_buffers();

            if (PHP_SAPI === 'cli') {
                if (ZF_DEBUG) {
                    fwrite(STDERR, 'Fatal ' . zf_error_type_name((int) $last['type'])
                        . ': ' . $last['message'] . ' at ' . $last['file'] . ':' . $last['line'] . "\n");
                } else {
                    fwrite(STDERR, 'An unexpected error occurred.' . PHP_EOL);
                }
            } else {
                zf_emit_error_response(500);
            }
            return;
        }

        // Normal exit: deliver the buffered page.
        zf_flush_output_buffers();
    } catch (Throwable) {
        // A failure inside the shutdown handler must never mask the output
        // or the response that was already produced.
    }
});

// Buffer every page from the earliest possible moment so headers remain
// sendable for the whole request and partial output can be discarded on
// failure. Flushed by the shutdown handler on normal completion.
ob_start();
