<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

$id = $_GET['id'] ?? 0;

// Header Transaksi
$stmt = $pdo->prepare("SELECT i.reference, i.date, i.type, i.notes, 
                              w.name as wh_name, w.location as wh_loc, 
                              u.username 
                       FROM inventory_transactions i 
                       LEFT JOIN warehouses w ON i.warehouse_id = w.id
                       LEFT JOIN users u ON i.user_id = u.id
                       WHERE i.id = ?");
$stmt->execute([$id]);
$header = $stmt->fetch();

if (!$header) die("Data transaksi tidak ditemukan (ID: $id).");

// Items Transaksi
// UPDATE: Menambahkan p.category untuk detail material yang lebih lengkap
if (empty($header['reference']) || $header['reference'] == '-') {
    $sql_items = "SELECT i.*, p.sku, p.name as prod_name, p.category, p.unit, w.name as origin_warehouse 
                  FROM inventory_transactions i 
                  JOIN products p ON i.product_id = p.id 
                  LEFT JOIN warehouses w ON i.warehouse_id = w.id
                  WHERE i.id = ?";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute([$id]);
} else {
    $sql_items = "SELECT i.*, p.sku, p.name as prod_name, p.category, p.unit, w.name as origin_warehouse 
                  FROM inventory_transactions i 
                  JOIN products p ON i.product_id = p.id 
                  LEFT JOIN warehouses w ON i.warehouse_id = w.id
                  WHERE i.reference = ? AND i.type = ? AND i.date = ?";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute([$header['reference'], $header['type'], $header['date']]);
}

$items = $stmt_items->fetchAll();

// Config Perusahaan
$settings_query = $pdo->query("SELECT * FROM settings");
$config = [];
while ($row = $settings_query->fetch()) $config[$row['setting_key']] = $row['setting_value'];

$company = $config['company_name'] ?? 'NAMA PERUSAHAAN';
$address = $config['company_address'] ?? 'Alamat Perusahaan';
$logo = $config['company_logo'] ?? '';
$title = ($header['type'] == 'IN') ? 'BUKTI BARANG MASUK' : 'SURAT JALAN / BUKTI KELUAR';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak - <?= $header['reference'] ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bwip-js/3.4.4/bwip-js-min.js"></script>
    <style>
        @media print {
            @page { size: A4 portrait; margin: 0; }
            body { margin: 1cm; }
            .no-print { display: none !important; }
        }

        body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #000; margin: 0; padding: 20px; max-width: 210mm; margin: auto; display: flex; flex-direction: column; min-height: 95vh; }
        .w-full { width: 100%; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        .kop-table { width: 100%; margin-bottom: 10px; border-bottom: 4px double #000; padding-bottom: 10px; }
        .kop-logo { width: 120px; text-align: center; vertical-align: middle; }
        .kop-logo img { max-width: 100px; max-height: 90px; }
        .kop-info { text-align: center; vertical-align: middle; padding: 0 10px; }
        .kop-company { font-size: 24pt; font-weight: 900; text-transform: uppercase; margin-bottom: 5px; line-height: 1.1; letter-spacing: 1px; }
        .kop-addr { font-size: 11pt; line-height: 1.3; }
        .kop-npwp { font-size: 11pt; font-weight: bold; margin-top: 3px; }
        .kop-spacer { width: 120px; }
        .doc-title { text-align: center; font-size: 16px; font-weight: bold; text-decoration: underline; margin: 20px 0; text-transform: uppercase; }
        .info-table { width: 100%; margin-bottom: 15px; }
        .info-table td { padding: 3px 0; vertical-align: top; }
        .label-cell { width: 100px; font-weight: bold; }
        .val-cell { padding-left: 10px; }
        .data-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .data-table th, .data-table td { border: 1px solid #000; padding: 5px; vertical-align: middle; }
        .data-table th { background-color: #f0f0f0; text-align: center; font-weight: bold; }
        .footer-wrapper { margin-top: auto; padding-top: 20px; }
        .footer-note { font-size: 10px; font-style: italic; margin-bottom: 20px; }
        .signature-table { width: 100%; margin-top: 10px; margin-bottom: 20px; }
        .signature-table td { text-align: center; vertical-align: top; width: 33%; }
        .sig-box { height: 70px; }
        .sig-name { font-weight: bold; text-decoration: underline; }
        .barcode-footer { text-align: center; margin-top: 20px; padding-top: 10px; border-top: 1px dashed #ccc; }
        .no-print { background: #333; color: #fff; padding: 10px; text-align: center; margin-bottom: 20px; border-radius: 5px; }
        .btn-print { background: #fff; color: #000; border: none; padding: 5px 15px; cursor: pointer; font-weight: bold; border-radius: 3px; }
        canvas.barcode-canvas { max-width: 100%; height: auto; }
        .item-category { font-size: 10px; font-style: italic; color: #555; }
        .item-notes { font-size: 10px; margin-top: 2px; }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" class="btn-print">🖨️ Cetak Dokumen</button>
        <button onclick="window.close()" class="btn-print">❌ Tutup</button>
    </div>

    <div class="content-wrapper">
        <table class="kop-table">
            <tr>
                <td class="kop-logo">
                    <?php if(!empty($logo)): ?>
                        <img src="<?= $logo ?>">
                    <?php endif; ?>
                </td>
                <td class="kop-info">
                    <div class="kop-company"><?= $company ?></div>
                    <div class="kop-addr"><?= nl2br($address) ?></div>
                    <?php if(!empty($config['company_npwp'])): ?>
                        <div class="kop-npwp">NPWP: <?= $config['company_npwp'] ?></div>
                    <?php endif; ?>
                </td>
                <td class="kop-spacer"></td> 
            </tr>
        </table>

        <div class="doc-title"><?= $title ?></div>

        <table class="info-table">
            <tr>
                <td class="label-cell">No. Referensi</td>
                <td style="width: 10px;">:</td>
                <td class="val-cell text-bold"><?= $header['reference'] ?></td>
                <td class="label-cell" style="padding-left: 30px;">Tanggal</td>
                <td style="width: 10px;">:</td>
                <td class="val-cell"><?= date('d F Y', strtotime($header['date'])) ?></td>
            </tr>
            <tr>
                <td class="label-cell">Admin Input</td>
                <td>:</td>
                <td class="val-cell"><?= $header['username'] ?? 'System' ?></td>
                <td class="label-cell" style="padding-left: 30px;">Tipe</td>
                <td>:</td>
                <td class="val-cell"><?= $header['type'] == 'IN' ? 'Barang Masuk' : 'Barang Keluar' ?></td>
            </tr>
            <?php if($header['notes']): ?>
            <tr>
                <td class="label-cell">Keterangan</td>
                <td>:</td>
                <td class="val-cell" colspan="4"><?= $header['notes'] ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">No</th>
                    <th style="width: 120px;">SKU / Barcode</th>
                    <th>Detail Material / Barang</th>
                    <th style="width: 100px;">Gudang</th>
                    <th>Catatan Item</th>
                    <th style="width: 50px;">Jml</th>
                    <th style="width: 50px;">Unit</th>
                    <th style="width: 40px;">Cek</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1; 
                foreach($items as $item): 
                    $row_id = "bc_" . $no; 
                ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td class="text-center" style="padding: 4px;">
                        <canvas id="<?= $row_id ?>" class="barcode-canvas"></canvas>
                        <div style="font-size: 9px; margin-top:2px; font-family: monospace;"><?= $item['sku'] ?></div>
                    </td>
                    <td>
                        <div class="text-bold"><?= $item['prod_name'] ?></div>
                        <div class="item-category">Kategori: <?= $item['category'] ?></div>
                    </td>
                    <td class="text-center"><?= $item['origin_warehouse'] ?? '-' ?></td>
                    <td class="item-notes"><?= $item['notes'] ?? '-' ?></td>
                    <td class="text-center text-bold" style="font-size: 14px;"><?= $item['quantity'] ?></td>
                    <td class="text-center"><?= $item['unit'] ?></td>
                    <td class="text-center">▢</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="footer-wrapper">
        <div class="footer-note">
            * Harap diperiksa kembali spesifikasi dan jumlah barang.<br>
            * Dokumen ini sah jika disertai tanda tangan penanggung jawab.
        </div>

        <table class="signature-table">
            <tr>
                <td>Dibuat Oleh,<br><div class="sig-box"></div><div class="sig-name"><?= $header['username'] ?? 'Admin' ?></div></td>
                <td>Pengirim / Supir,<br><div class="sig-box"></div><div class="sig-line"></div></td>
                <td>Penerima,<br><div class="sig-box"></div><div class="sig-line"></div></td>
            </tr>
        </table>

        <div class="barcode-footer">
            <div style="font-size: 10px; margin-bottom: 2px;">Ref Surat Jalan:</div>
            <canvas id="barcode_ref" class="barcode-canvas"></canvas>
        </div>
    </div>

    <script>
        // Render Barcode Referensi Surat Jalan
        try {
            bwipjs.toCanvas('barcode_ref', {
                bcid:        'code128',
                text:        '<?= $header['reference'] ?>',
                scale:       2,
                height:      10,
                includetext: true,
                textxalign:  'center',
            });
        } catch(e) {}

        // Render Barcode Per Item
        <?php 
        $no = 1;
        foreach($items as $item): 
            $row_id = "bc_" . $no++;
        ?>
            try {
                bwipjs.toCanvas('<?= $row_id ?>', {
                    bcid:        'code128',
                    text:        '<?= $item['sku'] ?>',
                    scale:       2,
                    height:      8, // Slightly shorter for table row
                    includetext: false, // Text is handled in HTML div for better control
                });
            } catch(e) {}
        <?php endforeach; ?>
    </script>
</body>
</html>