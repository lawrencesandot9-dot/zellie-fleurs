<?php
declare(strict_types=1);

/**
 * Shared helpers for Zellie Fleurs.
 * These functions are deliberately dependency-free so they can be used by
 * web pages, CLI scripts and tests.
 */

function zf_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function zf_money(mixed $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function zf_image_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '/assets/img/flower-placeholder.svg';
    }

    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    return '/' . ltrim($path, '/');
}

function zf_redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function zf_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    session_name('ZELLIEFLEURS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function zf_csrf_token(): string
{
    zf_session_start();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function zf_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . zf_e(zf_csrf_token()) . '">';
}

function zf_csrf_valid(): bool
{
    zf_session_start();
    $posted = $_POST['csrf_token'] ?? '';
    $known = $_SESSION['csrf_token'] ?? '';

    return is_string($posted)
        && is_string($known)
        && $known !== ''
        && hash_equals($known, $posted);
}

function zf_require_csrf(): void
{
    if (!zf_csrf_valid()) {
        zf_abort(419, 'Your security token has expired. Please go back, refresh the page and try again.');
    }
}

function zf_checkout_token(): string
{
    zf_session_start();
    if (empty($_SESSION['checkout_token']) || !is_string($_SESSION['checkout_token'])) {
        $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['checkout_token'];
}

function zf_flash(string $type, string $message): void
{
    zf_session_start();
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function zf_flashes(): array
{
    zf_session_start();
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);

    return is_array($flashes) ? $flashes : [];
}

function zf_render_flashes(): string
{
    $html = '';
    foreach (zf_flashes() as $flash) {
        $type = in_array($flash['type'] ?? '', ['success', 'error', 'info'], true)
            ? $flash['type']
            : 'info';
        $html .= '<div class="flash flash--' . zf_e($type) . '" role="status">'
            . zf_e($flash['message'] ?? '')
            . '</div>';
    }

    return $html;
}

function zf_abort(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $safe = zf_e($message);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Zellie Fleurs</title><link rel="stylesheet" href="/assets/css/style.css"></head>'
        . '<body><main class="error-page"><img src="/assets/img/logo-mark.svg" alt="" width="54" height="54">'
        . '<h1>Something needs your attention</h1><p>' . $safe . '</p>'
        . '<p><a class="btn btn--primary" href="/index.php">Return to the shop</a></p></main></body></html>';
    exit;
}

function zf_json_response(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function zf_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function zf_random_password(int $length = 16): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }

    return $password;
}

/**
 * Escape LIKE wildcards so user-supplied search terms act as substrings,
 * never as %, _ or backslash patterns. Pair the bound value with an
 * `ESCAPE '\'` clause on the LIKE predicate.
 *
 * Deliberately lives in this always-loaded file so src/orders.php and
 * src/products.php can never diverge.
 */
function zf_like_term(string $term): string
{
    $escaped = addcslashes($term, '\%_');

    return '%' . $escaped . '%';
}

function zf_format_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($date);
        return $dt->format($format);
    } catch (Throwable) {
        return (string) $date;
    }
}

function zf_format_datetime(?string $date): string
{
    return zf_format_date($date, 'M j, Y · g:i A');
}

function zf_safe_text(mixed $value): string
{
    $value = (string) $value;
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

    return trim($value);
}

function zf_safe_int(mixed $value, int $default = 0): int
{
    $filtered = filter_var($value, FILTER_VALIDATE_INT);

    return $filtered === false ? $default : (int) $filtered;
}

function zf_safe_float(mixed $value, float $default = 0.0): float
{
    if (!is_numeric($value)) {
        return $default;
    }

    return (float) $value;
}

function zf_settings(bool $reload = false): array
{
    global $db;

    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }

    $defaults = zf_setting_defaults();
    if (!isset($db) || !($db instanceof PDO)) {
        return $cache = $defaults;
    }

    try {
        $rows = $db->query('SELECT key, value FROM settings')->fetchAll();
        foreach ($rows as $row) {
            if (is_string($row['key'])) {
                $defaults[$row['key']] = (string) $row['value'];
            }
        }
    } catch (Throwable) {
        // Keep safe defaults if settings cannot be read.
    }

    return $cache = $defaults;
}

function zf_setting(string $key, string $default = ''): string
{
    $settings = zf_settings();

    return array_key_exists($key, $settings) ? (string) $settings[$key] : $default;
}

function zf_setting_defaults(): array
{
    return [
        'shop_name' => 'Zellie Fleurs',
        'tagline' => 'Artisanal Floristry',
        'announcement' => 'Same-day delivery available on orders placed before 12:00 PM.',
        'contact_number' => 'Contact number not yet set',
        'gcash_number' => 'GCash number not yet set',
        'gcash_name' => 'GCash account name not yet set',
        'store_address' => 'Studio address not yet set',
        'operating_hours' => 'Monday – Sunday · 8:00 AM – 7:00 PM',
        'delivery_info' => 'Fresh floral delivery within the city. Same-day orders must be placed before 12:00 PM.',
    ];
}

function zf_order_statuses(): array
{
    return ['Pending', 'Confirmed', 'Preparing', 'Out for Delivery', 'Delivered', 'Cancelled'];
}

function zf_status_class(string $status): string
{
    return match ($status) {
        'Pending' => 'status--pending',
        'Confirmed' => 'status--confirmed',
        'Preparing' => 'status--preparing',
        'Out for Delivery' => 'status--out',
        'Delivered' => 'status--delivered',
        'Cancelled' => 'status--cancelled',
        default => 'status--pending',
    };
}

function zf_status_label(string $status): string
{
    return in_array($status, zf_order_statuses(), true) ? $status : 'Pending';
}

function zf_tracking_steps(): array
{
    return ['Pending', 'Confirmed', 'Preparing', 'Out for Delivery', 'Delivered'];
}

/**
 * Render a contact value for the storefront. When it looks like a phone
 * number (seven or more digits once non-digits are stripped) it becomes a
 * tappable tel: link; obvious "not yet set" placeholders get a friendlier
 * line instead of raw settings text; anything else is escaped as-is.
 */
function zf_contact_link(string $value): string
{
    $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

    if (strlen($digits) >= 7) {
        return '<a href="tel:' . zf_e($digits) . '">' . zf_e($value) . '</a>';
    }

    if (stripos($value, 'not yet set') !== false) {
        return zf_e('No contact details published yet');
    }

    return zf_e($value);
}

function zf_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image is too large. Please choose a file under 3 MB.',
        UPLOAD_ERR_PARTIAL => 'The image did not upload completely. Please try again.',
        UPLOAD_ERR_NO_FILE => 'No image was selected.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the image. Please try again.',
        default => 'The image could not be uploaded. Please try another file.',
    };
}

/**
 * Mask a payment reference for display: keep at most the first two and last
 * two characters and replace the middle with bullets. References shorter
 * than six characters are fully masked so no usable fragment survives.
 */
function zf_mask_reference(string $ref): string
{
    $ref = trim($ref);
    $length = mb_strlen($ref);

    if ($length === 0) {
        return '';
    }

    if ($length < 6) {
        return str_repeat('•', $length);
    }

    return mb_substr($ref, 0, 2) . str_repeat('•', $length - 4) . mb_substr($ref, -2);
}

/**
 * Minimise a customer name for public tracking: first name in full,
 * subsequent parts reduced to initials ('Maria Santos' → 'Maria S.').
 * Single-word names are returned unchanged.
 */
function zf_mask_name(string $name): string
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        return '';
    }

    $parts = explode(' ', $name);
    if (count($parts) === 1) {
        return $parts[0];
    }

    $first = array_shift($parts);
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= ' ' . mb_substr($part, 0, 1) . '.';
    }

    return $first . $initials;
}
