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

/**
 * Build a chip/filter link that preserves the current search term and sort,
 * swapping only the status (empty string = all statuses).
 */
$chip_href = static function (string $statusValue) use ($search, $sort): string {
    $params = array_filter(
        ['q' => $search, 'status' => $statusValue, 'sort' => $sort === 'oldest' ? 'oldest' : ''],
        static fn (string $value): bool => $value !== '',
    );
    $query = http_build_query($params);

    return '/admin/orders.php' . ($query !== '' ? '?' . $query : '');
};

$pageTitle = 'Orders';
$activeAdmin = 'orders';
require ZF_ROOT . '/src/views/admin_header.php';
?>

<section class="admin-section">
    <form class="admin-filters" method="get" action="/admin/orders.php">
        <div class="field field--search">
            <label class="label" for="order-search">Search</label>
            <input id="order-search" type="search" name="q" value="<?= zf_e($search) ?>" placeholder="Code, customer or number…">
        </div>
        <div class="field">
            <label class="label" for="order-status">Status</label>
            <select id="order-status" name="status">
                <option value="">All statuses</option>
                <?php foreach (zf_order_statuses() as $option): ?>
                    <option value="<?= zf_e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= zf_e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="label" for="order-sort">Sort</label>
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

    <nav class="status-chips" aria-label="Quick status filters">
        <a class="status status-chip status-chip--all <?= $status === '' ? 'is-active' : '' ?>" href="<?= zf_e($chip_href('')) ?>">All</a>
        <?php foreach (zf_order_statuses() as $option): ?>
            <a class="status <?= zf_e(zf_status_class($option)) ?> status-chip <?= $status === $option ? 'is-active' : '' ?>" href="<?= zf_e($chip_href($option)) ?>"><?= zf_e($option) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($orders === []): ?>
        <div class="empty-state empty-state--wide">
            <img src="/assets/img/logo-mark.svg" alt="" width="48" height="48">
            <h3>No matching orders</h3>
            <p>Try clearing the filters or search for a different reference.</p>
            <a class="btn btn--soft" href="/admin/orders.php">Show all orders</a>
        </div>
    <?php else: ?>
        <div class="orders-table-wrap">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Delivery</th>
                        <th scope="col">Items</th>
                        <th scope="col">Total</th>
                        <th scope="col">Payment</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): $items = zf_order_items($order); ?>
                        <tr>
                            <td data-label="Order">
                                <span class="order-code"><?= zf_e($order['order_code']) ?></span>
                                <span class="muted"><?= zf_e(zf_format_datetime($order['created_at'])) ?></span>
                            </td>
                            <td data-label="Customer">
                                <strong><?= zf_e($order['customer_name']) ?></strong>
                                <span class="muted"><?= zf_e($order['contact_number']) ?></span>
                            </td>
                            <td data-label="Delivery">
                                <strong><?= zf_e(zf_format_date($order['delivery_date'])) ?></strong>
                            </td>
                            <td data-label="Items">
                                <?php foreach ($items as $item): ?>
                                    <span class="muted"><?= (int) $item['qty'] ?> × <?= zf_e($item['name']) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td data-label="Total">
                                <strong class="order-total"><?= zf_money($order['total_amount']) ?></strong>
                            </td>
                            <td data-label="Payment">
                                <strong><?= zf_e($order['payment_method']) ?></strong>
                                <span class="muted"><?= $order['payment_method'] === 'GCash' ? 'Ref: ' . zf_e($order['gcash_ref']) : 'Pay on delivery' ?></span>
                            </td>
                            <td data-label="Status">
                                <span class="status <?= zf_e(zf_status_class($order['status'])) ?>"><?= zf_e($order['status']) ?></span>
                            </td>
                            <td data-label="Actions">
                                <div class="orders-actions">
                                    <a class="btn btn--ghost btn--small" href="/admin/view_order.php?id=<?= (int) $order['id'] ?>">View details</a>
                                    <form class="status-form" method="post" action="/admin/orders.php" data-status-form>
                                        <?= zf_csrf_field() ?>
                                        <input type="hidden" name="update_status" value="1">
                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                        <label class="sr-only" for="status-<?= (int) $order['id'] ?>">Update status for <?= zf_e($order['order_code']) ?></label>
                                        <select id="status-<?= (int) $order['id'] ?>" name="status" data-prev="<?= zf_e($order['status']) ?>">
                                            <?php foreach (zf_order_statuses() as $option): ?>
                                                <option value="<?= zf_e($option) ?>" <?= $order['status'] === $option ? 'selected' : '' ?>><?= zf_e($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn--soft btn--small" type="submit">Update</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require ZF_ROOT . '/src/views/admin_footer.php'; ?>
