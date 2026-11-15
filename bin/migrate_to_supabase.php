<?php
declare(strict_types=1);

/**
 * Zellie Fleurs: SQLite -> Supabase migration
 *
 * Default: dry-run only.
 * Execute: php bin/migrate_to_supabase.php --execute
 *
 * Required environment variables:
 * SUPABASE_URL
 * SUPABASE_SECRET_KEY
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$execute = in_array('--execute', $argv, true);

$url = rtrim((string) getenv('SUPABASE_URL'), '/');
$key = (string) getenv('SUPABASE_SECRET_KEY');

if ($url === '' || $key === '') {
    fwrite(STDERR, "Missing SUPABASE_URL or SUPABASE_SECRET_KEY.\n");
    exit(1);
}

if (!extension_loaded('curl')) {
    fwrite(STDERR, "PHP cURL extension is required.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config/database.php';

function migration_timestamp(string $value): string
{
    $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Manila'));
    return $date->format('Y-m-d\TH:i:sP');
}

function migration_send(
    string $url,
    string $key,
    string $table,
    string $conflictColumn,
    array $rows
): void {
    if ($rows === []) {
        return;
    }

    $endpoint = $url . '/rest/v1/' . $table
        . '?on_conflict=' . rawurlencode($conflictColumn);

    $body = json_encode($rows, JSON_THROW_ON_ERROR);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'Prefer: resolution=merge-duplicates,return=minimal',
        ],
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException(
            "Supabase migration failed for {$table}. HTTP {$status}. "
            . ($error !== '' ? $error : (string) $response)
        );
    }

    echo "  Uploaded " . count($rows) . " rows to {$table}.\n";
}

function migration_batches(
    string $url,
    string $key,
    string $table,
    string $conflictColumn,
    array $rows
): void {
    foreach (array_chunk($rows, 100) as $batch) {
        migration_send($url, $key, $table, $conflictColumn, $batch);
    }
}

try {
    echo "=== ZELLIE FLEURS SUPABASE MIGRATION ===\n";
    echo $execute ? "MODE: EXECUTE\n\n" : "MODE: DRY RUN (no data will be sent)\n\n";

    $products = $db->query('SELECT * FROM products ORDER BY id')->fetchAll();
    $orders = $db->query('SELECT * FROM orders ORDER BY id')->fetchAll();
    $settings = $db->query('SELECT * FROM settings ORDER BY key')->fetchAll();

    echo "LOCAL SQLITE RECORD COUNTS\n";
    echo "Products: " . count($products) . "\n";
    echo "Orders:   " . count($orders) . "\n";
    echo "Settings: " . count($settings) . "\n";
    echo "Total:    " . (count($products) + count($orders) + count($settings)) . "\n\n";

    if (!$execute) {
        echo "DRY RUN COMPLETE. No records were uploaded.\n";
        echo "Review the counts above before running with --execute.\n";
        exit(0);
    }

    echo "This will transfer product, order, and settings records to Supabase.\n";
    echo "Order records include customer and delivery information.\n";
    echo "Your local SQLite database will NOT be modified.\n\n";
    echo "Type MIGRATE to continue: ";

    $confirmation = trim((string) fgets(STDIN));
    if ($confirmation !== 'MIGRATE') {
        echo "Cancelled. Nothing was uploaded.\n";
        exit(0);
    }

    foreach ($products as &$row) {
        $row['id'] = (int) $row['id'];
        $row['price'] = number_format((float) $row['price'], 2, '.', '');
        $row['stock_quantity'] = (int) $row['stock_quantity'];
        $row['is_active'] = (int) $row['is_active'] === 1;
        $row['created_at'] = migration_timestamp((string) $row['created_at']);
        $row['updated_at'] = migration_timestamp((string) $row['updated_at']);
    }
    unset($row);

    foreach ($orders as &$row) {
        $row['id'] = (int) $row['id'];
        $row['total_amount'] = number_format((float) $row['total_amount'], 2, '.', '');
        $row['created_at'] = migration_timestamp((string) $row['created_at']);
        $row['updated_at'] = migration_timestamp((string) $row['updated_at']);
    }
    unset($row);

    echo "\nUPLOADING RECORDS\n";
    migration_batches($url, $key, 'products', 'id', $products);
    migration_batches($url, $key, 'orders', 'id', $orders);
    migration_batches($url, $key, 'settings', 'key', $settings);

    echo "\nMIGRATION UPLOAD COMPLETE.\n";
    echo "Next: verify the record counts in Supabase before changing the application.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(1);
}
