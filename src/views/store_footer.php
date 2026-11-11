<?php
declare(strict_types=1);

$settings = zf_settings();
?>
<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-brand">
            <a class="brand brand--footer" href="/index.php">
                <img src="/assets/img/logo-mark.svg" alt="" width="42" height="42">
                <span class="brand__text">
                    <span class="brand__name"><?= zf_e($settings['shop_name']) ?></span>
                    <span class="brand__tagline"><?= zf_e($settings['tagline']) ?></span>
                </span>
            </a>
            <p><?= zf_e($settings['delivery_info']) ?></p>
        </div>

        <div>
            <h3>Explore</h3>
            <ul class="footer-links">
                <li><a href="/index.php#shop">The collection</a></li>
                <li><a href="/index.php#collections">Featured collections</a></li>
                <li><a href="/index.php#about">Our story</a></li>
                <li><a href="/track.php">Track your order</a></li>
            </ul>
        </div>

        <div>
            <h3>Visit</h3>
            <ul class="footer-links footer-links--plain">
                <li><?= zf_e($settings['store_address']) ?></li>
                <li><?= zf_e($settings['operating_hours']) ?></li>
                <li><?= zf_contact_link($settings['contact_number']) ?></li>
            </ul>
        </div>

        <div>
            <h3>Payments</h3>
            <ul class="footer-links footer-links--plain">
                <li>GCash transfer</li>
                <li>Cash on delivery</li>
            </ul>
            <p class="footer-note">Orders are confirmed by our studio before dispatch.</p>
        </div>
    </div>

    <div class="container footer-bottom">
        <p>© <?= date('Y') ?> <?= zf_e($settings['shop_name']) ?>. Handcrafted with care.</p>
        <a href="/admin/login.php">Studio access</a>
    </div>
</footer>

<div class="cart-overlay" data-cart-overlay hidden></div>
<aside class="cart-drawer" data-cart-drawer aria-label="Shopping cart" aria-hidden="true">
    <div class="cart-drawer__head">
        <div>
            <span class="eyebrow">Your selection</span>
            <h2>Shopping cart</h2>
        </div>
        <button class="icon-button" type="button" data-cart-close aria-label="Close cart">✕</button>
    </div>

    <div class="cart-drawer__body" data-cart-items>
        <div class="empty-state">
            <img src="/assets/img/logo-mark.svg" alt="" width="48" height="48">
            <h3>Your cart is empty</h3>
            <p>Add a bouquet or keepsake box to begin your order.</p>
            <button class="btn btn--soft" type="button" data-cart-close>Browse the collection</button>
        </div>
    </div>

    <div class="cart-drawer__foot">
        <div class="cart-total-row">
            <span>Subtotal</span>
            <strong data-cart-subtotal>₱0.00</strong>
        </div>
        <p class="cart-note">Delivery is arranged with you after your order is reviewed.</p>
        <a class="btn btn--primary btn--full" href="/checkout.php" data-cart-checkout>Proceed to checkout</a>
    </div>
</aside>

<div class="toast-region" data-toast-region aria-live="polite" aria-atomic="true"></div>
</body>
</html>
