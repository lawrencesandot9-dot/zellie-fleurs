<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once ZF_ROOT . '/src/products.php';

$settings = zf_settings();
$search = zf_safe_text($_GET['q'] ?? '');
$category = zf_safe_text($_GET['category'] ?? '');

$products = zf_get_products($db, [
    'search' => $search,
    'category' => $category,
    'active_only' => true,
]);
$categories = zf_get_categories($db, true);
$allProducts = zf_get_products($db, ['active_only' => true]);

$collectionImages = [];
foreach ($allProducts as $product) {
    $cat = (string) $product['category'];
    if (!isset($collectionImages[$cat])) {
        $collectionImages[$cat] = (string) $product['image_url'];
    }
}

$pageTitle = $settings['shop_name'] . ' | ' . $settings['tagline'];
$activeNav = 'shop';
require ZF_ROOT . '/src/views/store_header.php';
?>

<main id="main">
    <section class="hero">
        <div class="container hero__grid">
            <div class="hero__copy">
                <span class="eyebrow">Floral atelier · <?= zf_e(date('Y')) ?></span>
                <h1>Flowers with a quiet kind of luxury.</h1>
                <p class="hero__lede">Thoughtfully gathered blooms, sculptural keepsake boxes and seasonal arrangements — made by hand for the moments that matter.</p>
                <div class="hero__actions">
                    <a class="btn btn--primary" href="#shop">Explore the collection</a>
                    <a class="btn btn--ghost" href="#collections">View collections</a>
                </div>
                <div class="hero__notes">
                    <span>Same-day city delivery</span>
                    <span>Freshly arranged daily</span>
                    <span>Handwritten gift cards</span>
                </div>
            </div>
            <div class="hero__visual">
                <div class="hero__image-wrap">
                    <img src="/assets/img/hero-florals.jpg" alt="Soft blush and ivory floral arrangement by Zellie Fleurs" width="1600" height="2133">
                </div>
                <div class="hero__card">
                    <span class="hero__card-label">Signature note</span>
                    <p>Blush roses, eucalyptus &amp; a silk ribbon.</p>
                </div>
                <div class="hero__stamp" aria-hidden="true">
                    <span>ZF</span>
                    <small>made by hand</small>
                </div>
            </div>
        </div>
    </section>

    <section class="section section--collections" id="collections">
        <div class="container">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Curated for you</span>
                    <h2>Featured collections</h2>
                </div>
                <p>Each collection is composed with the freshest stems of the season and finished by hand in our studio.</p>
            </div>

            <div class="collection-grid">
                <?php foreach ($categories as $index => $cat):
                    $image = $collectionImages[$cat] ?? 'assets/img/flower-placeholder.svg';
                ?>
                    <a class="collection-card collection-card--<?= ($index % 3) + 1 ?>" href="/index.php?category=<?= rawurlencode($cat) ?>#shop">
                        <img src="<?= zf_e(zf_image_url($image)) ?>" alt="<?= zf_e($cat) ?> collection" loading="lazy">
                        <span class="collection-card__shade"></span>
                        <span class="collection-card__text">
                            <span class="eyebrow">Collection</span>
                            <strong><?= zf_e($cat) ?></strong>
                            <span>Discover <?= count(array_filter($allProducts, static fn ($p) => $p['category'] === $cat)) ?> arrangements →</span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="section section--catalog" id="shop">
        <div class="container">
            <div class="catalog-head">
                <div>
                    <span class="eyebrow">The flower shop</span>
                    <h2>Our floral collection</h2>
                    <p class="catalog-intro">Choose a ready-to-send arrangement, or ask us to tailor something personal.</p>
                </div>

                <form class="catalog-search" method="get" action="/index.php#shop" role="search">
                    <?php if ($category !== ''): ?>
                        <input type="hidden" name="category" value="<?= zf_e($category) ?>">
                    <?php endif; ?>
                    <label class="sr-only" for="catalog-q">Search arrangements</label>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input id="catalog-q" type="search" name="q" value="<?= zf_e($search) ?>" placeholder="Search roses, tulips, boxes…">
                    <button type="submit">Search</button>
                </form>
            </div>

            <div class="filter-row" aria-label="Filter by category">
                <a class="filter-pill <?= $category === '' ? 'is-active' : '' ?>" href="/index.php<?= $search !== '' ? '?q=' . rawurlencode($search) : '' ?>#shop">All arrangements</a>
                <?php foreach ($categories as $cat): ?>
                    <a class="filter-pill <?= $category === $cat ? 'is-active' : '' ?>" href="/index.php?category=<?= rawurlencode($cat) ?><?= $search !== '' ? '&q=' . rawurlencode($search) : '' ?>#shop"><?= zf_e($cat) ?></a>
                <?php endforeach; ?>
            </div>

            <div class="catalog-meta">
                <span><?= count($products) ?> <?= count($products) === 1 ? 'arrangement' : 'arrangements' ?></span>
                <?php if ($search !== '' || $category !== ''): ?>
                    <a href="/index.php#shop">Clear filters</a>
                <?php endif; ?>
            </div>

            <?php if ($products === []): ?>
                <div class="empty-state empty-state--wide">
                    <img src="/assets/img/logo-mark.svg" alt="" width="52" height="52">
                    <h3>No arrangements found</h3>
                    <p>Try a different search, or browse the full collection.</p>
                    <a class="btn btn--soft" href="/index.php#shop">View everything</a>
                </div>
            <?php else: ?>
                <div class="product-grid">
                    <?php foreach ($products as $product):
                        $availability = zf_product_availability($product);
                        $isAvailable = $availability['available'];
                    ?>
                        <article class="product-card">
                            <div class="product-card__image">
                                <img src="<?= zf_e(zf_image_url($product['image_url'])) ?>"
                                     alt="<?= zf_e($product['name']) ?>"
                                     loading="lazy"
                                     onerror="this.onerror=null;this.src='/assets/img/flower-placeholder.svg';">
                                <?php if (!$isAvailable): ?>
                                    <span class="product-badge product-badge--out">Sold out</span>
                                <?php elseif ((int) $product['stock_quantity'] <= 3): ?>
                                    <span class="product-badge product-badge--low">Only <?= (int) $product['stock_quantity'] ?> left</span>
                                <?php else: ?>
                                    <span class="product-badge">Fresh today</span>
                                <?php endif; ?>
                            </div>
                            <div class="product-card__body">
                                <span class="product-card__category"><?= zf_e($product['category']) ?></span>
                                <h3><?= zf_e($product['name']) ?></h3>
                                <p><?= zf_e($product['description']) ?></p>
                                <div class="product-card__foot">
                                    <div>
                                        <span class="product-price"><?= zf_money($product['price']) ?></span>
                                        <span class="availability <?= zf_e($availability['class']) ?>"><?= zf_e($availability['label']) ?></span>
                                    </div>
                                    <button class="btn btn--add" type="button"
                                            data-add-to-cart
                                            data-product-id="<?= (int) $product['id'] ?>"
                                            data-product-name="<?= zf_e($product['name']) ?>"
                                            data-product-stock="<?= (int) $product['stock_quantity'] ?>"
                                            <?= $isAvailable ? '' : 'disabled' ?>>
                                        <?= $isAvailable ? 'Add to cart' : 'Sold out' ?>
                                    </button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section section--story" id="about">
        <div class="container story-grid">
            <div class="story-copy">
                <span class="eyebrow">Our story</span>
                <h2>Composed slowly, delivered beautifully.</h2>
                <p><?= zf_e($settings['shop_name']) ?> is a small floral studio built around a simple belief: flowers should feel personal. We work with seasonal blooms, soft colour and natural texture to create arrangements that feel at home in real life.</p>
                <p>Every order is prepared by hand, wrapped with care and delivered with a handwritten note. No mass-produced bouquets — just thoughtful floristry.</p>
                <div class="story-stats">
                    <div><strong>Hand-tied</strong><span>Every stem selected</span></div>
                    <div><strong>Same-day</strong><span>City delivery available</span></div>
                    <div><strong>Personal</strong><span>Gift notes included</span></div>
                </div>
            </div>
            <div class="story-aside">
                <div class="story-quote">
                    <span class="story-quote__mark">“</span>
                    <p>Flowers are the quietest way to say the biggest things.</p>
                    <span>— The Zellie Fleurs studio</span>
                </div>
                <div class="story-detail">
                    <span class="eyebrow">Delivery note</span>
                    <p><?= zf_e($settings['delivery_info']) ?></p>
                </div>
            </div>
        </div>
    </section>

    <section class="section section--contact" id="contact">
        <div class="container">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Visit the studio</span>
                    <h2>Contact &amp; details</h2>
                </div>
                <p>Questions about a bouquet, a delivery or a custom arrangement? We are happy to help.</p>
            </div>

            <div class="contact-grid">
                <div class="contact-card contact-card--feature">
                    <span class="contact-card__icon">✦</span>
                    <h3>Studio</h3>
                    <p><?= zf_e($settings['store_address']) ?></p>
                    <p class="muted"><?= zf_e($settings['operating_hours']) ?></p>
                </div>
                <div class="contact-card">
                    <span class="contact-card__icon">☎</span>
                    <h3>Contact</h3>
                    <p><?= zf_e($settings['contact_number']) ?></p>
                    <p class="muted">For order changes and delivery questions.</p>
                </div>
                <div class="contact-card">
                    <span class="contact-card__icon">◈</span>
                    <h3>Payments</h3>
                    <p>GCash transfer or cash on delivery.</p>
                    <p class="muted">GCash: <?= zf_e($settings['gcash_name']) ?> · <?= zf_e($settings['gcash_number']) ?></p>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require ZF_ROOT . '/src/views/store_footer.php'; ?>
