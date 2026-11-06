<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once ZF_ROOT . '/src/auth.php';
require_once ZF_ROOT . '/src/orders.php';

zf_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    zf_require_csrf();
    $orderId = zf_safe_int($_POST['order_id'] ?? 0);
    $newStatus = zf_safe_text($_POST['status'] ?? '');
    $error = null;

    if (zf_update_order_status($db, $orderId, $newStatus, $error)) {
        zf_flash('success', 'Order status updated to ' . $newStatus . '.');
    } else {
        zf_flash('error', $error ?: 'The order status could not be updated.');
    }

    zf_redirect('/admin/orders.php');
}

$search = zf_safe_text($_GET['q'] ?? '');
$status = zf_safe_text($_GET['status'] ?? '');
$sort = ($_GET['sort'] ?? 'newest') === 'oldest' ? 'oldest' : 'newest';
$orders = zf_search_orders($db, $search, $status, $sort);

$pageTitle = 'Orders';
$activeAdmin = 'orders';
require ZF_ROOT . '/src/views/admin_header.php';
?>

<section class="admin-section">
    <form class="admin-filters" method="get" action="/admin/orders.php">
        <div class="field field--search">
            <label class="sr-only" for="order-search">Search orders</label>
            <input id="order-search" type="search" name="q" value="<?= zf_e($search) ?>" placeholder="Search code, customer or number…">
        </div>
        <div class="field">
            <label class="sr-only" for="order-status">Filter status</label>
            <select id="order-status" name="status">
                <option value="">All statuses</option>
                <?php foreach (zf_order_statuses() as $option): ?>
                    <option value="<?= zf_e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= zf_e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="sr-only" for="order-sort">Sort</label>
            <select id="order-sort" name="sort">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
            </select>
        </div>
        <button class="btn btn--primary btn--small" type="submit">Apply</button>
        <?php if ($search !== '' || $status !== ''): ?>
            <a class="btn btn--ghost btn--small" href="/admin/orders.php">Reset</a>
        <?php endif; ?>
    </form>
</section>

<section class="admin-section">
    <div class="admin-section__head">
        <div>
            <span class="eyebrow">Order management</span>
            <h2><?= count($orders) ?> <?= count($orders) === 1 ? 'order' : 'orders' ?></h2>
        </div>
    </div>

    <?php if ($orders === []): ?>
        <div class="empty-state empty-state--wide">
            <img src="/assets/img/logo-mark.svg" alt="" width="48" height="48">
            <h3>No matching orders</h3>
            <p>Try clearing the filters or search for a different reference.</p>
            <a class="btn btn--soft" href="/admin/orders.php">Show all orders</a>
        </div>
    <?php else: ?>
        <div class="admin-order-list">
            <?php foreach ($orders as $order): $items = zf_order_items($order); ?>
                <article class="admin-order-card">
                    <div class="admin-order-card__top">
                        <div>
                            <span class="order-code"><?= zf_e($order['order_code']) ?></span>
                            <span class="muted"><?= zf_e(zf_format_datetime($order['created_at'])) ?></span>
                        </div>
                        <span class="status <?= zf_e(zf_status_class($order['status'])) ?>"><?= zf_e($order['status']) ?></span>
                    </div>

                    <div class="admin-order-card__grid">
                        <div>
                            <span class="label">Customer</span>
                            <strong><?= zf_e($order['customer_name']) ?></strong>
                            <span class="muted"><?= zf_e($order['contact_number']) ?></span>
                        </div>
                        <div>
                            <span class="label">Delivery</span>
                            <strong><?= zf_e(zf_format_date($order['delivery_date'], 'M j, Y')) ?></strong>
                            <span class="muted">Scheduled date</span>
                        </div>
                        <div>
                            <span class="label">Payment</span>
                            <strong><?= zf_e($order['payment_method']) ?></strong>
                            <span class="muted"><?= $order['payment_method'] === 'GCash' ? 'Ref: ' . zf_e($order['gcash_ref']) : 'Pay on delivery' ?></span>
                        </div>
                        <div>
                            <span class="label">Total</span>
                            <strong class="order-total"><?= zf_money($order['total_amount']) ?></strong>
                            <span class="muted"><?= count($items) ?> <?= count($items) === 1 ? 'item' : 'items' ?></span>
                        </div>
                    </div>

                    <div class="admin-order-card__items">
                        <?php foreach ($items as $item): ?>
                            <span><strong><?= (int) $item['qty'] ?>×</strong> <?= zf_e($item['name']) ?></span>
                        <?php endforeach; ?>
                    </div>

                    <div class="admin-order-card__actions">
                        <a class="btn btn--ghost btn--small" href="/admin/view_order.php?id=<?= (int) $order['id'] ?>">View details</a>
                        <form class="status-form" method="post" action="/admin/orders.php" data-status-form>
                            <?= zf_csrf_field() ?>
                            <input type="hidden" name="update_status" value="1">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <label class="sr-only" for="status-<?= (int) $order['id'] ?>">Update status</label>
                            <select id="status-<?= (int) $order['id'] ?>" name="status" data-prev="<?= zf_e($order['status']) ?>">
                                <?php foreach (zf_order_statuses() as $option): ?>
                                    <option value="<?= zf_e($option) ?>" <?= $order['status'] === $option ? 'selected' : '' ?>><?= zf_e($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn--soft btn--small" type="submit">Update</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require ZF_ROOT . '/src/views/admin_footer.php'; ?>
