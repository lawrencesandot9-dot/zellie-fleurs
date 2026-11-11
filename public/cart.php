<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$settings = zf_settings();

$pageTitle = 'Shopping cart | ' . $settings['shop_name'];
$activeNav = 'shop';
$bodyClass = 'cart-page-body';
require ZF_ROOT . '/src/views/store_header.php';
?>

<main id="main" class="cart-page">
    <div class="container">
        <div class="page-heading">
            <span class="eyebrow">Your selection</span>
            <h1>Shopping cart</h1>
            <p>Review your selection before heading to checkout — delivery is arranged personally with you after your order is placed.</p>
        </div>

        <noscript>
            <div class="cart-page__noscript">
                <p><strong>Your browser has JavaScript turned off.</strong> Your cart lives in your browser, so live quantities and this page's summary are unavailable. You can still order — <strong>message the studio</strong> at <?= zf_contact_link($settings['contact_number']) ?> with the arrangements you would like, and we will place the order for you by hand.</p>
            </div>
        </noscript>

        <div class="cart-page__layout">
            <div class="cart-page__items" data-cart-page-items>
                <p class="cart-page__skeleton">Loading your selection…</p>
            </div>

            <aside class="cart-page__foot">
                <div class="cart-total-row">
                    <span>Subtotal</span>
                    <strong data-cart-page-subtotal>₱0.00</strong>
                </div>
                <p class="cart-note">Delivery is arranged with you after your order is reviewed.</p>
                <a class="btn btn--primary btn--full" href="/checkout.php" data-cart-page-link>Proceed to checkout</a>
                <a class="cart-page__continue" href="/index.php#shop">← Continue browsing the collection</a>
            </aside>
        </div>
    </div>
</main>

<?php require ZF_ROOT . '/src/views/store_footer.php'; ?>
