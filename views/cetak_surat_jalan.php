<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

$id = $_GET['id'] ?? 0;
// Ambil data detail transaksi
$stmt = $pdo->prepare("SELECT i.*, p.sku, p.name as prod_name, p.unit, w.name as wh_name, w.location as wh_loc, u.username 
                       FROM inventory_transactions i 
                       JOIN products p ON i.product_id = p.id 
                       JOIN warehouses w ON i.warehouse_id = w.id
                       JOIN users u ON i.user_id = u.id
                       WHERE i.id = ?");
$stmt->execute([$id]);
$data = $stmt->fetch();

if (!$data) die("Data transaksi tidak ditemukan.");

// Ambil Pengaturan Perusahaan
$settings_query = $pdo->query("SELECT * FROM settings");
$config = [];
while ($row = $settings_query->fetch()) $config[$row['setting_key']] = $row['setting_value'];

$company = $config['company_name'] ?? 'NAMA PERUSAHAAN';
$address = $config['company_address'] ?? 'Alamat Perusahaan';
$logo = $config['company_logo'] ?? '';
$title = ($data['type'] == 'IN') ? 'BUKTI BARANG MASUK' : 'SURAT JALAN / BUKTI KELUAR';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak - <?= $data['reference'] ?></title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"></script>
    <style>
        /* A4 Portrait Setting */
        @page { size: A4 portrait; margin: 1cm; }
        body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #000; margin: 0; padding: 20px; max-width: 210mm; margin: auto; display: flex; flex-direction: column; min-height: 95vh; }
        
        /* Layout Helpers */
        .w-full { width: 100%; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        .mb-2 { margin-bottom: 8px; }
        .mb-4 { margin-bottom: 16px; }
        .border-b { border-bottom: 2px solid #000; }
        
        /* KOP SURAT */
        .kop-table { width: 100%; margin-bottom: 10px; border-bottom: 4px double #000; padding-bottom: 10px; }
        .kop-logo { width: 120px; text-align: center; vertical-align: middle; }
        .kop-logo img { max-width: 100px; max-height: 90px; }
        .kop-info { text-align: center; vertical-align: middle; padding: 0 10px; }
        .kop-company { 
            font-size: 24pt; 
            font-weight: 900; 
            text-transform: uppercase; 
            margin-bottom: 5px; 
            line-height: 1.1;
            letter-spacing: 1px;
        }
        .kop-addr { font-size: 11pt; line-height: 1.3; }
        .kop-npwp { font-size: 11pt; font-weight: bold; margin-top: 3px; }
        .kop-spacer { width: 120px; }

        /* TITLE & INFO */
        .doc-title { text-align: center; font-size: 16px; font-weight: bold; text-decoration: underline; margin: 20px 0; text-transform: uppercase; }
        .info-table { width: 100%; margin-bottom: 15px; }
        .info-table td { padding: 3px 0; vertical-align: top; }
        .label-cell { width: 100px; font-weight: bold; }
        .val-cell { padding-left: 10px; }

        /* MAIN TABLE */
        .data-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .data-table th, .data-table td { border: 1px solid #000; padding: 8px; }
        .data-table th { background-color: #f0f0f0; text-align: center; font-weight: bold; }
        
        /* FOOTER & SIGNATURE */
        .content-wrapper { flex: 1; }
        .footer-wrapper { margin-top: auto; padding-top: 20px; }
        
        .footer-note { font-size: 10px; font-style: italic; margin-bottom: 20px; }
        .signature-table { width: 100%; margin-top: 10px; margin-bottom: 20px; }
        .signature-table td { text-align: center; vertical-align: top; width: 33%; }
        .sig-box { height: 70px; }
        .sig-name { font-weight: bold; text-decoration: underline; }

        .barcode-footer { text-align: center; margin-top: 20px; padding-top: 10px; border-top: 1px dashed #ccc; }

        /* Screen Only */
        .no-print { background: #333; color: #fff; padding: 10px; text-align: center; margin-bottom: 20px; border-radius: 5px; }
        .btn-print { background: #fff; color: #000; border: none; padding: 5px 15px; cursor: pointer; font-weight: bold; border-radius: 3px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" class="btn-print">🖨️ Cetak Dokumen</button>
        <button onclick="window.close()" class="btn-print">❌ Tutup</button>
    </div>

    <div class="content-wrapper">
        <!-- KOP SURAT (Updated Style) -->
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
                <td class="val-cell text-bold"><?= $data['reference'] ?></td>
                
                <td class="label-cell" style="padding-left: 30px;">Tanggal</td>
                <td style="width: 10px;">:</td>
                <td class="val-cell"><?= date('d F Y', strtotime($data['date'])) ?></td>
            </tr>
            <tr>
                <td class="label-cell">Lokasi Gudang</td>
                <td>:</td>
                <td class="val-cell"><?= $data['wh_name'] ?> (<?= $data['wh_loc'] ?>)</td>
                
                <td class="label-cell" style="padding-left: 30px;">Admin</td>
                <td>:</td>
                <td class="val-cell"><?= $data['username'] ?></td>
            </tr>
            <?php if($data['notes']): ?>
            <tr>
                <td class="label-cell">Keterangan</td>
                <td>:</td>
                <td class="val-cell" colspan="4"><?= $data['notes'] ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 40px;">No</th>
                    <th style="width: 120px;">SKU / Barcode</th>
                    <th>Nama Barang</th>
                    <th style="width: 80px;">Jumlah</th>
                    <th style="width: 80px;">Satuan</th>
                    <th>Ceklis</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-center">1</td>
                    <td class="text-center font-mono"><?= $data['sku'] ?></td>
                    <td><?= $data['prod_name'] ?></td>
                    <td class="text-center text-bold" style="font-size: 14px;"><?= $data['quantity'] ?></td>
                    <td class="text-center"><?= $data['unit'] ?></td>
                    <td class="text-center">▢</td>
                </tr>
                <!-- Tambahkan baris kosong jika perlu manual input -->
                <?php for($i=0; $i<3; $i++): ?>
                <tr>
                    <td style="height: 25px;"></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <!-- FOOTER SECTION -->
    <div class="footer-wrapper">
        <div class="footer-note">
            * Harap diperiksa kembali kondisi barang. Barang yang sudah diterima/keluar tidak dapat ditukar kecuali perjanjian khusus.<br>
            * Dokumen ini sah jika disertai tanda tangan penanggung jawab.
        </div>

        <table class="signature-table">
            <tr>
                <td>
                    Dibuat Oleh,<br>
                    <div class="sig-box"></div>
                    <div class="sig-name"><?= $data['username'] ?></div>
                    <div>Admin Gudang</div>
                </td>
                <td>
                    Pengirim / Supir,<br>
                    <div class="sig-box"></div>
                    <div class="sig-line"></div>
                </td>
                <td>
                    Penerima,<br>
                    <div class="sig-box"></div>
                    <div class="sig-line"></div>
                </td>
            </tr>
        </table>

        <!-- Barcode Moved to Bottom -->
        <div class="barcode-footer">
            <svg id="barcode_ref" style="height: 40px; max-width: 100%;"></svg>
        </div>
    </div>

    <script>
        try {
            JsBarcode("#barcode_ref", "<?= $data['reference'] ?>", {
                format: "CODE128",
                displayValue: true,
                height: 40,
                fontSize: 10,
                margin: 0
            });
        } catch(e) {}
    </script>
</body>
</html>