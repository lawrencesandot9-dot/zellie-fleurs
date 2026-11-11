<?php
declare(strict_types=1);

/**
 * Idempotent database initialisation and seed data.
 */
function zf_ensure_schema(PDO $db, string $dbPath): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'Bouquets',
            price REAL NOT NULL CHECK (price >= 0),
            description TEXT NOT NULL DEFAULT '',
            image_url TEXT NOT NULL DEFAULT '',
            stock_quantity INTEGER NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_code TEXT NOT NULL UNIQUE,
            client_token TEXT UNIQUE,
            customer_name TEXT NOT NULL,
            contact_number TEXT NOT NULL,
            delivery_address TEXT NOT NULL,
            delivery_date TEXT NOT NULL,
            card_message TEXT NOT NULL DEFAULT '',
            payment_method TEXT NOT NULL CHECK (payment_method IN ('GCash', 'COD')),
            gcash_ref TEXT NOT NULL DEFAULT '',
            total_amount REAL NOT NULL CHECK (total_amount >= 0),
            items_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'Pending'
                CHECK (status IN ('Pending', 'Confirmed', 'Preparing', 'Out for Delivery', 'Delivered', 'Cancelled')),
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
        )
    ");

    // SQLite has no ADD COLUMN IF NOT EXISTS, so guard with table_info.
    // session_invalid_before stores a PHP time() stamp: any admin session
    // that logged in before it is expired (password-change invalidation).
    $hasInvalidBefore = false;
    foreach ($db->query('PRAGMA table_info(admin_users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if (($column['name'] ?? '') === 'session_invalid_before') {
            $hasInvalidBefore = true;
            break;
        }
    }
    if (!$hasInvalidBefore) {
        $db->exec('ALTER TABLE admin_users ADD COLUMN session_invalid_before INTEGER NOT NULL DEFAULT 0');
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            attempted_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS lookup_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            attempted_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )
    ");

    $db->exec('CREATE INDEX IF NOT EXISTS idx_products_category ON products (category)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_products_active ON products (is_active)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_orders_created ON orders (created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_time ON login_attempts (attempted_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_lookup_attempts_ip_time ON lookup_attempts (ip_address, attempted_at)');
    $db->exec('PRAGMA user_version = 1');

    zf_seed_settings($db);
    zf_seed_products($db);
    zf_seed_admin($db, $dbPath);
}

function zf_seed_settings(PDO $db): void
{
    $stmt = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
    foreach (zf_setting_defaults() as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function zf_seed_products(PDO $db): void
{
    $count = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $products = [
        [
            'Zellie Signature Blush Roses',
            'Bouquets',
            1899.00,
            'Hand-tied blush and ivory garden roses with soft eucalyptus, wrapped in matte cream paper and finished with a silk ribbon.',
            'assets/img/products/blush-roses.jpg',
            12,
        ],
        [
            'Elysian Blush Bouquet',
            'Bouquets',
            1699.00,
            'Pastel peonies, carnations and delicate seasonal fillers gathered into a romantic, cloud-soft bouquet.',
            'assets/img/products/elysian-blush.jpg',
            8,
        ],
        [
            'Solstice Sunflower Box',
            'Luxury Boxes',
            1450.00,
            'Golden sunflowers paired with white baby’s breath and dried wheat, arranged in a keepsake blush box.',
            'assets/img/products/sunflower-box.jpg',
            10,
        ],
        [
            'Amethyst Dream Tulips',
            'Bouquets',
            2200.00,
            'Ten fresh lavender and violet tulips, selected for their elegant shape and long-lasting blooms.',
            'assets/img/products/amethyst-tulips.jpg',
            6,
        ],
        [
            'Ivory Garden Arrangement',
            'Vase Arrangements',
            2650.00,
            'A sculptural ivory and green arrangement with roses, lisianthus and textured foliage in a ceramic vessel.',
            'assets/img/products/ivory-garden.jpg',
            5,
        ],
        [
            'Rosy Pastel Medley',
            'Bouquets',
            1599.00,
            'A gentle medley of pink roses, wax flower and soft greenery — a graceful everyday gesture.',
            'assets/img/products/rosy-pastel.jpg',
            0,
        ],
    ];

    $stmt = $db->prepare('
        INSERT INTO products (name, category, price, description, image_url, stock_quantity, is_active)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ');

    foreach ($products as $product) {
        $stmt->execute($product);
    }
}

function zf_seed_admin(PDO $db, string $dbPath): void
{
    $count = (int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $username = trim((string) (getenv('ZF_ADMIN_USER') ?: 'admin'));
    $password = (string) getenv('ZF_ADMIN_PASSWORD');
    $generated = false;

    if ($password === '') {
        $password = zf_random_password(18);
        $generated = true;
    }

    if ($username === '') {
        $username = 'admin';
    }

    $stmt = $db->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

    if ($generated) {
        $credentialFile = dirname($dbPath) . '/initial_admin_credentials.txt';
        $content = "ZELLIE FLEURS — INITIAL ADMINISTRATOR\n"
            . "======================================\n\n"
            . "Created: " . date('Y-m-d H:i:s') . "\n"
            . "Username: {$username}\n"
            . "Password: {$password}\n\n"
            . "Sign in at: /admin/login.php\n\n"
            . "Keep this file private. Change the password from Admin → Settings\n"
            . "after your first sign-in, then delete this file.\n";
        file_put_contents($credentialFile, $content, LOCK_EX);
        @chmod($credentialFile, 0600);
    }
}
