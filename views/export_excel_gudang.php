<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$search = $_GET['q'] ?? '';
$warehouse_id = $_GET['warehouse_id'] ?? 'ALL';

// Set headers for Excel download
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=Laporan_Transaksi_Gudang_" . date('Ymd') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

if ($warehouse_id !== 'ALL' && !empty($warehouse_id)) {
    $stmt_wh = $pdo->prepare("SELECT id, name FROM warehouses WHERE id = ? ORDER BY name ASC");
    $stmt_wh->execute([$warehouse_id]);
    $warehouses = $stmt_wh->fetchAll();
} else {
    $warehouses = $pdo->query("SELECT id, name FROM warehouses ORDER BY name ASC")->fetchAll();
}

$sql_prod = "SELECT * FROM products";
$params_prod = [];
if (!empty($search)) {
    $sql_prod .= " WHERE name LIKE ? OR sku LIKE ?";
    $params_prod[] = "%$search%";
    $params_prod[] = "%$search%";
}
$sql_prod .= " ORDER BY name ASC";
$stmt_prod = $pdo->prepare($sql_prod);
$stmt_prod->execute($params_prod);
$products = $stmt_prod->fetchAll();

// --- 1. GET ALL-TIME REAL DATA (Non-Serialized) ---
$sql_real_trx = "SELECT product_id, warehouse_id, 
            SUM(CASE WHEN type='IN' THEN quantity ELSE 0 END) - SUM(CASE WHEN type='OUT' THEN quantity ELSE 0 END) as ready_qty,
            SUM(CASE WHEN type='OUT' AND (notes LIKE 'Aktivitas:%' OR notes LIKE '%[PEMAKAIAN]%') THEN quantity ELSE 0 END) as used_qty,
            SUM(CASE WHEN type='OUT' AND notes LIKE 'Rusak:%' THEN quantity ELSE 0 END) as damaged_qty,
            SUM(CASE WHEN type='OUT' AND notes LIKE 'Hilang:%' THEN quantity ELSE 0 END) as missing_qty
            FROM inventory_transactions 
            GROUP BY product_id, warehouse_id";
$stmt_real_trx = $pdo->query($sql_real_trx);
$real_trx = [];
while($row = $stmt_real_trx->fetch()) {
    $real_trx[$row['product_id']][$row['warehouse_id']] = $row;
}

// --- 2. GET ALL-TIME REAL DATA (Serialized) ---
$sql_real_sn = "SELECT product_id, warehouse_id,
            SUM(CASE WHEN status='AVAILABLE' THEN 1 ELSE 0 END) as ready_qty,
            SUM(CASE WHEN status='SOLD' THEN 1 ELSE 0 END) as used_qty,
            SUM(CASE WHEN status='DEFECTIVE' THEN 1 ELSE 0 END) as damaged_qty,
            SUM(CASE WHEN status='MISSING' THEN 1 ELSE 0 END) as missing_qty
            FROM product_serials
            GROUP BY product_id, warehouse_id";
$stmt_real_sn = $pdo->query($sql_real_sn);
$real_sn = [];
while($row = $stmt_real_sn->fetch()) {
    $real_sn[$row['product_id']][$row['warehouse_id']] = $row;
}

// --- 3. IDENTIFY PRODUCTS WITH ACTIVITY IN SELECTED PERIOD ---
$sql_active = "SELECT DISTINCT product_id 
               FROM inventory_transactions 
               WHERE date BETWEEN ? AND ?";
$params_active = [$start, $end];
if ($warehouse_id !== 'ALL') {
    $sql_active .= " AND warehouse_id = ?";
    $params_active[] = $warehouse_id;
}
$stmt_active = $pdo->prepare($sql_active);
$stmt_active->execute($params_active);
$active_products = array_flip($stmt_active->fetchAll(PDO::FETCH_COLUMN));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 5px; font-size: 11px; font-family: Arial, sans-serif; }
        .text-center { text-align: center; }
        .font-bold { font-weight: bold; }
        .title { font-size: 16px; font-weight: bold; text-align: center; border: none !important; }
        .subtitle { text-align: center; border: none !important; }
        .empty-row td { border: none !important; }
    </style>
</head>
<body>
    <table>
        <tr>
            <th colspan="<?= 3 + (count($warehouses) * 4) + 1 ?>" class="title">Laporan Transaksi Gudang</th>
        </tr>
        <tr>
            <th colspan="<?= 3 + (count($warehouses) * 4) + 1 ?>" class="subtitle">Periode <?= date('d/m/Y', strtotime($start)) ?> - <?= date('d/m/Y', strtotime($end)) ?></th>
        </tr>
        <tr class="empty-row">
            <td colspan="<?= 3 + (count($warehouses) * 4) + 1 ?>"></td>
        </tr>
        <tr>
            <th rowspan="2" class="text-center" style="vertical-align: middle;">SKU</th>
            <th rowspan="2" class="text-center" style="vertical-align: middle;">Nama Barang</th>
            <th rowspan="2" class="text-center" style="vertical-align: middle;">Satuan</th>
            <?php foreach($warehouses as $wh): ?>
                <th colspan="4" class="text-center"><?= htmlspecialchars($wh['name']) ?></th>
            <?php endforeach; ?>
            <th rowspan="2" class="text-center" style="vertical-align: middle;">Total</th>
        </tr>
        <tr>
            <?php foreach($warehouses as $wh): ?>
                <th class="text-center">Stok Ready</th>
                <th class="text-center">Terpakai</th>
                <th class="text-center">Rusak</th>
                <th class="text-center">Hilang</th>
            <?php endforeach; ?>
        </tr>
        
        <?php 
        $grand_total = 0;
        $wh_totals = [];
        foreach($warehouses as $wh) {
            $wh_totals[$wh['id']] = ['ready' => 0, 'used' => 0, 'damaged' => 0, 'missing' => 0];
        }

        foreach($products as $p): 
            $has_data = false;
            $row_ready = 0;
            $row_activity_in_period = isset($active_products[$p['id']]);

            // Calculate values first
            $wh_data = [];
            foreach($warehouses as $wh) {
                $ready = 0; $used = 0; $damaged = 0; $missing = 0;
                
                if ($p['has_serial_number'] == 1 && isset($real_sn[$p['id']][$wh['id']])) {
                    $d = $real_sn[$p['id']][$wh['id']];
                    $ready = max(0, (int)$d['ready_qty']);
                    $used = max(0, (int)$d['used_qty']);
                    $damaged = max(0, (int)$d['damaged_qty']);
                    $missing = max(0, (int)$d['missing_qty']);
                } elseif ($p['has_serial_number'] == 0 && isset($real_trx[$p['id']][$wh['id']])) {
                    $d = $real_trx[$p['id']][$wh['id']];
                    $ready = max(0, (int)$d['ready_qty']);
                    $used = max(0, (int)$d['used_qty']);
                    $damaged = max(0, (int)$d['damaged_qty']);
                    $missing = max(0, (int)$d['missing_qty']);
                }
                
                $wh_data[$wh['id']] = [
                    'ready' => $ready, 'used' => $used, 'damaged' => $damaged, 'missing' => $missing
                ];
                
                $row_ready += $ready;
                // If it has ANY historical data in selected warehouses, we MIGHT consider it, 
                // but let's strictly stick to the logic below
            }

            // Only show if it has ANY Ready Qty OR had activity in period (as requested)
            if ($row_ready > 0 || $row_activity_in_period) {
                $has_data = true;
            }

            if (!$has_data) continue;

            $row_total = 0;
        ?>
            <tr>
                <td style="mso-number-format:'\@';"><?= htmlspecialchars($p['sku']) ?></td>
                <td><?= htmlspecialchars($p['name']) ?></td>
                <td class="text-center"><?= htmlspecialchars($p['unit']) ?></td>
                <?php foreach($warehouses as $wh): ?>
                    <?php 
                        $ready = $wh_data[$wh['id']]['ready'];
                        $used = $wh_data[$wh['id']]['used'];
                        $damaged = $wh_data[$wh['id']]['damaged'];
                        $missing = $wh_data[$wh['id']]['missing'];

                        $row_total += $ready;
                        
                        $wh_totals[$wh['id']]['ready'] += $ready;
                        $wh_totals[$wh['id']]['used'] += $used;
                        $wh_totals[$wh['id']]['damaged'] += $damaged;
                        $wh_totals[$wh['id']]['missing'] += $missing;
                    ?>
                    <td class="text-center"><?= $ready ?></td>
                    <td class="text-center"><?= $used ?></td>
                    <td class="text-center"><?= $damaged ?></td>
                    <td class="text-center"><?= $missing ?></td>
                <?php endforeach; ?>
                <td class="text-center font-bold"><?= $row_total ?></td>
                <?php $grand_total += $row_total; ?>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="3" class="text-center font-bold">Total Per Wilayah</td>
            <?php foreach($warehouses as $wh): ?>
                <td class="text-center font-bold"><?= $wh_totals[$wh['id']]['ready'] ?></td>
                <td class="text-center font-bold"><?= $wh_totals[$wh['id']]['used'] ?></td>
                <td class="text-center font-bold"><?= $wh_totals[$wh['id']]['damaged'] ?></td>
                <td class="text-center font-bold"><?= $wh_totals[$wh['id']]['missing'] ?></td>
            <?php endforeach; ?>
            <td class="text-center font-bold"><?= $grand_total ?></td>
        </tr>
        <tr class="empty-row">
            <td colspan="<?= 3 + (count($warehouses) * 4) + 1 ?>"></td>
        </tr>
        <tr class="empty-row">
            <td colspan="3">Mengetahui,</td>
            <td colspan="<?= (count($warehouses) * 4) - 2 ?>"></td>
        </tr>
    </table>
</body>
</html>
