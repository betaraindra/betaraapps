<?php
require_once 'config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

// === PRODUCT SEARCH ===
if ($action === 'search_product') {
    $keyword = $_GET['q'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM products WHERE sku LIKE ? OR name LIKE ? LIMIT 10");
    $stmt->execute(["%$keyword%", "%$keyword%"]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// === GET PRODUCT BY SKU (SCANNER) ===
if ($action === 'get_product_by_sku') {
    $sku = $_GET['sku'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM products WHERE sku = ?");
    $stmt->execute([$sku]);
    $product = $stmt->fetch();
    echo json_encode($product ?: ['error' => 'Not found']);
    exit;
}

// === GET NEXT TRANSACTION NUMBER ===
if ($action === 'get_next_trx_number') {
    $type = $_GET['type'] ?? 'IN'; // IN or OUT
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE type = ?");
    $stmt->execute([$type]);
    $count = $stmt->fetchColumn();
    $next = $count + 1;
    echo json_encode(['next_no' => $next]);
    exit;
}

// === GENERATE RANDOM SKU (AUTO BARCODE) ===
if ($action === 'generate_sku') {
    $found = true;
    $sku = '';
    // Loop sampai nemu yang belum ada di DB
    while($found) {
        // Format: 899 + 9 digit random (Format umum Indonesia)
        $sku = '899' . str_pad(mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->execute([$sku]);
        if($stmt->rowCount() == 0) {
            $found = false;
        }
    }
    echo json_encode(['sku' => $sku]);
    exit;
}
?>