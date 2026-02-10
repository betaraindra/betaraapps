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

// --- HANDLE POST REQUESTS (Activity Actions) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. DELETE AKTIVITAS (ROLLBACK STOK & SN)
    if (isset($_POST['delete_activity'])) {
        validate_csrf();
        $trx_id = $_POST['trx_id'];
        
        $pdo->beginTransaction();
        try {
            // Ambil Data Transaksi
            $stmt = $pdo->prepare("SELECT product_id, quantity, notes FROM inventory_transactions WHERE id = ? AND warehouse_id = ? AND type='OUT'");
            $stmt->execute([$trx_id, $id]);
            $trx = $stmt->fetch();
            
            if (!$trx) throw new Exception("Transaksi tidak ditemukan atau tidak valid.");

            // 1. Kembalikan Stok Produk
            $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([$trx['quantity'], $trx['product_id']]);

            // 2. Kembalikan Status SN menjadi AVAILABLE
            // Cari SN yang terikat dengan transaksi OUT ini
            $stmtSn = $pdo->prepare("UPDATE product_serials SET status='AVAILABLE', out_transaction_id=NULL WHERE out_transaction_id = ?");
            $stmtSn->execute([$trx_id]);
            
            // 3. Hapus Record Transaksi
            $pdo->prepare("DELETE FROM inventory_transactions WHERE id = ?")->execute([$trx_id]);

            // 4. Log Aktivitas
            logActivity($pdo, 'ROLLBACK_ACTIVITY', "Rollback Aktivitas Gudang ID $id. Item ID {$trx['product_id']} Qty {$trx['quantity']} dikembalikan.");

            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Aktivitas dihapus. Stok dan SN telah dikembalikan (Rollback).'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal Rollback: ' . $e->getMessage()];
        }
        echo "<script>window.location='?page=detail_gudang&id=$id&tab=activity';</script>";
        exit;
    }

    // 2. EDIT AKTIVITAS (Update Notes/Ref)
    if (isset($_POST['edit_activity_save'])) {
        validate_csrf();
        $trx_id = $_POST['trx_id'];
        $new_ref = $_POST['reference'];
        $new_note = $_POST['notes'];
        
        try {
            $pdo->prepare("UPDATE inventory_transactions SET reference = ?, notes = ? WHERE id = ?")->execute([$new_ref, $new_note, $trx_id]);
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data aktivitas diperbarui.'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal update: ' . $e->getMessage()];
        }
        echo "<script>window.location='?page=detail_gudang&id=$id&tab=activity';</script>";
        exit;
    }
}

// --- FILTER ---
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');
$q = $_GET['q'] ?? '';
$tab = $_GET['tab'] ?? 'stock'; // stock, history, finance, activity

// --- 1. OPTIMIZED DATA STOK FISIK (Menggunakan JOIN aggregation) ---
$total_asset_value = 0;
$total_ready_qty = 0;

if ($tab === 'stock') {
    // UPDATE LOGIC: Unit Ready dihitung real dari tabel SN (Status AVAILABLE) jika barang ber-SN
    // Jika non-SN, fallback ke hitungan Transaksi
    $sql_stock = "
        SELECT p.id, p.sku, p.name, p.unit, p.buy_price, p.has_serial_number,
               -- Total Masuk
               COALESCE(SUM(CASE WHEN i.type = 'IN' THEN i.quantity ELSE 0 END), 0) as qty_in,
               
               -- Total Keluar (SEMUA)
               COALESCE(SUM(CASE WHEN i.type = 'OUT' THEN i.quantity ELSE 0 END), 0) as qty_out_total,
               
               -- Keluar Khusus Aktivitas/Pemakaian
               COALESCE(SUM(CASE 
                    WHEN i.type = 'OUT' AND (i.notes LIKE 'Aktivitas:%' OR i.notes LIKE '%[PEMAKAIAN]%') 
                    THEN i.quantity ELSE 0 
               END), 0) as qty_used,
               
               -- Real Available Count from product_serials (Subquery)
               (SELECT COUNT(*) FROM product_serials ps WHERE ps.product_id = p.id AND ps.warehouse_id = ? AND ps.status = 'AVAILABLE') as sn_ready_count
               
        FROM products p
        JOIN inventory_transactions i ON p.id = i.product_id
        WHERE i.warehouse_id = ?
        AND (p.name LIKE ? OR p.sku LIKE ?)
        GROUP BY p.id
        HAVING qty_in > 0
        ORDER BY p.name ASC
    ";
    $stmt_stock = $pdo->prepare($sql_stock);
    $stmt_stock->execute([$id, $id, "%$q%", "%$q%"]);
    $stocks = $stmt_stock->fetchAll();
    
    foreach($stocks as $s) {
        $trx_ready = $s['qty_in'] - $s['qty_out_total'];
        
        // PRIORITAS: Unit Ready diambil dari Count Available SN jika has_serial_number=1
        // Jika SN status SOLD (sudah terpakai di aktivitas), count ini TIDAK akan memasukkannya.
        $ready_stock = ($s['has_serial_number'] == 1) ? $s['sn_ready_count'] : $trx_ready;
        
        $used_stock = $s['qty_used'];
        $asset_qty = $ready_stock + $used_stock;
        $asset_value = $asset_qty * $s['buy_price'];
        
        $total_asset_value += $asset_value;
        $total_ready_qty += $ready_stock;
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

    $sql_count = "SELECT COUNT(*) FROM inventory_transactions i JOIN products p ON i.product_id = p.id WHERE i.warehouse_id = ? AND i.date BETWEEN ? AND ? AND (p.name LIKE ? OR p.sku LIKE ? OR i.reference LIKE ? OR i.notes LIKE ?)";
    $stmt_count = $pdo->prepare($sql_count);
    $stmt_count->execute([$id, $start_date, $end_date, "%$q%", "%$q%", "%$q%", "%$q%"]);
    $total_rows = $stmt_count->fetchColumn();
    $total_pages = ceil($total_rows / $limit);
}

// --- 3. KEUANGAN WILAYAH ---
if ($tab === 'finance') {
    $wh_name_clean = trim($warehouse['name']);
    
    // UPDATE: Ambil type akun untuk filtering ASSET
    $sql_fin = "SELECT f.*, a.code, a.name as acc_name, a.type as acc_type, u.username
                FROM finance_transactions f
                JOIN accounts a ON f.account_id = a.id
                JOIN users u ON f.user_id = u.id
                WHERE f.date BETWEEN ? AND ?
                AND f.description LIKE ?
                AND (f.description LIKE ? OR a.name LIKE ? OR a.code LIKE ?)
                ORDER BY f.date DESC";
                
    $stmt_fin = $pdo->prepare($sql_fin);
    $stmt_fin->execute([$start_date, $end_date, "%[Wilayah: $wh_name_clean]%", "%$q%", "%$q%", "%$q%"]);
    $finances = $stmt_fin->fetchAll();

    $total_rev = 0; $total_exp = 0;
    foreach($finances as $f) {
        if($f['type'] == 'INCOME') {
            $total_rev += $f['amount']; 
        } else {
            // FIX: Jangan hitung pembelian aset (stok) sebagai pengeluaran profit operasional
            if ($f['acc_type'] !== 'ASSET') {
                $total_exp += $f['amount'];
            }
        }
    }
}

// --- 4. AKTIVITAS / PEMAKAIAN (TAB BARU) ---
if ($tab === 'activity') {
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

    $sql_count_act = "SELECT COUNT(*) FROM inventory_transactions i JOIN products p ON i.product_id = p.id WHERE i.warehouse_id = ? AND i.type = 'OUT' AND i.notes LIKE 'Aktivitas:%' AND i.date BETWEEN ? AND ? AND (p.name LIKE ? OR p.sku LIKE ? OR i.reference LIKE ? OR i.notes LIKE ?)";
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
        <!-- Shortcut ke Input Aktivitas -->
        <a href="?page=input_aktivitas" class="bg-green-600 text-white px-4 py-2 rounded shadow hover:bg-green-700 font-bold flex items-center gap-2">
            <i class="fas fa-tools"></i> Input Aktivitas Baru
        </a>

        <?php if($tab === 'stock'): ?>
        <div class="bg-yellow-100 text-yellow-800 px-4 py-2 rounded text-center border border-yellow-200 shadow-sm">
            <div class="text-xs font-bold uppercase">Total Stok Fisik</div>
            <div class="font-bold text-lg"><?= number_format($total_ready_qty) ?> <span class="text-xs">Qty</span></div>
        </div>
        <div class="bg-blue-100 text-blue-800 px-4 py-2 rounded text-center border border-blue-200 shadow-sm">
            <div class="text-xs font-bold uppercase">Total Nilai Aset</div>
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
       <i class="fas fa-boxes mr-2"></i> Stok Fisik & Aset
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
        <h3 class="font-bold text-gray-800 mb-4 border-b pb-2">Posisi Stok & Aset Wilayah</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border-b">SKU</th>
                        <th class="p-3 border-b">Nama Barang</th>
                        <th class="p-3 border-b text-right text-green-600" title="Total semua barang yang pernah masuk">Total Masuk</th>
                        <th class="p-3 border-b text-right text-purple-600" title="Barang dipakai untuk aktivitas internal">Terpakai (Akt)</th>
                        <th class="p-3 border-b text-right text-red-600" title="Barang keluar dijual/transfer (Bukan aktivitas)">Keluar Lain</th>
                        <th class="p-3 border-b text-right bg-blue-100 text-blue-800 font-bold" title="Barang fisik tersedia di rak (Cek SN Available)">Unit Ready</th>
                        <th class="p-3 border-b text-center">Sat</th>
                        <th class="p-3 border-b text-right font-bold text-gray-800" title="(Ready + Terpakai) x Harga Beli">Nilai Aset (Rp)</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($stocks as $s): 
                        $trx_ready = $s['qty_in'] - $s['qty_out_total'];
                        // LOGIC: Jika barang ber-SN, Unit Ready MURNI dari count SN available. 
                        // Jika SN sudah terpakai (Aktivitas), statusnya SOLD, tidak akan masuk sini.
                        $ready_stock = ($s['has_serial_number'] == 1) ? $s['sn_ready_count'] : $trx_ready;
                        
                        $used_stock = $s['qty_used'];
                        $other_out = $s['qty_out_total'] - $used_stock;
                        $asset_qty = $ready_stock + $used_stock;
                        $asset_value = $asset_qty * $s['buy_price'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 font-mono text-xs"><?= h($s['sku']) ?></td>
                        <td class="p-3 font-medium"><?= h($s['name']) ?></td>
                        <td class="p-3 text-right text-green-600 font-medium"><?= number_format($s['qty_in']) ?></td>
                        <td class="p-3 text-right text-purple-600 font-bold"><?= number_format($used_stock) ?></td>
                        <td class="p-3 text-right text-red-600"><?= number_format($other_out) ?></td>
                        <td class="p-3 text-right font-bold bg-blue-50 text-blue-800 text-lg"><?= number_format($ready_stock) ?></td>
                        <td class="p-3 text-center text-xs text-gray-500"><?= h($s['unit']) ?></td>
                        <td class="p-3 text-right font-bold"><?= formatRupiah($asset_value) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($stocks)): ?>
                        <tr><td colspan="8" class="p-8 text-center text-gray-400">Tidak ada stok barang di gudang ini sesuai filter.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="text-xs text-gray-500 mt-3 italic">
                * <b>Unit Ready</b>: Stok fisik yang tersedia di gudang. (Untuk barang SN, dihitung dari jumlah SN status 'AVAILABLE').<br>
                * <b>Terpakai (Akt)</b>: Barang yang sudah digunakan untuk aktivitas wilayah (SN sudah 'SOLD').<br>
            </p>
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
                        <th class="p-3 border-b w-24">Tanggal</th>
                        <th class="p-3 border-b w-32">Ref</th>
                        <th class="p-3 border-b">Barang (SKU)</th>
                        <th class="p-3 border-b w-20 text-right">Jumlah</th>
                        <th class="p-3 border-b w-48">Serial Number (SN)</th>
                        <th class="p-3 border-b">Detail Aktivitas</th>
                        <th class="p-3 border-b w-24 text-center">User</th>
                        <th class="p-3 border-b w-20 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($activities as $act): 
                        // Clean Notes: Hapus string [SN: ...] jika ada, karena sudah ada kolom SN sendiri
                        $clean_notes = preg_replace('/\[SN:.*?\]/', '', $act['notes']);
                        $clean_notes = str_replace("Aktivitas:", "<b>Aktivitas:</b>", $clean_notes);
                        
                        // FETCH SN (Jika tidak ada di notes, cari di DB serials)
                        $stmtSn = $pdo->prepare("SELECT serial_number FROM product_serials WHERE out_transaction_id = ? ORDER BY serial_number ASC");
                        $stmtSn->execute([$act['id']]);
                        $sns = $stmtSn->fetchAll(PDO::FETCH_COLUMN);
                        
                        // Jika tidak ada di DB, coba parse dari notes (legacy data)
                        if (empty($sns) && preg_match('/\[SN:\s*(.*?)\]/', $act['notes'], $matches)) {
                            $sn_string = $matches[1];
                        } else {
                            $sn_string = !empty($sns) ? implode(', ', $sns) : '-';
                        }
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 whitespace-nowrap"><?= date('d/m/Y', strtotime($act['date'])) ?></td>
                        <td class="p-3 font-mono text-xs font-bold text-blue-700"><?= h($act['reference']) ?></td>
                        <td class="p-3">
                            <div class="font-bold text-gray-800"><?= h($act['prod_name']) ?></div>
                            <div class="text-xs text-gray-500"><?= h($act['sku']) ?></div>
                        </td>
                        <td class="p-3 text-right font-bold text-red-600"><?= number_format($act['quantity']) ?></td>
                        <td class="p-3 font-mono text-[10px] text-gray-600 break-all bg-yellow-50 rounded border border-yellow-100 p-1">
                            <?= h($sn_string) ?>
                        </td>
                        <td class="p-3 text-gray-700 text-xs"><?= $clean_notes ?></td>
                        <td class="p-3 text-center text-xs text-gray-500"><?= h($act['username']) ?></td>
                        <td class="p-3 text-center">
                            <div class="flex justify-center gap-1">
                                <button type="button" onclick='openEditActivity(<?= json_encode($act) ?>)' class="text-blue-500 hover:text-blue-700 p-1 rounded hover:bg-blue-50" title="Edit Catatan"><i class="fas fa-edit"></i></button>
                                
                                <form method="POST" onsubmit="return confirm('Yakin Hapus (Rollback)? Stok akan dikembalikan & SN akan Available kembali.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_activity" value="1">
                                    <input type="hidden" name="trx_id" value="<?= $act['id'] ?>">
                                    <button class="text-red-500 hover:text-red-700 p-1 rounded hover:bg-red-50" title="Hapus & Kembalikan Stok"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($activities)): ?>
                        <tr><td colspan="8" class="p-12 text-center text-gray-400">Belum ada data aktivitas/pemakaian pada periode ini.</td></tr>
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
        <!-- ... (Existing History View) ... -->
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
                </tbody>
            </table>
        </div>
        <!-- ... (Existing Pagination) ... -->

    <!-- TAB 3: KEUANGAN WILAYAH -->
    <?php elseif($tab == 'finance'): ?>
        <!-- ... (Existing Finance View) ... -->
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h3 class="font-bold text-gray-800">Laporan Arus Kas Wilayah</h3>
            <div class="text-xs text-gray-500 italic bg-yellow-50 px-2 py-1 rounded border border-yellow-200">
                <i class="fas fa-info-circle"></i> Menampilkan semua transaksi yang di-tag "<?= h($warehouse['name']) ?>" pada deskripsi.
            </div>
        </div>
        <!-- ... (Stats Cards & Table) ... -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-green-50 p-4 rounded border border-green-200 text-center">
                <p class="text-xs text-green-700 font-bold uppercase">Total Pemasukan</p>
                <p class="text-xl font-bold text-green-800"><?= formatRupiah($total_rev) ?></p>
            </div>
            <div class="bg-red-50 p-4 rounded border border-red-200 text-center">
                <p class="text-xs text-red-700 font-bold uppercase">Total Pengeluaran (Ops)</p>
                <p class="text-xl font-bold text-red-800"><?= formatRupiah($total_exp) ?></p>
            </div>
            <div class="bg-blue-50 p-4 rounded border border-blue-200 text-center">
                <p class="text-xs text-blue-700 font-bold uppercase">Saldo Wilayah (Net)</p>
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
                            <?php if($f['acc_type'] === 'ASSET' && $f['type'] === 'EXPENSE'): ?>
                                <span class="text-[9px] block text-gray-400 italic">(Aset - Excluded from Profit)</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 text-center text-xs text-gray-500"><?= h($f['username']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<!-- MODAL EDIT AKTIVITAS (REUSED FROM PREV) -->
<div id="modalEditActivity" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[60] flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <button onclick="document.getElementById('modalEditActivity').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
            <i class="fas fa-times text-xl"></i>
        </button>
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-blue-700">
            <i class="fas fa-edit"></i> Edit Catatan Aktivitas
        </h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="edit_activity_save" value="1">
            <input type="hidden" name="trx_id" id="edit_trx_id">
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Referensi</label>
                <input type="text" name="reference" id="edit_reference" class="w-full border p-2 rounded text-sm">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Catatan / Detail</label>
                <textarea name="notes" id="edit_notes" class="w-full border p-2 rounded text-sm" rows="3"></textarea>
            </div>
            <div class="bg-yellow-50 p-2 text-xs text-yellow-800 rounded mb-4">
                Note: Mengubah jumlah item atau SN tidak diizinkan di sini. Silakan Hapus (Rollback) dan input ulang jika ada kesalahan jumlah.
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700">Simpan Perubahan</button>
        </form>
    </div>
</div>

<script>
function openEditActivity(data) {
    document.getElementById('edit_trx_id').value = data.id;
    document.getElementById('edit_reference').value = data.reference;
    document.getElementById('edit_notes').value = data.notes;
    document.getElementById('modalEditActivity').classList.remove('hidden');
}
</script>