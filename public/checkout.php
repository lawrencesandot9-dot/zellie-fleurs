<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once ZF_ROOT . '/src/orders.php';
require_once ZF_ROOT . '/src/products.php';

$settings = zf_settings();
$errors = [];
$fieldErrors = [];
$old = [
    'customer_name' => '',
    'contact_number' => '',
    'delivery_address' => '',
    'delivery_date' => '',
    'card_message' => '',
    'payment_method' => 'GCash',
    'gcash_ref' => '',
];
$successOrder = null;
$pageError = '';

if (isset($_GET['order']) && $_GET['order'] !== '') {
    $successOrder = zf_get_order_by_code($db, (string) $_GET['order']);
    if ($successOrder === null) {
        $pageError = 'We could not find that order reference. Please check the code and try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'customer_name' => zf_safe_text($_POST['customer_name'] ?? ''),
        'contact_number' => zf_safe_text($_POST['contact_number'] ?? ''),
        'delivery_address' => zf_safe_text($_POST['delivery_address'] ?? ''),
        'delivery_date' => zf_safe_text($_POST['delivery_date'] ?? ''),
        'card_message' => zf_safe_text($_POST['card_message'] ?? ''),
        'payment_method' => zf_safe_text($_POST['payment_method'] ?? 'GCash'),
        'gcash_ref' => zf_safe_text($_POST['gcash_ref'] ?? ''),
    ];

    if (!zf_csrf_valid()) {
        $errors[] = 'Your security token has expired. Please refresh the page and try again.';
    } else {
        zf_session_start();

        // Bot guard (no-JS safe): the honeypot trips only when the hidden
        // "website" field is filled, a path real customers cannot reach.
        // The speed guard only fires when the posted form_loaded_at token is
        // present AND matches the session entry captured at render time, so a
        // missing cookie/token never blocks checkout.
        $loadedAt = $_SESSION['checkout_loaded_at'] ?? null;
        $loadedToken = (string) ($_POST['form_loaded_at'] ?? '');
        $isBot = trim((string) ($_POST['website'] ?? '')) !== '';

        if (
            !$isBot
            && is_array($loadedAt)
            && is_string($loadedAt['token'] ?? null)
            && is_int($loadedAt['at'] ?? null)
            && ctype_xdigit($loadedAt['token'])
            && $loadedToken !== ''
            && hash_equals($loadedAt['token'], $loadedToken)
            && (time() - $loadedAt['at']) < 2
        ) {
            $isBot = true;
        }

        // Single-use token: consumed on every validated submission so a
        // replayed request cannot reuse the render timestamp.
        unset($_SESSION['checkout_loaded_at']);

        if ($isBot) {
            $errors[] = 'We could not place your order. Please try again.';
        }

        $postedToken = (string) ($_POST['checkout_token'] ?? '');
        $sessionToken = (string) ($_SESSION['checkout_token'] ?? '');

        if ($postedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
            if (!empty($_SESSION['last_order_code'])) {
                zf_redirect('/checkout.php?order=' . rawurlencode((string) $_SESSION['last_order_code']));
            }
            $errors[] = 'Your checkout session has expired. Please review your cart and try again.';
        } elseif ($isBot) {
            // Honeypot or too-fast submit: the generic error is already set
            // above. Fall through without creating any order.
        } else {
            $items = json_decode((string) ($_POST['items_json'] ?? '[]'), true);
            if (!is_array($items)) {
                $items = [];
            }

            $result = zf_create_order($db, [
                'customer_name' => $old['customer_name'],
                'contact_number' => $old['contact_number'],
                'delivery_address' => $old['delivery_address'],
                'delivery_date' => $old['delivery_date'],
                'card_message' => $old['card_message'],
                'payment_method' => $old['payment_method'],
                'gcash_ref' => $old['gcash_ref'],
                'items' => $items,
            ], $postedToken);

            if ($result['ok']) {
                unset($_SESSION['checkout_token']);
                $_SESSION['last_order_code'] = (string) $result['order']['order_code'];
                zf_redirect('/checkout.php?order=' . rawurlencode((string) $result['order']['order_code']));
            }

            $errors = $result['errors'] ?? ['We could not place your order. Please try again.'];
            $fieldErrors = $result['field_errors'] ?? [];
        }
    }
}

$today = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+180 days'));
$checkoutToken = zf_checkout_token();

// Speed guard, no-JS safe: a render token is stored with the time the
// checkout form was served. The POST handler only enforces the 2-second
// minimum when this token round-trips with the same session cookie, so
// cookie-less or JS-less clients are never blocked.
$loadedFormToken = bin2hex(random_bytes(8));
zf_session_start();
$_SESSION['checkout_loaded_at'] = ['token' => $loadedFormToken, 'at' => time()];

$pageTitle = 'Checkout | ' . $settings['shop_name'];
$activeNav = 'shop';
require ZF_ROOT . '/src/views/store_header.php';
?>

<main id="main" class="checkout-page" <?= $successOrder ? 'data-order-success' : 'data-checkout-page' ?>>
    <div class="container">
        <?php if ($successOrder): ?>
            <?php $items = zf_order_items($successOrder); ?>
            <section class="confirmation">
                <div class="confirmation__mark" aria-hidden="true">✓</div>
                <span class="eyebrow">Order received</span>
                <h1>Thank you, <?= zf_e($successOrder['customer_name']) ?>.</h1>
                <p class="confirmation__lede">Your order is safely with our studio. We will review it and begin preparing your blooms.</p>

                <div class="reference-box">
                    <span>Your order reference</span>
                    <strong><?= zf_e($successOrder['order_code']) ?></strong>
                    <small>Keep this code to track your delivery.</small>
                </div>

                <div class="confirmation__grid">
                    <div class="panel">
                        <h2>Order summary</h2>
                        <div class="summary-list">
                            <?php foreach ($items as $item): ?>
                                <div class="summary-line">
                                    <span><strong><?= (int) $item['qty'] ?>×</strong> <?= zf_e($item['name']) ?></span>
                                    <span><?= zf_money($item['subtotal']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="summary-total">
                            <span>Total</span>
                            <strong><?= zf_money($successOrder['total_amount']) ?></strong>
                        </div>
                    </div>

                    <div class="panel panel--muted">
                        <h2>Delivery details</h2>
                        <dl class="detail-list">
                            <div><dt>Delivery date</dt><dd><?= zf_e(zf_format_date($successOrder['delivery_date'], 'l, M j, Y')) ?></dd></div>
                            <div><dt>Payment</dt><dd><?= zf_e($successOrder['payment_method']) ?><?= $successOrder['payment_method'] === 'GCash' ? ' · ' . zf_e($successOrder['gcash_ref']) : '' ?></dd></div>
                            <div><dt>Status</dt><dd><span class="status <?= zf_e(zf_status_class($successOrder['status'])) ?>"><?= zf_e($successOrder['status']) ?></span></dd></div>
                            <?php if (trim((string) $successOrder['card_message']) !== ''): ?>
                                <div><dt>Gift message</dt><dd>“<?= zf_e($successOrder['card_message']) ?>”</dd></div>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>

                <div class="confirmation__actions">
                    <a class="btn btn--primary" href="/track.php?code=<?= rawurlencode($successOrder['order_code']) ?>">Track this order</a>
                    <a class="btn btn--ghost" href="/index.php#shop">Continue shopping</a>
                </div>
            </section>
        <?php else: ?>
            <div class="page-heading">
                <span class="eyebrow">Secure checkout</span>
                <h1>Complete your order</h1>
                <p>Tell us where the flowers are going. Your total is always calculated securely on our server.</p>
            </div>

            <?php if ($pageError !== ''): ?>
                <div class="flash flash--error" role="alert"><?= zf_e($pageError) ?></div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="flash flash--error" role="alert">
                    <strong>Please check your order:</strong>
                    <ul class="error-list">
                        <?php foreach ($errors as $error): ?>
                            <li><?= zf_e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="checkout-layout">
                <form class="checkout-form panel" action="/checkout.php" method="post" data-checkout-form>
                    <?= zf_csrf_field() ?>
                    <input type="hidden" name="checkout_token" value="<?= zf_e($checkoutToken) ?>">
                    <input type="hidden" name="items_json" value="[]" data-cart-json>
                    <input type="hidden" name="form_loaded_at" value="<?= zf_e($loadedFormToken) ?>">
                    <input type="text" name="website" value="" style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true" tabindex="-1" autocomplete="off">

                    <section class="form-section">
                        <div class="form-section__head">
                            <span class="form-step">01</span>
                            <div>
                                <h2>Delivery information</h2>
                                <p>We use these details only to prepare and deliver your order.</p>
                            </div>
                        </div>

                        <div class="field-grid">
                            <div class="field <?= isset($fieldErrors['customer_name']) ? 'field--error' : '' ?>">
                                <label for="customer_name">Full name</label>
                                <input id="customer_name" type="text" name="customer_name" value="<?= zf_e($old['customer_name']) ?>" autocomplete="name" required maxlength="100" placeholder="e.g. Maria Santos">
                            </div>

                            <div class="field <?= isset($fieldErrors['contact_number']) ? 'field--error' : '' ?>">
                                <label for="contact_number">Contact number</label>
                                <input id="contact_number" type="tel" name="contact_number" value="<?= zf_e($old['contact_number']) ?>" autocomplete="tel" required maxlength="20" placeholder="09XX XXX XXXX">
                            </div>
                        </div>

                        <div class="field <?= isset($fieldErrors['delivery_address']) ? 'field--error' : '' ?>">
                            <label for="delivery_address">Complete delivery address</label>
                            <textarea id="delivery_address" name="delivery_address" rows="3" required maxlength="500" placeholder="House / unit, street, barangay, city"><?= zf_e($old['delivery_address']) ?></textarea>
                        </div>

                        <div class="field-grid">
                            <div class="field <?= isset($fieldErrors['delivery_date']) ? 'field--error' : '' ?>">
                                <label for="delivery_date">Scheduled delivery date</label>
                                <input id="delivery_date" type="date" name="delivery_date" value="<?= zf_e($old['delivery_date']) ?>" min="<?= zf_e($today) ?>" max="<?= zf_e($maxDate) ?>" required>
                            </div>
                            <div class="field <?= isset($fieldErrors['card_message']) ? 'field--error' : '' ?>">
                                <label for="card_message">Gift card message <span class="optional">optional</span></label>
                                <input id="card_message" type="text" name="card_message" value="<?= zf_e($old['card_message']) ?>" maxlength="300" placeholder="A short note for the recipient">
                            </div>
                        </div>
                    </section>

                    <section class="form-section">
                        <div class="form-section__head">
                            <span class="form-step">02</span>
                            <div>
                                <h2>Payment method</h2>
                                <p>Choose how you would like to settle your order.</p>
                            </div>
                        </div>

                        <div class="payment-options">
                            <label class="payment-option <?= $old['payment_method'] === 'GCash' ? 'is-selected' : '' ?>" data-payment-option="GCash">
                                <input type="radio" name="payment_method" value="GCash" <?= $old['payment_method'] === 'GCash' ? 'checked' : '' ?>>
                                <span class="payment-option__icon">G</span>
                                <span>
                                    <strong>GCash transfer</strong>
                                    <small>Send payment before dispatch</small>
                                </span>
                            </label>
                            <label class="payment-option <?= $old['payment_method'] === 'COD' ? 'is-selected' : '' ?>" data-payment-option="COD">
                                <input type="radio" name="payment_method" value="COD" <?= $old['payment_method'] === 'COD' ? 'checked' : '' ?>>
                                <span class="payment-option__icon">₱</span>
                                <span>
                                    <strong>Cash on delivery</strong>
                                    <small>Pay when your flowers arrive</small>
                                </span>
                            </label>
                        </div>

                        <div class="gcash-box <?= $old['payment_method'] === 'COD' ? 'is-hidden' : '' ?>" data-gcash-box>
                            <div class="gcash-box__head">
                                <span class="gcash-mark">GCash</span>
                                <strong>Payment instructions</strong>
                            </div>
                            <p>Send your payment to <strong><?= zf_e($settings['gcash_number']) ?></strong> (<?= zf_e($settings['gcash_name']) ?>), then enter the transaction reference below.</p>
                            <div class="field <?= isset($fieldErrors['gcash_ref']) ? 'field--error' : '' ?>">
                                <label for="gcash_ref">GCash reference number</label>
                                <input id="gcash_ref" type="text" name="gcash_ref" value="<?= zf_e($old['gcash_ref']) ?>" maxlength="40" placeholder="e.g. 1234567890123" autocomplete="off">
                                <small>Required for GCash orders. Use the reference shown in your GCash receipt.</small>
                            </div>
                        </div>
                    </section>

                    <div class="checkout-submit">
                        <p class="secure-note">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                            Prices and totals are verified securely when you place your order.
                        </p>
                        <button class="btn btn--primary btn--full" type="submit" data-checkout-submit>
                            Place order securely
                        </button>
                    </div>
                </form>

                <aside class="checkout-summary panel">
                    <div class="checkout-summary__head">
                        <span class="eyebrow">Your order</span>
                        <a href="/index.php#shop">Edit cart</a>
                    </div>

                    <div data-cart-summary class="checkout-summary__items">
                        <p class="muted">Loading your selection…</p>
                    </div>

                    <div class="summary-total summary-total--checkout">
                        <span>Total</span>
                        <strong data-checkout-total>₱0.00</strong>
                    </div>

                    <div class="checkout-summary__note">
                        <span>✦</span>
                        <p>Your blooms are arranged fresh on the day of delivery. We will contact you if any seasonal stem needs to be substituted.</p>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require ZF_ROOT . '/src/views/store_footer.php'; ?>
