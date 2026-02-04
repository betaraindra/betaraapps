<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

// --- CONFIG & HEADER DATA ---
$config_query = $pdo->query("SELECT * FROM settings");
$config = [];
while ($row = $config_query->fetch()) $config[$row['setting_key']] = $row['setting_value'];

// --- GET MASTER DATA FOR FILTER ---
$categories_data = $pdo->query("
    SELECT DISTINCT p.category AS code, a.name 
    FROM products p 
    LEFT JOIN accounts a ON p.category = a.code 
    ORDER BY p.category ASC
")->fetchAll();

$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

// --- FILTER INPUTS ---
$category_filter = $_GET['category'] ?? 'ALL';
$warehouse_filter = $_GET['warehouse'] ?? 'ALL';
$search_query = $_GET['q'] ?? '';

$params = [];

// --- LOGIC QUERY DATA ---
if ($warehouse_filter === 'ALL') {
    // JIKA SEMUA GUDANG: Gunakan stok global dari tabel products
    // Ini memastikan data hasil import (yang langsung update p.stock) terbaca
    $sql = "SELECT p.id, p.sku, p.name, p.category, p.unit, p.buy_price, p.sell_price, p.image_url, p.stock,
            a.name as acc_name
            FROM products p 
            LEFT JOIN accounts a ON p.category = a.code
            WHERE p.stock > 0";
    
    if ($category_filter !== 'ALL') {
        $sql .= " AND p.category = ?";
        $params[] = $category_filter;
    }

    if (!empty($search_query)) {
        $sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }

    $sql .= " ORDER BY p.name ASC";
    $current_warehouse_name = "Semua Gudang (Global)";

} else {
    // JIKA PER GUDANG: Hitung berdasarkan mutasi
    $sql = "SELECT p.id, p.sku, p.name, p.category, p.unit, p.buy_price, p.sell_price, p.image_url,
            a.name as acc_name,
            (
                COALESCE((SELECT SUM(quantity) FROM inventory_transactions WHERE product_id = p.id AND warehouse_id = ? AND type = 'IN'), 0) -
                COALESCE((SELECT SUM(quantity) FROM inventory_transactions WHERE product_id = p.id AND warehouse_id = ? AND type = 'OUT'), 0)
            ) as stock
            FROM products p
            LEFT JOIN accounts a ON p.category = a.code
            WHERE 1=1";
    
    $params[] = $warehouse_filter;
    $params[] = $warehouse_filter;

    if ($category_filter !== 'ALL') {
        $sql .= " AND p.category = ?";
        $params[] = $category_filter;
    }

    if (!empty($search_query)) {
        $sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }

    // Filter hanya barang yang ada stoknya di gudang tersebut
    $sql .= " HAVING stock > 0 ORDER BY name ASC";

    // Ambil nama gudang
    $stmt_wh = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
    $stmt_wh->execute([$warehouse_filter]);
    $current_warehouse_name = $stmt_wh->fetchColumn() ?: "Gudang Tidak Dikenal";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// --- FETCH SN FOR REPORT ---
foreach($products as &$prod_row) {
    // Ambil SN yang AVAILABLE
    $sn_sql = "SELECT serial_number FROM product_serials WHERE product_id = ? AND status = 'AVAILABLE'";
    $sn_params = [$prod_row['id']];

    if ($warehouse_filter !== 'ALL') {
        $sn_sql .= " AND warehouse_id = ?";
        $sn_params[] = $warehouse_filter;
    }
    
    $sn_sql .= " ORDER BY serial_number ASC LIMIT 10"; // Limit agar tidak terlalu panjang

    $stmt_sn_list = $pdo->prepare($sn_sql);
    $stmt_sn_list->execute($sn_params);
    $sn_arr = $stmt_sn_list->fetchAll(PDO::FETCH_COLUMN);
    
    $prod_row['sn_string'] = !empty($sn_arr) ? implode(', ', $sn_arr) : '-';
    if(count($sn_arr) >= 10) $prod_row['sn_string'] .= '...';
}
unset($prod_row); 

// --- SUMMARY CALCULATION ---
$total_asset_value = 0;
$total_qty = 0;
$total_items = count($products);

foreach($products as $p) {
    $total_asset_value += ($p['stock'] * $p['buy_price']);
    $total_qty += $p['stock'];
}
?>

<!-- Load Library PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<!-- STYLE KHUSUS PRINT & PDF -->
<style>
    @media print {
        @page { size: landscape; margin: 0mm; } 
        body { margin: 10mm; background: white; font-family: sans-serif; color: black; font-size: 10pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .no-print, aside, header, nav { display: none !important; }
        .main-content, #report_content { margin: 0; padding: 0; box-shadow: none; border: none; width: 100%; max-width: none; }
        
        /* KOP SURAT */
        .header-print { display: block !important; margin-bottom: 20px; }
        .kop-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 10px;
            margin-bottom: 20px;
            border-bottom: 4px double #000;
            width: 100%;
        }
        .kop-logo img { max-height: 90px; max-width: 100%; }
        .kop-text { flex-grow: 1; text-align: center; }
        .kop-company { font-size: 20pt; font-weight: 900; text-transform: uppercase; margin: 0; line-height: 1.1; color: #000; }
        
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #000 !important; padding: 6px; font-size: 9pt; }
        th { background-color: #f3f4f6 !important; text-align: center; }
        .text-right { text-align: right; }
    }
    .header-print { display: none; }
</style>

<!-- HEADER SCREEN (NO PRINT) -->
<div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4 no-print">
    <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-coins text-yellow-600"></i> Laporan Aset Gudang</h2>
    
    <div class="flex gap-2">
        <button onclick="window.print()" class="bg-gray-700 text-white px-4 py-2 rounded font-bold hover:bg-gray-800 shadow flex items-center gap-2">
            <i class="fas fa-print"></i> Cetak
        </button>
        <button onclick="savePDF()" class="bg-red-600 text-white px-4 py-2 rounded font-bold hover:bg-red-700 shadow flex items-center gap-2">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
    </div>
</div>

<!-- STATS CARDS (NO PRINT) -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 no-print">
    <div class="bg-white p-4 rounded shadow border-l-4 border-blue-500">
        <p class="text-gray-500 text-sm font-bold">Total Nilai Aset</p>
        <p class="text-2xl font-bold text-blue-600"><?= formatRupiah($total_asset_value) ?></p>
        <p class="text-xs text-gray-400 mt-1">*Berdasarkan Harga Beli (HPP)</p>
    </div>
    <div class="bg-white p-4 rounded shadow border-l-4 border-green-500">
        <p class="text-gray-500 text-sm font-bold">Total Stok Fisik</p>
        <p class="text-2xl font-bold text-green-600"><?= number_format($total_qty) ?> <span class="text-sm text-gray-400">Unit</span></p>
    </div>
    <div class="bg-white p-4 rounded shadow border-l-4 border-purple-500">
        <p class="text-gray-500 text-sm font-bold">Jenis Barang</p>
        <p class="text-2xl font-bold text-purple-600"><?= number_format($total_items) ?> <span class="text-sm text-gray-400">Item</span></p>
    </div>
</div>

<!-- FILTER (NO PRINT) -->
<div class="bg-white p-4 rounded shadow mb-6 no-print">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        <input type="hidden" name="page" value="laporan_aset">
        
        <div class="md:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-blue-500" placeholder="Nama / SKU ...">
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Lokasi Gudang</label>
            <select name="warehouse" class="w-full border p-2 rounded text-sm bg-gray-50 font-bold text-blue-800">
                <option value="ALL" <?= $warehouse_filter == 'ALL' ? 'selected' : '' ?>>-- Semua Gudang (Global) --</option>
                <?php foreach($warehouses as $wh): ?>
                    <option value="<?= $wh['id'] ?>" <?= $warehouse_filter == $wh['id'] ? 'selected' : '' ?>>
                        <?= $wh['name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Kategori (Akun)</label>
            <select name="category" class="w-full border p-2 rounded text-sm bg-gray-50">
                <option value="ALL">-- Semua Kategori --</option>
                <?php foreach($categories_data as $cat): 
                    $label = $cat['code'] . (!empty($cat['name']) ? " - " . $cat['name'] : "");
                ?>
                    <option value="<?= $cat['code'] ?>" <?= $category_filter == $cat['code'] ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded font-bold hover:bg-blue-700 w-full text-sm">
                <i class="fas fa-filter"></i> Tampilkan
            </button>
        </div>
    </form>
</div>

<!-- REPORT CONTENT -->
<div id="report_content" class="bg-white p-8 rounded shadow">
    
    <!-- PRINT HEADER -->
    <div class="header-print">
        <div class="kop-container">
            <div class="kop-logo">
                <?php if(!empty($config['company_logo'])): ?>
                    <img src="<?= $config['company_logo'] ?>">
                <?php endif; ?>
            </div>
            <div class="kop-text">
                <h1 class="kop-company"><?= $config['company_name'] ?? 'NAMA PERUSAHAAN' ?></h1>
                <div class="kop-address"><?= nl2br($config['company_address'] ?? '') ?></div>
                <?php if(!empty($config['company_npwp'])): ?>
                    <div class="kop-npwp">NPWP: <?= $config['company_npwp'] ?></div>
                <?php endif; ?>
            </div>
            <div class="kop-spacer"></div>
        </div>
        <div class="text-center mb-6">
            <h3 style="font-size: 16pt; font-weight: bold; text-decoration: underline; text-transform: uppercase;">LAPORAN NILAI ASET PERSEDIAAN</h3>
            <p style="font-size: 11pt;">Lokasi: <b><?= htmlspecialchars($current_warehouse_name) ?></b> | Per Tanggal: <?= date('d F Y') ?></p>
        </div>
    </div>

    <!-- TABLE DATA -->
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left border-collapse border border-gray-300 min-w-[1000px]">
            <thead class="bg-gray-100 text-gray-700">
                <tr>
                    <th class="p-2 border border-gray-300 text-center w-10">No</th>
                    <th class="p-2 border border-gray-300">Kode (SKU)</th>
                    <th class="p-2 border border-gray-300">Nama Barang</th>
                    <th class="p-2 border border-gray-300">Kategori</th>
                    <th class="p-2 border border-gray-300 w-48">Contoh SN</th>
                    <th class="p-2 border border-gray-300 text-right">Stok</th>
                    <th class="p-2 border border-gray-300 text-center">Sat</th>
                    <th class="p-2 border border-gray-300 text-right">Harga Beli (HPP)</th>
                    <th class="p-2 border border-gray-300 text-right">Nilai Aset</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                foreach($products as $p): 
                    $subtotal = $p['stock'] * $p['buy_price'];
                    $cat_label = !empty($p['acc_name']) ? "{$p['category']} - {$p['acc_name']}" : $p['category'];
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="p-2 border border-gray-300 text-center"><?= $no++ ?></td>
                    <td class="p-2 border border-gray-300 font-mono"><?= $p['sku'] ?></td>
                    <td class="p-2 border border-gray-300 font-medium"><?= $p['name'] ?></td>
                    <td class="p-2 border border-gray-300 text-gray-600"><?= $cat_label ?></td>
                    <td class="p-2 border border-gray-300 text-xs font-mono bg-gray-50 text-gray-500">
                        <?= h($p['sn_string']) ?>
                    </td>
                    <td class="p-2 border border-gray-300 text-right font-bold"><?= number_format($p['stock']) ?></td>
                    <td class="p-2 border border-gray-300 text-center text-xs"><?= $p['unit'] ?></td>
                    <td class="p-2 border border-gray-300 text-right"><?= formatRupiah($p['buy_price']) ?></td>
                    <td class="p-2 border border-gray-300 text-right font-bold bg-blue-50 text-blue-800">
                        <?= formatRupiah($subtotal) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($products)): ?>
                    <tr><td colspan="9" class="p-4 text-center text-gray-500">Tidak ada stok barang di lokasi ini.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot class="bg-gray-100 font-bold border-t-2 border-gray-400">
                <tr>
                    <td colspan="5" class="p-3 text-right border border-gray-300">TOTAL</td>
                    <td class="p-3 text-right text-blue-700 border border-gray-300"><?= number_format($total_qty) ?></td>
                    <td colspan="2" class="border border-gray-300"></td>
                    <td class="p-3 text-right text-lg text-blue-800 bg-blue-100 border border-gray-300">
                        <?= formatRupiah($total_asset_value) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- SIGNATURE AREA (PRINT ONLY) -->
    <div class="mt-8 flex justify-end header-print">
        <div class="text-center w-48">
            <p class="mb-16">Disetujui Oleh,</p>
            <p class="font-bold border-t border-black pt-1">Finance Manager</p>
        </div>
    </div>
</div>

<script>
function savePDF() {
    const element = document.getElementById('report_content');
    const header = document.querySelector('.header-print');
    
    if(header) header.style.display = 'block';

    const opt = {
        margin:       10,
        filename:     'Laporan_Aset_<?= $warehouse_filter ?>_<?= date('Ymd') ?>.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };

    html2pdf().set(opt).from(element).save().then(() => {
        if(header) header.style.display = '';
    });
}
</script>