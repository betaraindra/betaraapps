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

// === GET AVAILABLE SERIAL NUMBERS (ARRAY STRING) ===
// Digunakan di dropdown Select2 untuk transaksi (Hanya Available)
if ($action === 'get_available_sns') {
    $prod_id = $_GET['product_id'] ?? 0;
    $wh_id = $_GET['warehouse_id'] ?? 0;
    
    if (empty($prod_id) || empty($wh_id)) {
        echo json_encode([]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT serial_number FROM product_serials 
                           WHERE product_id = ? AND warehouse_id = ? AND status = 'AVAILABLE' 
                           ORDER BY serial_number ASC");
    $stmt->execute([$prod_id, $wh_id]);
    $sns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode($sns);
    exit;
}

// === GET MANAGEABLE SERIAL NUMBERS (ID & VALUE) ===
// Digunakan untuk Edit SN di Data Barang
// UPDATE: Supports 'show_all' parameter
if ($action === 'get_manageable_sns') {
    $prod_id = $_GET['product_id'] ?? 0;
    $show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
    
    if (empty($prod_id)) {
        echo json_encode([]);
        exit;
    }

    if ($show_all) {
        // Tampilkan semua (Termasuk Sold)
        $stmt = $pdo->prepare("SELECT id, serial_number, warehouse_id, status, out_transaction_id FROM product_serials 
                               WHERE product_id = ? 
                               ORDER BY status ASC, created_at DESC"); // Available first
        $stmt->execute([$prod_id]);
    } else {
        // Default: Hanya Available
        $stmt = $pdo->prepare("SELECT id, serial_number, warehouse_id, status, out_transaction_id FROM product_serials 
                               WHERE product_id = ? AND status = 'AVAILABLE' 
                               ORDER BY created_at DESC");
        $stmt->execute([$prod_id]);
    }
    
    $data = $stmt->fetchAll();
    
    // Fetch warehouse names for better context
    $result = [];
    foreach($data as $row) {
        $wh_name = '-';
        if ($row['warehouse_id']) {
            $wh_name = $pdo->query("SELECT name FROM warehouses WHERE id = {$row['warehouse_id']}")->fetchColumn() ?: '-';
        }
        
        // Jika SOLD, mungkin ada info tambahan
        $extra_info = '';
        if ($row['status'] == 'SOLD' && $row['out_transaction_id']) {
            $trx_ref = $pdo->query("SELECT reference FROM inventory_transactions WHERE id = {$row['out_transaction_id']}")->fetchColumn();
            if ($trx_ref) $extra_info = " (Ref: $trx_ref)";
        }

        $result[] = [
            'id' => $row['id'],
            'sn' => $row['serial_number'],
            'wh_name' => $wh_name . $extra_info,
            'status' => $row['status']
        ];
    }
    
    echo json_encode($result);
    exit;
}

// === GET WAREHOUSE STOCK ===
if ($action === 'get_warehouse_stock') {
    $wh_id = $_GET['warehouse_id'] ?? 0;
    if (empty($wh_id)) { echo json_encode([]); exit; }

    $sql = "SELECT p.id, p.sku, p.name, p.unit,
            (COALESCE(SUM(CASE WHEN t.type = 'IN' THEN t.quantity ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' THEN t.quantity ELSE 0 END), 0)) as current_qty
            FROM products p
            JOIN inventory_transactions t ON p.id = t.product_id
            WHERE t.warehouse_id = ?
            GROUP BY p.id
            HAVING current_qty > 0
            ORDER BY p.name ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$wh_id]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// === GET PRODUCT BY SKU (AUTO DETECT WAREHOUSE) ===
if ($action === 'get_product_by_sku') {
    $code = trim($_GET['sku'] ?? '');
    
    // 1. Cek apakah ini Serial Number Unik? (Hanya Available)
    $stmtSn = $pdo->prepare("
        SELECT ps.product_id, ps.serial_number, ps.warehouse_id, 
               p.sku, p.name, p.unit, p.stock as global_stock, p.sell_price, p.buy_price, p.has_serial_number
        FROM product_serials ps
        JOIN products p ON ps.product_id = p.id
        WHERE ps.serial_number = ? AND ps.status = 'AVAILABLE'
        LIMIT 1
    ");
    $stmtSn->execute([$code]);
    $snData = $stmtSn->fetch();

    if ($snData) {
        // MATCH BY SN
        $response = [
            'id' => $snData['product_id'],
            'sku' => $snData['sku'],
            'name' => $snData['name'],
            'unit' => $snData['unit'],
            'stock' => $snData['global_stock'],
            'sell_price' => $snData['sell_price'],
            'buy_price' => $snData['buy_price'],
            'has_serial_number' => $snData['has_serial_number'],
            'detected_warehouse_id' => $snData['detected_warehouse_id'] ?? $snData['warehouse_id'], // Fix variable name if needed, but original was just warehouse_id
            'scanned_sn' => $snData['serial_number'] // Auto Select This SN
        ];
        echo json_encode($response);
        exit;
    }

    // 2. Jika bukan SN, Cek apakah SKU Produk?
    $stmtProd = $pdo->prepare("SELECT * FROM products WHERE sku = ?");
    $stmtProd->execute([$code]);
    $prodData = $stmtProd->fetch();

    if ($prodData) {
        // MATCH BY SKU
        // Cari Gudang dengan stok terbanyak untuk barang ini sebagai default
        $stmtWh = $pdo->prepare("
            SELECT t.warehouse_id, 
                   (SUM(CASE WHEN t.type='IN' THEN t.quantity ELSE 0 END) - SUM(CASE WHEN t.type='OUT' THEN t.quantity ELSE 0 END)) as qty
            FROM inventory_transactions t
            WHERE t.product_id = ?
            GROUP BY t.warehouse_id
            ORDER BY qty DESC
            LIMIT 1
        ");
        $stmtWh->execute([$prodData['id']]);
        $whData = $stmtWh->fetch();
        
        $prodData['detected_warehouse_id'] = $whData ? $whData['warehouse_id'] : null;
        
        echo json_encode($prodData);
        exit;
    }

    echo json_encode(['error' => 'Barang tidak ditemukan']);
    exit;
}

// === CHECK SN DUPLICATE ===
if ($action === 'check_sn_duplicate') {
    $sn = $_GET['sn'] ?? '';
    $prod_id = $_GET['product_id'] ?? 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_serials WHERE serial_number = ? AND product_id = ?");
    $stmt->execute([$sn, $prod_id]);
    echo json_encode(['exists' => $stmt->fetchColumn() > 0]);
    exit;
}

// === GET NEXT TRX NUMBER ===
if ($action === 'get_next_trx_number') {
    $type = $_GET['type'] ?? 'IN';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE type = ?");
    $stmt->execute([$type]);
    echo json_encode(['next_no' => $stmt->fetchColumn() + 1]);
    exit;
}

// === GENERATE SKU ===
if ($action === 'generate_sku') {
    $found = true; $sku = ''; $attempts = 0;
    while($found && $attempts < 100) {
        $sku = '899' . str_pad((string)mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->execute([$sku]);
        if($stmt->rowCount() == 0) $found = false;
        $attempts++;
    }
    echo json_encode(['sku' => $sku]);
    exit;
}

// === GET SN BY STATUS (FOR MODAL VIEW) ===
if ($action === 'get_sn_by_status') {
    $prod_id = $_GET['product_id'] ?? 0;
    $wh_id = $_GET['warehouse_id'] ?? 0; // 0 = All Warehouses
    $status_type = $_GET['status'] ?? ''; // READY, USED, DAMAGED

    if (empty($prod_id) || empty($status_type)) {
        echo json_encode([]);
        exit;
    }

    $db_status = '';
    switch ($status_type) {
        case 'READY': $db_status = 'AVAILABLE'; break;
        case 'USED': $db_status = 'SOLD'; break;
        case 'DAMAGED': $db_status = 'DEFECTIVE'; break;
        default: echo json_encode([]); exit;
    }

    $sql = "SELECT ps.serial_number, w.name as wh_name, ps.created_at, ps.updated_at, it.reference, it.notes
            FROM product_serials ps
            LEFT JOIN warehouses w ON ps.warehouse_id = w.id
            LEFT JOIN inventory_transactions it ON ps.out_transaction_id = it.id
            WHERE ps.product_id = ? AND ps.status = ?";
    
    $params = [$prod_id, $db_status];

    if (!empty($wh_id)) {
        $sql .= " AND ps.warehouse_id = ?";
        $params[] = $wh_id;
    }

    $sql .= " ORDER BY ps.updated_at DESC LIMIT 500"; // Limit to prevent overload

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($data);
    exit;
}
?>