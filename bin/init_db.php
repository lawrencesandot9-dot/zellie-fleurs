#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$dbPath = getenv('ZF_DB_PATH') ?: ZF_DATA . '/shop.db';

echo "Zellie Fleurs database initialisation\n";
echo "-------------------------------------\n";
echo "Database: {$dbPath}\n";
echo "Products: " . (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() . "\n";
echo "Orders:   " . (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn() . "\n";
echo "Admins:   " . (int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() . "\n";
echo "Settings: " . (int) $db->query('SELECT COUNT(*) FROM settings')->fetchColumn() . "\n";

$credentialFile = dirname($dbPath) . '/initial_admin_credentials.txt';
if (is_file($credentialFile)) {
    echo "\nInitial administrator credentials are in:\n{$credentialFile}\n";
    echo "Sign in at /admin/login.php, then change the password and delete that file.\n";
} else {
    echo "\nAdministrator credentials were supplied through the environment (or already exist).\n";
}

echo "Integrity check: " . $db->query('PRAGMA integrity_check')->fetchColumn() . "\n";
