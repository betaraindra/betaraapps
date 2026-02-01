<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

$id = $_GET['id'] ?? 0;
// Gunakan Cache untuk detail gudang (Jarang berubah)
$cacheKey = "warehouse_detail_" . $id;
$warehouse = $cache->get($cacheKey);

if(!$warehouse) {
    $wh = $pdo->prepare("SELECT * FROM warehouses WHERE id = ?");
    $wh->execute([$id]);
    $warehouse = $wh->fetch();
    if($warehouse) $cache->set($cacheKey, $warehouse, 3600); // Cache 1 jam
}

if(!$warehouse) { 
    echo "<div class='p-4 text-center text-red-500 font-bold'>Gudang tidak ditemukan!</div>"; 
    exit; 
}

// --- FILTER ---
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');
$q = $_GET['q'] ?? '';
$tab = $_GET['tab'] ?? 'stock'; // stock, history, finance

// --- 1. OPTIMIZED DATA STOK FISIK (Menggunakan JOIN aggregation) ---
// Sebelumnya: Subquery di SELECT clause (Lambat: O(n*m))
// Sekarang: Derived Table / JOIN (Cepat: O(n))
if ($tab === 'stock') {
    $sql_stock = "
        SELECT p.sku, p.name, p.unit, p.buy_price,
               COALESCE(SUM(CASE WHEN i.type = 'IN' THEN i.quantity ELSE 0 END), 0) as qty_in,
               COALESCE(SUM(CASE WHEN i.type = 'OUT' THEN i.quantity ELSE 0 END), 0) as qty_out
        FROM products p
        JOIN inventory_transactions i ON p.id = i.product_id
        WHERE i.warehouse_id = ?
        AND (p.name LIKE ? OR p.sku LIKE ?)
        GROUP BY p.id
        HAVING (qty_in - qty_out) > 0 OR (qty_in > 0 OR qty_out > 0)
        ORDER BY p.name ASC
    ";
    $stmt_stock = $pdo->prepare($sql_stock);
    $stmt_stock->execute([$id, "%$q%", "%$q%"]);
    $stocks = $stmt_stock->fetchAll();
    
    // Hitung Total Aset (PHP Side calculation is fast enough for < 5000 rows)
    $total_asset_value = 0;
    foreach($stocks as $s) {
        $curr_qty = $s['qty_in'] - $s['qty_out'];
        $total_asset_value += ($curr_qty * $s['buy_price']);
    }
}

// --- 2. RIWAYAT MUTASI (History) with Pagination ---
if ($tab === 'history') {
    // Pagination Logic
    $page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $limit = 20;
    $offset = ($page_num - 1) * $limit;

    // Data Query
    $sql_hist = "SELECT i.*, p.name as prod_name, p.sku, u.username 
                 FROM inventory_transactions i 
                 JOIN products p ON i.product_id = p.id 
                 JOIN users u ON i.user_id = u.id 
                 WHERE i.warehouse_id = ? 
                 AND i.date BETWEEN ? AND ?
                 AND (p.name LIKE ? OR p.sku LIKE ? OR i.reference LIKE ? OR i.notes LIKE ?)
                 ORDER BY i.date DESC, i.created_at DESC
                 LIMIT $limit OFFSET $offset";
    
    $stmt_hist = $pdo->prepare($sql_hist);
    $stmt_hist->execute([$id, $start_date, $end_date, "%$q%", "%$q%", "%$q%", "%$q%"]);
    $history = $stmt_hist->fetchAll();

    // Count Query for Pagination
    $sql_count = "SELECT COUNT(*) 
                  FROM inventory_transactions i 
                  JOIN products p ON i.product_id = p.id 
                  WHERE i.warehouse_id = ? 
                  AND i.date BETWEEN ? AND ?
                  AND (p.name LIKE ? OR p.sku LIKE ? OR i.reference LIKE ? OR i.notes LIKE ?)";
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute([$id, $start_date, $end_date, "%$q%", "%$q%", "%$q%", "%$q%"]);
    $total_rows = $stmt_count->fetchColumn();
    $total_pages = ceil($total_rows / $limit);
}

// --- 3. KEUANGAN WILAYAH ---
// ... (Logic Keuangan tetap sama, sudah cukup efisien dengan index baru) ...
if ($tab === 'finance') {
    $wh_name_clean = trim($warehouse['name']);
    $sql_fin = "SELECT f.*, a.code, a.name as acc_name, u.username
                FROM finance_transactions f
                JOIN accounts a ON f.account_id = a.id
                JOIN users u ON f.user_id = u.id
                WHERE (a.code = '1004' OR a.code = '2009')
                AND f.date BETWEEN ? AND ?
                AND (f.description LIKE ? OR f.description LIKE ?)
                ORDER BY f.date DESC";
    $stmt_fin = $pdo->prepare($sql_fin);
    $stmt_fin->execute([$start_date, $end_date, "%$wh_name_clean%", "%$q%"]);
    $finances = $stmt_fin->fetchAll();

    $total_rev = 0; $total_exp = 0;
    foreach($finances as $f) {
        if($f['type'] == 'INCOME') $total_rev += $f['amount']; else $total_exp += $f['amount'];
    }
}
?>

<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <div class="flex items-center gap-2">
            <a href="?page=lokasi_gudang" class="text-gray-500 hover:text-gray-700"><i class="fas fa-arrow-left"></i></a>
            <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-warehouse text-blue-600"></i> <?= h($warehouse['name']) ?></h2>
        </div>
        <p class="text-gray-500 text-sm ml-6"><i class="fas fa-map-marker-alt"></i> <?= h($warehouse['location']) ?></p>
    </div>
    
    <?php if($tab === 'stock'): ?>
    <div class="flex gap-4">
        <div class="bg-blue-100 text-blue-800 px-4 py-2 rounded text-center">
            <div class="text-xs font-bold uppercase">Nilai Aset</div>
            <div class="font-bold text-lg"><?= formatRupiah($total_asset_value) ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- FILTER BAR -->
<div class="bg-white p-4 rounded-lg shadow mb-6 border-l-4 border-indigo-600">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <input type="hidden" name="page" value="detail_gudang">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="tab" value="<?= $tab ?>">

        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-bold text-gray-600 mb-1">Pencarian</label>
            <div class="relative">
                <span class="absolute left-3 top-2 text-gray-400"><i class="fas fa-search"></i></span>
                <input type="text" name="q" value="<?= h($q) ?>" class="w-full border p-2 pl-9 rounded text-sm focus:ring-2 focus:ring-indigo-500" placeholder="Nama Barang / Ref / Ket...">
            </div>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
            <input type="date" name="start" value="<?= $start_date ?>" class="border p-2 rounded text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
            <input type="date" name="end" value="<?= $end_date ?>" class="border p-2 rounded text-sm">
        </div>
        
        <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded font-bold hover:bg-indigo-700 text-sm shadow h-[38px]">
            <i class="fas fa-filter"></i> Filter
        </button>
    </form>
</div>

<!-- TABS NAVIGATION -->
<div class="flex border-b border-gray-200 mb-6 bg-white rounded-t-lg overflow-hidden">
    <a href="?page=detail_gudang&id=<?= $id ?>&tab=stock&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" 
       class="flex-1 py-3 text-center text-sm font-bold border-b-2 hover:bg-gray-50 <?= $tab=='stock' ? 'border-blue-600 text-blue-600 bg-blue-50' : 'border-transparent text-gray-500' ?>">
       <i class="fas fa-boxes mr-2"></i> Stok Fisik
    </a>
    <a href="?page=detail_gudang&id=<?= $id ?>&tab=history&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" 
       class="flex-1 py-3 text-center text-sm font-bold border-b-2 hover:bg-gray-50 <?= $tab=='history' ? 'border-orange-600 text-orange-600 bg-orange-50' : 'border-transparent text-gray-500' ?>">
       <i class="fas fa-history mr-2"></i> Riwayat Mutasi
    </a>
    <a href="?page=detail_gudang&id=<?= $id ?>&tab=finance&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" 
       class="flex-1 py-3 text-center text-sm font-bold border-b-2 hover:bg-gray-50 <?= $tab=='finance' ? 'border-green-600 text-green-600 bg-green-50' : 'border-transparent text-gray-500' ?>">
       <i class="fas fa-money-bill-wave mr-2"></i> Keuangan
    </a>
</div>

<!-- TAB CONTENT -->
<div class="bg-white rounded-b-lg shadow p-6 min-h-[400px]">
    
    <!-- TAB 1: STOK FISIK (OPTIMIZED) -->
    <?php if($tab == 'stock'): ?>
        <h3 class="font-bold text-gray-800 mb-4 border-b pb-2">Posisi Stok Gudang (<?= count($stocks) ?> Item)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border-b">SKU</th>
                        <th class="p-3 border-b">Nama Barang</th>
                        <th class="p-3 border-b text-right text-green-600">Total Masuk</th>
                        <th class="p-3 border-b text-right text-red-600">Total Keluar</th>
                        <th class="p-3 border-b text-right bg-blue-50 text-blue-800 font-bold">Stok Akhir</th>
                        <th class="p-3 border-b text-center">Satuan</th>
                        <th class="p-3 border-b text-right">Nilai Aset (Rp)</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($stocks as $s): 
                        $final = $s['qty_in'] - $s['qty_out'];
                        $val = $final * $s['buy_price'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 font-mono text-xs"><?= h($s['sku']) ?></td>
                        <td class="p-3 font-medium"><?= h($s['name']) ?></td>
                        <td class="p-3 text-right text-green-600"><?= number_format($s['qty_in']) ?></td>
                        <td class="p-3 text-right text-red-600"><?= number_format($s['qty_out']) ?></td>
                        <td class="p-3 text-right font-bold bg-blue-50 text-blue-800 text-lg"><?= number_format($final) ?></td>
                        <td class="p-3 text-center text-xs text-gray-500"><?= h($s['unit']) ?></td>
                        <td class="p-3 text-right"><?= formatRupiah($val) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($stocks)): ?>
                        <tr><td colspan="7" class="p-8 text-center text-gray-400">Tidak ada stok barang di gudang ini sesuai filter.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    
    <!-- TAB 2: RIWAYAT MUTASI (WITH PAGINATION) -->
    <?php elseif($tab == 'history'): ?>
        <h3 class="font-bold text-gray-800 mb-4 border-b pb-2">Log Mutasi Barang</h3>
        <div class="overflow-x-auto mb-4">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border-b">Tanggal</th>
                        <th class="p-3 border-b">Ref</th>
                        <th class="p-3 border-b">SKU</th>
                        <th class="p-3 border-b">Nama Barang</th>
                        <th class="p-3 border-b text-center">Tipe</th>
                        <th class="p-3 border-b text-right">Jumlah</th>
                        <th class="p-3 border-b">Ket</th>
                        <th class="p-3 border-b text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($history as $h): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 whitespace-nowrap"><?= date('d/m/Y', strtotime($h['date'])) ?></td>
                        <td class="p-3 font-mono text-xs uppercase"><?= h($h['reference']) ?></td>
                        <td class="p-3 font-mono text-xs"><?= h($h['sku']) ?></td>
                        <td class="p-3 font-bold text-gray-700"><?= h($h['prod_name']) ?></td>
                        <td class="p-3 text-center">
                            <span class="px-2 py-1 rounded text-xs font-bold <?= $h['type']=='IN'?'bg-green-100 text-green-700':'bg-red-100 text-red-700' ?>">
                                <?= $h['type']=='IN'?'MASUK':'KELUAR' ?>
                            </span>
                        </td>
                        <td class="p-3 text-right font-bold"><?= number_format($h['quantity']) ?></td>
                        <td class="p-3 text-gray-500 italic truncate max-w-xs"><?= h($h['notes']) ?></td>
                        <td class="p-3 text-center">
                            <a href="?page=cetak_surat_jalan&id=<?= $h['id'] ?>" target="_blank" class="text-blue-600 hover:underline text-xs"><i class="fas fa-print"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($history)): ?>
                        <tr><td colspan="8" class="p-8 text-center text-gray-400">Belum ada riwayat transaksi pada periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION CONTROLS -->
        <?php if($total_pages > 1): ?>
        <div class="flex justify-center gap-2 mt-4">
            <?php if($page_num > 1): ?>
                <a href="?page=detail_gudang&id=<?= $id ?>&tab=history&p=<?= $page_num - 1 ?>&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" class="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded text-sm">Prev</a>
            <?php endif; ?>
            <span class="px-3 py-1 text-sm font-bold text-gray-600">Halaman <?= $page_num ?> dari <?= $total_pages ?></span>
            <?php if($page_num < $total_pages): ?>
                <a href="?page=detail_gudang&id=<?= $id ?>&tab=history&p=<?= $page_num + 1 ?>&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" class="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded text-sm">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    <!-- TAB 3: KEUANGAN WILAYAH -->
    <?php elseif($tab == 'finance'): ?>
        <!-- ... Content Finance (Sama seperti sebelumnya) ... -->
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h3 class="font-bold text-gray-800">Laporan Akun 1004 & 2009</h3>
            <div class="text-xs text-gray-500 italic bg-yellow-50 px-2 py-1 rounded border border-yellow-200">
                <i class="fas fa-info-circle"></i> Menampilkan transaksi yang mengandung nama "<?= h($warehouse['name']) ?>" pada deskripsi.
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-green-50 p-4 rounded border border-green-200 text-center">
                <p class="text-xs text-green-700 font-bold uppercase">Total Pemasukan (1004)</p>
                <p class="text-xl font-bold text-green-800"><?= formatRupiah($total_rev) ?></p>
            </div>
            <div class="bg-red-50 p-4 rounded border border-red-200 text-center">
                <p class="text-xs text-red-700 font-bold uppercase">Total Pengeluaran (2009)</p>
                <p class="text-xl font-bold text-red-800"><?= formatRupiah($total_exp) ?></p>
            </div>
            <div class="bg-blue-50 p-4 rounded border border-blue-200 text-center">
                <p class="text-xs text-blue-700 font-bold uppercase">Saldo Wilayah</p>
                <p class="text-xl font-bold text-blue-800"><?= formatRupiah($total_rev - $total_exp) ?></p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border-b">Tanggal</th>
                        <th class="p-3 border-b">Akun</th>
                        <th class="p-3 border-b">Keterangan</th>
                        <th class="p-3 border-b text-right">Nominal</th>
                        <th class="p-3 border-b text-center">User</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($finances as $f): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 whitespace-nowrap"><?= date('d/m/Y', strtotime($f['date'])) ?></td>
                        <td class="p-3">
                            <div class="font-bold text-gray-800"><?= h($f['acc_name']) ?></div>
                            <div class="text-xs text-gray-500 font-mono"><?= h($f['code']) ?></div>
                        </td>
                        <td class="p-3 text-gray-600"><?= h($f['description']) ?></td>
                        <td class="p-3 text-right font-bold <?= $f['type']=='INCOME'?'text-green-600':'text-red-600' ?>">
                            <?= $f['type']=='INCOME' ? '+' : '-' ?> <?= formatRupiah($f['amount']) ?>
                        </td>
                        <td class="p-3 text-center text-xs text-gray-500"><?= h($f['username']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($finances)): ?>
                        <tr><td colspan="5" class="p-12 text-center text-gray-400">Tidak ada data keuangan.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>