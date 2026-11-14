<?php
declare(strict_types=1);

/**
 * Idempotent database initialisation and seed data.
 *
 * Initialization is gated by SQLite's built-in user_version pragma, which is
 * stored atomically inside the database header — a persistent marker, not an
 * in-memory flag, so it protects across requests and processes.
 *
 * - ZF_SCHEMA_VERSION is the highest schema version this code understands.
 * - A database stamped with user_version >= ZF_SCHEMA_VERSION is assumed
 *   initialized; a pure-read canary (expected tables + migrated column) still
 *   runs so a missing/partially-dropped table triggers safe recovery.
 * - The full idempotent DDL + seed pass runs only when the marker is behind
 *   or the canary fails, wrapped in BEGIN IMMEDIATE with an in-transaction
 *   re-check so concurrent first-boot processes cannot double-seed.
 *
 * Historical note: releases before this gate stamped user_version = 1 on
 * every request, so every existing installation carries marker 1 and will
 * re-run the (idempotent) init exactly once here, then be stamped 2.
 */
define('ZF_SCHEMA_VERSION', 2);

function zf_ensure_schema(PDO $db, string $dbPath): void
{
    // Fast path: already initialized and structurally intact — a handful of
    // tiny reads (marker, table list, index list, one table_info), zero
    // writes. This is what every ordinary request pays.
    if (zf_schema_is_current($db)) {
        return;
    }

    // Full initialization. BEGIN IMMEDIATE serializes concurrent bootstraps:
    // the loser waits on busy_timeout, then re-checks inside the transaction
    // and skips work the winner already did.
    try {
        $db->exec('BEGIN IMMEDIATE');
    } catch (Throwable $e) {
        // Lock could not be acquired within busy_timeout — no transaction is
        // open, so there is nothing to roll back; surface as bootstrap failure.
        throw $e;
    }

    // Tracks the credential file created by THIS bootstrap run, if any.
    // Filesystem operations do not participate in the SQLite transaction, so
    // a file created before a failed COMMIT would otherwise survive while the
    // admin row it documents was rolled back.
    $createdCredentialFile = null;

    try {
        if (zf_schema_is_current($db)) {
            $db->exec('COMMIT');
            return;
        }

        $db->exec('
            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT \'Bouquets\',
                price REAL NOT NULL CHECK (price >= 0),
                description TEXT NOT NULL DEFAULT \'\',
                image_url TEXT NOT NULL DEFAULT \'\',
                stock_quantity INTEGER NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
                is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\')),
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_code TEXT NOT NULL UNIQUE,
                client_token TEXT UNIQUE,
                customer_name TEXT NOT NULL,
                contact_number TEXT NOT NULL,
                delivery_address TEXT NOT NULL,
                delivery_date TEXT NOT NULL,
                card_message TEXT NOT NULL DEFAULT \'\',
                payment_method TEXT NOT NULL CHECK (payment_method IN (\'GCash\', \'COD\')),
                gcash_ref TEXT NOT NULL DEFAULT \'\',
                total_amount REAL NOT NULL CHECK (total_amount >= 0),
                items_json TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'Pending\'
                    CHECK (status IN (\'Pending\', \'Confirmed\', \'Preparing\', \'Out for Delivery\', \'Delivered\', \'Cancelled\')),
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\')),
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
            )
        ');

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

        $db->exec('
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                attempted_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS lookup_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                attempted_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT \'\'
            )
        ');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_category ON products (category)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_active ON products (is_active)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_orders_created ON orders (created_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_time ON login_attempts (attempted_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_lookup_attempts_ip_time ON lookup_attempts (ip_address, attempted_at)');

        zf_seed_settings($db);
        zf_seed_products($db);
        zf_seed_admin($db, $dbPath, $createdCredentialFile);

        $db->exec('PRAGMA user_version = ' . ZF_SCHEMA_VERSION);

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        // Leave the database untouched on any failure: no half-initialized
        // schema, no partial seeds.
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $ignored) {
            // Rollback of a transaction that is already closed is harmless.
        }
        // The SQLite transaction is gone, but the filesystem is not part of
        // it: discard the credential file created by THIS run so no password
        // for a rolled-back admin row survives on disk. Files that predate
        // this bootstrap are never touched here (see zf_discard_credential_file).
        zf_discard_credential_file($createdCredentialFile);
        throw $e;
    }
}

/**
 * Unlink a credential file ONLY when this bootstrap created it (tracked by
 * the reference zf_seed_admin() populates). A null — including the seeded
 * non-generated-password path — leaves the filesystem untouched, so a
 * pre-existing or hand-placed credential file can never be deleted by
 * bootstrap-failure cleanup.
 */
function zf_discard_credential_file(?string $createdCredentialFile): void
{
    if ($createdCredentialFile === null || !is_file($createdCredentialFile)) {
        return;
    }
    @unlink($createdCredentialFile);
}

/**
 * Pure-read gate: true when the schema marker is current AND every expected
 * table, index, and the migrated admin column is present. This keeps ordinary
 * requests write-free while still recovering safely from a dropped table,
 * dropped index, or dropped column. Seed data is deliberately NOT part of the
 * canary: emptying products/admin_users is a legitimate admin action, not
 * schema damage. (Column drift inside otherwise-present tables is not
 * detected — SQLite cannot repair altered columns, and the previous
 * every-request DDL could not either.)
 */
function zf_schema_is_current(PDO $db): bool
{
    if ((int) $db->query('PRAGMA user_version')->fetchColumn() < ZF_SCHEMA_VERSION) {
        return false;
    }

    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['products', 'orders', 'admin_users', 'login_attempts', 'lookup_attempts', 'settings'] as $expected) {
        if (!in_array($expected, $tables, true)) {
            return false;
        }
    }

    $indexes = $db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'idx_%'")->fetchAll(PDO::FETCH_COLUMN);
    $expectedIndexes = [
        'idx_products_category',
        'idx_products_active',
        'idx_orders_status',
        'idx_orders_created',
        'idx_login_attempts_time',
        'idx_lookup_attempts_ip_time',
    ];
    foreach ($expectedIndexes as $expected) {
        if (!in_array($expected, $indexes, true)) {
            return false;
        }
    }

    foreach ($db->query('PRAGMA table_info(admin_users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if (($column['name'] ?? '') === 'session_invalid_before') {
            return true;
        }
    }

    return false;
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

function zf_seed_admin(PDO $db, string $dbPath, ?string &$createdCredentialFile = null): void
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

        // Filesystem operations do not participate in the SQLite transaction,
        // so the credential file is created EXCLUSIVELY (fopen 'x') and locked
        // to owner-only permissions BEFORE any secret is written to it: the
        // generated password never rests on disk at broader permissions, and
        // a permission-enforcement failure aborts initialization (the caller
        // rolls the transaction back — no unreachable admin row is committed).
        $credentialFile = zf_create_restricted_file($credentialFile);
        if (@fwrite($credentialFile['handle'], $content) === false || !@fclose($credentialFile['handle'])) {
            // The file was created by THIS call, so it is discarded here
            // directly (the caller's tracked-cleanup var is not set yet and
            // must not touch untracked files).
            @unlink($credentialFile['path']);
            throw new RuntimeException(
                "Unable to write initial admin credentials to {$credentialFile['path']}; "
                . 'aborting bootstrap so the generated administrator password is not lost.'
            );
        }
        clearstatcache(true, $credentialFile['path']);
        if ((fileperms($credentialFile['path']) & 0777) !== 0600) {
            // Something restored broader permissions after creation; refuse to
            // leave the password readable.
            @unlink($credentialFile['path']);
            throw new RuntimeException(
                "Unable to enforce owner-only permissions on {$credentialFile['path']}; "
                . 'aborting bootstrap so the generated administrator password is not exposed.'
            );
        }
        $createdCredentialFile = $credentialFile['path'];
    }
}

/**
 * Create a credential file exclusively and enforce 0600 before the caller
 * writes anything into it. The only file ever removed here is one created by
 * THIS call; a pre-existing regular file at the target path is replaced only
 * in the admin-seeding branch that runs when admin_users is EMPTY — i.e. the
 * old file documents an administrator whose row was rolled back or deleted,
 * so replacement is deliberate, never indiscriminate. Failure at any step is
 * an initialization failure.
 *
 * @return array{path: string, handle: resource}
 */
function zf_create_restricted_file(string $path): array
{
    $open = static function () use ($path) {
        return @fopen($path, 'x'); // 'x': create exclusively, never truncate
    };

    $handle = $open();
    if ($handle === false) {
        // The path is taken. Two cases: an existing credential file from an
        // earlier bootstrap whose transaction did not commit (its admin row
        // is gone — safe to replace because we only get here when
        // admin_users is empty), or something else. Replace only a REGULAR
        // file; anything else is treated as an obstruction, not data.
        if (is_file($path) && @unlink($path)) {
            $handle = $open();
        }
        if ($handle === false) {
            throw new RuntimeException(
                "Unable to create {$path} exclusively (an existing credential "
                . 'file could not be replaced); aborting bootstrap so the generated '
                . 'administrator password is not lost.'
            );
        }
    }

    // Lock the file down before the first byte of secret content is written.
    if (!@chmod($path, 0600) || (fileperms($path) & 0777) !== 0600) {
        @fclose($handle);
        @unlink($path);
        throw new RuntimeException(
            "Unable to enforce owner-only permissions on {$path}; "
            . 'aborting bootstrap so the generated administrator password is not exposed.'
        );
    }

    return ['path' => $path, 'handle' => $handle];
}
