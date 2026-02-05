<?php
require_once 'config.php';

// Pastikan output selalu JSON
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// --- SELF-HEALING: AUTO MIGRATION CHECK ---
try {
    $pdo->query("SELECT has_serial_number FROM products LIMIT 0");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN has_serial_number TINYINT(1) DEFAULT 0");
    } catch (Exception $ex) { }
}

try {
    $pdo->query("SELECT 1 FROM product_serials LIMIT 0");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_serials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            serial_number VARCHAR(100) NOT NULL,
            status ENUM('AVAILABLE','SOLD','RETURNED','DEFECTIVE') DEFAULT 'AVAILABLE',
            warehouse_id INT DEFAULT NULL,
            in_transaction_id INT DEFAULT NULL,
            out_transaction_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_sn_product (product_id, serial_number),
            INDEX idx_sn (serial_number),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $ex) { }
}
// --- END SELF-HEALING ---

$action = $_GET['action'] ?? '';

// === GET AVAILABLE SERIAL NUMBERS ===
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

// === GET WAREHOUSE STOCK (EXISTING) ===
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

// === GET PRODUCT BY SKU (EXISTING) ===
if ($action === 'get_product_by_sku') {
    $code = $_GET['sku'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM products WHERE sku = ?");
    $stmt->execute([$code]);
    $product = $stmt->fetch();

    $found_sn = null;
    if (!$product) {
        $stmtSn = $pdo->prepare("
            SELECT p.*, ps.serial_number as scanned_sn, ps.warehouse_id as sn_wh_id
            FROM product_serials ps
            JOIN products p ON ps.product_id = p.id
            WHERE ps.serial_number = ? AND ps.status = 'AVAILABLE'
            LIMIT 1
        ");
        $stmtSn->execute([$code]);
        $product = $stmtSn->fetch();
        if ($product) $found_sn = $product['scanned_sn'];
    }

    if ($product) {
        $stmtWh = $pdo->prepare("SELECT warehouse_id FROM inventory_transactions WHERE product_id = ? AND type = 'IN' ORDER BY date DESC, created_at DESC LIMIT 1");
        $stmtWh->execute([$product['id']]);
        $product['last_warehouse_id'] = $stmtWh->fetchColumn();
        if ($found_sn) {
            $product['scanned_sn'] = $found_sn;
            $product['sn_warehouse_id'] = $product['sn_wh_id']; 
        }
    }
    echo json_encode($product ?: ['error' => 'Not found']);
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
?>