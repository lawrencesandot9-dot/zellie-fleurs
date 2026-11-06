<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once ZF_ROOT . '/src/auth.php';
require_once ZF_ROOT . '/src/orders.php';

zf_require_admin();

$orderId = zf_safe_int($_GET['id'] ?? 0);
$order = zf_get_order($db, $orderId);

if ($order === null) {
    zf_flash('error', 'That order could not be found.');
    zf_redirect('/admin/orders.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    zf_require_csrf();
    $newStatus = zf_safe_text($_POST['status'] ?? '');
    $error = null;

    if (zf_update_order_status($db, $orderId, $newStatus, $error)) {
        zf_flash('success', 'Order status updated to ' . $newStatus . '.');
    } else {
        zf_flash('error', $error ?: 'The order status could not be updated.');
    }
    zf_redirect('/admin/view_order.php?id=' . $orderId);
}

$items = zf_order_items($order);
$pageTitle = 'Order ' . $order['order_code'];
$activeAdmin = 'orders';
require ZF_ROOT . '/src/views/admin_header.php';
?>

<div class="admin-detail-head">
    <a class="back-link" href="/admin/orders.php">← Back to orders</a>
    <button class="btn btn--ghost btn--small" type="button" onclick="window.print()">Print receipt</button>
</div>

<div class="admin-detail-grid">
    <section class="panel order-invoice">
        <div class="invoice-head">
            <div>
                <span class="eyebrow">Zellie Fleurs</span>
                <h2>Order invoice</h2>
                <span class="order-code"><?= zf_e($order['order_code']) ?></span>
            </div>
            <span class="status status--large <?= zf_e(zf_status_class($order['status'])) ?>"><?= zf_e($order['status']) ?></span>
        </div>

        <div class="invoice-meta">
            <div><span>Placed</span><strong><?= zf_e(zf_format_datetime($order['created_at'])) ?></strong></div>
            <div><span>Last updated</span><strong><?= zf_e(zf_format_datetime($order['updated_at'])) ?></strong></div>
            <div><span>Payment</span><strong><?= zf_e($order['payment_method']) ?></strong></div>
            <div><span>GCash reference</span><strong><?= $order['payment_method'] === 'GCash' ? zf_e($order['gcash_ref']) : 'Not applicable' ?></strong></div>
        </div>

        <div class="invoice-section">
            <h3>Customer &amp; delivery</h3>
            <div class="invoice-customer">
                <div><span>Customer</span><strong><?= zf_e($order['customer_name']) ?></strong></div>
                <div><span>Contact</span><strong><?= zf_e($order['contact_number']) ?></strong></div>
                <div class="invoice-customer__wide"><span>Delivery address</span><strong><?= nl2br(zf_e($order['delivery_address'])) ?></strong></div>
                <div><span>Delivery date</span><strong><?= zf_e(zf_format_date($order['delivery_date'], 'l, M j, Y')) ?></strong></div>
                <div><span>Card message</span><strong><?= trim((string) $order['card_message']) !== '' ? '“' . zf_e($order['card_message']) . '”' : '—' ?></strong></div>
            </div>
        </div>

        <div class="invoice-section">
            <h3>Arrangements</h3>
            <div class="invoice-items">
                <?php foreach ($items as $item): ?>
                    <div class="invoice-item">
                        <img src="<?= zf_e(zf_image_url($item['image_url'])) ?>" alt="" width="54" height="66" loading="lazy" onerror="this.onerror=null;this.src='/assets/img/flower-placeholder.svg';">
                        <div>
                            <strong><?= zf_e($item['name']) ?></strong>
                            <span><?= zf_money($item['price']) ?> × <?= (int) $item['qty'] ?></span>
                        </div>
                        <strong><?= zf_money($item['subtotal']) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="summary-total">
                <span>Total</span>
                <strong><?= zf_money($order['total_amount']) ?></strong>
            </div>
        </div>
    </section>

    <aside class="admin-detail-side">
        <div class="panel">
            <span class="eyebrow">Order status</span>
            <h2>Update progress</h2>
            <p class="muted">Customers see this update immediately on the tracking page.</p>
            <form method="post" action="/admin/view_order.php?id=<?= (int) $order['id'] ?>" data-status-form>
                <?= zf_csrf_field() ?>
                <input type="hidden" name="update_status" value="1">
                <div class="field">
                    <label for="detail-status">Status</label>
                    <select id="detail-status" name="status" data-prev="<?= zf_e($order['status']) ?>">
                        <?php foreach (zf_order_statuses() as $option): ?>
                            <option value="<?= zf_e($option) ?>" <?= $order['status'] === $option ? 'selected' : '' ?>><?= zf_e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn--primary btn--full" type="submit">Save status</button>
            </form>
        </div>

        <div class="panel panel--muted">
            <span class="eyebrow">Care note</span>
            <h3>Before cancelling</h3>
            <p class="muted">Cancelling returns the ordered quantities to stock and removes the order from revenue. This action can be reversed if stock is available.</p>
        </div>

        <div class="panel">
            <span class="eyebrow">Quick links</span>
            <div class="quick-actions quick-actions--compact">
                <a href="/track.php?code=<?= rawurlencode($order['order_code']) ?>" target="_blank" rel="noopener">Open customer tracking <span>↗</span></a>
                <a href="/admin/orders.php?q=<?= rawurlencode($order['order_code']) ?>">Find in order list <span>→</span></a>
            </div>
        </div>
    </aside>
</div>

<?php require ZF_ROOT . '/src/views/admin_footer.php'; ?>
