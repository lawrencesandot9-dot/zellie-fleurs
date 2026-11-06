<?php
declare(strict_types=1);

/**
 * Store settings stored as key/value pairs in SQLite.
 */

function zf_settings_allowed_keys(): array
{
    return array_keys(zf_setting_defaults());
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
        error_log('[Zellie Fleurs] Settings update failed: ' . $e->getMessage());
        $errors[] = 'We could not save the shop settings. Please try again.';
        return false;
    }
}
