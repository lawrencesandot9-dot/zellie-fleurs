<?php
declare(strict_types=1);

/**
 * Order creation, validation, status changes, tracking and reporting.
 */

// 12 alphabet characters × 5 bits = 60 bits of entropy (~1e18 combinations).
const ZF_ORDER_CODE_CHARS = 12;

function zf_generate_order_code(PDO $db): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    for ($attempt = 0; $attempt < 10; $attempt++) {
        // random_bytes(16) mapped with & 0x1F is unbiased because 256 % 32 == 0.
        $bytes = random_bytes(16);
        $code = 'ZF-';
        for ($i = 0; $i < ZF_ORDER_CODE_CHARS; $i++) {
            $code .= $alphabet[ord($bytes[$i]) & 0x1F];
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM orders WHERE order_code = ?');
        $stmt->execute([$code]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $code;
        }
    }

    // Astronomically unlikely fallback (ten consecutive collisions in a
    // 60-bit space); keep the same 'ZF-' + 12-char alphabet shape.
    $code = 'ZF-';
    for ($i = 0; $i < ZF_ORDER_CODE_CHARS; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $code;
}

function zf_normalize_order_items(mixed $rawItems, array &$errors): array
{
    if (!is_array($rawItems) || $rawItems === []) {
        $errors[] = 'Your cart is empty. Please add a floral arrangement before checking out.';
        return [];
    }

    $quantities = [];
    foreach ($rawItems as $raw) {
        if (!is_array($raw)) {
            $errors[] = 'One of the cart items is invalid.';
            continue;
        }

        $id = filter_var($raw['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $qty = filter_var($raw['qty'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);

        if ($id === false || $qty === false) {
            $errors[] = 'One of the cart items has an invalid product or quantity.';
            continue;
        }

        // Cap distinct line items so a hostile items_json cannot post an
        // oversized cart (quantities for the same product still merge).
        if (!isset($quantities[(int) $id]) && count($quantities) >= 20) {
            $errors[] = 'Orders are limited to 20 different arrangements. Please reduce or split your cart.';
            continue;
        }

        $quantities[(int) $id] = ($quantities[(int) $id] ?? 0) + (int) $qty;
        if ($quantities[(int) $id] > 99) {
            $errors[] = 'The maximum quantity per arrangement is 99.';
        }
    }

    return $quantities;
}

function zf_create_order(PDO $db, array $input, ?string $clientToken = null): array
{
    $errors = [];
    $fieldErrors = [];

    $customerName = zf_safe_text($input['customer_name'] ?? '');
    $contactNumber = zf_safe_text($input['contact_number'] ?? '');
    $deliveryAddress = zf_safe_text($input['delivery_address'] ?? '');
    $deliveryDate = zf_safe_text($input['delivery_date'] ?? '');
    $cardMessage = zf_safe_text($input['card_message'] ?? '');
    $paymentMethod = zf_safe_text($input['payment_method'] ?? '');
    $gcashRef = strtoupper(zf_safe_text($input['gcash_ref'] ?? ''));

    if (mb_strlen($customerName) < 2 || mb_strlen($customerName) > 100) {
        $errors[] = 'Please enter your full name (2–100 characters).';
        $fieldErrors['customer_name'] = true;
    }

    $digits = preg_replace('/\D/', '', $contactNumber) ?? '';
    if (!preg_match('/^[0-9+()\-\s]{7,20}$/', $contactNumber) || strlen($digits) < 7) {
        $errors[] = 'Please enter a valid contact number.';
        $fieldErrors['contact_number'] = true;
    }

    if (mb_strlen($deliveryAddress) < 10 || mb_strlen($deliveryAddress) > 500) {
        $errors[] = 'Please enter a complete delivery address (10–500 characters).';
        $fieldErrors['delivery_address'] = true;
    }

    $delivery = DateTimeImmutable::createFromFormat('!Y-m-d', $deliveryDate);
    $today = new DateTimeImmutable('today');
    if (!$delivery || $delivery->format('Y-m-d') !== $deliveryDate) {
        $errors[] = 'Please choose a valid delivery date.';
        $fieldErrors['delivery_date'] = true;
    } elseif ($delivery < $today) {
        $errors[] = 'The delivery date cannot be in the past.';
        $fieldErrors['delivery_date'] = true;
    } elseif ($delivery > $today->modify('+180 days')) {
        $errors[] = 'Delivery dates can be scheduled up to 180 days ahead.';
        $fieldErrors['delivery_date'] = true;
    }

    if (mb_strlen($cardMessage) > 300) {
        $errors[] = 'The gift card message must be 300 characters or fewer.';
        $fieldErrors['card_message'] = true;
    }

    if (!in_array($paymentMethod, ['GCash', 'COD'], true)) {
        $errors[] = 'Please choose a valid payment method.';
        $fieldErrors['payment_method'] = true;
    }

    if ($paymentMethod === 'GCash') {
        if (!preg_match('/^[A-Z0-9\-]{6,40}$/', $gcashRef)) {
            $errors[] = 'Please enter a valid GCash reference number (6–40 letters, numbers or dashes).';
            $fieldErrors['gcash_ref'] = true;
        }
    } else {
        $gcashRef = '';
    }

    $quantities = zf_normalize_order_items($input['items'] ?? [], $errors);

    $lineItems = [];
    $total = 0.0;

    if ($quantities !== []) {
        $ids = array_keys($quantities);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM products WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        $products = [];
        foreach ($stmt->fetchAll() as $product) {
            $products[(int) $product['id']] = $product;
        }

        foreach ($quantities as $id => $qty) {
            if (!isset($products[$id])) {
                $errors[] = 'One or more products are no longer available.';
                continue;
            }

            $product = $products[$id];
            if ((int) $product['is_active'] !== 1) {
                $errors[] = (string) $product['name'] . ' is no longer available.';
                continue;
            }
            if ((int) $product['stock_quantity'] < $qty) {
                $errors[] = 'Only ' . (int) $product['stock_quantity'] . ' of '
                    . (string) $product['name'] . ' remain in stock.';
                continue;
            }

            $price = round((float) $product['price'], 2);
            $subtotal = round($price * $qty, 2);
            $total = round($total + $subtotal, 2);
            $lineItems[] = [
                'product_id' => (int) $product['id'],
                'name' => (string) $product['name'],
                'price' => $price,
                'qty' => $qty,
                'subtotal' => $subtotal,
                'image_url' => (string) $product['image_url'],
            ];
        }
    }

    if ($total <= 0) {
        $errors[] = 'Your cart total is invalid. Please review your cart and try again.';
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'field_errors' => $fieldErrors];
    }

    if ($clientToken !== null && $clientToken !== '') {
        $existing = zf_get_order_by_client_token($db, $clientToken);
        if ($existing !== null) {
            return ['ok' => true, 'order' => $existing, 'duplicate' => true];
        }
    }

    $itemsJson = json_encode($lineItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $db->beginTransaction();

        $stockStmt = $db->prepare('
            UPDATE products
            SET stock_quantity = stock_quantity - ?, updated_at = datetime(\'now\', \'localtime\')
            WHERE id = ? AND is_active = 1 AND stock_quantity >= ?
        ');

        foreach ($lineItems as $item) {
            $stockStmt->execute([$item['qty'], $item['product_id'], $item['qty']]);
            if ($stockStmt->rowCount() !== 1) {
                throw new RuntimeException('Insufficient stock while placing the order.');
            }
        }

        $orderCode = zf_generate_order_code($db);
        $stmt = $db->prepare('
            INSERT INTO orders (
                order_code, client_token, customer_name, contact_number, delivery_address,
                delivery_date, card_message, payment_method, gcash_ref, total_amount,
                items_json, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending\')
        ');
        $stmt->execute([
            $orderCode,
            $clientToken !== '' ? $clientToken : null,
            $customerName,
            $contactNumber,
            $deliveryAddress,
            $deliveryDate,
            $cardMessage,
            $paymentMethod,
            $gcashRef,
            $total,
            $itemsJson,
        ]);

        $orderId = (int) $db->lastInsertId();
        $db->commit();

        $order = zf_get_order($db, $orderId);
        if ($order === null) {
            throw new RuntimeException('Order could not be reloaded.');
        }

        return ['ok' => true, 'order' => $order, 'duplicate' => false];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        if ($clientToken !== null && $clientToken !== '') {
            $existing = zf_get_order_by_client_token($db, $clientToken);
            if ($existing !== null) {
                return ['ok' => true, 'order' => $existing, 'duplicate' => true];
            }
        }

        error_log('[Zellie Fleurs] Order creation failed: ' . $e->getMessage());
        return [
            'ok' => false,
            'errors' => ['We could not place your order right now. Please try again in a moment.'],
            'field_errors' => [],
        ];
    }
}

function zf_get_order(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    return $order ?: null;
}

function zf_get_order_by_code(PDO $db, string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM orders WHERE order_code = ?');
    $stmt->execute([$code]);
    $order = $stmt->fetch();

    return $order ?: null;
}

function zf_get_order_by_client_token(PDO $db, string $token): ?array
{
    $stmt = $db->prepare('SELECT * FROM orders WHERE client_token = ?');
    $stmt->execute([$token]);
    $order = $stmt->fetch();

    return $order ?: null;
}

function zf_order_items(array $order): array
{
    $decoded = json_decode((string) ($order['items_json'] ?? '[]'), true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }
        $items[] = [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'name' => (string) ($item['name'] ?? 'Floral arrangement'),
            'price' => (float) ($item['price'] ?? 0),
            'qty' => max(1, (int) ($item['qty'] ?? 1)),
            'subtotal' => (float) ($item['subtotal'] ?? 0),
            'image_url' => (string) ($item['image_url'] ?? ''),
        ];
    }

    return $items;
}

function zf_update_order_status(PDO $db, int $orderId, string $newStatus, ?string &$error = null): bool
{
    $error = null;

    if (!in_array($newStatus, zf_order_statuses(), true)) {
        $error = 'That order status is not valid.';
        return false;
    }

    $order = zf_get_order($db, $orderId);
    if ($order === null) {
        $error = 'That order could not be found.';
        return false;
    }

    $oldStatus = (string) $order['status'];
    if ($oldStatus === $newStatus) {
        return true;
    }

    $items = zf_order_items($order);

    try {
        $db->beginTransaction();

        if ($newStatus === 'Cancelled' && $oldStatus !== 'Cancelled') {
            $restore = $db->prepare('
                UPDATE products
                SET stock_quantity = stock_quantity + ?, updated_at = datetime(\'now\', \'localtime\')
                WHERE id = ?
            ');
            foreach ($items as $item) {
                $restore->execute([$item['qty'], $item['product_id']]);
            }
        }

        if ($oldStatus === 'Cancelled' && $newStatus !== 'Cancelled') {
            $deduct = $db->prepare('
                UPDATE products
                SET stock_quantity = stock_quantity - ?, updated_at = datetime(\'now\', \'localtime\')
                WHERE id = ? AND stock_quantity >= ?
            ');
            foreach ($items as $item) {
                $deduct->execute([$item['qty'], $item['product_id'], $item['qty']]);
                if ($deduct->rowCount() !== 1) {
                    throw new RuntimeException('Not enough stock to reopen this order.');
                }
            }
        }

        $stmt = $db->prepare('
            UPDATE orders
            SET status = ?, updated_at = datetime(\'now\', \'localtime\')
            WHERE id = ?
        ');
        $stmt->execute([$newStatus, $orderId]);
        $db->commit();

        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[Zellie Fleurs] Status update failed: ' . $e->getMessage());
        $error = 'We could not update the order status. Please try again.';
        if ($e instanceof RuntimeException && !($e instanceof PDOException)) {
            $error = $e->getMessage();
        }
        return false;
    }
}

function zf_order_analytics(PDO $db): array
{
    $counts = array_fill_keys(zf_order_statuses(), 0);
    $rows = $db->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status')->fetchAll();

    foreach ($rows as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int) $row['total'];
        }
    }

    $totalOrders = array_sum($counts);
    $revenue = (float) $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status <> 'Cancelled'")->fetchColumn();
    $recent = $db->query('SELECT * FROM orders ORDER BY datetime(created_at) DESC, id DESC LIMIT 6')->fetchAll();

    return [
        'counts' => $counts,
        'total_orders' => $totalOrders,
        'revenue' => $revenue,
        'recent' => $recent,
    ];
}

function zf_search_orders(PDO $db, string $search = '', string $status = '', string $sort = 'newest'): array
{
    $sql = 'SELECT * FROM orders WHERE 1 = 1';
    $params = [];

    if ($search !== '') {
        $sql .= " AND (order_code LIKE :q1 ESCAPE '\\' OR customer_name LIKE :q2 ESCAPE '\\' OR contact_number LIKE :q3 ESCAPE '\\')";
        $term = zf_like_term($search);
        $params[':q1'] = $term;
        $params[':q2'] = $term;
        $params[':q3'] = $term;
    }

    if ($status !== '' && in_array($status, zf_order_statuses(), true)) {
        $sql .= ' AND status = :status';
        $params[':status'] = $status;
    }

    $sql .= $sort === 'oldest'
        ? ' ORDER BY datetime(created_at) ASC, id ASC'
        : ' ORDER BY datetime(created_at) DESC, id DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Anonymous order-lookup throttling (mirrors the login_attempts pattern in
 * src/auth.php). Only failed lookups are recorded, so a legitimate customer
 * who knows their reference can never lock themselves out by tracking it.
 */
const ZF_LOOKUP_MAX_ATTEMPTS = 10;
const ZF_LOOKUP_WINDOW_MINUTES = 15;

function zf_track_lookup_blocked(PDO $db, string $ip): bool
{
    try {
        // Opportunistic self-cleaning, same shape as auth.php login throttling.
        $db->exec("DELETE FROM lookup_attempts WHERE attempted_at < datetime('now', 'localtime', '-1 day')");

        $stmt = $db->prepare("
            SELECT COUNT(*) FROM lookup_attempts
            WHERE ip_address = ?
              AND attempted_at >= datetime('now', 'localtime', ?)
        ");
        $stmt->execute([$ip, '-' . ZF_LOOKUP_WINDOW_MINUTES . ' minutes']);

        return (int) $stmt->fetchColumn() >= ZF_LOOKUP_MAX_ATTEMPTS;
    } catch (Throwable $e) {
        // Fail open: a throttling infrastructure problem must never take the
        // tracking page down.
        error_log('[Zellie Fleurs] Lookup throttle check failed: ' . $e->getMessage());
        return false;
    }
}

function zf_record_lookup_failure(PDO $db, string $ip): void
{
    try {
        $stmt = $db->prepare('INSERT INTO lookup_attempts (ip_address) VALUES (?)');
        $stmt->execute([$ip]);
    } catch (Throwable $e) {
        error_log('[Zellie Fleurs] Lookup failure could not be recorded: ' . $e->getMessage());
    }
}
