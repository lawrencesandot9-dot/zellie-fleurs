<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once ZF_ROOT . '/src/products.php';

$settings = zf_settings();
$productId = zf_safe_int($_GET['id'] ?? 0);
$product = $productId > 0 ? zf_get_product($db, $productId, true) : null;

if ($product === null) {
    zf_abort(404, 'That arrangement could not be found. It may have sold out or been retired from the collection.');
}

$availability = zf_product_availability($product);
$isAvailable = $availability['available'];
$stock = (int) $product['stock_quantity'];
$maxQty = max(1, min($stock > 0 ? $stock : 1, 99));

$related = [];
foreach (zf_get_products($db, ['category' => $product['category'], 'active_only' => true]) as $candidate) {
    if ((int) $candidate['id'] === (int) $product['id']) {
        continue;
    }
    $related[] = $candidate;
}
usort($related, static fn (array $a, array $b): int =>
    ((int) $a['stock_quantity'] > 0 ? 0 : 1) <=> ((int) $b['stock_quantity'] > 0 ? 0 : 1));
$related = array_slice($related, 0, 3);

$baseOrigin = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$imageSrc = zf_image_url($product['image_url']);
$imageAbsolute = preg_match('#^https?://#i', $imageSrc) === 1
    ? $imageSrc
    : $baseOrigin . $imageSrc;

$ldJson = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $product['name'],
    'image' => $imageAbsolute,
    'description' => $product['description'],
    'brand' => ['@type' => 'Brand', 'name' => 'Zellie Fleurs'],
    'offers' => [
        '@type' => 'Offer',
        'price' => number_format((float) $product['price'], 2, '.', ''),
        'priceCurrency' => 'PHP',
        'availability' => $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'itemCondition' => 'https://schema.org/NewCondition',
    ],
];

$pageTitle = $product['name'] . ' | ' . $settings['shop_name'];
$activeNav = 'shop';
$ogType = 'product';
$ogImage = $imageAbsolute;
require ZF_ROOT . '/src/views/store_header.php';
?>

<main id="main" class="product-page">
    <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="/index.php#shop">Shop</a>
            <span aria-hidden="true">/</span>
            <a href="/index.php?category=<?= rawurlencode((string) $product['category']) ?>#shop"><?= zf_e($product['category']) ?></a>
            <span aria-hidden="true">/</span>
            <span aria-current="page"><?= zf_e($product['name']) ?></span>
        </nav>

        <article class="product-detail">
            <figure class="product-detail__figure">
                <img src="<?= zf_e($imageSrc) ?>"
                     alt="<?= zf_e($product['name']) ?>"
                     width="900" height="1125"
                     onerror="this.onerror=null;this.src='/assets/img/flower-placeholder.svg';">
                <?php if (!$isAvailable): ?>
                    <span class="product-badge product-badge--out">Sold out</span>
                <?php elseif ($stock <= 3): ?>
                    <span class="product-badge product-badge--low">Only <?= $stock ?> left</span>
                <?php else: ?>
                    <span class="product-badge">Fresh today</span>
                <?php endif; ?>
            </figure>

            <div class="product-detail__info">
                <span class="eyebrow"><?= zf_e($product['category']) ?></span>
                <h1><?= zf_e($product['name']) ?></h1>

                <div class="product-detail__price-row">
                    <span class="product-price"><?= zf_money($product['price']) ?></span>
                    <span class="availability <?= zf_e($availability['class']) ?>"><?= zf_e($availability['label']) ?></span>
                </div>

                <p class="product-detail__desc"><?= zf_e($product['description']) ?></p>

                <div class="product-detail__actions">
                    <div class="qty-stepper" data-max-qty="<?= $maxQty ?>">
                        <button type="button" data-qty-dec aria-label="Decrease quantity" <?= $isAvailable ? '' : 'disabled' ?>>−</button>
                        <label class="sr-only" for="product-qty">Quantity</label>
                        <input id="product-qty" type="number" data-qty-input value="1" min="1" max="<?= $maxQty ?>" inputmode="numeric" <?= $isAvailable ? '' : 'disabled' ?>>
                        <button type="button" data-qty-inc aria-label="Increase quantity" <?= $isAvailable ? '' : 'disabled' ?>>+</button>
                    </div>
                    <button class="btn btn--add" type="button"
                            data-add-to-cart
                            data-product-id="<?= (int) $product['id'] ?>"
                            data-product-name="<?= zf_e($product['name']) ?>"
                            data-product-stock="<?= $stock ?>"
                            <?= $isAvailable ? '' : 'disabled' ?>>
                        <?= $isAvailable ? 'Add to cart' : 'Sold out' ?>
                    </button>
                </div>

                <aside class="product-notes">
                    <div class="product-note">
                        <span class="eyebrow">Delivery</span>
                        <p><?= zf_e($settings['delivery_info']) ?></p>
                        <p class="muted">Every arrangement is finished by hand and delivered with a handwritten note.</p>
                    </div>
                    <div class="product-note product-note--gcash">
                        <div class="gcash-box__head">
                            <span class="gcash-mark">GCash</span>
                            <strong>GCash transfer</strong>
                        </div>
                        <p>Prefer to settle by GCash? Send your payment to <strong><?= zf_e($settings['gcash_number']) ?></strong> (<?= zf_e($settings['gcash_name']) ?>) after placing your order — we confirm every order before dispatch.</p>
                    </div>
                </aside>
            </div>
        </article>

        <?php if ($related !== []): ?>
            <section class="related-products">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">More to love</span>
                        <h2>Related arrangements</h2>
                    </div>
                    <p>More from the <?= zf_e($product['category']) ?> collection, composed in the same spirit.</p>
                </div>

                <div class="product-grid related-grid">
                    <?php foreach ($related as $item):
                        $itemAvailability = zf_product_availability($item);
                    ?>
                        <article class="product-card product-card--compact">
                            <a class="product-card__link" href="/product.php?id=<?= (int) $item['id'] ?>" aria-label="View <?= zf_e($item['name']) ?> details">
                                <div class="product-card__image">
                                    <img src="<?= zf_e(zf_image_url($item['image_url'])) ?>"
                                         alt="<?= zf_e($item['name']) ?>"
                                         loading="lazy"
                                         onerror="this.onerror=null;this.src='/assets/img/flower-placeholder.svg';">
                                </div>
                            </a>
                            <div class="product-card__body">
                                <span class="product-card__category"><?= zf_e($item['category']) ?></span>
                                <h3><a class="product-card__name" href="/product.php?id=<?= (int) $item['id'] ?>"><?= zf_e($item['name']) ?></a></h3>
                                <div class="product-card__foot">
                                    <div>
                                        <span class="product-price"><?= zf_money($item['price']) ?></span>
                                        <span class="availability <?= zf_e($itemAvailability['class']) ?>"><?= zf_e($itemAvailability['label']) ?></span>
                                    </div>
                                    <a class="product-card__go" href="/product.php?id=<?= (int) $item['id'] ?>">View →</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>

<script type="application/ld+json"><?= json_encode($ldJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php require ZF_ROOT . '/src/views/store_footer.php'; ?>
