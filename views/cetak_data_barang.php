<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

$search = $_GET['q'] ?? '';
$cat_filter = $_GET['category'] ?? 'ALL';
$sort_by = $_GET['sort'] ?? 'newest';
$stock_filter = $_GET['stock'] ?? 'ALL';

$sql = "SELECT p.* FROM products p 
        WHERE (
            p.name LIKE ? 
            OR p.sku LIKE ? 
            OR p.notes LIKE ? 
            OR EXISTS (
                SELECT 1 FROM product_serials ps 
                WHERE ps.product_id = p.id 
                AND ps.serial_number LIKE ?
            )
        )";
$params = ["%$search%", "%$search%", "%$search%", "%$search%"];

if ($cat_filter !== 'ALL') {
    $sql .= " AND p.category = ?";
    $params[] = $cat_filter;
}

if ($stock_filter === 'available') {
    $sql .= " AND p.stock > 0";
} elseif ($stock_filter === 'empty') {
    $sql .= " AND p.stock = 0";
} elseif ($stock_filter === 'low') {
    $sql .= " AND p.stock < 10";
}

if ($sort_by === 'stock_low_10') {
    $sql .= " AND p.stock BETWEEN 0 AND 10";
}

if ($sort_by === 'name_asc') $sql .= " ORDER BY p.name ASC";
elseif ($sort_by === 'stock_high') $sql .= " ORDER BY p.stock DESC";
elseif ($sort_by === 'stock_low' || $sort_by === 'stock_low_10') $sql .= " ORDER BY p.stock ASC";
else $sql .= " ORDER BY p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Settings
$settings_query = $pdo->query("SELECT * FROM settings");
$config = [];
while ($row = $settings_query->fetch()) $config[$row['setting_key']] = $row['setting_value'];

$app_name = $config['app_name'] ?? 'SIKI APP';
$company_name = $config['company_name'] ?? 'PT. SIKI GLOBAL';
$company_logo = $config['company_logo'] ?? '';

$period_filter = $_GET['period'] ?? date('Y-m');
$period_label = date('F Y', strtotime($period_filter . '-01'));
$months = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$period_label = $months[(int)date('m', strtotime($period_filter . '-01'))] . ' ' . date('Y', strtotime($period_filter . '-01'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Data Barang - <?= htmlspecialchars($app_name) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header img { max-height: 80px; display: block; margin: 0 auto 10px auto; object-fit: contain; }
        .header h1 { margin: 0; font-size: 18px; text-transform: uppercase; }
        .header h2 { margin: 3px 0; font-size: 14px; color: #555; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table, th, td { border: 1px solid #000; }
        th { background-color: #f0f0f0; padding: 8px; font-size: 12px; }
        td { padding: 6px 8px; font-size: 11px; vertical-align: top; }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        
        .filter-info { margin-bottom: 10px; font-size: 11px; }
        
        @media print {
            body { padding: 0; margin: 1cm; }
            @page { size: landscape; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="header">
        <?php if(!empty($company_logo)): ?>
            <img src="<?= $company_logo ?>" alt="Logo">
        <?php endif; ?>
        <h1><?= htmlspecialchars($company_name) ?></h1>
        <h2>LAPORAN STOK BARANG</h2>
    </div>

    <div class="filter-info">
        <b>Filter:</b> 
        <?= $search ? "Pencarian: " . htmlspecialchars($search) . " | " : "" ?>
        Kategori: <?= htmlspecialchars($cat_filter) ?> | 
        Status Stok: <?= htmlspecialchars($stock_filter) ?> | 
        Periode: <?= htmlspecialchars($period_label) ?> | 
        Tgl Cetak: <?= date('d/m/Y H:i') ?>
    </div>

    <table>
        <thead>
            <tr>
                <th width="3%">No</th>
                <th width="15%">SKU / Kode</th>
                <th width="25%">Nama Barang</th>
                <th width="12%">Kategori</th>
                <th width="8%">Satuan</th>
                <th width="15%">Info Harga</th>
                <th width="8%">Stok Total</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1; 
            $total_all_asset = 0;
            foreach($products as $p): 
                $asset_val = $p['stock'] * $p['buy_price'];
                $total_all_asset += $asset_val;
            ?>
            <tr>
                <td class="text-center"><?= $no++ ?></td>
                <td>
                    <b><?= htmlspecialchars($p['sku']) ?></b>
                    <?php if($p['has_serial_number'] == 1): ?>
                        <br><span style="font-size:9px; color:#555;">[SN AKTIF]</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($p['name']) ?></td>
                <td class="text-center"><?= htmlspecialchars($p['category']) ?></td>
                <td class="text-center"><?= htmlspecialchars($p['unit']) ?></td>
                <td>
                    Beli: Rp<?= number_format($p['buy_price'],0,',','.') ?><br>
                    Jual: Rp<?= number_format($p['sell_price'],0,',','.') ?>
                </td>
                <td class="text-center text-bold" style="font-size:14px;"><?= number_format($p['stock'],0,',','.') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 20px; font-size: 11px;">
        <table style="width: auto; border: none;">
            <tr>
                <td style="border: none; padding-right: 20px;">
                    Dibuat Oleh,<br><br><br><br>
                    ( <?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?> )
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
