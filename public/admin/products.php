<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once ZF_ROOT . '/src/auth.php';
require_once ZF_ROOT . '/src/products.php';

zf_require_admin();

$errors = [];
$editProduct = null;
$form = [
    'id' => 0,
    'name' => '',
    'category' => 'Bouquets',
    'price' => '',
    'description' => '',
    'image_url' => '',
    'stock_quantity' => '',
    'is_active' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    zf_require_csrf();
    $action = zf_safe_text($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = zf_safe_int($_POST['id'] ?? 0);
        $result = zf_delete_product($db, $id);
        zf_flash('success', $result === 'deactivated'
            ? 'This product is referenced by an order, so it was deactivated instead of deleted.'
            : 'Product deleted.');
        zf_redirect('/admin/products.php');
    }

    if ($action === 'toggle') {
        $id = zf_safe_int($_POST['id'] ?? 0);
        if (zf_toggle_product($db, $id)) {
            zf_flash('success', 'Product availability updated.');
        } else {
            zf_flash('error', 'That product could not be found.');
        }
        zf_redirect('/admin/products.php');
    }

    if ($action === 'add' || $action === 'update') {
        $id = $action === 'update' ? zf_safe_int($_POST['id'] ?? 0) : null;
        $file = $_FILES['image'] ?? null;
        $savedId = zf_save_product($db, $_POST, is_array($file) ? $file : null, $id, $errors);

        if ($savedId !== null) {
            zf_flash('success', $action === 'add' ? 'New product added to the storefront.' : 'Product updated.');
            zf_redirect('/admin/products.php');
        }

        $form = [
            'id' => $id ?? 0,
            'name' => zf_safe_text($_POST['name'] ?? ''),
            'category' => zf_safe_text($_POST['category'] ?? 'Bouquets'),
            'price' => zf_safe_text($_POST['price'] ?? ''),
            'description' => zf_safe_text($_POST['description'] ?? ''),
            'image_url' => zf_safe_text($_POST['image_url'] ?? ''),
            'stock_quantity' => zf_safe_text($_POST['stock_quantity'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($id !== null) {
            $editProduct = zf_get_product($db, $id);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $editProduct = zf_get_product($db, zf_safe_int($_GET['edit'] ?? 0));
    if ($editProduct === null) {
        zf_flash('error', 'That product could not be found.');
        zf_redirect('/admin/products.php');
    }
    $form = [
        'id' => (int) $editProduct['id'],
        'name' => (string) $editProduct['name'],
        'category' => (string) $editProduct['category'],
        'price' => (string) $editProduct['price'],
        'description' => (string) $editProduct['description'],
        'image_url' => (string) $editProduct['image_url'],
        'stock_quantity' => (string) $editProduct['stock_quantity'],
        'is_active' => (int) $editProduct['is_active'],
    ];
}

$page = zf_safe_int($_GET['page'] ?? 1, 1);
$productsMeta = zf_products_page($db, $page, false);
$products = $productsMeta['rows'];
$categories = zf_get_categories($db, false);
$isEditing = $form['id'] > 0;
$previewImage = $form['image_url'] !== '' ? zf_image_url($form['image_url']) : '/assets/img/flower-placeholder.svg';

$pageTitle = 'Products';
$activeAdmin = 'products';
require ZF_ROOT . '/src/views/admin_header.php';
?>

<?php if ($errors !== []): ?>
    <div class="flash flash--error" role="alert">
        <strong>Please review the product:</strong>
        <ul class="error-list">
            <?php foreach ($errors as $error): ?>
                <li><?= zf_e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="admin-products-layout">
    <section class="panel product-form-panel">
        <div class="admin-section__head">
            <div>
                <span class="eyebrow"><?= $isEditing ? 'Editing arrangement' : 'New arrangement' ?></span>
                <h2><?= $isEditing ? 'Edit product' : 'Add a product' ?></h2>
            </div>
            <?php if ($isEditing): ?>
                <a class="btn btn--ghost btn--small" href="/admin/products.php">Cancel edit</a>
            <?php endif; ?>
        </div>

        <form method="post" action="/admin/products.php" enctype="multipart/form-data" class="product-form">
            <?= zf_csrf_field() ?>
            <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'add' ?>">
            <?php if ($isEditing): ?>
                <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <?php endif; ?>

            <div class="product-preview">
                <img src="<?= zf_e($previewImage) ?>" alt="" data-image-preview onerror="this.onerror=null;this.src='/assets/img/flower-placeholder.svg';">
                <div>
                    <span class="eyebrow">Image preview</span>
                    <p class="muted">Use a bright, softly styled photograph. JPEG, PNG or WebP up to 3 MB.</p>
                </div>
            </div>

            <div class="field">
                <label for="name">Product name</label>
                <input id="name" type="text" name="name" value="<?= zf_e($form['name']) ?>" required maxlength="120">
            </div>

            <div class="field-grid">
                <div class="field">
                    <label for="category">Category</label>
                    <input id="category" type="text" name="category" value="<?= zf_e($form['category']) ?>" list="category-options" required maxlength="60">
                    <datalist id="category-options">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= zf_e($category) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="field">
                    <label for="price">Price (₱)</label>
                    <input id="price" type="number" name="price" value="<?= zf_e($form['price']) ?>" min="0" max="1000000" step="0.01" required>
                </div>
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4" required maxlength="2000"><?= zf_e($form['description']) ?></textarea>
            </div>

            <div class="field-grid">
                <div class="field">
                    <label for="stock_quantity">Stock quantity</label>
                    <input id="stock_quantity" type="number" name="stock_quantity" value="<?= zf_e($form['stock_quantity']) ?>" min="0" max="100000" step="1" required>
                </div>
                <div class="field">
                    <label for="image">Upload image</label>
                    <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" data-image-input>
                </div>
            </div>

            <div class="field">
                <label for="image_url">Or image URL / local path</label>
                <input id="image_url" type="text" name="image_url" value="<?= zf_e($form['image_url']) ?>" placeholder="https://… or assets/img/products/example.jpg">
                <small>Leave blank to keep the current image. A new upload always takes precedence.</small>
            </div>

            <label class="checkbox-field">
                <input type="checkbox" name="is_active" value="1" <?= (int) $form['is_active'] === 1 ? 'checked' : '' ?>>
                <span>Visible and available on the storefront</span>
            </label>

            <button class="btn btn--primary btn--full" type="submit"><?= $isEditing ? 'Save changes' : 'Add product' ?></button>
        </form>
    </section>

    <section class="panel product-list-panel">
        <div class="admin-section__head">
            <div>
                <span class="eyebrow">Storefront catalogue</span>
                <h2><?= number_format((int) $productsMeta['total']) ?> <?= $productsMeta['total'] === 1 ? 'product' : 'products' ?></h2>
            </div>
        </div>

        <?php if ($products === []): ?>
            <div class="empty-state">
                <h3>No products yet</h3>
                <p>Add your first arrangement using the form.</p>
            </div>
        <?php else: ?>
            <div class="admin-product-list">
                <?php foreach ($products as $product): $availability = zf_product_availability($product); ?>
                    <article class="admin-product <?= (int) $product['is_active'] === 1 ? '' : 'is-inactive' ?>">
                        <img src="<?= zf_e(zf_image_url($product['image_url'])) ?>" alt="" width="74" height="88" loading="lazy" onerror="this.onerror=null;this.src='/assets/img/flower-placeholder.svg';">
                        <div class="admin-product__body">
                            <div class="admin-product__top">
                                <span class="product-card__category"><?= zf_e($product['category']) ?></span>
                                <span class="status <?= (int) $product['is_active'] === 1 ? 'status--delivered' : 'status--cancelled' ?>"><?= (int) $product['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                            </div>
                            <h3><?= zf_e($product['name']) ?></h3>
                            <p class="muted"><?= zf_e($product['description']) ?></p>
                            <div class="admin-product__meta">
                                <strong><?= zf_money($product['price']) ?></strong>
                                <span class="availability <?= zf_e($availability['class']) ?>"><?= zf_e($availability['label']) ?></span>
                                <span class="muted">Stock: <?= (int) $product['stock_quantity'] ?></span>
                            </div>
                        </div>
                        <div class="admin-product__actions">
                            <a class="btn btn--soft btn--small" href="/admin/products.php?edit=<?= (int) $product['id'] ?>">Edit</a>
                            <form method="post" action="/admin/products.php">
                                <?= zf_csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                <button class="btn btn--ghost btn--small" type="submit"><?= (int) $product['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                            <form method="post" action="/admin/products.php" data-confirm="Delete this product? If it appears in an existing order it will be deactivated instead.">
                                <?= zf_csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                <button class="btn btn--danger btn--small" type="submit">Delete</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php zf_render_pagination(
                $productsMeta['page'],
                $productsMeta['pages'],
                $productsMeta['total'],
                $productsMeta['page_size'],
                '/admin/products.php',
                [], // the catalogue has no user-controlled filters to preserve
                'product',
                'products'
            ); ?>
        <?php endif; ?>
    </section>
</div>

<?php require ZF_ROOT . '/src/views/admin_footer.php'; ?>
