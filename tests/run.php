<?php
declare(strict_types=1);

ob_start();

/**
 * Zellie Fleurs automated tests.
 * Run: php tests/run.php
 */

$tmpDir = sys_get_temp_dir() . '/zellie-fleurs-tests-' . getmypid();
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
$dbPath = $tmpDir . '/shop.db';
@unlink($dbPath);
@unlink($dbPath . '-wal');
@unlink($dbPath . '-shm');

putenv('ZF_DB_PATH=' . $dbPath);
putenv('ZF_LOG_PATH=' . $tmpDir . '/php-error.log');
// Route new-order notification log lines to the temp dir for the whole run
// so the suite never appends to the real data/order-notifications.log.
putenv('ZF_ORDER_NOTIFICATION_LOG=' . $tmpDir . '/order-notifications.log');
putenv('ZF_ADMIN_USER=admin');
putenv('ZF_ADMIN_PASSWORD=TestAdmin123!');
putenv('ZF_TESTING=1');
putenv('ZF_DEBUG=1');

require_once __DIR__ . '/../config/database.php';
require_once ZF_ROOT . '/src/auth.php';
require_once ZF_ROOT . '/src/products.php';
require_once ZF_ROOT . '/src/orders.php';
require_once ZF_ROOT . '/src/settings.php';

zf_session_start();

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

function orderInput(int $productId, int $qty = 1, string $payment = 'COD', string $gcashRef = ''): array
{
    return [
        'customer_name' => 'Maria Santos',
        'contact_number' => '0917 123 4567',
        'delivery_address' => '12 Rose Lane, Barangay Garden, Quezon City',
        'delivery_date' => date('Y-m-d', strtotime('+3 days')),
        'card_message' => 'With love',
        'payment_method' => $payment,
        'gcash_ref' => $gcashRef,
        'items' => [['id' => $productId, 'qty' => $qty]],
    ];
}

section('Database');
$tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['products', 'orders', 'admin_users', 'login_attempts', 'settings'] as $table) {
    check("table {$table} exists", in_array($table, $tables, true));
}
$productCount = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
check('seed products inserted', $productCount >= 4, "found {$productCount}");
zf_ensure_schema($db, $dbPath);
check('schema is idempotent (no duplicate seed products)', (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() === $productCount);
check('integrity check passes', $db->query('PRAGMA integrity_check')->fetchColumn() === 'ok');

section('Admin session migration');
$invalidBeforeColumn = null;
foreach ($db->query('PRAGMA table_info(admin_users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
    if (($column['name'] ?? '') === 'session_invalid_before') {
        $invalidBeforeColumn = $column;
    }
}
check('admin_users has session_invalid_before for session invalidation', $invalidBeforeColumn !== null);
check('session_invalid_before defaults to 0', $invalidBeforeColumn !== null && (int) $invalidBeforeColumn['dflt_value'] === 0);
zf_ensure_schema($db, $dbPath);
$invalidBeforeCopies = 0;
foreach ($db->query('PRAGMA table_info(admin_users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
    if (($column['name'] ?? '') === 'session_invalid_before') {
        $invalidBeforeCopies++;
    }
}
check('admin session migration is idempotent on a second run', $invalidBeforeCopies === 1);

section('Storefront queries');
$searchResults = zf_get_products($db, ['search' => 'tulip', 'active_only' => true]);
check('product search finds tulips', $searchResults !== [] && stripos($searchResults[0]['name'], 'tulip') !== false);
$boxResults = zf_get_products($db, ['category' => 'Luxury Boxes', 'active_only' => true]);
check('category filter works', $boxResults !== [] && $boxResults[0]['category'] === 'Luxury Boxes');
$categories = zf_get_categories($db, true);
check('categories load from database', in_array('Bouquets', $categories, true));
$soldOut = $db->query('SELECT * FROM products WHERE stock_quantity = 0 AND is_active = 1 LIMIT 1')->fetch();
check('out-of-stock product is marked unavailable', $soldOut !== false && zf_product_availability($soldOut)['available'] === false);

section('Checkout validation and order creation');
$product = $db->query('SELECT * FROM products WHERE stock_quantity >= 5 AND is_active = 1 ORDER BY id ASC LIMIT 1')->fetch();
$productId = (int) $product['id'];
$stockBefore = (int) $product['stock_quantity'];

$empty = zf_create_order($db, orderInput($productId, 0));
check('empty/invalid cart is rejected', $empty['ok'] === false);

$invalidId = zf_create_order($db, orderInput(999999, 1));
check('invalid product id is rejected', $invalidId['ok'] === false);

$negative = zf_create_order($db, orderInput($productId, -2));
check('negative quantity is rejected', $negative['ok'] === false);

$fractional = orderInput($productId, 1);
$fractional['items'] = [['id' => $productId, 'qty' => 1.5]];
check('fractional quantity is rejected', zf_create_order($db, $fractional)['ok'] === false);

$missing = orderInput($productId, 1);
$missing['customer_name'] = '';
check('missing customer name is rejected', zf_create_order($db, $missing)['ok'] === false);

$badDate = orderInput($productId, 1);
$badDate['delivery_date'] = '2000-01-01';
check('past delivery date is rejected', zf_create_order($db, $badDate)['ok'] === false);

$gcashMissing = zf_create_order($db, orderInput($productId, 1, 'GCash', ''));
check('GCash reference is required', $gcashMissing['ok'] === false);

$manipulated = orderInput($productId, 2);
$manipulated['price'] = 1;
$manipulated['total_amount'] = 1;
$manipulated['name'] = 'Hacked name';
$manipulated['stock_quantity'] = 9999;

$created = zf_create_order($db, $manipulated, 'test-token-' . bin2hex(random_bytes(4)));
check('valid COD order succeeds', $created['ok'] === true, implode('; ', $created['errors'] ?? []));

if ($created['ok']) {
    $order = $created['order'];
    check('order reference format is correct', preg_match('/^ZF-[A-HJ-NP-Z2-9]{12}$/', $order['order_code']) === 1, $order['order_code']);
    check('server calculates the real total', abs((float) $order['total_amount'] - round((float) $product['price'] * 2, 2)) < 0.001);
    check('client price manipulation is ignored', (float) $order['total_amount'] !== 1.0);
    check('client product name manipulation is ignored', !str_contains($order['items_json'], 'Hacked name'));
    check('stock is decremented', (int) zf_get_product($db, $productId)['stock_quantity'] === $stockBefore - 2);
    check('order starts as Pending', $order['status'] === 'Pending');
    check('COD order has no GCash reference', $order['gcash_ref'] === '');

    $tracked = zf_get_order_by_code($db, $order['order_code']);
    check('valid order code returns order', $tracked !== null && $tracked['order_code'] === $order['order_code']);
    check('invalid order code returns nothing', zf_get_order_by_code($db, 'ZF-NOPE00') === null);
    $orderId = (int) $order['id'];
    $orderCode = $order['order_code'];

    $duplicate = zf_create_order($db, $manipulated, $created['order']['client_token'] ?? '');
    check('duplicate submission is prevented', $duplicate['ok'] === true && ($duplicate['duplicate'] ?? false) === true);

    $gcashOrder = zf_create_order($db, orderInput($productId, 1, 'GCash', 'abc12345'));
    check('valid GCash order succeeds', $gcashOrder['ok'] === true, implode('; ', $gcashOrder['errors'] ?? []));
    if ($gcashOrder['ok']) {
        check('GCash reference is stored', $gcashOrder['order']['gcash_ref'] === 'ABC12345');
    }

    section('Order statuses and tracking');
    foreach (['Confirmed', 'Preparing', 'Out for Delivery', 'Delivered'] as $status) {
        $err = null;
        check("status can be set to {$status}", zf_update_order_status($db, $orderId, $status, $err), (string) $err);
    }
    check('tracking reflects the latest status', zf_get_order($db, $orderId)['status'] === 'Delivered');

    $stockBeforeCancel = (int) zf_get_product($db, $productId)['stock_quantity'];
    $err = null;
    check('order can be cancelled', zf_update_order_status($db, $orderId, 'Cancelled', $err), (string) $err);
    check('cancelling restores stock', (int) zf_get_product($db, $productId)['stock_quantity'] === $stockBeforeCancel + 2);

    $analytics = zf_order_analytics($db);
    $cancelledRevenue = (float) $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'Cancelled'")->fetchColumn();
    check('revenue excludes cancelled orders', $analytics['revenue'] > 0 && $cancelledRevenue > 0);
    check('analytics count every status', array_keys($analytics['counts']) === zf_order_statuses());

    $err = null;
    check('cancelled order can be reopened when stock exists', zf_update_order_status($db, $orderId, 'Confirmed', $err), (string) $err);
    check('reopening deducts stock again', (int) zf_get_product($db, $productId)['stock_quantity'] === $stockBeforeCancel);

    $found = zf_search_orders($db, $orderCode, '', 'newest');
    check('admin order search works', count($found) === 1 && $found[0]['order_code'] === $orderCode);
    check('admin status filter works', zf_search_orders($db, '', 'Confirmed', 'newest') !== []);
}

section('Administrator');
$admin = $db->query('SELECT * FROM admin_users ORDER BY id ASC LIMIT 1')->fetch();
check('administrator is seeded', $admin !== false);
check('password is stored as a secure hash', $admin !== false && password_verify('TestAdmin123!', $admin['password_hash']));

$badLogin = zf_auth_login($db, 'admin', 'wrong-password');
check('invalid login is rejected', $badLogin['ok'] === false);
$goodLogin = zf_auth_login($db, 'admin', 'TestAdmin123!');
check('valid login succeeds', $goodLogin['ok'] === true, $goodLogin['error'] ?? '');
check('session is authenticated after login', zf_admin_logged_in() === true);

// Cross-session invalidation: stamping session_invalid_before ahead of the
// session's login time must expire it (and clear only the admin keys).
$db->prepare('UPDATE admin_users SET session_invalid_before = ? WHERE id = ?')
    ->execute([time() + 60, (int) $admin['id']]);
check('a session predating session_invalid_before is expired', zf_admin_logged_in() === false);
check('only admin keys are cleared, the session itself survives', empty($_SESSION['admin_id']) && empty($_SESSION['admin_login_at']) && isset($_SESSION['csrf_token']));
zf_auth_logout();
check('logout clears the session', zf_admin_logged_in() === false);

$passwordChange = zf_change_admin_password($db, (int) $admin['id'], 'TestAdmin123!', 'NewAdminPass456!', 'NewAdminPass456!');
check('administrator password can be changed', $passwordChange['ok'] === true, $passwordChange['error'] ?? '');
$hash = $db->query('SELECT password_hash FROM admin_users WHERE id = ' . (int) $admin['id'])->fetchColumn();
check('new administrator password verifies', password_verify('NewAdminPass456!', $hash));

for ($i = 0; $i < 5; $i++) {
    zf_auth_login($db, 'admin', 'still-wrong');
}
$locked = zf_auth_login($db, 'admin', 'NewAdminPass456!');
check('repeated failed logins trigger a temporary lockout', $locked['ok'] === false && stripos($locked['error'], 'Too many') !== false);

section('Admin session expiry verdicts');
$now = time();
check('fresh admin session is not expired', zf_admin_session_expired(['admin_login_at' => $now, 'admin_last_seen' => $now], $now, 1800, 28800) === false);
check('idle exactly at the limit is not expired', zf_admin_session_expired(['admin_login_at' => $now - 1800, 'admin_last_seen' => $now - 1800], $now, 1800, 28800) === false);
check('idle one tick past the limit is expired', zf_admin_session_expired(['admin_login_at' => $now - 1801, 'admin_last_seen' => $now - 1801], $now, 1800, 28800) === true);
check('recent activity within the idle window is not expired', zf_admin_session_expired(['admin_login_at' => $now - 20000, 'admin_last_seen' => $now - 100], $now, 1800, 28800) === false);
check('idle past the limit expires despite recent activity', zf_admin_session_expired(['admin_login_at' => $now - 20000, 'admin_last_seen' => $now - 2000], $now, 1800, 28800) === true);
check('absolute exactly at the limit is not expired', zf_admin_session_expired(['admin_login_at' => $now - 28800, 'admin_last_seen' => $now], $now, 1800, 28800) === false);
check('absolute one tick past the limit is expired even with recent activity', zf_admin_session_expired(['admin_login_at' => $now - 28801, 'admin_last_seen' => $now], $now, 1800, 28800) === true);
check('missing admin_last_seen falls back to admin_login_at', zf_admin_session_expired(['admin_login_at' => $now - 100], $now, 1800, 28800) === false);
check('missing timestamps are treated as expired', zf_admin_session_expired([], $now, 1800, 28800) === true);
check('a missing login timestamp is treated as expired', zf_admin_session_expired(['admin_last_seen' => $now], $now, 1800, 28800) === true);

section('Product management');
$errors = [];
$newId = zf_save_product($db, [
    'name' => 'Test Peony Arrangement',
    'category' => 'Bouquets',
    'price' => '1250.50',
    'description' => 'A test arrangement created by the automated test suite.',
    'image_url' => 'assets/img/products/elysian-blush.jpg',
    'stock_quantity' => '4',
    'is_active' => '1',
], null, null, $errors);
check('product creation works', $newId !== null && $newId > 0, implode('; ', $errors));

if ($newId !== null) {
    $editErrors = [];
    $edited = zf_save_product($db, [
        'name' => 'Test Peony Arrangement Updated',
        'category' => 'Vase Arrangements',
        'price' => '1500',
        'description' => 'Updated by the automated test suite.',
        'image_url' => 'assets/img/products/ivory-garden.jpg',
        'stock_quantity' => '7',
        'is_active' => '1',
    ], null, $newId, $editErrors);
    $editedProduct = zf_get_product($db, $newId);
    check('product editing works', $edited === $newId && $editedProduct['name'] === 'Test Peony Arrangement Updated' && (float) $editedProduct['price'] === 1500.0, implode('; ', $editErrors));

    check('product can be deactivated', zf_toggle_product($db, $newId) && (int) zf_get_product($db, $newId)['is_active'] === 0);
    check('product can be reactivated', zf_toggle_product($db, $newId) && (int) zf_get_product($db, $newId)['is_active'] === 1);

    check('unreferenced product can be deleted', zf_delete_product($db, $newId) === 'deleted' && zf_get_product($db, $newId) === null);
}

if (isset($productId)) {
    check('referenced product is deactivated instead of deleted', zf_delete_product($db, $productId) === 'deactivated' && zf_get_product($db, $productId) !== null);
}

section('Image upload security');
$pngPath = $tmpDir . '/valid.png';
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2n0sAAAAASUVORK5CYII=');
file_put_contents($pngPath, $pngBytes);
$uploadError = null;
$uploaded = zf_handle_image_upload([
    'name' => 'valid.png',
    'type' => 'image/png',
    'tmp_name' => $pngPath,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($pngPath),
], $uploadError);
check('valid PNG upload succeeds', $uploaded !== null && $uploadError === null && is_file(ZF_PUBLIC . '/' . $uploaded), (string) $uploadError);
if ($uploaded !== null) {
    @unlink(ZF_PUBLIC . '/' . $uploaded);
}

$fakePath = $tmpDir . '/evil.png';
file_put_contents($fakePath, '<?php echo "not an image";');
$fakeError = null;
$fakeUpload = zf_handle_image_upload([
    'name' => 'evil.png',
    'type' => 'image/png',
    'tmp_name' => $fakePath,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($fakePath),
], $fakeError);
check('executable disguised as image is rejected', $fakeUpload === null && $fakeError !== null);

section('Settings');
$settingsErrors = [];
$saved = zf_update_settings($db, array_merge(zf_settings(true), ['shop_name' => 'Zellie Fleurs Test']), $settingsErrors);
check('shop settings can be updated', $saved === true && zf_setting('shop_name') === 'Zellie Fleurs Test', implode('; ', $settingsErrors));

section('New-order notifications');

// (a) The notify_email setting round-trips through the save/validate helpers.
check('notify_email defaults to disabled (empty string)', zf_setting('notify_email') === '');

$settingsErrors = [];
$validSave = zf_update_settings($db, array_merge(zf_settings(true), ['notify_email' => 'owner@zellie.example']), $settingsErrors);
check('valid notify_email round-trips through the settings helpers', $validSave === true && zf_setting('notify_email') === 'owner@zellie.example', implode('; ', $settingsErrors));

$settingsErrors = [];
$invalidSave = zf_update_settings($db, array_merge(zf_settings(true), ['notify_email' => 'not-an-email']), $settingsErrors);
check('invalid notify_email is rejected and not persisted', $invalidSave === false && $settingsErrors !== [] && zf_setting('notify_email') === 'owner@zellie.example', implode('; ', $settingsErrors));

$settingsErrors = [];
$emptySave = zf_update_settings($db, array_merge(zf_settings(true), ['notify_email' => '']), $settingsErrors);
check('empty notify_email saves (notifications disabled)', $emptySave === true && zf_setting('notify_email') === '', implode('; ', $settingsErrors));

// (b) zf_notify_new_order logs even with notifications disabled and never throws.
$notifyLog = $tmpDir . '/notify-direct.log';
@unlink($notifyLog);
$notifyOrder = [
    'id' => 123,
    'order_code' => 'ZF-NOTIFY01',
    'customer_name' => "Juan\nDela Cruz",
    'total_amount' => 1899.0,
    'delivery_date' => date('Y-m-d', strtotime('+2 days')),
    'items_json' => '[{"qty":1},{"qty":2},{"qty":3}]',
];
$notifyThrew = false;
try {
    zf_notify_new_order($notifyOrder, $notifyLog);
} catch (Throwable) {
    $notifyThrew = true;
}
$notifyLines = is_file($notifyLog) ? file($notifyLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$notifyParts = $notifyLines !== [] ? explode(' | ', trim($notifyLines[0])) : [];
check('notification log is written even when notify_email is empty and never throws', !$notifyThrew && count($notifyParts) === 5);
$codeField = $notifyParts[1] ?? '';
$nameField = $notifyParts[2] ?? '';
$totalField = $notifyParts[3] ?? '';
$countField = $notifyParts[4] ?? '';
check('notification log line matches the timestamp | code | name | total | item count format', $codeField === 'ZF-NOTIFY01' && $nameField === 'Juan Dela Cruz' && $totalField === '1899.00' && $countField === '3', implode(' | ', $notifyParts));
check('notification log is created with owner-only permissions', is_file($notifyLog) && (fileperms($notifyLog) & 0777) === 0600);

$blockedPath = $notifyLog . '/blocked.log';
$blockedThrew = false;
try {
    zf_notify_new_order($notifyOrder, $blockedPath);
} catch (Throwable) {
    $blockedThrew = true;
}
check('an unwritable notification log path never throws', $blockedThrew === false);

// (c) The notification fires once per successful zf_create_order.
// The original $productId product was deactivated earlier in the suite, so
// pick any still-active stocked product the way the checkout would.
$notifyProduct = $db->query('SELECT id FROM products WHERE is_active = 1 AND stock_quantity >= 1 ORDER BY id ASC LIMIT 1')->fetch();
$notifyProductId = (int) ($notifyProduct['id'] ?? 0);
check('an active product is available for the notification integration test', $notifyProductId > 0);
$integrationLog = $tmpDir . '/order-notifications.log';
$integrationBefore = is_file($integrationLog) ? count(file($integrationLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
$notifyToken = 'notify-token-' . bin2hex(random_bytes(4));
$notified = zf_create_order($db, orderInput($notifyProductId, 1, 'COD'), $notifyToken);
$integrationLines = is_file($integrationLog) ? file($integrationLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
check('a successful order appends exactly one notification line', $notified['ok'] === true && count($integrationLines) === $integrationBefore + 1, implode('; ', $notified['errors'] ?? []));
$integrationLine = $integrationLines[$integrationBefore] ?? '';
check('the notification line references the created order code', str_contains($integrationLine, (string) ($notified['order']['order_code'] ?? 'ZF-MISSING')), $integrationLine);

if ($notified['ok'] === true) {
    // A token-duplicate resolution must not fire a second notification: the
    // original creation is the only one that notifies.
    $duplicateNotified = zf_create_order($db, orderInput($notifyProductId, 1, 'COD'), $notifyToken);
    $integrationAfterDup = is_file($integrationLog) ? count(file($integrationLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    check('duplicate token submissions do not re-notify', ($duplicateNotified['duplicate'] ?? false) === true && $integrationAfterDup === count($integrationLines));
}

section('Mobile navigation');
$storeHeader = (string) file_get_contents(ZF_ROOT . '/src/views/store_header.php');
$storeCss = (string) file_get_contents(ZF_PUBLIC . '/assets/css/style.css');
$storeJs = (string) file_get_contents(ZF_PUBLIC . '/assets/js/app.js');

check('mobile navigation is hidden by default', preg_match('/<nav[^>]*class="mobile-nav"[^>]*\bhidden\b/', $storeHeader) === 1);
check('menu trigger reports the collapsed state', preg_match('/data-menu-toggle[^>]*aria-expanded="false"[^>]*aria-controls="mobile-menu"/s', $storeHeader) === 1);
check('mobile navigation has an accessible label and id', str_contains($storeHeader, 'id="mobile-menu"') && str_contains($storeHeader, 'aria-label="Mobile navigation"'));
check('hidden navigation is removed from layout', preg_match('/\.mobile-nav\[hidden\]\s*\{[^}]*display:\s*none/s', $storeCss) === 1);
check('mobile navigation overlays instead of pushing the hero', preg_match('/\.mobile-nav\s*\{[^}]*position:\s*absolute/s', $storeCss) === 1);
check('menu script updates expanded state and label', str_contains($storeJs, "toggle.setAttribute('aria-expanded'") && str_contains($storeJs, "toggle.setAttribute('aria-label'"));
check('menu script closes on escape and outside clicks', str_contains($storeJs, "event.key === 'Escape'") && str_contains($storeJs, 'closeMobileMenu(false)'));
check('menu script locks background scrolling', str_contains($storeJs, "classList.toggle('menu-open'") && str_contains($storeCss, 'body.menu-open'));

section('Order code generator');
$generatedCodes = [];
$formatOk = true;
for ($i = 0; $i < 200; $i++) {
    $code = zf_generate_order_code($db);
    $generatedCodes[] = $code;
    if (preg_match('/^ZF-[A-HJ-NP-Z2-9]{12}$/', $code) !== 1) {
        $formatOk = false;
    }
}
check('generated codes use the 12-character alphabet format', $formatOk, 'sample: ' . ($generatedCodes[0] ?? 'none'));
check('generated codes are unique across 200 generations', count(array_unique($generatedCodes)) === 200, 'unique: ' . count(array_unique($generatedCodes)));
check('generator produces different codes across calls', count(array_unique($generatedCodes)) > 1);

section('Legacy order code compatibility');
$legacyDate = date('Y-m-d', strtotime('+2 days'));
$legacy = $db->prepare('
    INSERT INTO orders (
        order_code, customer_name, contact_number, delivery_address, delivery_date,
        payment_method, gcash_ref, total_amount, items_json, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$legacy->execute(['ZF-TEST01', 'Legacy Buyer', '0917 000 0000', '1 Old Code Road, Quezon City', $legacyDate, 'COD', '', 100.0, '[]', 'Pending']);
check('legacy 6-character order code still resolves', zf_get_order_by_code($db, 'ZF-TEST01') !== null);

section('Reference and name masking');
check('reference masking keeps first two and last two characters', zf_mask_reference('GC12345678') === 'GC••••••78', zf_mask_reference('GC12345678'));
check('short references are fully masked', zf_mask_reference('GC12') === '••••' && zf_mask_reference('12345') === '•••••');
check('empty reference stays empty', zf_mask_reference('') === '');
check('name masking keeps the first name and initialises the rest', zf_mask_name('Maria Santos') === 'Maria S.');
check('single-word names are unchanged', zf_mask_name('Maria') === 'Maria');
check('extra name parts each become initials', zf_mask_name('Anna Grace Reyes') === 'Anna G. R.');

section('Order rate limiting');

// Start from a clean slate so the quota arithmetic below is deterministic
// (the checkout section above already recorded a few successful orders).
zf_order_rate_limit_ready($db);
$db->exec('DELETE FROM order_attempts');

$rateLog = $tmpDir . '/order-notifications.log';
$rateNotifyBase = is_file($rateLog) ? count(file($rateLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
$rateProduct = $db->query('SELECT id, stock_quantity FROM products WHERE is_active = 1 AND stock_quantity >= 6 ORDER BY stock_quantity DESC, id ASC LIMIT 1')->fetch();
$rateProductId = (int) ($rateProduct['id'] ?? 0);
$prevRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';

// (a) Under the cap: five successful checkouts in a row, each recorded once.
$rateOk = true;
if ($rateProductId > 0) {
    for ($i = 1; $i <= 5; $i++) {
        $rateResult = zf_create_order($db, orderInput($rateProductId, 1, 'COD'), 'rate-token-' . $i);
        $rateOk = $rateOk && $rateResult['ok'] === true;
    }
}
$rateOrdersAfterFive = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$rateRows = (int) $db->query("SELECT COUNT(*) FROM order_attempts WHERE ip_address = '203.0.113.50'")->fetchColumn();
check('five orders within the cap succeed and each records one attempt', $rateProductId > 0 && $rateOk && $rateRows === 5 && $rateOrdersAfterFive >= 5, "rows {$rateRows}, orders {$rateOrdersAfterFive}");

// (b) The 6th order is rejected before any state changes.
$rateStockBefore = (int) zf_get_product($db, $rateProductId)['stock_quantity'];
$rateOrdersBefore = $rateOrdersAfterFive;
$blocked = zf_create_order($db, orderInput($rateProductId, 1, 'COD'), 'rate-token-6');
$rateOrdersAfterBlock = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$rateStockAfter = (int) zf_get_product($db, $rateProductId)['stock_quantity'];
$rateNotifyLines = is_file($rateLog) ? count(file($rateLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
check('the 6th order within the hour is rejected', $blocked['ok'] === false, implode('; ', $blocked['errors'] ?? []));
check('the rejection shows the friendly rate-limit message', str_contains($blocked['errors'][0] ?? '', 'Too many orders placed recently'), implode('; ', $blocked['errors'] ?? []));
check('the rejected order creates no order row', $rateOrdersAfterBlock === $rateOrdersBefore, "before {$rateOrdersBefore} after {$rateOrdersAfterBlock}");
check('the rejected order does not decrement stock', $rateStockAfter === $rateStockBefore, "before {$rateStockBefore} after {$rateStockAfter}");
check('the rejected order fires no notification line', $rateNotifyLines === $rateNotifyBase + 5, "{$rateNotifyLines} vs base {$rateNotifyBase} + 5");

// (e) The rejection consumed nothing: the same token still creates a fresh
// order once the cap is lifted via the options override.
$tokenReuse = zf_create_order($db, orderInput($rateProductId, 1, 'COD'), 'rate-token-6', ['max_per_hour' => 1000]);
check('the rejected order burns no idempotency token', $tokenReuse['ok'] === true && ($tokenReuse['duplicate'] ?? true) === false, implode('; ', $tokenReuse['errors'] ?? []));

// (c) Keying: the client IP is a fallback key alongside the session id, so
// a fresh or sessionless client behind a flooded IP is blocked, while a
// different IP is unaffected by someone else's flood.
$floodIp = '198.51.100.77';
$floodInsert = $db->prepare("INSERT INTO order_attempts (session_id, ip_address, created_at) VALUES ('attacker-session', ?, datetime('now', 'localtime'))");
for ($i = 0; $i < 5; $i++) {
    $floodInsert->execute([$floodIp]);
}
$floodBlocksFresh = zf_order_rate_limit_blocked($db, ['session_id' => 'victim-session', 'ip' => $floodIp]) !== null;
$floodBlocksSessionless = zf_order_rate_limit_blocked($db, ['session_id' => '', 'ip' => $floodIp]) !== null;
check('a flooded IP blocks fresh and sessionless clients alike (IP key)', $floodBlocksFresh && $floodBlocksSessionless);
check('an order from a different IP is unaffected by a flooded IP', zf_order_rate_limit_blocked($db, ['session_id' => 'victim-session', 'ip' => '192.0.2.9']) === null);

// (d) Rows older than the window are ignored and opportunistically removed.
$db->exec("DELETE FROM order_attempts WHERE ip_address = '198.51.100.77'");
$db->prepare("INSERT INTO order_attempts (session_id, ip_address, created_at) VALUES ('old-session', '192.0.2.99', datetime('now', 'localtime', '-2 hours'))")->execute();
$oldKeys = ['session_id' => 'old-session', 'ip' => '192.0.2.99'];
$oldNotCounted = zf_order_rate_limit_blocked($db, $oldKeys) === null;
$oldRowsLeft = (int) $db->query("SELECT COUNT(*) FROM order_attempts WHERE session_id = 'old-session'")->fetchColumn();
check('rows older than one hour do not count and are cleaned up', $oldNotCounted && $oldRowsLeft === 0, "blocked " . ($oldNotCounted ? 'no' : 'yes') . ", rows left {$oldRowsLeft}");

// Failed validation must never burn the quota (only successes are recorded).
$rateBad = zf_create_order($db, orderInput($rateProductId, 0));
$rateRowsNow = (int) $db->query("SELECT COUNT(*) FROM order_attempts WHERE ip_address = '203.0.113.50'")->fetchColumn();
check('failed validation attempts do not consume the quota', $rateBad['ok'] === false && $rateRowsNow === 6 && !str_contains(implode(' ', $rateBad['errors']), 'Too many'), "rows {$rateRowsNow}");

// The ZF_ORDER_RATE_LIMIT environment override (used by the HTTP harness).
putenv('ZF_ORDER_RATE_LIMIT=1');
$envAllowed = zf_order_rate_limit_blocked($db, ['session_id' => 'env-session', 'ip' => '192.0.2.51']) === null;
zf_record_order_attempt($db, ['session_id' => 'env-session', 'ip' => '192.0.2.51']);
$envBlocked = zf_order_rate_limit_blocked($db, ['session_id' => 'env-session', 'ip' => '192.0.2.51']) !== null;
putenv('ZF_ORDER_RATE_LIMIT');
$db->exec("DELETE FROM order_attempts WHERE session_id = 'env-session'");
check('ZF_ORDER_RATE_LIMIT env override is honored', $envAllowed && $envBlocked);

if ($prevRemoteAddr !== null) {
    $_SERVER['REMOTE_ADDR'] = $prevRemoteAddr;
} else {
    unset($_SERVER['REMOTE_ADDR']);
}

section('Same-day delivery cutoff');

// The storefront announcement promises same-day delivery only for orders
// placed before 12:00 PM (shop TZ, Asia/Manila via ZF_TZ). zf_create_order()
// accepts an $options['now'] override (int unix timestamp, interpreted with
// date('Y-m-d')/date('G') in the configured timezone) so these checks are
// deterministic no matter what time of day the suite runs. Runs after the
// rate-limit section, so its attempt-row arithmetic above is untouched; the
// accepted cutoff orders raise the cap via the options override.
$cutoffProduct = $db->query('SELECT id FROM products WHERE is_active = 1 AND stock_quantity >= 2 ORDER BY stock_quantity DESC, id ASC LIMIT 1')->fetch();
$cutoffProductId = (int) ($cutoffProduct['id'] ?? 0);
$cutoffLog = $tmpDir . '/order-notifications.log';
$cutoffNotifyBase = is_file($cutoffLog) ? count(file($cutoffLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
$cutoffOrdersBase = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();

if ($cutoffProductId > 0) {
    $cutoffToday = date('Y-m-d');
    $cutoffTomorrow = date('Y-m-d', strtotime('+1 day'));
    $cutoffYesterday = date('Y-m-d', strtotime('-1 day'));
    $cutoffBefore = strtotime('today 10:00');  // 10:00 AM local — before cutoff
    $cutoffAt = strtotime('today 12:00');      // 12:00:00 sharp — at cutoff
    $cutoffAfter = strtotime('today 15:30');   // 3:30 PM local — after cutoff
    $cutoffMessage = 'Same-day delivery is available only for orders placed before 12:00 PM. Please choose tomorrow or another date.';
    $cutoffClock = static fn (int $ts): array => ['now' => $ts, 'max_per_hour' => 1000];
    $cutoffInput = static fn (string $date): array => array_merge(orderInput($cutoffProductId, 1, 'COD'), ['delivery_date' => $date]);

    // (a) Before the cutoff, a same-day order is accepted and stored as-is.
    $cutoffEarly = zf_create_order($db, $cutoffInput($cutoffToday), 'cutoff-early', $cutoffClock($cutoffBefore));
    check('an order for today before the cutoff (10:00) is accepted with today as the date', $cutoffProductId > 0 && $cutoffEarly['ok'] === true && ($cutoffEarly['order']['delivery_date'] ?? '') === $cutoffToday, implode('; ', $cutoffEarly['errors'] ?? []));

    // (b) The announcement says "before 12:00 PM", so 12:00:00 sharp is
    // already too late: hour >= ZF_SAME_DAY_CUTOFF_HOUR rejects.
    $cutoffNoon = zf_create_order($db, $cutoffInput($cutoffToday), 'cutoff-noon', $cutoffClock($cutoffAt));
    check('an order for today at exactly 12:00 is rejected with the cutoff message and field error', $cutoffNoon['ok'] === false && str_contains($cutoffNoon['errors'][0] ?? '', $cutoffMessage) && ($cutoffNoon['field_errors']['delivery_date'] ?? false) === true, implode('; ', $cutoffNoon['errors'] ?? []));

    // (c) After the cutoff, a same-day order is rejected with the message.
    $cutoffLate = zf_create_order($db, $cutoffInput($cutoffToday), 'cutoff-late', $cutoffClock($cutoffAfter));
    check('an order for today after the cutoff (15:30) is rejected with the cutoff message', $cutoffLate['ok'] === false && str_contains($cutoffLate['errors'][0] ?? '', $cutoffMessage), implode('; ', $cutoffLate['errors'] ?? []));

    // (d) After the cutoff, tomorrow is still bookable.
    $cutoffTomorrowOrder = zf_create_order($db, $cutoffInput($cutoffTomorrow), 'cutoff-tomorrow', $cutoffClock($cutoffAfter));
    check('an order for tomorrow after the cutoff (15:30) is accepted with tomorrow as the date', $cutoffTomorrowOrder['ok'] === true && ($cutoffTomorrowOrder['order']['delivery_date'] ?? '') === $cutoffTomorrow, implode('; ', $cutoffTomorrowOrder['errors'] ?? []));

    // (e) Check order matters: yesterday must still be caught by the
    // pre-existing past-date validation, never shadowed by the cutoff branch.
    $cutoffPast = zf_create_order($db, $cutoffInput($cutoffYesterday), 'cutoff-past', $cutoffClock($cutoffAfter));
    check("yesterday's date is still rejected by the past-date validation, not the cutoff", $cutoffPast['ok'] === false && str_contains($cutoffPast['errors'][0] ?? '', 'The delivery date cannot be in the past'), implode('; ', $cutoffPast['errors'] ?? []));

    // (f) The cutoff rejection happens during validation, before the token
    // replay, rate-limit check, transaction or notification: no partial
    // state of any kind (mirrors the rate-limit assertions above).
    $cutoffStockBase = (int) zf_get_product($db, $cutoffProductId)['stock_quantity'];
    $cutoffAttemptsBase = (int) $db->query('SELECT COUNT(*) FROM order_attempts')->fetchColumn();
    $cutoffRejected = zf_create_order($db, $cutoffInput($cutoffToday), 'cutoff-rejected', ['now' => $cutoffAfter]);
    $cutoffOrdersNow = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $cutoffStockNow = (int) zf_get_product($db, $cutoffProductId)['stock_quantity'];
    $cutoffNotifyNow = is_file($cutoffLog) ? count(file($cutoffLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    $cutoffAttemptsNow = (int) $db->query('SELECT COUNT(*) FROM order_attempts')->fetchColumn();
    check('the after-cutoff rejection fires the cutoff message', $cutoffRejected['ok'] === false && str_contains($cutoffRejected['errors'][0] ?? '', $cutoffMessage), implode('; ', $cutoffRejected['errors'] ?? []));
    check('the cutoff rejection writes no order row', $cutoffOrdersNow === $cutoffOrdersBase + 2, "before {$cutoffOrdersBase} after {$cutoffOrdersNow}");
    check('the cutoff rejection does not decrement stock', $cutoffStockNow === $cutoffStockBase, "before {$cutoffStockBase} after {$cutoffStockNow}");
    check('the cutoff rejection fires no notification line', $cutoffNotifyNow === $cutoffNotifyBase + 2, "{$cutoffNotifyNow} vs base {$cutoffNotifyBase} + 2");
    check('the cutoff rejection records no order attempt', $cutoffAttemptsNow === $cutoffAttemptsBase, "before {$cutoffAttemptsBase} after {$cutoffAttemptsNow}");
}

section('Lookup rate limiting');
$rlIp = '192.0.2.77';
check('an IP with zero recorded failures is not blocked', zf_track_lookup_blocked($db, $rlIp) === false);
for ($i = 0; $i < ZF_LOOKUP_MAX_ATTEMPTS; $i++) {
    zf_record_lookup_failure($db, $rlIp);
}
check('ten failed lookups trip the block', zf_track_lookup_blocked($db, $rlIp) === true);
check("another IP is not blocked by someone else's failures", zf_track_lookup_blocked($db, '192.0.2.78') === false);
$db->prepare('DELETE FROM lookup_attempts WHERE ip_address = ?')->execute([$rlIp]);
check('cleared failures lift the block', zf_track_lookup_blocked($db, $rlIp) === false);

section('Result');
echo "\n{$passed} passed, {$failed} failed.\n";

foreach (glob($tmpDir . '/*') as $file) {
    @unlink($file);
}
@rmdir($tmpDir);

ob_end_flush();
exit($failed === 0 ? 0 : 1);
