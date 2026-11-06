<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once ZF_ROOT . '/src/products.php';

try {
    $products = zf_get_products($db, ['active_only' => true]);
    $payload = [];

    foreach ($products as $product) {
        $availability = zf_product_availability($product);
        $payload[] = [
            'id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'category' => (string) $product['category'],
            'price' => round((float) $product['price'], 2),
            'description' => (string) $product['description'],
            'image_url' => zf_image_url((string) $product['image_url']),
            'stock_quantity' => (int) $product['stock_quantity'],
            'available' => $availability['available'],
            'availability_label' => $availability['label'],
        ];
    }

    zf_json_response(['ok' => true, 'products' => $payload]);
} catch (Throwable $e) {
    error_log('[Zellie Fleurs] Products API failed: ' . $e->getMessage());
    zf_json_response(['ok' => false, 'products' => [], 'error' => 'Products are temporarily unavailable.'], 500);
}
