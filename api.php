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

// === DASHBOARD STATS ===
if ($action === 'get_dashboard_stats') {
    // Finance Stats
    $stmt = $pdo->query("SELECT 
        SUM(CASE WHEN type='INCOME' THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN type='EXPENSE' THEN amount ELSE 0 END) as total_expense
        FROM finance_transactions");
    $fin = $stmt->fetch();
    
    // Low Stock
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE stock < 10");
    $lowStock = $stmt->fetch()['count'];

    // Chart Data (Last 7 Days Transaction)
    $stmt = $pdo->query("SELECT DATE(date) as t_date, type, SUM(amount) as total 
                        FROM finance_transactions 
                        WHERE date >= DATE(NOW()) - INTERVAL 7 DAY 
                        GROUP BY t_date, type 
                        ORDER BY t_date");
    $chartData = $stmt->fetchAll();

    echo json_encode([
        'income' => $fin['total_income'] ?? 0,
        'expense' => $fin['total_expense'] ?? 0,
        'low_stock' => $lowStock,
        'chart' => $chartData
    ]);
    exit;
}

// === GET NEXT TRANSACTION NUMBER (FOR AUTO REFERENCE) ===
if ($action === 'get_next_trx_number') {
    $type = $_GET['type'] ?? 'IN'; // IN or OUT
    
    // Hitung jumlah transaksi tipe tersebut hari ini untuk nomor urut
    // Atau hitung total transaksi tipe tersebut + 1
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE type = ?");
    $stmt->execute([$type]);
    $count = $stmt->fetchColumn();
    
    $next = $count + 1;
    echo json_encode(['next_no' => $next]);
    exit;
}
?>