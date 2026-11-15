<?php
declare(strict_types=1);

/**
 * Dedicated data-layer pagination tests (Batch 4).
 *
 * Runs entirely against throwaway SQLite databases in a uniquely generated
 * temp directory (ZF_DB_PATH override) — data/shop.db and
 * data/shop.db.pre-batch3-backup are never opened. Run:
 * php tests/pagination_test.php
 */

$failures = 0;
$passes = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        echo "  PASS  {$name}\n";
    } else {
        $failures++;
        echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n{$title}\n";
}

$projectRoot = dirname(__DIR__);
$shopDb = $projectRoot . '/data/shop.db';
$shopDbBefore = is_file($shopDb) ? sha1_file($shopDb) : 'absent';
$backupDb = $projectRoot . '/data/shop.db.pre-batch3-backup';
$backupDbBefore = is_file($backupDb) ? sha1_file($backupDb) : 'absent';

$tmpDir = sys_get_temp_dir() . '/zf-pagination-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0775, true);

putenv('ZF_DB_PATH=' . $tmpDir . '/shop.db');
putenv('ZF_LOG_PATH=' . $tmpDir . '/log');
putenv('ZF_DEBUG');
putenv('ZF_ADMIN_PASSWORD=Batch4TestPass!');
putenv('ZF_ADMIN_USER');
require $projectRoot . '/config/database.php';
require $projectRoot . '/src/orders.php';
require $projectRoot . '/src/products.php';

// ------------------------------------------------------------------
// Seed a deterministic dataset directly (bypasses HTTP): 45 orders with
// staggered timestamps so newest/oldest ordering is exact, and 25 products.
// The bootstrap's own seed rows are cleared first so counts are exact.
// ------------------------------------------------------------------
$db->exec('DELETE FROM orders');
$db->exec('DELETE FROM products');
$insertOrder = $db->prepare('
    INSERT INTO orders (
        order_code, client_token, customer_name, contact_number, delivery_address,
        delivery_date, card_message, payment_method, gcash_ref, total_amount,
        items_json, status, created_at, updated_at
    ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$itemsJson = json_encode([
    ['product_id' => 1, 'name' => 'Pagination Seed', 'price' => 500.0, 'qty' => 1, 'subtotal' => 500.0],
], JSON_UNESCAPED_SLASHES);

for ($j = 1; $j <= 45; $j++) {
    // j = 1 is oldest (-45 minutes), j = 45 is newest (now). Deterministic
    // one-minute spacing means datetime(created_at) ordering == id ordering.
    $minutes = 45 - $j;
    $status = $j <= 30 ? 'Pending' : 'Confirmed';
    $searchable = ($j % 3 === 0) ? 'Pagination Customer ' . $j : 'Regular Customer ' . $j;
    $insertOrder->execute([
        sprintf('ZF-PAG-%04d', $j),
        $searchable,
        '0917' . sprintf('%08d', $j),
        '123 Pagination Street, Test City',
        '2027-01-15',
        '',
        'GCash',
        sprintf('PAGREF%04d', $j),
        500.0 + $j,
        $itemsJson,
        $status,
        "datetime('now', 'localtime', '-{$minutes} minutes')", // raw SQL expr
        "datetime('now', 'localtime', '-{$minutes} minutes')",
    ]);
}

// Oops: the two date values above were inserted as strings, not expressions.
// Fix the timestamps with an UPDATE using real SQL expressions.
$db->exec("UPDATE orders SET created_at = datetime('now', 'localtime', '-' || (45 - CAST(SUBSTR(order_code, 8) AS INTEGER)) || ' minutes'), updated_at = created_at");

$insertProduct = $db->prepare('
    INSERT INTO products (name, category, price, description, image_url, stock_quantity, is_active)
    VALUES (?, ?, ?, ?, ?, ?, ?)
');
for ($p = 1; $p <= 25; $p++) {
    $category = $p % 2 === 0 ? 'Pagination Category' : 'Bouquets';
    $active = $p <= 5 ? 0 : 1; // five inactive, in stock; ordering constant
    $insertProduct->execute([
        'Pagination Product ' . sprintf('%02d', $p),
        $category,
        150.0 + $p,
        'Seed product for pagination tests.',
        'assets/img/products/rosy-pastel.jpg',
        5,
        $active,
    ]);
}

// ------------------------------------------------------------------
section('1. Orders: page geometry and counts');
$meta = zf_orders_page($db);
check('default page returns 20 rows', count($meta['rows']) === ZF_ADMIN_PAGE_SIZE, 'got ' . count($meta['rows']));
check('total is 45', $meta['total'] === 45, 'got ' . $meta['total']);
check('pages = 3', $meta['pages'] === 3, 'got ' . $meta['pages']);
check('page normalized to 1', $meta['page'] === 1);
check('page size echoed back', $meta['page_size'] === ZF_ADMIN_PAGE_SIZE);

$last = zf_orders_page($db, '', '', 'newest', 3);
check('last page returns the remaining 5 rows', count($last['rows']) === 5, 'got ' . count($last['rows']));
check('last page reports page 3', $last['page'] === 3);

$middle = zf_orders_page($db, '', '', 'newest', 2);
check('middle page returns 20 rows', count($middle['rows']) === 20);
check('middle page reports page 2', $middle['page'] === 2);

section('2. Orders: no duplicate or missing records across adjacent pages');
$ids = [];
foreach ([$meta, $middle, $last] as $m) {
    foreach ($m['rows'] as $row) {
        $ids[] = (int) $row['id'];
    }
}
check('all 45 records appear across pages', count($ids) === 45, 'got ' . count($ids));
check('no duplicates across pages', count(array_unique($ids)) === 45);
$allIds = array_map('intval', $db->query('SELECT id FROM orders')->fetchAll(PDO::FETCH_COLUMN));
check('paged ids match the full dataset', sort($ids) && sort($allIds) && $ids === $allIds);

section('3. Orders: explicit sort allowlist combined with pagination');
$newestFirst = $meta['rows'][0];
$oldest = zf_orders_page($db, '', '', 'oldest', 1);
$oldestFirst = $oldest['rows'][0];
check('newest page starts with the most recent order', (int) $newestFirst['id'] === max($allIds), 'got id ' . $newestFirst['id']);
check('oldest page starts with the earliest order', (int) $oldestFirst['id'] === min($allIds), 'got id ' . $oldestFirst['id']);
check('oldest page 1 holds the 20 oldest records', count($oldest['rows']) === 20 && min(array_map(static fn ($r) => (int) $r['id'], $oldest['rows'])) === min($allIds));
// Adjacent-page continuity under oldest sort: page 2 starts where page 1 ended.
$oldest2 = zf_orders_page($db, '', '', 'oldest', 2);
$oldest1Ids = array_map(static fn ($r) => (int) $r['id'], $oldest['rows']);
$oldest2Ids = array_map(static fn ($r) => (int) $r['id'], $oldest2['rows']);
check('no overlap between oldest pages 1 and 2', count(array_intersect($oldest1Ids, $oldest2Ids)) === 0);

section('4. Orders: page validation and graceful out-of-range handling');
$zero = zf_orders_page($db, '', '', 'newest', 0);
check('page 0 normalized to page 1', $zero['page'] === 1 && $zero['rows'] === $meta['rows']);
$negative = zf_orders_page($db, '', '', 'newest', -5);
check('negative page normalized to page 1', $negative['page'] === 1);
$far = zf_orders_page($db, '', '', 'newest', 9999);
check('out-of-range page clamps to last page', $far['page'] === 3 && count($far['rows']) === 5);
$huge = zf_orders_page($db, '', '', 'newest', ZF_MAX_PAGE + 5000);
check('absurd page value clamps to last page', $huge['page'] === 3 && count($huge['rows']) === 5);
check('offset never negative for clamped pages', $far['page'] >= 1 && $far['page'] <= $far['pages']);

section('5. Orders: empty results handled gracefully');
$empty = zf_orders_page($db, 'no-such-order-xyz', '', 'newest', 7);
check('empty search yields zero rows', $empty['rows'] === []);
check('empty search still reports a sane page', $empty['page'] === 1 && $empty['pages'] === 1 && $empty['total'] === 0);
$emptyStatus = zf_orders_page($db, '', 'Delivered', 'newest', 2);
check('filter with no matches yields zero rows', $emptyStatus['rows'] === [] && $emptyStatus['total'] === 0 && $emptyStatus['page'] === 1);

section('6. Orders: search combined with pagination');
$searchMeta = zf_orders_page($db, 'Pagination Customer', '', 'newest', 1);
check('search total is 15 (every third order)', $searchMeta['total'] === 15, 'got ' . $searchMeta['total']);
check('search pages = 1', $searchMeta['pages'] === 1);
$likeWildcards = zf_orders_page($db, '%PAG%', '', 'newest', 1);
check('LIKE wildcards in search are literal (escaped)', $likeWildcards['total'] === 0, 'got ' . $likeWildcards['total']);
$codeMeta = zf_orders_page($db, 'ZF-PAG-0042', '', 'newest', 1);
check('exact order code search finds one order', $codeMeta['total'] === 1 && (string) $codeMeta['rows'][0]['order_code'] === 'ZF-PAG-0042');

section('7. Orders: status filter combined with pagination');
$confirmed = zf_orders_page($db, '', 'Confirmed', 'newest', 1);
check('status filter total is 15', $confirmed['total'] === 15, 'got ' . $confirmed['total']);
check('status filter pages = 1', $confirmed['pages'] === 1);
$pending = zf_orders_page($db, '', 'Pending', 'newest', 2);
check('pending filter paginates to 2 pages', $pending['total'] === 30 && $pending['pages'] === 2 && $pending['page'] === 2);
$pendingIds = array_map(static fn ($r) => (int) $r['id'], $pending['rows']);
check('filtered page rows all match the filter', $pendingIds !== [] && count($pendingIds) === 10);
$invalid = zf_orders_page($db, '', 'Not-A-Status', 'newest', 1);
check('invalid status filter is ignored (all 45)', $invalid['total'] === 45);
$combined = zf_orders_page($db, 'Pagination Customer', 'Confirmed', 'newest', 1);
check('search + status filter combine correctly', $combined['total'] === 5, 'got ' . $combined['total']);

section('8. Orders: record-summary arithmetic');
check('page 2 range is 21–40', (($middle['page'] - 1) * $middle['page_size'] + 1) === 21 && min($middle['page'] * $middle['page_size'], $middle['total']) === 40);
check('page 3 range is 41–45', (($last['page'] - 1) * $last['page_size'] + 1) === 41 && min($last['page'] * $last['page_size'], $last['total']) === 45);
check('zf_normalize_page clamps correctly', zf_normalize_page('999', 3) === 3 && zf_normalize_page('abc', 3) === 1 && zf_normalize_page('-2', 3) === 1 && zf_normalize_page('2', 5) === 2 && zf_normalize_page('2', 0) === 2);

// ------------------------------------------------------------------
section('9. Products: page geometry, filters, and continuity');
$pMeta = zf_products_page($db);
check('products page 1 returns 20 rows', count($pMeta['rows']) === 20);
check('products total is 25', $pMeta['total'] === 25, 'got ' . $pMeta['total']);
check('products pages = 2', $pMeta['pages'] === 2);
$pLast = zf_products_page($db, 2);
check('products page 2 returns 5 rows', count($pLast['rows']) === 5);
$pIds = array_map(static fn ($r) => (int) $r['id'], array_merge($pMeta['rows'], $pLast['rows']));
check('no duplicate products across pages', count(array_unique($pIds)) === 25);
check('all products covered across pages', count($pIds) === 25);
$pFar = zf_products_page($db, 400);
check('products out-of-range page clamps to last', $pFar['page'] === 2 && count($pFar['rows']) === 5);
$pEmpty = zf_products_page($db, 3, false, 'no-such-product-xyz');
check('products empty search handled gracefully', $pEmpty['rows'] === [] && $pEmpty['page'] === 1 && $pEmpty['total'] === 0);
$pCat = zf_products_page($db, 1, false, '', 'Pagination Category');
check('category filter combined with pagination', $pCat['total'] === 12 && $pCat['pages'] === 1, 'got ' . $pCat['total']);
$pSearch = zf_products_page($db, 1, false, 'Pagination Product 01', '');
check('search filter finds the seeded product', $pSearch['total'] === 1);
$pActive = zf_products_page($db, 1, true);
check('active-only filter combined with pagination', $pActive['total'] === 20, 'got ' . $pActive['total']);
$pOrder = $pMeta['rows'][0];
check('product ordering preserved (newest id first, active first)', (int) $pOrder['id'] === max($pIds) && (int) $pOrder['is_active'] === 1);
$pInvalid = zf_orders_page($db, '', '', 'DROP TABLE orders', 1);
check('unknown sort token falls back to the default clause', $pInvalid['total'] === 45 && $pInvalid['page'] === 1);

// ------------------------------------------------------------------
section('10. Storefront helpers unchanged (regression)');
$storefront = zf_get_products($db, ['active_only' => true]);
check('zf_get_products still returns all active products unpaged', count($storefront) === 20, 'got ' . count($storefront));
$searchResults = zf_get_products($db, ['search' => 'Pagination Product 07', 'active_only' => true]);
check('zf_get_products search still works', count($searchResults) === 1);
$boxResults = zf_get_products($db, ['category' => 'Pagination Category', 'active_only' => true]);
check('zf_get_products category filter still works', count($boxResults) === 10, 'got ' . count($boxResults));
$allOrders = zf_search_orders($db);
check('zf_search_orders still returns every order unpaged', count($allOrders) === 45, 'got ' . count($allOrders));

// ------------------------------------------------------------------
section('11. Production database untouched');
$shopDbAfter = is_file($shopDb) ? sha1_file($shopDb) : 'absent';
$backupDbAfter = is_file($backupDb) ? sha1_file($backupDb) : 'absent';
check('data/shop.db byte-identical before and after suite', $shopDbBefore === $shopDbAfter);
check('data/shop.db.pre-batch3-backup byte-identical before and after suite', $backupDbBefore === $backupDbAfter);

// ------------------------------------------------------------------
echo "\nResult: {$passes} passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
