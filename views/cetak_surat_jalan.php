<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'ADMIN_KEUANGAN']);

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT i.*, p.sku, p.name as prod_name, p.unit, w.name as wh_name, u.username 
                       FROM inventory_transactions i 
                       JOIN products p ON i.product_id = p.id 
                       JOIN warehouses w ON i.warehouse_id = w.id
                       JOIN users u ON i.user_id = u.id
                       WHERE i.id = ?");
$stmt->execute([$id]);
$data = $stmt->fetch();

if (!$data) {
    echo "Data transaksi tidak ditemukan.";
    exit;
}

$company = $settings['company_name'] ?? 'Perusahaan Anda';
$title = ($data['type'] == 'IN') ? 'SURAT JALAN / BUKTI MASUK' : 'SURAT JALAN / BUKTI KELUAR';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Surat Jalan #<?= $data['id'] ?></title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"></script>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; color: #000; }
        .container { width: 100%; max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header h1 { margin: 0; font-size: 24px; text-transform: uppercase; }
        .header p { margin: 5px 0; }
        .meta-table { width: 100%; margin-bottom: 20px; }
        .meta-table td { padding: 5px; vertical-align: top; }
        .content-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .content-table th, .content-table td { border: 1px solid #000; padding: 8px; text-align: left; }
        .content-table th { background-color: #f0f0f0; }
        .signatures { display: flex; justify-content: space-between; margin-top: 50px; text-align: center; }
        .sig-box { width: 30%; }
        .sig-line { margin-top: 60px; border-top: 1px solid #000; }
        .barcode-box { text-align: center; margin-top: 20px; }
        @media print {
            .container { border: none; padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="no-print" style="margin-bottom: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #333; color: #fff; border: none; border-radius: 5px;">Cetak Dokumen</button>
        <button onclick="window.close()" style="padding: 10px 20px; cursor: pointer; background: #ddd; border: none; border-radius: 5px;">Tutup</button>
    </div>

    <div class="container">
        <div class="header">
            <h1><?= $company ?></h1>
            <h3><?= $title ?></h3>
        </div>

        <table class="meta-table">
            <tr>
                <td width="15%"><strong>No. Transaksi</strong></td>
                <td width="35%">: #<?= $data['id'] ?></td>
                <td width="15%"><strong>Gudang</strong></td>
                <td width="35%">: <?= $data['wh_name'] ?></td>
            </tr>
            <tr>
                <td><strong>Tanggal</strong></td>
                <td>: <?= date('d-m-Y', strtotime($data['date'])) ?></td>
                <td><strong>No. Ref / SJ</strong></td>
                <td>: <?= $data['reference'] ? $data['reference'] : '-' ?></td>
            </tr>
            <tr>
                <td><strong>Admin</strong></td>
                <td>: <?= $data['username'] ?></td>
                <td><strong>Tipe</strong></td>
                <td>: <?= $data['type'] == 'IN' ? 'BARANG MASUK' : 'BARANG KELUAR' ?></td>
            </tr>
        </table>

        <table class="content-table">
            <thead>
                <tr>
                    <th>Kode Barang / SKU</th>
                    <th>Nama Barang</th>
                    <th style="text-align: center;">Jumlah</th>
                    <th>Satuan</th>
                    <th>Catatan</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= $data['sku'] ?></td>
                    <td><?= $data['prod_name'] ?></td>
                    <td style="text-align: center; font-weight: bold; font-size: 16px;"><?= $data['quantity'] ?></td>
                    <td><?= $data['unit'] ?></td>
                    <td><?= $data['notes'] ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Barcode SKU untuk memudahkan scanning ulang -->
        <div class="barcode-box">
            <svg id="barcode"></svg>
            <script>
                JsBarcode("#barcode", "<?= $data['sku'] ?>", {
                    format: "CODE128",
                    lineColor: "#000",
                    width: 2,
                    height: 50,
                    displayValue: true
                });
            </script>
            <p style="font-size: 10px; margin-top: 5px;">Scan barcode di atas untuk pencarian barang</p>
        </div>

        <div class="signatures">
            <div class="sig-box">
                <p>Pengirim / Penyerah</p>
                <div class="sig-line"></div>
            </div>
            <div class="sig-box">
                <p>Gudang / Penerima</p>
                <div class="sig-line"></div>
                <p>( <?= $data['username'] ?> )</p>
            </div>
            <div class="sig-box">
                <p>Mengetahui</p>
                <div class="sig-line"></div>
            </div>
        </div>
    </div>
</body>
</html>