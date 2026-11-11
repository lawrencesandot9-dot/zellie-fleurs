<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once ZF_ROOT . '/src/orders.php';

$settings = zf_settings();
$searchedCode = strtoupper(zf_safe_text($_GET['code'] ?? ''));
$order = null;
$notFound = false;

if ($searchedCode !== '') {
    $order = zf_get_order_by_code($db, $searchedCode);
    $notFound = $order === null;
}

$steps = zf_tracking_steps();
$currentStep = -1;
$cancelled = false;
$items = [];

if ($order !== null) {
    $items = zf_order_items($order);
    $cancelled = $order['status'] === 'Cancelled';
    $currentStep = array_search($order['status'], $steps, true);
    if ($currentStep === false) {
        $currentStep = -1;
    }
}

$pageTitle = 'Track your order | ' . $settings['shop_name'];
$activeNav = 'track';
require ZF_ROOT . '/src/views/store_header.php';
?>

<main id="main" class="track-page">
    <div class="container">
        <div class="page-heading page-heading--center">
            <span class="eyebrow">Order tracking</span>
            <h1>Where are your flowers?</h1>
            <p>Enter the order reference from your confirmation to see the latest update from our studio.</p>
        </div>

        <form class="track-search panel" action="/track.php" method="get">
            <label for="track-code">Order reference</label>
            <div class="track-search__row">
                <input id="track-code" type="text" name="code" value="<?= zf_e($searchedCode) ?>" placeholder="e.g. ZF-A1B2C3" autocomplete="off" required maxlength="20">
                <button class="btn btn--primary" type="submit">Track order</button>
            </div>
            <p class="track-search__hint">Your reference starts with <strong>ZF-</strong> and was shown after checkout.</p>
        </form>

        <?php if ($notFound): ?>
            <div class="empty-state empty-state--wide track-empty">
                <img src="/assets/img/logo-mark.svg" alt="" width="52" height="52">
                <h3>No order found</h3>
                <p>We could not find an order with the reference <strong><?= zf_e($searchedCode) ?></strong>. Please check the code and try again.</p>
                <a class="btn btn--soft" href="/index.php#contact">Contact the studio</a>
            </div>
        <?php elseif ($order !== null): ?>
            <section class="track-result">
                <div class="track-head panel">
                    <div>
                        <span class="eyebrow">Order reference</span>
                        <div class="track-code-row">
                            <h2 class="track-code"><?= zf_e($order['order_code']) ?></h2>
                            <button class="copy-code" type="button" data-copy-code="<?= zf_e($order['order_code']) ?>" aria-label="Copy order reference to clipboard">Copy</button>
                        </div>
                        <p class="muted">Placed <?= zf_e(zf_format_datetime($order['created_at'])) ?> by <?= zf_e($order['customer_name']) ?></p>
                    </div>
                    <span class="status status--large <?= zf_e(zf_status_class($order['status'])) ?>"><?= zf_e($order['status']) ?></span>
                </div>

                <?php if ($cancelled): ?>
                    <div class="track-cancelled">
                        <strong>This order has been cancelled.</strong>
                        <p>If you believe this is unexpected, please contact our studio and we will be glad to help.</p>
                    </div>
                <?php else: ?>
                    <div class="progress panel" aria-label="Order progress">
                        <?php foreach ($steps as $index => $step): ?>
                            <div class="progress-step <?= $index < $currentStep ? 'is-done' : '' ?> <?= $index === $currentStep ? 'is-current' : '' ?>">
                                <span class="progress-step__dot"><?= $index < $currentStep ? '✓' : $index + 1 ?></span>
                                <span class="progress-step__label"><?= zf_e($step) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="track-grid">
                    <div class="panel">
                        <h3>Your arrangements</h3>
                        <div class="summary-list">
                            <?php foreach ($items as $item): ?>
                                <div class="summary-line summary-line--item">
                                    <div class="summary-item">
                                        <img src="<?= zf_e(zf_image_url($item['image_url'])) ?>" alt="" width="52" height="64" loading="lazy" onerror="this.onerror=null;this.src='/assets/img/flower-placeholder.svg';">
                                        <span><strong><?= (int) $item['qty'] ?>×</strong> <?= zf_e($item['name']) ?></span>
                                    </div>
                                    <span><?= zf_money($item['subtotal']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="summary-total">
                            <span>Total</span>
                            <strong><?= zf_money($order['total_amount']) ?></strong>
                        </div>
                    </div>

                    <div class="panel panel--muted">
                        <h3>Delivery details</h3>
                        <dl class="detail-list">
                            <div><dt>Customer</dt><dd><?= zf_e($order['customer_name']) ?></dd></div>
                            <div><dt>Delivery date</dt><dd><?= zf_e(zf_format_date($order['delivery_date'], 'l, M j, Y')) ?></dd></div>
                            <div><dt>Payment method</dt><dd><?= zf_e($order['payment_method']) ?></dd></div>
                            <div><dt>Order status</dt><dd><?= zf_e($order['status']) ?></dd></div>
                            <?php if (trim((string) $order['card_message']) !== ''): ?>
                                <div><dt>Gift message</dt><dd>“<?= zf_e($order['card_message']) ?>”</dd></div>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>

                <p class="track-help">Need to change something? Contact the studio at <strong><?= zf_contact_link($settings['contact_number']) ?></strong>.</p>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php require ZF_ROOT . '/src/views/store_footer.php'; ?>
