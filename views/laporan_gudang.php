<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$warehouse_filter = $_GET['warehouse_id'] ?? 'ALL';

// --- CONFIG & HEADER DATA ---
$config_query = $pdo->query("SELECT * FROM settings");
$config = [];
while ($row = $config_query->fetch()) $config[$row['setting_key']] = $row['setting_value'];

// --- GET WAREHOUSES FOR FILTER ---
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

// --- PREPARE QUERY ---
$sql = "SELECT i.*, p.sku, p.name as prod_name, p.unit, p.buy_price, p.sell_price, p.image_url, w.name as wh_name 
        FROM inventory_transactions i 
        JOIN products p ON i.product_id=p.id 
        JOIN warehouses w ON i.warehouse_id=w.id 
        WHERE i.date BETWEEN ? AND ?";

$params = [$start, $end];

// Apply Warehouse Filter
if ($warehouse_filter !== 'ALL') {
    $sql .= " AND i.warehouse_id = ?";
    $params[] = $warehouse_filter;
}

$sql .= " ORDER BY i.date DESC, i.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Grouping Logic
$grouped_data = [];
foreach($transactions as $t) {
    // Group by Month Year
    $month_ts = strtotime($t['date']);
    $group_key = date('Y-m', $month_ts);
    
    // Label
    $months = [1=>'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $period_label = $months[date('n', $month_ts)] . " " . date('Y', $month_ts);
    
    $grouped_data[$group_key]['label'] = $period_label;
    $grouped_data[$group_key]['items'][] = $t;
    
    // Calculate Total Material (Transaction Value) based on Buy Price
    if(!isset($grouped_data[$group_key]['total'])) $grouped_data[$group_key]['total'] = 0;
    // Asumsi: Total Material = Qty Transaksi * Harga Beli (Nilai barang yang bergerak)
    $grouped_data[$group_key]['total'] += ($t['quantity'] * $t['buy_price']);
}

// Get Selected Warehouse Name for Header
$selected_wh_name = "Semua Gudang";
if ($warehouse_filter !== 'ALL') {
    foreach($warehouses as $w) {
        if ($w['id'] == $warehouse_filter) {
            $selected_wh_name = $w['name'];
            break;
        }
    }
}
?>

<!-- Load Library PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<!-- CSS Print -->
<style>
    @media print {
        /* Set margin halaman 0 untuk menyembunyikan Header/Footer browser (Tanggal, URL) */
        @page { size: landscape; margin: 0mm; }
        
        /* Tambahkan margin kembali pada body agar konten tidak terpotong */
        body { margin: 10mm 10mm 10mm 10mm !important; }

        .no-print { display: none !important; }
        body { font-size: 10px; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-family: sans-serif; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000 !important; padding: 4px; }
        
        .bg-red-300 { background-color: #fca5a5 !important; }
        .bg-yellow-400 { background-color: #facc15 !important; }
        .bg-green-100 { background-color: #dcfce7 !important; }
        .bg-red-100 { background-color: #fee2e2 !important; }
        
        .header-print { display: block !important; margin-bottom: 20px; }
        
        /* Hilangkan shadow & border container utama saat print */
        #report_content { box-shadow: none !important; border: none !important; margin: 0 !important; width: 100% !important; }

        /* Kop Surat Styles */
        .kop-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 10px;
            margin-bottom: 20px;
            border-bottom: 4px double #000;
            width: 100%;
        }
        .kop-logo, .kop-spacer { width: 120px; display: flex; justify-content: center; align-items: center; }
        .kop-logo img { max-height: 90px; max-width: 100%; }
        .kop-text { flex-grow: 1; text-align: center; }
        .kop-company { 
            font-size: 24pt; 
            font-weight: 900; 
            text-transform: uppercase; 
            margin: 0; 
            line-height: 1.1; 
            color: #000;
            letter-spacing: 1px;
        }
        .kop-address { font-size: 11pt; margin: 5px 0 0 0; line-height: 1.3; }
        .kop-npwp { font-size: 11pt; font-weight: bold; margin-top: 3px; }
    }
    .header-print { display: none; }
</style>

<div class="bg-white p-6 rounded shadow mb-6 no-print">
    <div class="flex flex-col md:flex-row justify-between items-end gap-4">
        <form class="flex flex-wrap gap-4 items-end">
            <input type="hidden" name="page" value="laporan_gudang">
            
            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">Lokasi Gudang</label>
                <select name="warehouse_id" class="border p-2 rounded text-sm min-w-[200px] bg-white focus:ring-2 focus:ring-blue-500">
                    <option value="ALL">-- Semua Gudang --</option>
                    <?php foreach($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= $warehouse_filter == $wh['id'] ? 'selected' : '' ?>>
                            <?= $wh['name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">Dari Tanggal</label>
                <input type="date" name="start" value="<?= $start ?>" class="border p-2 rounded text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">Sampai Tanggal</label>
                <input type="date" name="end" value="<?= $end ?>" class="border p-2 rounded text-sm">
            </div>
            <button class="bg-blue-600 text-white px-4 py-2 rounded font-bold hover:bg-blue-700 text-sm">Filter</button>
        </form>
        
        <div class="flex gap-2">
            <button onclick="window.print()" class="bg-gray-700 text-white px-4 py-2 rounded font-bold hover:bg-gray-800 text-sm"><i class="fas fa-print"></i> Print</button>
            <button onclick="savePDF()" class="bg-red-600 text-white px-4 py-2 rounded font-bold hover:bg-red-700 text-sm"><i class="fas fa-file-pdf"></i> Simpan PDF</button>
        </div>
    </div>
</div>

<div id="report_content" class="bg-white p-6 rounded shadow min-h-screen">
    
    <!-- Header Print (Kop Surat) -->
    <div class="header-print">
        <div class="kop-container">
            <div class="kop-logo">
                <?php if(!empty($config['company_logo'])): ?>
                    <img src="<?= $config['company_logo'] ?>">
                <?php endif; ?>
            </div>
            <div class="kop-text">
                <h1 class="kop-company"><?= $config['company_name'] ?? 'NAMA PERUSAHAAN' ?></h1>
                <div class="kop-address"><?= nl2br(htmlspecialchars($config['company_address'] ?? '')) ?></div>
                <?php if(!empty($config['company_npwp'])): ?>
                    <div class="kop-npwp">NPWP: <?= $config['company_npwp'] ?></div>
                <?php endif; ?>
            </div>
            <div class="kop-spacer"></div>
        </div>
        <div style="text-align: center; margin-bottom: 20px;">
            <h3 style="font-size: 14pt; font-weight: bold; text-transform: uppercase; text-decoration: underline; margin-bottom: 5px;">Laporan Transaksi Gudang</h3>
            <p style="font-size: 10pt; font-weight: bold;">
                Lokasi: <?= htmlspecialchars($selected_wh_name) ?> <br>
                Periode: <?= date('d/m/Y', strtotime($start)) ?> - <?= date('d/m/Y', strtotime($end)) ?>
            </p>
        </div>
    </div>

    <!-- Judul Layar (No Print) -->
    <div class="mb-4 border-b pb-2 text-center no-print">
        <h2 class="text-xl font-bold text-gray-800 uppercase">Laporan Transaksi Gudang</h2>
        <p class="text-sm text-gray-600 font-bold mb-1">Lokasi: <?= htmlspecialchars($selected_wh_name) ?></p>
        <p class="text-xs text-gray-500">Periode: <?= date('d/m/Y', strtotime($start)) ?> - <?= date('d/m/Y', strtotime($end)) ?></p>
    </div>

    <table class="w-full text-xs border text-left border-collapse border-gray-400">
        <?php if(empty($grouped_data)): ?>
            <tr><td class="p-4 text-center text-gray-500 border border-gray-300">Tidak ada data transaksi pada periode ini.</td></tr>
        <?php else: ?>
            
            <?php foreach($grouped_data as $key => $group): ?>
                <!-- Header Periode -->
                <tr class="bg-red-300 print:bg-red-300">
                    <td colspan="6" class="border border-gray-400 p-2 font-bold text-red-900">
                        Total Material Periode <?= $group['label'] ?>
                    </td>
                    <td colspan="5" class="border border-gray-400 p-2 font-bold text-red-900 text-right">
                        Total Nilai Transaksi: <?= formatRupiah($group['total']) ?>
                    </td>
                </tr>

                <!-- Header Kolom -->
                <tr class="bg-yellow-400 print:bg-yellow-400 font-bold text-center text-gray-900">
                    <th class="border border-gray-500 p-2 w-10">Gbr</th>
                    <th class="border border-gray-500 p-2">SKU (Barcode)</th>
                    <th class="border border-gray-500 p-2">Tanggal</th>
                    <th class="border border-gray-500 p-2">Reference</th>
                    <th class="border border-gray-500 p-2">Nama Barang</th>
                    <th class="border border-gray-500 p-2">Harga Beli</th>
                    <th class="border border-gray-500 p-2">Lokasi Gudang</th>
                    <th class="border border-gray-500 p-2">Harga Jual</th>
                    <th class="border border-gray-500 p-2 w-16">Stok (Qty)</th>
                    <th class="border border-gray-500 p-2 w-10">Satuan</th>
                    <th class="border border-gray-500 p-2">Keterangan</th>
                </tr>

                <!-- Data Rows -->
                <?php foreach($group['items'] as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="border border-gray-400 p-1 text-center">
                        <?php if($item['image_url']): ?>
                            <img src="<?= $item['image_url'] ?>" class="w-6 h-6 object-cover mx-auto">
                        <?php endif; ?>
                    </td>
                    <td class="border border-gray-400 p-2 font-mono text-center"><?= $item['sku'] ?></td>
                    <td class="border border-gray-400 p-2 text-center whitespace-nowrap"><?= date('d/m/Y', strtotime($item['date'])) ?></td>
                    <td class="border border-gray-400 p-2 text-center uppercase"><?= $item['reference'] ?></td>
                    <td class="border border-gray-400 p-2 font-bold"><?= $item['prod_name'] ?></td>
                    <td class="border border-gray-400 p-2 text-right"><?= formatRupiah($item['buy_price']) ?></td>
                    <td class="border border-gray-400 p-2 text-center"><?= $item['wh_name'] ?></td>
                    <td class="border border-gray-400 p-2 text-right"><?= formatRupiah($item['sell_price']) ?></td>
                    <td class="border border-gray-400 p-2 text-center font-bold">
                        <span class="<?= $item['type']=='IN' ? 'text-green-600' : 'text-red-600' ?>">
                            <?= $item['type']=='IN' ? '+' : '-' ?><?= $item['quantity'] ?>
                        </span>
                    </td>
                    <td class="border border-gray-400 p-2 text-center"><?= $item['unit'] ?></td>
                    <td class="border border-gray-400 p-2 text-gray-600 italic"><?= $item['notes'] ?></td>
                </tr>
                <?php endforeach; ?>
                
                <!-- Spacer -->
                <tr class="h-4 border-none"><td colspan="11" class="border-none"></td></tr>

            <?php endforeach; ?>
        <?php endif; ?>
    </table>
</div>

<script>
function savePDF() {
    const element = document.getElementById('report_content');
    const header = document.querySelector('.header-print');
    
    // Tampilkan Header sementara agar masuk ke PDF
    if(header) header.style.display = 'block';

    const opt = {
        margin:       5,
        filename:     'Laporan_Gudang_Periode_<?= date('Ymd') ?>.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };
    
    const btn = event.currentTarget;
    btn.innerHTML = 'Processing...';
    btn.disabled = true;

    html2pdf().set(opt).from(element).save().then(() => {
        btn.innerHTML = '<i class="fas fa-file-pdf"></i> Simpan PDF';
        btn.disabled = false;
        // Sembunyikan kembali
        if(header) header.style.display = '';
    });
}
</script>