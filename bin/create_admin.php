#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$username = trim((string) ($argv[1] ?? getenv('ZF_ADMIN_USER') ?: ''));
$password = (string) ($argv[2] ?? getenv('ZF_ADMIN_PASSWORD') ?: '');

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php bin/create_admin.php <username> <password>\n");
    fwrite(STDERR, "   or: ZF_ADMIN_USER=... ZF_ADMIN_PASSWORD=... php bin/create_admin.php\n");
    exit(1);
}

if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
    fwrite(STDERR, "Username must be 3–40 characters (letters, numbers, dot, dash or underscore).\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters long.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $db->prepare('SELECT id FROM admin_users WHERE username = ?');
$stmt->execute([$username]);
$existing = $stmt->fetchColumn();

if ($existing !== false) {
    $update = $db->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
    $update->execute([$hash, $existing]);
    echo "Password updated for administrator '{$username}'.\n";
} else {
    $insert = $db->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)');
    $insert->execute([$username, $hash]);
    echo "Administrator '{$username}' created.\n";
}
