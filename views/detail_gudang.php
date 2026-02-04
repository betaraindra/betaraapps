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

// --- LOGIC BARU: HANDLE SIMPAN AKTIVITAS (PEMAKAIAN MULTI-ITEM) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_activity'])) {
    validate_csrf();
    
    $act_date = $_POST['act_date'];
    $act_desc_main = trim($_POST['act_desc']);
    $act_ref = trim($_POST['act_ref']); // Misal ID Pelanggan
    $cart_json = $_POST['activity_cart_json'];
    $cart_items = json_decode($cart_json, true);

    if (empty($cart_items) || !is_array($cart_items)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: Tidak ada barang yang dipilih.'];
    } else {
        $pdo->beginTransaction();
        try {
            // Generate Auto Reference jika kosong (Satu Ref untuk semua item di sesi ini)
            if(empty($act_ref)) $act_ref = "ACT/" . date('ymd') . "/" . rand(100,999);
            
            // Ambil ID Akun "Pengeluaran Wilayah (2009)"
            // Jika tidak ada, fallback ke Pengeluaran Material (2105) atau Operasional (2002)
            $accStmt = $pdo->query("SELECT id FROM accounts WHERE code = '2009' LIMIT 1");
            $accId = $accStmt->fetchColumn();
            if (!$accId) {
                $accId = $pdo->query("SELECT id FROM accounts WHERE code = '2105' LIMIT 1")->fetchColumn();
            }

            $success_count = 0;

            foreach ($cart_items as $item) {
                $prod_id = $item['id'];
                $qty = (float)$item['qty'];
                $notes_item = isset($item['notes']) ? trim($item['notes']) : '';

                // Validasi Stok Per Item & Ambil Harga Beli (HPP)
                $stmtCheck = $pdo->prepare("
                    SELECT p.name, p.buy_price,
                           (COALESCE(SUM(CASE WHEN i.type = 'IN' THEN i.quantity ELSE 0 END), 0) -
                            COALESCE(SUM(CASE WHEN i.type = 'OUT' THEN i.quantity ELSE 0 END), 0)) as current_stock
                    FROM products p
                    JOIN inventory_transactions i ON p.id = i.product_id
                    WHERE i.warehouse_id = ? AND p.id = ?
                    GROUP BY p.id
                ");
                $stmtCheck->execute([$id, $prod_id]);
                $stockData = $stmtCheck->fetch();
                
                if (!$stockData) {
                    throw new Exception("Barang ID $prod_id tidak ditemukan di gudang ini.");
                }
                
                if ($stockData['current_stock'] < $qty) {
                    throw new Exception("Stok {$stockData['name']} tidak cukup (Sisa: {$stockData['current_stock']}).");
                }

                // Format Catatan: Gabungan Deskripsi Utama + Note per Item
                $final_notes = "Aktivitas: " . $act_desc_main;
                if (!empty($notes_item)) {
                    $final_notes .= " (" . $notes_item . ")";
                }
                
                // 1. Simpan Transaksi OUT (Inventori)
                $stmtIns = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$act_date, $prod_id, $id, $qty, $act_ref, $final_notes, $_SESSION['user_id']]);
                
                // 2. Simpan Transaksi Keuangan (Expense) -> Mengurangi Nilai Aset Wilayah
                // Nilai = Qty * Harga Beli
                $total_value = $qty * $stockData['buy_price'];
                
                if ($accId && $total_value > 0) {
                    // Tag Wilayah wajib ada agar masuk filter Laporan Wilayah
                    $fin_desc = "[PEMAKAIAN] {$stockData['name']} ($qty) - $act_desc_main [Wilayah: {$warehouse['name']}]";
                    
                    $stmtFin = $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)");
                    $stmtFin->execute([$act_date, $accId, $total_value, $fin_desc, $_SESSION['user_id']]);
                }

                $success_count++;
            }

            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$success_count item aktivitas berhasil dicatat. Stok & Keuangan diperbarui."];

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: ' . $e->getMessage()];
        }
    }
    
    echo "<script>window.location='?page=detail_gudang&id=$id&tab=activity';</script>";
    exit;
}

// --- FILTER ---
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');
$q = $_GET['q'] ?? '';
$tab = $_GET['tab'] ?? 'stock'; // stock, history, finance, activity

// --- QUERY PRODUK TERSEDIA (UNTUK MODAL AKTIVITAS) ---
// Hanya ambil produk yang stoknya > 0 di gudang ini
$available_products = $pdo->prepare("
    SELECT p.id, p.name, p.sku, p.unit,
           (COALESCE(SUM(CASE WHEN i.type = 'IN' THEN i.quantity ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN i.type = 'OUT' THEN i.quantity ELSE 0 END), 0)) as stock
    FROM products p
    JOIN inventory_transactions i ON p.id = i.product_id
    WHERE i.warehouse_id = ?
    GROUP BY p.id
    HAVING stock > 0
    ORDER BY p.name ASC
");
$available_products->execute([$id]);
$modal_products = $available_products->fetchAll();

// --- 1. OPTIMIZED DATA STOK FISIK (Menggunakan JOIN aggregation) ---
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
if ($tab === 'finance') {
    $wh_name_clean = trim($warehouse['name']);
    
    // UPDATE: Menampilkan SEMUA Akun, bukan hanya (1004, 2009, 2105)
    // Filter berdasarkan Tag [Wilayah: Nama] di deskripsi transaksi
    $sql_fin = "SELECT f.*, a.code, a.name as acc_name, u.username
                FROM finance_transactions f
                JOIN accounts a ON f.account_id = a.id
                JOIN users u ON f.user_id = u.id
                WHERE f.date BETWEEN ? AND ?
                AND f.description LIKE ? -- Filter Wajib Tag Wilayah
                AND (f.description LIKE ? OR a.name LIKE ? OR a.code LIKE ?) -- Search Optional
                ORDER BY f.date DESC";
                
    $stmt_fin = $pdo->prepare($sql_fin);
    // Execute: Start, End, %TagWilayah%, %Search%, %Search%, %Search%
    $stmt_fin->execute([
        $start_date, 
        $end_date, 
        "%[Wilayah: $wh_name_clean]%", 
        "%$q%", "%$q%", "%$q%"
    ]);
    $finances = $stmt_fin->fetchAll();

    $total_rev = 0; $total_exp = 0;
    foreach($finances as $f) {
        if($f['type'] == 'INCOME') $total_rev += $f['amount']; else $total_exp += $f['amount'];
    }
}

// --- 4. AKTIVITAS / PEMAKAIAN (TAB BARU) ---
if ($tab === 'activity') {
    // Mirip History tapi filter khusus notes LIKE 'Aktivitas:%' dan type OUT
    $page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $limit = 20;
    $offset = ($page_num - 1) * $limit;

    $sql_act = "SELECT i.*, p.name as prod_name, p.sku, u.username 
                 FROM inventory_transactions i 
                 JOIN products p ON i.product_id = p.id 
                 JOIN users u ON i.user_id = u.id 
                 WHERE i.warehouse_id = ? 
                 AND i.type = 'OUT' 
                 AND i.notes LIKE 'Aktivitas:%'
                 AND i.date BETWEEN ? AND ?
                 AND (p.name LIKE ? OR p.sku LIKE ? OR i.reference LIKE ? OR i.notes LIKE ?)
                 ORDER BY i.date DESC, i.created_at DESC
                 LIMIT $limit OFFSET $offset";
    
    $stmt_act = $pdo->prepare($sql_act);
    $stmt_act->execute([$id, $start_date, $end_date, "%$q%", "%$q%", "%$q%", "%$q%"]);
    $activities = $stmt_act->fetchAll();

    $sql_count_act = "SELECT COUNT(*) 
                  FROM inventory_transactions i 
                  JOIN products p ON i.product_id = p.id 
                  WHERE i.warehouse_id = ? 
                  AND i.type = 'OUT'
                  AND i.notes LIKE 'Aktivitas:%'
                  AND i.date BETWEEN ? AND ?
                  AND (p.name LIKE ? OR p.sku LIKE ? OR i.reference LIKE ? OR i.notes LIKE ?)";
    $stmt_count_act = $pdo->prepare($sql_count_act);
    $stmt_count_act->execute([$id, $start_date, $end_date, "%$q%", "%$q%", "%$q%", "%$q%"]);
    $total_rows_act = $stmt_count_act->fetchColumn();
    $total_pages_act = ceil($total_rows_act / $limit);
}
?>

<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <div class="flex items-center gap-2">
            <a href="?page=data_wilayah&start=<?=$start_date?>&end=<?=$end_date?>" class="text-gray-500 hover:text-gray-700 bg-white p-2 rounded shadow-sm border border-gray-200">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
            <h2 class="text-2xl font-bold text-gray-800 ml-2"><i class="fas fa-warehouse text-blue-600"></i> <?= h($warehouse['name']) ?></h2>
        </div>
        <p class="text-gray-500 text-sm ml-14"><i class="fas fa-map-marker-alt"></i> <?= h($warehouse['location']) ?></p>
    </div>
    
    <div class="flex gap-4 items-center">
        <!-- TOMBOL INPUT AKTIVITAS -->
        <button onclick="document.getElementById('modalActivity').classList.remove('hidden')" class="bg-green-600 text-white px-4 py-2 rounded shadow hover:bg-green-700 font-bold flex items-center gap-2">
            <i class="fas fa-tools"></i> Input Aktivitas / Pemakaian
        </button>

        <?php if($tab === 'stock'): ?>
        <div class="bg-blue-100 text-blue-800 px-4 py-2 rounded text-center border border-blue-200">
            <div class="text-xs font-bold uppercase">Nilai Aset Fisik</div>
            <div class="font-bold text-lg"><?= formatRupiah($total_asset_value) ?></div>
        </div>
        <?php endif; ?>
    </div>
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
<div class="flex border-b border-gray-200 mb-6 bg-white rounded-t-lg overflow-x-auto">
    <a href="?page=detail_gudang&id=<?= $id ?>&tab=stock&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" 
       class="flex-1 py-3 text-center text-sm font-bold border-b-2 hover:bg-gray-50 whitespace-nowrap px-4 <?= $tab=='stock' ? 'border-blue-600 text-blue-600 bg-blue-50' : 'border-transparent text-gray-500' ?>">
       <i class="fas fa-boxes mr-2"></i> Stok Fisik
    </a>
    <a href="?page=detail_gudang&id=<?= $id ?>&tab=activity&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" 
       class="flex-1 py-3 text-center text-sm font-bold border-b-2 hover:bg-gray-50 whitespace-nowrap px-4 <?= $tab=='activity' ? 'border-purple-600 text-purple-600 bg-purple-50' : 'border-transparent text-gray-500' ?>">
       <i class="fas fa-tools mr-2"></i> Aktivitas/Pemakaian
    </a>
    <a href="?page=detail_gudang&id=<?= $id ?>&tab=finance&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" 
       class="flex-1 py-3 text-center text-sm font-bold border-b-2 hover:bg-gray-50 whitespace-nowrap px-4 <?= $tab=='finance' ? 'border-green-600 text-green-600 bg-green-50' : 'border-transparent text-gray-500' ?>">
       <i class="fas fa-money-bill-wave mr-2"></i> Keuangan
    </a>
    <a href="?page=detail_gudang&id=<?= $id ?>&tab=history&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" 
       class="flex-1 py-3 text-center text-sm font-bold border-b-2 hover:bg-gray-50 whitespace-nowrap px-4 <?= $tab=='history' ? 'border-orange-600 text-orange-600 bg-orange-50' : 'border-transparent text-gray-500' ?>">
       <i class="fas fa-history mr-2"></i> Riwayat Mutasi Lengkap
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
    
    <!-- TAB 2: AKTIVITAS / PEMAKAIAN -->
    <?php elseif($tab == 'activity'): ?>
        <h3 class="font-bold text-gray-800 mb-4 border-b pb-2 flex items-center gap-2">
            <i class="fas fa-tools text-purple-600"></i> Laporan Aktivitas / Pemakaian Barang
        </h3>
        <div class="overflow-x-auto mb-4">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-purple-100 text-purple-900">
                    <tr>
                        <th class="p-3 border-b">Tanggal</th>
                        <th class="p-3 border-b">Ref (Pelanggan/Tiket)</th>
                        <th class="p-3 border-b">Barang (SKU)</th>
                        <th class="p-3 border-b text-right">Jumlah</th>
                        <th class="p-3 border-b">Detail Aktivitas</th>
                        <th class="p-3 border-b text-center">User</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($activities as $act): 
                        // Bersihkan prefix "Aktivitas:" dari display jika mau, tapi biarkan saja untuk jelas
                        $desc = str_replace("Aktivitas:", "<b>Aktivitas:</b>", h($act['notes']));
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 whitespace-nowrap"><?= date('d/m/Y', strtotime($act['date'])) ?></td>
                        <td class="p-3 font-mono text-xs font-bold text-blue-700"><?= h($act['reference']) ?></td>
                        <td class="p-3">
                            <div class="font-bold text-gray-800"><?= h($act['prod_name']) ?></div>
                            <div class="text-xs text-gray-500"><?= h($act['sku']) ?></div>
                        </td>
                        <td class="p-3 text-right font-bold text-red-600"><?= number_format($act['quantity']) ?></td>
                        <td class="p-3 text-gray-700"><?= $desc ?></td>
                        <td class="p-3 text-center text-xs text-gray-500"><?= h($act['username']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($activities)): ?>
                        <tr><td colspan="6" class="p-12 text-center text-gray-400">Belum ada data aktivitas/pemakaian pada periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION CONTROLS -->
        <?php if($total_pages_act > 1): ?>
        <div class="flex justify-center gap-2 mt-4">
            <?php if($page_num > 1): ?>
                <a href="?page=detail_gudang&id=<?= $id ?>&tab=activity&p=<?= $page_num - 1 ?>&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" class="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded text-sm">Prev</a>
            <?php endif; ?>
            <span class="px-3 py-1 text-sm font-bold text-gray-600">Halaman <?= $page_num ?> dari <?= $total_pages_act ?></span>
            <?php if($page_num < $total_pages_act): ?>
                <a href="?page=detail_gudang&id=<?= $id ?>&tab=activity&p=<?= $page_num + 1 ?>&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($q) ?>" class="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded text-sm">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    <!-- TAB 3: RIWAYAT MUTASI -->
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
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h3 class="font-bold text-gray-800">Laporan Arus Kas Wilayah</h3>
            <div class="text-xs text-gray-500 italic bg-yellow-50 px-2 py-1 rounded border border-yellow-200">
                <i class="fas fa-info-circle"></i> Menampilkan semua transaksi yang di-tag "<?= h($warehouse['name']) ?>" pada deskripsi.
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-green-50 p-4 rounded border border-green-200 text-center">
                <p class="text-xs text-green-700 font-bold uppercase">Total Pemasukan</p>
                <p class="text-xl font-bold text-green-800"><?= formatRupiah($total_rev) ?></p>
            </div>
            <div class="bg-red-50 p-4 rounded border border-red-200 text-center">
                <p class="text-xs text-red-700 font-bold uppercase">Total Pengeluaran</p>
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

<!-- MODAL INPUT AKTIVITAS (MULTI ITEM) -->
<div id="modalActivity" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6 relative flex flex-col max-h-[90vh]">
        <button onclick="document.getElementById('modalActivity').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
            <i class="fas fa-times text-xl"></i>
        </button>
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-green-700 flex items-center gap-2">
            <i class="fas fa-tools"></i> Input Aktivitas Wilayah
        </h3>
        <div class="mb-4 bg-green-50 p-3 rounded text-sm text-green-800 border border-green-200">
            <p><strong>Info:</strong> Gunakan fitur ini untuk mencatat pemakaian barang (modem, kabel, material) yang digunakan di wilayah ini.</p>
        </div>
        
        <form method="POST" class="flex-1 flex flex-col" onsubmit="return validateActivityForm()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_activity" value="1">
            <input type="hidden" name="activity_cart_json" id="activity_cart_json">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="act_date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded text-sm" required>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">ID Pelanggan / Ref (Umum)</label>
                    <input type="text" name="act_ref" class="w-full border p-2 rounded text-sm" placeholder="Contoh: PLG-001 / Tiket-123">
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Keterangan Aktivitas (Utama)</label>
                <input type="text" name="act_desc" class="w-full border p-2 rounded text-sm" placeholder="Contoh: Pemasangan baru di rumah Bpk Budi" required>
            </div>

            <!-- AREA INPUT BARANG (MINI CART) -->
            <div class="bg-gray-50 p-3 rounded border border-gray-200 mb-4">
                <h4 class="text-xs font-bold text-gray-600 mb-2 uppercase border-b pb-1">Tambah Barang ke Aktivitas</h4>
                <div class="flex flex-wrap gap-2 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-bold text-gray-500 mb-1">Pilih Barang</label>
                        <select id="act_item_select" class="w-full border p-2 rounded text-sm bg-white">
                            <option value="">-- Pilih Barang (Stok > 0) --</option>
                            <?php foreach($modal_products as $mp): ?>
                                <option value="<?= $mp['id'] ?>" data-name="<?= htmlspecialchars($mp['name']) ?>" data-stock="<?= $mp['stock'] ?>" data-unit="<?= $mp['unit'] ?>">
                                    <?= htmlspecialchars($mp['name']) ?> (Stok: <?= $mp['stock'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="w-20">
                        <label class="block text-xs font-bold text-gray-500 mb-1">Qty</label>
                        <input type="number" id="act_item_qty" class="w-full border p-2 rounded text-sm text-center font-bold" placeholder="1">
                    </div>
                    <div class="w-1/3">
                        <label class="block text-xs font-bold text-gray-500 mb-1">Ket. Item (Opsional)</label>
                        <input type="text" id="act_item_note" class="w-full border p-2 rounded text-sm" placeholder="SN/Lokasi...">
                    </div>
                    <div>
                        <button type="button" onclick="addActivityItem()" class="bg-blue-600 text-white px-3 py-2 rounded text-sm hover:bg-blue-700 font-bold"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
                <div id="act_stock_warning" class="text-xs text-red-500 mt-1 hidden font-bold">Stok tidak mencukupi!</div>
            </div>

            <!-- LIST BARANG -->
            <div class="flex-1 overflow-y-auto border rounded mb-4 bg-white">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100 text-gray-700 sticky top-0">
                        <tr>
                            <th class="p-2 border-b">Barang</th>
                            <th class="p-2 border-b text-center w-16">Qty</th>
                            <th class="p-2 border-b w-1/3">Catatan</th>
                            <th class="p-2 border-b text-center w-10"><i class="fas fa-trash"></i></th>
                        </tr>
                    </thead>
                    <tbody id="activity_cart_body">
                        <tr><td colspan="4" class="p-4 text-center text-gray-400 italic">Belum ada barang dipilih.</td></tr>
                    </tbody>
                </table>
            </div>
            
            <button type="submit" id="btn_save_activity" class="w-full bg-green-600 text-white py-3 rounded font-bold hover:bg-green-700 shadow disabled:bg-gray-400 disabled:cursor-not-allowed">
                Simpan Semua Aktivitas
            </button>
        </form>
    </div>
</div>

<script>
let activityCart = [];

function addActivityItem() {
    const select = document.getElementById('act_item_select');
    const qtyInput = document.getElementById('act_item_qty');
    const noteInput = document.getElementById('act_item_note');
    const warning = document.getElementById('act_stock_warning');
    
    const id = select.value;
    if(!id) return alert("Pilih barang dulu!");
    
    const qty = parseFloat(qtyInput.value);
    if(!qty || qty <= 0) return alert("Jumlah tidak valid!");
    
    const opt = select.options[select.selectedIndex];
    const name = opt.getAttribute('data-name');
    const stock = parseFloat(opt.getAttribute('data-stock'));
    const unit = opt.getAttribute('data-unit');
    const note = noteInput.value;

    // Cek stok lokal (cart + new)
    const existingIdx = activityCart.findIndex(item => item.id === id);
    let currentQtyInCart = 0;
    if(existingIdx > -1) currentQtyInCart = activityCart[existingIdx].qty;
    
    if((currentQtyInCart + qty) > stock) {
        warning.classList.remove('hidden');
        warning.innerText = `Stok tidak cukup! Tersedia: ${stock}, Di Keranjang: ${currentQtyInCart}, Input: ${qty}`;
        return;
    }
    warning.classList.add('hidden');

    // Add or Update
    if(existingIdx > -1) {
        activityCart[existingIdx].qty += qty;
        // Append note if different? Simple logic: overwrite or append. Let's append if not empty.
        if(note) activityCart[existingIdx].notes += (activityCart[existingIdx].notes ? ", " : "") + note;
    } else {
        activityCart.push({ id, name, qty, unit, notes: note });
    }

    // Reset Input Item
    select.value = "";
    qtyInput.value = "";
    noteInput.value = "";
    renderActivityCart();
}

function deleteActivityItem(index) {
    activityCart.splice(index, 1);
    renderActivityCart();
}

function renderActivityCart() {
    const tbody = document.getElementById('activity_cart_body');
    tbody.innerHTML = '';
    
    if(activityCart.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-gray-400 italic">Belum ada barang dipilih.</td></tr>';
        document.getElementById('btn_save_activity').disabled = true;
        return;
    }
    
    document.getElementById('btn_save_activity').disabled = false;

    activityCart.forEach((item, idx) => {
        const tr = document.createElement('tr');
        tr.className = 'border-b hover:bg-gray-50';
        tr.innerHTML = `
            <td class="p-2 font-medium">${item.name}</td>
            <td class="p-2 text-center font-bold text-blue-600">${item.qty} ${item.unit}</td>
            <td class="p-2 text-xs text-gray-500 italic">${item.notes || '-'}</td>
            <td class="p-2 text-center">
                <button type="button" onclick="deleteActivityItem(${idx})" class="text-red-500 hover:text-red-700"><i class="fas fa-times"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function validateActivityForm() {
    if(activityCart.length === 0) {
        alert("Pilih minimal satu barang!");
        return false;
    }
    document.getElementById('activity_cart_json').value = JSON.stringify(activityCart);
    return confirm("Simpan aktivitas ini? Stok gudang akan berkurang.");
}
</script>