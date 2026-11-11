<?php
declare(strict_types=1);

$settings = zf_settings();
$pageTitle = $pageTitle ?? ($settings['shop_name'] . ' | ' . $settings['tagline']);
$activeNav = $activeNav ?? '';
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#8f4e59">
    <meta name="description" content="<?= zf_e($settings['tagline'] . ' — ' . $settings['shop_name']) ?>">
    <title><?= zf_e($pageTitle) ?></title>
    <?php
    // Canonical URL: path only — query strings (search/filter params) must not self-canonicalize.
    $storeCanonical = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://'
        . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . (parse_url($_SERVER['REQUEST_URI'] ?? '/index.php', PHP_URL_PATH) ?: '/index.php');
    ?>
    <meta property="og:title" content="<?= zf_e($pageTitle) ?>">
    <meta property="og:type" content="<?= zf_e($ogType ?? 'website') ?>">
    <meta property="og:description" content="<?= zf_e($settings['tagline'] . ' — ' . $settings['shop_name']) ?>">
    <?php if (!empty($ogImage)): ?>
        <meta property="og:image" content="<?= zf_e($ogImage) ?>">
    <?php endif; ?>
    <meta name="robots" content="index,follow">
    <?php if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'): ?>
        <link rel="canonical" href="<?= zf_e($storeCanonical) ?>">
    <?php endif; ?>
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/style.css?v=20261110-refresh">
    <script defer src="/assets/js/app.js?v=20261110-refresh"></script>
</head>
<body class="<?= zf_e($bodyClass) ?>">
<a class="skip-link" href="#main">Skip to content</a>

<?php if (trim($settings['announcement']) !== ''): ?>
    <div class="announcement">
        <div class="container announcement__inner">
            <span class="announcement__petal" aria-hidden="true">✦</span>
            <span><?= zf_e($settings['announcement']) ?></span>
        </div>
    </div>
<?php endif; ?>

<header class="site-header" data-header>
    <div class="container site-header__inner">
        <a class="brand" href="/index.php" aria-label="<?= zf_e($settings['shop_name']) ?> home">
            <img src="/assets/img/logo-mark.svg" alt="" width="38" height="38">
            <span class="brand__text">
                <span class="brand__name"><?= zf_e($settings['shop_name']) ?></span>
                <span class="brand__tagline"><?= zf_e($settings['tagline']) ?></span>
            </span>
        </a>

        <nav class="site-nav" aria-label="Main navigation">
            <a class="<?= $activeNav === 'shop' ? 'is-active' : '' ?>" href="/index.php#shop">Shop</a>
            <a href="/index.php#collections">Collections</a>
            <a href="/index.php#about">Our Story</a>
            <a href="/index.php#contact">Visit</a>
            <a class="<?= $activeNav === 'track' ? 'is-active' : '' ?>" href="/track.php">Track Order</a>
        </nav>

        <div class="header-actions">
            <a class="header-link" href="/track.php" aria-label="Track an order">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <span>Track</span>
            </a>
            <a class="cart-trigger" href="/cart.php" data-cart-open aria-label="Open shopping cart">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 5h2l2.2 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L21.5 8H7"/><circle cx="10" cy="20" r="1.2"/><circle cx="18" cy="20" r="1.2"/></svg>
                <span>Cart</span>
                <span class="cart-badge" data-cart-badge>0</span>
            </a>
            <button class="menu-trigger" type="button" data-menu-toggle aria-label="Open menu" aria-expanded="false" aria-controls="mobile-menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>

    <nav class="mobile-nav" id="mobile-menu" data-mobile-nav aria-label="Mobile navigation" hidden>
        <a class="<?= $activeNav === 'shop' ? 'is-active' : '' ?>" href="/index.php#shop">Shop</a>
        <a href="/index.php#collections">Collections</a>
        <a href="/index.php#about">Our Story</a>
        <a href="/index.php#contact">Visit</a>
        <a class="<?= $activeNav === 'track' ? 'is-active' : '' ?>" href="/track.php">Track Order</a>
        <a href="/cart.php">Cart</a>
        <a href="/checkout.php">Checkout</a>
    </nav>
</header>
<div class="mobile-nav-scrim" data-menu-scrim hidden></div>
