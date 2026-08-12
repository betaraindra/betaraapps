<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$search = $_GET['q'] ?? '';

// Set headers for Excel download
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=Laporan_Transaksi_Gudang_" . date('Ymd') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

$warehouses = $pdo->query("SELECT id, name FROM warehouses ORDER BY name ASC")->fetchAll();

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

// We get the transaction data up to the $end date to show the stock status AS OF that period.
$sql_trx = "SELECT product_id, warehouse_id, 
            SUM(CASE WHEN type='IN' THEN quantity ELSE 0 END) - SUM(CASE WHEN type='OUT' THEN quantity ELSE 0 END) as ready_mutation,
            SUM(CASE WHEN type='OUT' AND (notes LIKE 'Aktivitas:%' OR notes LIKE '%[PEMAKAIAN]%') THEN quantity ELSE 0 END) as used_mutation,
            SUM(CASE WHEN type='OUT' AND notes LIKE 'Rusak:%' THEN quantity ELSE 0 END) as damaged_mutation,
            SUM(CASE WHEN type='OUT' AND notes LIKE 'Hilang:%' THEN quantity ELSE 0 END) as missing_mutation
            FROM inventory_transactions 
            WHERE date <= ?
            GROUP BY product_id, warehouse_id";
$stmt_trx = $pdo->prepare($sql_trx);
$stmt_trx->execute([$end]);
$trx_data = [];
while($row = $stmt_trx->fetch()) {
    $trx_data[$row['product_id']][$row['warehouse_id']] = $row;
}
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
            $row_total = 0;
        ?>
            <tr>
                <td style="mso-number-format:'\@';"><?= htmlspecialchars($p['sku']) ?></td>
                <td><?= htmlspecialchars($p['name']) ?></td>
                <td class="text-center"><?= htmlspecialchars($p['unit']) ?></td>
                <?php foreach($warehouses as $wh): ?>
                    <?php 
                        $ready = 0; $used = 0; $damaged = 0; $missing = 0;
                        if (isset($trx_data[$p['id']][$wh['id']])) {
                            $d = $trx_data[$p['id']][$wh['id']];
                            $ready = $d['ready_mutation'];
                            $used = $d['used_mutation'];
                            $damaged = $d['damaged_mutation'];
                            $missing = $d['missing_mutation'];
                        }
                        $row_total += $ready;
                        
                        $wh_totals[$wh['id']]['ready'] += $ready;
                        $wh_totals[$wh['id']]['used'] += $used;
                        $wh_totals[$wh['id']]['damaged'] += $damaged;
                        $wh_totals[$wh['id']]['missing'] += $missing;
                    ?>
                    <td class="text-center"><?= $ready ?: '' ?></td>
                    <td class="text-center"><?= $used ?: '' ?></td>
                    <td class="text-center"><?= $damaged ?: '' ?></td>
                    <td class="text-center"><?= $missing ?: '' ?></td>
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
