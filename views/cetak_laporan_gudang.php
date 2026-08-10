<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$warehouse_filter = $_GET['warehouse_id'] ?? 'ALL';
$type_filter = $_GET['type'] ?? 'ALL';
$search_query = $_GET['q'] ?? '';

// --- CONFIG & HEADER DATA ---
$config_query = $pdo->query("SELECT * FROM settings");
$config = [];
while ($row = $config_query->fetch()) $config[$row['setting_key']] = $row['setting_value'];

$app_name = $config['app_name'] ?? 'SIKI APP';
$company_name = $config['company_name'] ?? 'PT. SIKI GLOBAL';
$company_logo = $config['company_logo'] ?? '';

// --- GET WAREHOUSES FOR FILTER ---
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

$selected_wh_name = "Semua Gudang";
if ($warehouse_filter !== 'ALL') {
    foreach($warehouses as $w) {
        if ($w['id'] == $warehouse_filter) {
            $selected_wh_name = $w['name'];
            break;
        }
    }
}

// --- PREPARE QUERY ---
$sql = "SELECT i.*, p.sku, p.name as prod_name, p.unit, p.buy_price, p.sell_price, p.image_url, p.has_serial_number, p.stock, w.name as wh_name,
               COALESCE(ps.ready, 0) as ready_count,
               COALESCE(ps.rusak, 0) as rusak_count,
               COALESCE(ps.terpakai, 0) as terpakai_count,
               COALESCE(ps.hilang, 0) as hilang_count
        FROM inventory_transactions i 
        JOIN products p ON i.product_id=p.id 
        JOIN warehouses w ON i.warehouse_id=w.id 
        LEFT JOIN (
            SELECT product_id, 
                   SUM(CASE WHEN status='AVAILABLE' THEN 1 ELSE 0 END) as ready,
                   SUM(CASE WHEN status='DEFECTIVE' THEN 1 ELSE 0 END) as rusak,
                   SUM(CASE WHEN status='SOLD' THEN 1 ELSE 0 END) as terpakai,
                   SUM(CASE WHEN status='MISSING' THEN 1 ELSE 0 END) as hilang
            FROM product_serials
            GROUP BY product_id
        ) ps ON p.id = ps.product_id
        WHERE i.date BETWEEN ? AND ?";
$params = [$start, $end];

if ($warehouse_filter !== 'ALL') {
    $sql .= " AND i.warehouse_id = ?";
    $params[] = $warehouse_filter;
}

if ($type_filter !== 'ALL') {
    if ($type_filter == 'RUSAK') {
        $sql .= " AND i.type = 'OUT' AND i.notes LIKE 'Rusak:%'";
    } elseif ($type_filter == 'PEMAKAIAN') {
        $sql .= " AND i.type = 'OUT' AND i.notes LIKE 'Aktivitas:%'";
    } elseif ($type_filter == 'HILANG') {
        $sql .= " AND i.type = 'OUT' AND i.notes LIKE 'Hilang:%'";
    } else {
        $sql .= " AND i.type = ?";
        $params[] = $type_filter;
    }
}

if (!empty($search_query)) {
    $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR i.reference LIKE ? OR i.notes LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$sql .= " ORDER BY i.date DESC, i.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Grouping Logic
$grouped_data = [];
foreach($transactions as $t) {
    $month_ts = strtotime($t['date']);
    $group_key = date('Y-m', $month_ts);
    
    $months = [1=>'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $period_label = $months[date('n', $month_ts)] . " " . date('Y', $month_ts);
    
    $grouped_data[$group_key]['label'] = $period_label;
    $grouped_data[$group_key]['items'][] = $t;
    
    if(!isset($grouped_data[$group_key]['total'])) {
        $grouped_data[$group_key]['total'] = 0;
        $grouped_data[$group_key]['total_in'] = 0;
        $grouped_data[$group_key]['total_out'] = 0;
    }
    $grouped_data[$group_key]['total'] += ($t['quantity'] * $t['buy_price']);
    
    if($t['type'] == 'IN') {
        $grouped_data[$group_key]['total_in'] += $t['quantity'];
    } else {
        $grouped_data[$group_key]['total_out'] += $t['quantity'];
    }
}

function formatRupiah($num) {
    return "Rp " . number_format($num, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Laporan Gudang - <?= htmlspecialchars($app_name) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header img { max-height: 80px; display: block; margin: 0 auto 10px auto; object-fit: contain; }
        .header h1 { margin: 0; font-size: 18px; text-transform: uppercase; }
        .header h2 { margin: 3px 0; font-size: 14px; color: #555; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table, th, td { border: 1px solid #000; }
        th { background-color: #f0f0f0; padding: 6px; font-size: 11px; }
        td { padding: 4px 6px; font-size: 10px; vertical-align: top; }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        
        .filter-info { margin-bottom: 10px; font-size: 11px; }
        
        .bg-red-300 { background-color: #fca5a5 !important; }
        .bg-yellow-400 { background-color: #facc15 !important; }
        
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
        <h2>LAPORAN TRANSAKSI GUDANG</h2>
    </div>

    <div class="filter-info">
        <b>Filter:</b> 
        <?= $search_query ? "Pencarian: " . htmlspecialchars($search_query) . " | " : "" ?>
        Lokasi: <?= htmlspecialchars($selected_wh_name) ?> | 
        Periode: <?= date('d/m/Y', strtotime($start)) ?> - <?= date('d/m/Y', strtotime($end)) ?> | 
        Tgl Cetak: <?= date('d/m/Y H:i') ?>
    </div>

    <table>
        <?php if(empty($grouped_data)): ?>
            <tr><td class="text-center" style="padding: 20px;">Tidak ada data transaksi sesuai filter.</td></tr>
        <?php else: ?>
            <?php foreach($grouped_data as $key => $group): ?>
                <tr class="bg-red-300">
                    <td colspan="4" class="text-bold" style="color: #7f1d1d; font-size: 11px;">
                        Periode <?= $group['label'] ?>
                    </td>
                    <td colspan="5" class="text-bold text-center" style="color: #7f1d1d; font-size: 11px;">
                        Barang Masuk: <?= number_format($group['total_in'], 0, ',', '.') ?> | Barang Keluar: <?= number_format($group['total_out'], 0, ',', '.') ?>
                    </td>
                    <td colspan="5" class="text-bold text-right" style="color: #7f1d1d; font-size: 11px;">
                        Total Nilai Material: <?= formatRupiah($group['total']) ?>
                    </td>
                </tr>
                <tr class="bg-yellow-400 text-center text-bold">
                    <th style="width: 40px;">Gbr</th>
                    <th>SKU (Barcode)</th>
                    <th>Tanggal</th>
                    <th>Reference</th>
                    <th>Nama Barang</th>
                    <th>Gudang</th>
                    <th>Qty</th>
                    <th>Sat</th>
                    <th>READY</th>
                    <th>RUSAK</th>
                    <th>TERPAKAI</th>
                    <th>HILANG</th>
                    <th>Ket</th>
                    <th>User</th>
                </tr>
                <?php foreach($group['items'] as $item): ?>
                <tr>
                    <td class="text-center">
                        <?php if($item['image_url']): ?>
                            <img src="<?= $item['image_url'] ?>" style="width: 24px; height: 24px; object-fit: cover;">
                        <?php endif; ?>
                    </td>
                    <td class="text-center" style="font-family: monospace;"><?= htmlspecialchars($item['sku']) ?></td>
                    <td class="text-center"><?= date('d/m/Y', strtotime($item['date'])) ?></td>
                    <td class="text-center text-bold uppercase" style="color: #1e40af;"><?= htmlspecialchars($item['reference']) ?></td>
                    <td class="text-bold"><?= htmlspecialchars($item['prod_name']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($item['wh_name']) ?></td>
                    <td class="text-center text-bold" style="color: <?= $item['type']=='IN' ? '#16a34a' : '#dc2626' ?>;">
                        <?= $item['type']=='IN' ? '+' : '-' ?><?= $item['quantity'] ?>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($item['unit']) ?></td>
                    <td class="text-center text-bold" style="color: #15803d;">
                        <?= $item['has_serial_number'] == 1 ? $item['ready_count'] : $item['stock'] ?>
                    </td>
                    <td class="text-center text-bold" style="color: #b91c1c;">
                        <?= $item['has_serial_number'] == 1 ? $item['rusak_count'] : '-' ?>
                    </td>
                    <td class="text-center text-bold" style="color: #1d4ed8;">
                        <?= $item['has_serial_number'] == 1 ? $item['terpakai_count'] : '-' ?>
                    </td>
                    <td class="text-center text-bold" style="color: #c2410c;">
                        <?= $item['has_serial_number'] == 1 ? $item['hilang_count'] : '-' ?>
                    </td>
                    <td>
                        <?php
                            $raw_note = $item['notes'];
                            $coords = '';
                            $photos_json = '';
                            if (preg_match('/\[COORDS:\s*(.*?)\]/', $raw_note, $matches)) {
                                $coords = $matches[1];
                                $raw_note = str_replace($matches[0], '', $raw_note);
                            }
                            if (preg_match('/\[PHOTOS:\s*(\[.*?\])\]/', $raw_note, $matches)) {
                                $photos_json = $matches[1];
                                $raw_note = str_replace($matches[0], '', $raw_note);
                            }
                            $clean_note = trim(str_replace(['Aktivitas:', '[PEMAKAIAN]'], '', $raw_note));
                        ?>
                        <div style="font-style: italic; color: #555;"><?= htmlspecialchars($clean_note) ?></div>
                        <?php if(!empty($coords)): ?>
                            <div style="font-size: 9px; color: #2563eb; margin-top: 2px;">📍 <?= h($coords) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($item['user_id']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
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
