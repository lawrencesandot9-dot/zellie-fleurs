<?php
declare(strict_types=1);

/**
 * Branded 404 for missing routes and resources.
 * Reached via Apache's ErrorDocument 404 and via the PHP built-in server
 * router (public/router.php). Missing product pages answer through
 * product.php itself with the same zf_abort rendering.
 */
require_once __DIR__ . '/../config/database.php';

// API consumers always receive JSON, never an HTML error page.
if (zf_request_is_api()) {
    zf_json_response(
        ['ok' => false, 'error' => 'The requested API resource could not be found.'],
        404
    );
}

zf_abort(404, 'We could not find that page. It may have moved, or the address may be mistyped.');
