<?php
declare(strict_types=1);

/**
 * Product queries, validation, uploads and safe deletion.
 */

function zf_get_products(PDO $db, array $filters = []): array
{
    $activeOnly = $filters['active_only'] ?? true;
    $search = trim((string) ($filters['search'] ?? ''));
    $category = trim((string) ($filters['category'] ?? ''));

    $sql = 'SELECT * FROM products WHERE 1 = 1';
    $params = [];

    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    if ($search !== '') {
        $sql .= " AND (name LIKE :s1 ESCAPE '\\' OR description LIKE :s2 ESCAPE '\\' OR category LIKE :s3 ESCAPE '\\')";
        $term = zf_like_term($search);
        $params[':s1'] = $term;
        $params[':s2'] = $term;
        $params[':s3'] = $term;
    }
    if ($category !== '') {
        $sql .= ' AND category = :category';
        $params[':category'] = $category;
    }

    $sql .= ' ORDER BY is_active DESC, stock_quantity > 0 DESC, datetime(created_at) DESC, id DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function zf_get_product(PDO $db, int $id, bool $activeOnly = false): ?array
{
    $sql = 'SELECT * FROM products WHERE id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    return $product ?: null;
}

function zf_get_categories(PDO $db, bool $activeOnly = true): array
{
    $sql = 'SELECT DISTINCT category FROM products';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY category ASC';

    return $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
}

function zf_product_availability(array $product): array
{
    $stock = (int) ($product['stock_quantity'] ?? 0);
    $active = (int) ($product['is_active'] ?? 0) === 1;

    if (!$active) {
        return ['label' => 'Unavailable', 'class' => 'availability--out', 'available' => false];
    }
    if ($stock <= 0) {
        return ['label' => 'Sold out', 'class' => 'availability--out', 'available' => false];
    }
    if ($stock <= 3) {
        return ['label' => 'Only ' . $stock . ' left', 'class' => 'availability--low', 'available' => true];
    }

    return ['label' => 'In stock', 'class' => 'availability--in', 'available' => true];
}

function zf_validate_image_url(string $url): bool
{
    if ($url === '') {
        return true;
    }
    if (preg_match('#^https?://#i', $url) === 1) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    return preg_match('#^(assets/img|uploads)/[A-Za-z0-9._/\-]+$#', ltrim($url, '/')) === 1;
}

function zf_handle_image_upload(array $file, ?string &$error = null): ?string
{
    $error = null;

    if (!isset($file['error']) || is_array($file['error'])) {
        $error = 'The image upload was not valid.';
        return null;
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = zf_upload_error_message((int) $file['error']);
        return null;
    }
    if (($file['size'] ?? 0) <= 0 || (int) $file['size'] > 3 * 1024 * 1024) {
        $error = 'The image must be smaller than 3 MB.';
        return null;
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        $error = 'The uploaded image could not be read.';
        return null;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if (!is_string($mime) || !isset($allowed[$mime])) {
        $error = 'Only JPEG, PNG and WebP images are allowed.';
        return null;
    }

    $info = @getimagesize($tmp);
    if ($info === false || ($info[2] ?? 0) !== match ($mime) {
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
        default => -1,
    }) {
        $error = 'That file does not appear to be a valid image.';
        return null;
    }

    if (!is_dir(ZF_UPLOADS)) {
        mkdir(ZF_UPLOADS, 0775, true);
    }

    $filename = bin2hex(random_bytes(18)) . '.' . $allowed[$mime];
    $destination = ZF_UPLOADS . '/' . $filename;

    $moved = getenv('ZF_TESTING') === '1'
        ? rename($tmp, $destination)
        : move_uploaded_file($tmp, $destination);

    if (!$moved) {
        $error = 'The server could not save the image. Please try again.';
        return null;
    }

    @chmod($destination, 0644);

    return 'uploads/' . $filename;
}

function zf_save_product(PDO $db, array $input, ?array $file, ?int $id, array &$errors): ?int
{
    $errors = [];

    $name = zf_safe_text($input['name'] ?? '');
    $category = zf_safe_text($input['category'] ?? 'Bouquets');
    $description = zf_safe_text($input['description'] ?? '');
    $priceRaw = $input['price'] ?? '';
    $stockRaw = $input['stock_quantity'] ?? '';
    $imageUrl = zf_safe_text($input['image_url'] ?? '');
    $isActive = isset($input['is_active']) ? 1 : 0;

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $errors[] = 'The product name must be between 2 and 120 characters.';
    }
    if ($category === '' || mb_strlen($category) > 60) {
        $errors[] = 'Please provide a category under 60 characters.';
    }
    if (!is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 1000000) {
        $errors[] = 'Please enter a valid price between 0 and 1,000,000.';
    }
    if ($description === '' || mb_strlen($description) > 2000) {
        $errors[] = 'Please provide a description under 2,000 characters.';
    }
    if (!preg_match('/^\d+$/', (string) $stockRaw) || (int) $stockRaw < 0 || (int) $stockRaw > 100000) {
        $errors[] = 'Stock quantity must be a whole number between 0 and 100,000.';
    }
    if (!zf_validate_image_url($imageUrl)) {
        $errors[] = 'The image URL must be a secure web address or a local assets/uploads path.';
    }

    $existing = null;
    if ($id !== null) {
        $existing = zf_get_product($db, $id);
        if ($existing === null) {
            $errors[] = 'That product no longer exists.';
        }
    }

    $newImage = null;
    if ($file !== null) {
        $uploadError = null;
        $newImage = zf_handle_image_upload($file, $uploadError);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }
    }

    if ($errors !== []) {
        return null;
    }

    if ($newImage !== null) {
        $imageUrl = $newImage;
    } elseif ($imageUrl === '' && $existing !== null) {
        $imageUrl = (string) $existing['image_url'];
    } elseif ($imageUrl === '') {
        $imageUrl = 'assets/img/flower-placeholder.svg';
    }

    $price = round((float) $priceRaw, 2);
    $stock = (int) $stockRaw;

    try {
        if ($id === null) {
            $stmt = $db->prepare('
                INSERT INTO products (name, category, price, description, image_url, stock_quantity, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$name, $category, $price, $description, $imageUrl, $stock, $isActive]);

            return (int) $db->lastInsertId();
        }

        $stmt = $db->prepare('
            UPDATE products
            SET name = ?, category = ?, price = ?, description = ?, image_url = ?,
                stock_quantity = ?, is_active = ?, updated_at = datetime(\'now\', \'localtime\')
            WHERE id = ?
        ');
        $stmt->execute([$name, $category, $price, $description, $imageUrl, $stock, $isActive, $id]);

        return $id;
    } catch (Throwable $e) {
        error_log('[Zellie Fleurs] Product save failed: ' . $e->getMessage());
        $errors[] = 'We could not save the product. Please try again.';
        return null;
    }
}

function zf_product_referenced(PDO $db, int $id): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM orders WHERE items_json LIKE ? OR items_json LIKE ?');
    $stmt->execute(['%"product_id":' . $id . ',%', '%"product_id":' . $id . '}%']);

    return (int) $stmt->fetchColumn() > 0;
}

function zf_delete_product(PDO $db, int $id): string
{
    if (zf_product_referenced($db, $id)) {
        $stmt = $db->prepare('UPDATE products SET is_active = 0, updated_at = datetime(\'now\', \'localtime\') WHERE id = ?');
        $stmt->execute([$id]);

        return 'deactivated';
    }

    $stmt = $db->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$id]);

    return 'deleted';
}

function zf_toggle_product(PDO $db, int $id): bool
{
    $stmt = $db->prepare('
        UPDATE products
        SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END,
            updated_at = datetime(\'now\', \'localtime\')
        WHERE id = ?
    ');
    $stmt->execute([$id]);

    return $stmt->rowCount() === 1;
}
