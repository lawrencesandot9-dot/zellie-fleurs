<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once ZF_ROOT . '/src/auth.php';

if (zf_admin_logged_in()) {
    zf_redirect('index.php');
}

$settings = zf_settings();
$error = '';
$username = '';

// Expired admin sessions are bounced here with ?expired=1 (the admin keys
// only — never the whole session — were cleared). A password change also
// forces a fresh sign-in with ?changed=1. Both render as generic notices.
if (isset($_GET['expired'])) {
    $error = 'Your session has expired. Please sign in again.';
} elseif (isset($_GET['changed'])) {
    $error = 'Your password has been updated. Please sign in with your new password.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = zf_safe_text($_POST['username'] ?? '');

    if (!zf_csrf_valid()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $result = zf_auth_login($db, $username, (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            zf_redirect('index.php');
        }
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#241a20">
    <title>Studio sign in | <?= zf_e($settings['shop_name']) ?></title>
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
<canvas class="login-bloom" aria-hidden="true"></canvas>
<main class="login-shell">
    <section class="login-panel">
        <a class="brand brand--login" href="/index.php">
            <img src="/assets/img/logo-mark.svg" alt="" width="46" height="46">
            <span class="brand__text">
                <span class="brand__name"><?= zf_e($settings['shop_name']) ?></span>
                <span class="brand__tagline">Studio administration</span>
            </span>
        </a>

        <div class="login-heading">
            <span class="eyebrow">Private access</span>
            <h1>Welcome back.</h1>
            <p>Sign in to manage orders, products and shop details.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="flash flash--error" role="alert"><?= zf_e($error) ?></div>
        <?php endif; ?>

        <form class="login-form" method="post" action="/admin/login.php">
            <?= zf_csrf_field() ?>
            <div class="field">
                <label for="username">Username</label>
                <input id="username" type="text" name="username" value="<?= zf_e($username) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn--primary btn--full" type="submit">Sign in</button>
        </form>

        <p class="login-help">Forgot your credentials? Check the private setup file in the project’s <code>data/</code> directory, or reset the administrator from the command line.</p>
        <a class="login-back" href="/index.php">← Return to the storefront</a>
    </section>
</main>
<script src="/assets/js/login-bloom.js" defer></script>
</body>
</html>
