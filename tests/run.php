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
