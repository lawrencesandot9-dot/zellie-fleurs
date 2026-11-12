<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in development server (used by serve.sh).
 *
 * Real files and directories are served untouched — returning false hands
 * the request back to the built-in server (static files streamed, existing
 * scripts executed, DirectoryIndex honoured). Everything else — a missing
 * route or a missing asset — gets the branded 404 page instead of the
 * server's default response.
 */
if (PHP_SAPI !== 'cli-server') {
    return false;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// Defensive: refuse traversal attempts before touching the filesystem — do
// not rely on the SAPI alone to reject paths escaping the docroot.
if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0")) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}

$target = __DIR__ . $path;

if ($path === '/' || is_file($target) || is_dir($target)) {
    return false;
}

http_response_code(404);
require __DIR__ . '/404.php';
