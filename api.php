<?php
require_once 'config.php';

// Pastikan output selalu JSON
header('Content-Type: application/json');

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

// === GET PRODUCT BY SKU OR SERIAL NUMBER ===
if ($action === 'get_product_by_sku') {
    $code = $_GET['sku'] ?? '';
    
    // 1. Cek Apakah ini SKU Produk?
    $stmt = $pdo->prepare("SELECT * FROM products WHERE sku = ?");
    $stmt->execute([$code]);
    $product = $stmt->fetch();

    $found_sn = null;

    // 2. Jika bukan SKU, Cek Apakah ini Serial Number? (Hanya untuk yang statusnya AVAILABLE)
    if (!$product) {
        $stmtSn = $pdo->prepare("
            SELECT p.*, ps.serial_number as scanned_sn 
            FROM product_serials ps
            JOIN products p ON ps.product_id = p.id
            WHERE ps.serial_number = ? AND ps.status = 'AVAILABLE'
            LIMIT 1
        ");
        $stmtSn->execute([$code]);
        $product = $stmtSn->fetch();
        
        if ($product) {
            $found_sn = $product['scanned_sn'];
        }
    }

    if ($product) {
        // Cari Gudang Terakhir berdasarkan barang masuk terakhir
        $stmtWh = $pdo->prepare("SELECT warehouse_id FROM inventory_transactions 
                                 WHERE product_id = ? AND type = 'IN' 
                                 ORDER BY date DESC, created_at DESC LIMIT 1");
        $stmtWh->execute([$product['id']]);
        $wh_id = $stmtWh->fetchColumn();
        
        // Tambahkan ke response
        $product['last_warehouse_id'] = $wh_id;
        
        // Jika ditemukan via SN, kirim SN nya kembali
        if ($found_sn) {
            $product['scanned_sn'] = $found_sn;
        }
    }

    echo json_encode($product ?: ['error' => 'Not found']);
    exit;
}

// === CHECK SERIAL NUMBER AVAILABILITY (FOR INBOUND) ===
if ($action === 'check_sn_duplicate') {
    $sn = $_GET['sn'] ?? '';
    $prod_id = $_GET['product_id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_serials WHERE serial_number = ? AND product_id = ?");
    $stmt->execute([$sn, $prod_id]);
    $exists = $stmt->fetchColumn() > 0;
    
    echo json_encode(['exists' => $exists]);
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
    try {
        $found = true;
        $sku = '';
        $attempts = 0;
        $max_attempts = 100; // Mencegah infinite loop (Error 500 timeout)

        // Loop sampai nemu yang belum ada di DB atau limit tercapai
        while($found && $attempts < $max_attempts) {
            // Format: 899 + 9 digit random (Format umum Indonesia)
            $rand = mt_rand(0, 999999999);
            $sku = '899' . str_pad((string)$rand, 9, '0', STR_PAD_LEFT);
            
            $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
            $stmt->execute([$sku]);
            
            if($stmt->rowCount() == 0) {
                $found = false; // Unik, keluar loop
            }
            $attempts++;
        }

        if ($found) {
            throw new Exception("Gagal membuat SKU unik. Silakan coba lagi.");
        }

        echo json_encode(['sku' => $sku]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>