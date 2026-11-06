<?php
declare(strict_types=1);

$settings = zf_settings();
$pageTitle = $pageTitle ?? 'Admin';
$activeAdmin = $activeAdmin ?? '';
$admin = zf_current_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#2e2a28">
    <title><?= zf_e($pageTitle) ?> | <?= zf_e($settings['shop_name']) ?> Studio</title>
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/style.css?v=20261106-mobile">
    <script defer src="/assets/js/app.js?v=20261106-mobile"></script>
</head>
<body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to content</a>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand brand--admin" href="/admin/index.php">
            <img src="/assets/img/logo-mark.svg" alt="" width="38" height="38">
            <span class="brand__text">
                <span class="brand__name"><?= zf_e($settings['shop_name']) ?></span>
                <span class="brand__tagline">Studio admin</span>
            </span>
        </a>

        <nav class="admin-nav" aria-label="Admin navigation">
            <a class="<?= $activeAdmin === 'dashboard' ? 'is-active' : '' ?>" href="/admin/index.php">
                <span class="admin-nav__icon">◈</span> Dashboard
            </a>
            <a class="<?= $activeAdmin === 'orders' ? 'is-active' : '' ?>" href="/admin/orders.php">
                <span class="admin-nav__icon">▤</span> Orders
            </a>
            <a class="<?= $activeAdmin === 'products' ? 'is-active' : '' ?>" href="/admin/products.php">
                <span class="admin-nav__icon">✿</span> Products
            </a>
            <a class="<?= $activeAdmin === 'settings' ? 'is-active' : '' ?>" href="/admin/settings.php">
                <span class="admin-nav__icon">⚙</span> Settings
            </a>
        </nav>

        <div class="admin-sidebar__foot">
            <a class="admin-store-link" href="/index.php" target="_blank" rel="noopener">View storefront ↗</a>
            <form action="/admin/logout.php" method="post">
                <?= zf_csrf_field() ?>
                <button class="admin-logout" type="submit">Sign out</button>
            </form>
        </div>
    </aside>

    <div class="admin-workspace">
        <header class="admin-topbar">
            <div>
                <span class="eyebrow">Zellie Fleurs studio</span>
                <h1><?= zf_e($pageTitle) ?></h1>
            </div>
            <div class="admin-user">
                <span class="admin-user__dot"></span>
                <span><?= zf_e($admin['username'] ?? 'Administrator') ?></span>
            </div>
        </header>

        <main class="admin-main" id="admin-main">
            <?= zf_render_flashes() ?>
