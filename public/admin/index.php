<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once ZF_ROOT . '/src/auth.php';
require_once ZF_ROOT . '/src/orders.php';

zf_require_admin();

$analytics = zf_order_analytics($db);
$counts = $analytics['counts'];
$recent = $analytics['recent'];

$pageTitle = 'Dashboard';
$activeAdmin = 'dashboard';
require ZF_ROOT . '/src/views/admin_header.php';
?>

<section class="metric-grid">
    <article class="metric metric--wide">
        <span class="metric__label">Total revenue</span>
        <strong class="metric__value"><?= zf_money($analytics['revenue']) ?></strong>
        <span class="metric__hint">Excludes cancelled orders</span>
    </article>
    <article class="metric">
        <span class="metric__label">Total orders</span>
        <strong class="metric__value"><?= (int) $analytics['total_orders'] ?></strong>
        <span class="metric__hint">All time</span>
    </article>
    <article class="metric metric--amber">
        <span class="metric__label">Pending</span>
        <strong class="metric__value"><?= (int) $counts['Pending'] ?></strong>
        <span class="metric__hint">Awaiting review</span>
    </article>
    <article class="metric metric--blue">
        <span class="metric__label">Confirmed</span>
        <strong class="metric__value"><?= (int) $counts['Confirmed'] ?></strong>
        <span class="metric__hint">Ready to prepare</span>
    </article>
    <article class="metric metric--prep">
        <span class="metric__label">Preparing</span>
        <strong class="metric__value"><?= (int) $counts['Preparing'] ?></strong>
        <span class="metric__hint">In the studio</span>
    </article>
    <article class="metric metric--out">
        <span class="metric__label">Out for delivery</span>
        <strong class="metric__value"><?= (int) $counts['Out for Delivery'] ?></strong>
        <span class="metric__hint">On the way</span>
    </article>
    <article class="metric metric--green">
        <span class="metric__label">Delivered</span>
        <strong class="metric__value"><?= (int) $counts['Delivered'] ?></strong>
        <span class="metric__hint">Completed</span>
    </article>
    <article class="metric metric--rose">
        <span class="metric__label">Cancelled</span>
        <strong class="metric__value"><?= (int) $counts['Cancelled'] ?></strong>
        <span class="metric__hint">Not counted in revenue</span>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section__head">
        <div>
            <span class="eyebrow">Latest activity</span>
            <h2>Recent orders</h2>
        </div>
        <a class="btn btn--soft btn--small" href="/admin/orders.php">View all orders</a>
    </div>

    <?php if ($recent === []): ?>
        <div class="empty-state empty-state--wide">
            <img src="/assets/img/logo-mark.svg" alt="" width="48" height="48">
            <h3>No orders yet</h3>
            <p>New customer orders will appear here as soon as they are placed.</p>
            <a class="btn btn--soft" href="/index.php" target="_blank" rel="noopener">View storefront</a>
        </div>
    <?php else: ?>
        <div class="recent-list">
            <?php foreach ($recent as $order): ?>
                <a class="recent-order" href="/admin/view_order.php?id=<?= (int) $order['id'] ?>">
                    <div class="recent-order__main">
                        <span class="order-code"><?= zf_e($order['order_code']) ?></span>
                        <span class="recent-order__name"><?= zf_e($order['customer_name']) ?></span>
                        <span class="muted"><?= zf_e(zf_format_datetime($order['created_at'])) ?></span>
                    </div>
                    <div class="recent-order__side">
                        <span class="status <?= zf_e(zf_status_class($order['status'])) ?>"><?= zf_e($order['status']) ?></span>
                        <strong><?= zf_money($order['total_amount']) ?></strong>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="admin-section admin-section--split">
    <div class="panel">
        <span class="eyebrow">Quick actions</span>
        <h2>Keep the studio moving</h2>
        <div class="quick-actions">
            <a href="/admin/orders.php?status=Pending">Review pending orders <span>→</span></a>
            <a href="/admin/products.php">Add a new arrangement <span>→</span></a>
            <a href="/admin/settings.php">Update shop details <span>→</span></a>
            <a href="/index.php" target="_blank" rel="noopener">Open storefront <span>↗</span></a>
        </div>
    </div>
    <div class="panel panel--muted">
        <span class="eyebrow">Studio note</span>
        <h2>Orders, handled with care</h2>
        <p class="muted">Update an order to <strong>Confirmed</strong> once payment is verified, then move it through <strong>Preparing</strong> and <strong>Out for Delivery</strong>. Customers see each update on the tracking page.</p>
    </div>
</section>

<?php require ZF_ROOT . '/src/views/admin_footer.php'; ?>
