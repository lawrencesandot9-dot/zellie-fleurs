<?php
declare(strict_types=1);

/**
 * Store settings stored as key/value pairs in SQLite.
 */

/**
 * Allowlisted setting keys. zf_setting_defaults() (src/helpers.php) defines
 * the storefront keys; the notification email is admin-only configuration
 * with no storefront default, so it is appended here instead.
 */
function zf_settings_allowed_keys(): array
{
    return array_merge(array_keys(zf_setting_defaults()), ['notify_email']);
}

function zf_update_settings(PDO $db, array $input, array &$errors): bool
{
    $errors = [];
    $limits = [
        'shop_name' => 80,
        'tagline' => 100,
        'announcement' => 220,
        'contact_number' => 40,
        'gcash_number' => 40,
        'gcash_name' => 80,
        'store_address' => 220,
        'operating_hours' => 160,
        'delivery_info' => 500,
        'notify_email' => 120,
    ];

    $values = [];
    foreach (zf_settings_allowed_keys() as $key) {
        $value = zf_safe_text($input[$key] ?? '');
        if (mb_strlen($value) > $limits[$key]) {
            $errors[] = 'One of the fields is too long. Please keep it under ' . $limits[$key] . ' characters.';
        }
        $values[$key] = $value;
    }

    if ($values['shop_name'] === '') {
        $errors[] = 'The shop name is required.';
    }

    // An empty notification email disables new-order alerts; anything that
    // is present must be a syntactically valid address.
    if ($values['notify_email'] !== '' && filter_var($values['notify_email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'The notification email is not a valid email address. Please check it or leave it blank.';
    }

    if ($errors !== []) {
        return false;
    }

    $stmt = $db->prepare('
        INSERT INTO settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value
    ');

    try {
        $db->beginTransaction();
        foreach ($values as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        $db->commit();
        zf_settings(true);
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        zf_error_log('settings-update-failure', $e->getMessage(), $e->getFile() . ':' . $e->getLine());
        $errors[] = 'We could not save the shop settings. Please try again.';
        return false;
    }
}

/**
 * Destination for the new-order notification log. Mirrors the ZF_LOG_PATH
 * pattern in src/error_handler.php: an environment override (used by the
 * test suite) falls back to the protected data/ directory. The default
 * target, data/order-notifications.log, sits outside the public web root
 * next to data/php-error.log.
 */
function zf_order_notification_log_path(): string
{
    $override = getenv('ZF_ORDER_NOTIFICATION_LOG');
    if (is_string($override) && $override !== '') {
        return $override;
    }

    return ZF_DATA . '/order-notifications.log';
}

/**
 * Fires once per successful order, immediately after zf_create_order's
 * transaction commits. Two independent outputs, both best-effort:
 *
 *  1. A one-line record appended to the order-notification log (created on
 *     demand with owner-only permissions, appended under an exclusive file
 *     lock — same protections as the error log in src/error_handler.php).
 *     The log is the guaranteed delivery channel.
 *  2. If the notify_email setting holds a valid address, a plain-text
 *     mail() attempt. mail() failures are suppressed silently: an MTA may
 *     not be configured, and the log above is the reliable fallback.
 *
 * Nothing here is allowed to break checkout — every failure mode (missing
 * directory, unwritable file, mail errors) is swallowed.
 */
function zf_notify_new_order(array $order, ?string $logPath = null): void
{
    try {
        $code = trim((string) ($order['order_code'] ?? ''));
        $name = trim((string) ($order['customer_name'] ?? ''));
        $total = number_format((float) ($order['total_amount'] ?? 0), 2, '.', '');
        $deliveryDate = (string) ($order['delivery_date'] ?? '');

        // One field per log line is required by the format below, so pipes
        // and line breaks inside attacker-controlled names are flattened.
        $name = str_replace(["\r", "\n", '|'], ' ', $name);

        $decodedItems = json_decode((string) ($order['items_json'] ?? '[]'), true);
        $itemCount = is_array($decodedItems) ? count($decodedItems) : 0;

        $path = $logPath ?? zf_order_notification_log_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Create with owner-only permissions before the first write, matching
        // how the protected error log is provisioned.
        if (!is_file($path)) {
            @touch($path);
        }
        @chmod($path, 0600);

        @file_put_contents(
            $path,
            sprintf("%s | %s | %s | %s | %d\n", date('Y-m-d H:i:s'), $code, $name, $total, $itemCount),
            FILE_APPEND | LOCK_EX
        );

        $email = zf_setting('notify_email');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $shopName = str_replace(["\r", "\n"], ' ', zf_setting('shop_name'));
            $body = [
                'New order received.',
                '',
                'Order: ' . $code,
                'Customer: ' . $name,
                'Total: PHP ' . $total,
                'Delivery date: ' . $deliveryDate,
                'Items: ' . $itemCount,
            ];
            if (isset($order['id']) && (int) $order['id'] > 0) {
                $body[] = 'View in admin: /admin/view_order.php?id=' . (int) $order['id'];
            }

            // Subject/header values are flattened first: shop_name and the
            // order fields are admin/customer-controlled and must never be
            // able to inject mail headers.
            $subject = str_replace(["\r", "\n"], ' ', 'New order ' . $code . ' — ' . $shopName);
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n";

            // Suppressed on purpose: no MTA on the host means a warning, not
            // a failure the shop needs to hear about. The log is the fallback.
            @mail($email, $subject, implode("\n", $body), $headers);
        }
    } catch (Throwable) {
        // The notification is strictly best-effort: a broken log target or
        // mail transport must never fail a successful checkout.
    }
}
