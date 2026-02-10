<?php
checkRole(['SUPER_ADMIN', 'SVP', 'MANAGER', 'ADMIN_GUDANG', 'ADMIN_KEUANGAN']);

// --- 1. HANDLE DELETE (Same logic) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_type'])) {
    $id = $_POST['delete_id'];
    $type = $_POST['delete_type'];
    
    if ($type === 'FINANCE' && !in_array($_SESSION['role'], ['SUPER_ADMIN', 'SVP', 'MANAGER', 'ADMIN_KEUANGAN'])) {
        die("Akses Ditolak");
    }
    if ($type === 'INVENTORY' && !in_array($_SESSION['role'], ['SUPER_ADMIN', 'SVP', 'MANAGER', 'ADMIN_GUDANG'])) {
        die("Akses Ditolak");
    }

    $pdo->beginTransaction();
    try {
        if ($type === 'FINANCE') {
            $pdo->prepare("DELETE FROM finance_transactions WHERE id = ?")->execute([$id]);
            logActivity($pdo, 'DELETE_FINANCE', "Hapus Transaksi Keuangan ID: $id");
        } elseif ($type === 'INVENTORY') {
            $stmt = $pdo->prepare("SELECT * FROM inventory_transactions WHERE id = ?");
            $stmt->execute([$id]);
            $trx = $stmt->fetch();
            
            if ($trx) {
                // 1. AUTO DELETE FINANCE (SINKRONISASI)
                // Cari transaksi keuangan yang dibuat otomatis oleh transaksi barang ini
                // Mencocokkan: Tanggal, User, dan Pola Deskripsi (Nama Barang + Qty)
                
                $prod = $pdo->prepare("SELECT name FROM products WHERE id = ?");
                $prod->execute([$trx['product_id']]);
                $prodName = $prod->fetchColumn();

                if ($prodName) {
                    $qty = (float)$trx['quantity']; // Pastikan float/int bersih
                    $date = $trx['date'];
                    $userId = $trx['user_id'];
                    $finPattern = "";

                    if ($trx['type'] === 'IN') {
                        // Pola dari barang_masuk.php: "Stok Masuk: Nama (Qty)..."
                        $finPattern = "Stok Masuk: $prodName ($qty)%";
                    } elseif ($trx['type'] === 'OUT') {
                        // Pola dari barang_keluar.php: "Penjualan: Nama (Qty)..."
                        $finPattern = "Penjualan: $prodName ($qty)%";
                    }

                    if (!empty($finPattern)) {
                        // Hapus 1 record keuangan yang cocok (Limit 1 untuk safety)
                        $delFin = $pdo->prepare("DELETE FROM finance_transactions WHERE date = ? AND user_id = ? AND description LIKE ? LIMIT 1");
                        $delFin->execute([$date, $userId, $finPattern]);
                    }
                }

                // 2. ROLLBACK STOCK
                $operator = ($trx['type'] === 'IN') ? '-' : '+';
                $pdo->prepare("UPDATE products SET stock = stock $operator ? WHERE id = ?")->execute([$trx['quantity'], $trx['product_id']]);
                
                // 3. ROLLBACK SN STATUS (Jika ada)
                if ($trx['type'] === 'IN') {
                    // Jika barang masuk dihapus, hapus SN yang dibuat (jika status masih AVAILABLE)
                    $pdo->prepare("DELETE FROM product_serials WHERE in_transaction_id = ? AND status = 'AVAILABLE'")->execute([$id]);
                } elseif ($trx['type'] === 'OUT') {
                    // Jika barang keluar dihapus, kembalikan status SN menjadi AVAILABLE
                    $pdo->prepare("UPDATE product_serials SET status = 'AVAILABLE', out_transaction_id = NULL WHERE out_transaction_id = ?")->execute([$id]);
                }

                // 4. HAPUS TRANSAKSI INVENTORI
                $pdo->prepare("DELETE FROM inventory_transactions WHERE id = ?")->execute([$id]);
                logActivity($pdo, 'DELETE_INVENTORY', "Hapus Transaksi Inventori ID: $id (Stok & Keuangan Rollback)");
            }
        }
        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Transaksi berhasil dihapus. Stok & Keuangan terkait telah disesuaikan.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Gagal menghapus: ' . $e->getMessage()];
    }
    echo "<script>window.location='?page=data_transaksi&tab=" . strtolower($type) . "';</script>";
    exit;
}

// --- 2. PAGINATION CONFIG ---
$limit = 50; // Jumlah data per halaman
$page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$offset = ($page_num - 1) * $limit;

// Active Tab
$tab = $_GET['tab'] ?? 'finance';
$q = $_GET['q'] ?? '';

// Master Data
$accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

// --- LOGIC KEUANGAN ---
$finance_result = [];
$total_f_pages = 0;

if ($tab === 'finance') {
    $f_start = $_GET['f_start'] ?? date('Y-m-01');
    $f_end = $_GET['f_end'] ?? date('Y-m-d');
    $f_type = $_GET['f_type'] ?? 'ALL';
    $f_acc = $_GET['f_account'] ?? 'ALL';

    // Base Condition
    $cond = "f.date BETWEEN ? AND ?";
    $f_params = [$f_start, $f_end];

    if (!empty($q)) {
        $cond .= " AND (f.description LIKE ? OR a.name LIKE ? OR u.username LIKE ?)";
        $f_params[] = "%$q%"; $f_params[] = "%$q%"; $f_params[] = "%$q%";
    }
    if ($f_type !== 'ALL') {
        $cond .= " AND f.type = ?";
        $f_params[] = $f_type;
    }
    if ($f_acc !== 'ALL') {
        $cond .= " AND f.account_id = ?";
        $f_params[] = $f_acc;
    }

    // Query Data
    $f_sql = "SELECT f.*, a.name as acc_name, a.code as acc_code, u.username 
              FROM finance_transactions f 
              JOIN accounts a ON f.account_id=a.id 
              JOIN users u ON f.user_id=u.id 
              WHERE $cond 
              ORDER BY f.date DESC, f.created_at DESC
              LIMIT $limit OFFSET $offset";
    
    $stmt = $pdo->prepare($f_sql);
    $stmt->execute($f_params);
    $finance_result = $stmt->fetchAll();

    // Query Count (Untuk Pagination)
    $c_sql = "SELECT COUNT(*) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id JOIN users u ON f.user_id=u.id WHERE $cond";
    $stmt_c = $pdo->prepare($c_sql);
    $stmt_c->execute($f_params);
    $total_rows = $stmt_c->fetchColumn();
    $total_f_pages = ceil($total_rows / $limit);
}

// --- LOGIC INVENTORI ---
$inventory_result = [];
$total_i_pages = 0;

if ($tab === 'inventory') {
    $i_start = $_GET['i_start'] ?? date('Y-m-01');
    $i_end = $_GET['i_end'] ?? date('Y-m-d');
    $i_type = $_GET['i_type'] ?? 'ALL';
    $i_wh = $_GET['i_wh'] ?? 'ALL';

    $cond = "i.date BETWEEN ? AND ?";
    $i_params = [$i_start, $i_end];

    if (!empty($q)) {
        $cond .= " AND (p.name LIKE ? OR p.sku LIKE ? OR i.reference LIKE ? OR i.notes LIKE ? OR u.username LIKE ?)";
        $i_params[] = "%$q%"; $i_params[] = "%$q%"; $i_params[] = "%$q%"; $i_params[] = "%$q%"; $i_params[] = "%$q%";
    }
    if ($i_type !== 'ALL') {
        $cond .= " AND i.type = ?";
        $i_params[] = $i_type;
    }
    if ($i_wh !== 'ALL') {
        $cond .= " AND i.warehouse_id = ?";
        $i_params[] = $i_wh;
    }

    // Query Data
    $i_sql = "SELECT i.*, p.name as prod_name, p.sku, w.name as wh_name, u.username 
              FROM inventory_transactions i 
              JOIN products p ON i.product_id=p.id 
              JOIN warehouses w ON i.warehouse_id=w.id
              JOIN users u ON i.user_id=u.id 
              WHERE $cond 
              ORDER BY i.date DESC, i.created_at DESC
              LIMIT $limit OFFSET $offset";
    
    $stmt = $pdo->prepare($i_sql);
    $stmt->execute($i_params);
    $inventory_result = $stmt->fetchAll();

    // Query Count
    $c_sql = "SELECT COUNT(*) FROM inventory_transactions i JOIN products p ON i.product_id=p.id JOIN users u ON i.user_id=u.id WHERE $cond";
    $stmt_c = $pdo->prepare($c_sql);
    $stmt_c->execute($i_params);
    $total_rows = $stmt_c->fetchColumn();
    $total_i_pages = ceil($total_rows / $limit);
}
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-4">Data Transaksi</h2>
    
    <!-- TABS -->
    <div class="flex border-b border-gray-200 bg-white rounded-t-lg">
        <a href="?page=data_transaksi&tab=finance" class="flex-1 py-3 text-center font-bold border-b-2 <?= $tab=='finance' ? 'border-blue-600 text-blue-600 bg-blue-50' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
            <i class="fas fa-money-bill-wave mr-2"></i> Transaksi Keuangan
        </a>
        <a href="?page=data_transaksi&tab=inventory" class="flex-1 py-3 text-center font-bold border-b-2 <?= $tab=='inventory' ? 'border-orange-600 text-orange-600 bg-orange-50' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
            <i class="fas fa-boxes mr-2"></i> Transaksi Barang
        </a>
    </div>
</div>

<?php if ($tab === 'finance'): ?>
    <!-- TAB KEUANGAN -->
    <div class="bg-white p-6 rounded-b-lg shadow">
        
        <!-- FILTER BAR -->
        <form method="GET" class="mb-6 bg-gray-50 p-4 rounded border border-gray-200">
            <input type="hidden" name="page" value="data_transaksi">
            <input type="hidden" name="tab" value="finance">
            
            <div class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Cari (Ket, Akun, User)</label>
                    <input type="text" name="q" value="<?= h($q) ?>" class="border p-2 rounded text-sm w-full" placeholder="Kata kunci...">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Dari</label>
                    <input type="date" name="f_start" value="<?= $f_start ?>" class="border p-2 rounded text-sm w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Sampai</label>
                    <input type="date" name="f_end" value="<?= $f_end ?>" class="border p-2 rounded text-sm w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Jenis</label>
                    <select name="f_type" class="border p-2 rounded text-sm w-32 bg-white">
                        <option value="ALL">Semua</option>
                        <option value="INCOME" <?= $f_type=='INCOME'?'selected':'' ?>>Pemasukan</option>
                        <option value="EXPENSE" <?= $f_type=='EXPENSE'?'selected':'' ?>>Pengeluaran</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded font-bold text-sm shadow"><i class="fas fa-filter"></i></button>
                </div>
            </div>
        </form>

        <!-- TABLE -->
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700 border-b-2">
                    <tr>
                        <th class="p-3">Tanggal</th>
                        <th class="p-3">Akun</th>
                        <th class="p-3">Tipe</th>
                        <th class="p-3">Jumlah (Rp)</th>
                        <th class="p-3">Keterangan</th>
                        <th class="p-3">User</th>
                        <th class="p-3 text-center no-print">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($finance_result as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 whitespace-nowrap"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                        <td class="p-3">
                            <span class="font-bold text-gray-800"><?= h($row['acc_name']) ?></span>
                            <div class="text-xs text-gray-500 font-mono"><?= h($row['acc_code']) ?></div>
                        </td>
                        <td class="p-3">
                            <span class="px-2 py-1 rounded-full text-xs font-bold <?= $row['type']=='INCOME'?'bg-green-100 text-green-700':'bg-red-100 text-red-700' ?>">
                                <?= $row['type']=='INCOME' ? 'Pemasukan' : 'Pengeluaran' ?>
                            </span>
                        </td>
                        <td class="p-3 font-mono font-bold <?= $row['type']=='INCOME'?'text-green-700':'text-red-700' ?>">
                            <?= number_format($row['amount'], 0, ',', '.') ?>
                        </td>
                        <td class="p-3 text-gray-600 max-w-xs truncate" title="<?= h($row['description']) ?>">
                            <?= h($row['description']) ?>
                        </td>
                        <td class="p-3 text-gray-500 text-xs"><?= h($row['username']) ?></td>
                        <td class="p-3 text-center no-print">
                            <form method="POST" onsubmit="return confirm('Hapus data ini? Saldo akan berubah.')">
                                <input type="hidden" name="delete_type" value="FINANCE">
                                <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                <button class="text-red-500 hover:text-red-700 p-2" title="Hapus"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if($total_f_pages > 1): ?>
        <div class="flex justify-between items-center mt-4 border-t pt-4">
            <span class="text-xs text-gray-500">Halaman <?= $page_num ?> dari <?= $total_f_pages ?></span>
            <div class="flex gap-1">
                <?php if($page_num > 1): ?>
                    <a href="?page=data_transaksi&tab=finance&p=<?= $page_num-1 ?>&q=<?=h($q)?>&f_start=<?=$f_start?>&f_end=<?=$f_end?>&f_type=<?=$f_type?>" class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">Prev</a>
                <?php endif; ?>
                <?php if($page_num < $total_f_pages): ?>
                    <a href="?page=data_transaksi&tab=finance&p=<?= $page_num+1 ?>&q=<?=h($q)?>&f_start=<?=$f_start?>&f_end=<?=$f_end?>&f_type=<?=$f_type?>" class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- TAB INVENTORI -->
    <div class="bg-white p-6 rounded-b-lg shadow">
        
        <!-- FILTER BAR -->
        <form method="GET" class="mb-6 bg-gray-50 p-4 rounded border border-gray-200">
            <input type="hidden" name="page" value="data_transaksi">
            <input type="hidden" name="tab" value="inventory">
            
            <div class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Cari (Barang, SKU, Ref)</label>
                    <input type="text" name="q" value="<?= h($q) ?>" class="border p-2 rounded text-sm w-full" placeholder="Kata kunci...">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Dari</label>
                    <input type="date" name="i_start" value="<?= $i_start ?>" class="border p-2 rounded text-sm w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Sampai</label>
                    <input type="date" name="i_end" value="<?= $i_end ?>" class="border p-2 rounded text-sm w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Tipe</label>
                    <select name="i_type" class="border p-2 rounded text-sm w-32 bg-white">
                        <option value="ALL">Semua</option>
                        <option value="IN" <?= $i_type=='IN'?'selected':'' ?>>Masuk</option>
                        <option value="OUT" <?= $i_type=='OUT'?'selected':'' ?>>Keluar</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-orange-600 text-white px-4 py-2 rounded font-bold text-sm shadow"><i class="fas fa-filter"></i></button>
                </div>
            </div>
        </form>

        <!-- TABLE -->
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700 border-b-2">
                    <tr>
                        <th class="p-3">Tanggal</th>
                        <th class="p-3">Ref</th>
                        <th class="p-3">Barang</th>
                        <th class="p-3">Gudang</th>
                        <th class="p-3 text-center">Tipe</th>
                        <th class="p-3 text-right">Qty</th>
                        <th class="p-3">User</th>
                        <th class="p-3 text-center no-print">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($inventory_result as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 whitespace-nowrap"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                        <td class="p-3 font-mono text-xs"><?= h($row['reference']) ?></td>
                        <td class="p-3">
                            <div class="font-bold text-gray-800"><?= h($row['prod_name']) ?></div>
                            <div class="text-xs text-gray-500"><?= h($row['sku']) ?></div>
                        </td>
                        <td class="p-3 text-gray-600"><?= h($row['wh_name']) ?></td>
                        <td class="p-3 text-center">
                            <span class="px-2 py-1 rounded text-xs font-bold <?= $row['type']=='IN'?'bg-green-100 text-green-700':'bg-red-100 text-red-700' ?>">
                                <?= $row['type']=='IN'?'MASUK':'KELUAR' ?>
                            </span>
                        </td>
                        <td class="p-3 text-right font-bold"><?= $row['quantity'] ?></td>
                        <td class="p-3 text-xs text-gray-500"><?= h($row['username']) ?></td>
                        <td class="p-3 text-center no-print">
                            <div class="flex justify-center gap-2">
                                <a href="?page=cetak_surat_jalan&id=<?= $row['id'] ?>" target="_blank" class="text-blue-500 hover:text-blue-700 p-1"><i class="fas fa-print"></i></a>
                                <form method="POST" onsubmit="return confirm('PERINGATAN: Transaksi Keuangan terkait (Pembelian/Penjualan) akan OTOMATIS DIHAPUS. Stok akan di-rollback. Lanjutkan?')">
                                    <input type="hidden" name="delete_type" value="INVENTORY">
                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                    <button class="text-red-500 hover:text-red-700 p-1" title="Hapus & Rollback"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if($total_i_pages > 1): ?>
        <div class="flex justify-between items-center mt-4 border-t pt-4">
            <span class="text-xs text-gray-500">Halaman <?= $page_num ?> dari <?= $total_i_pages ?></span>
            <div class="flex gap-1">
                <?php if($page_num > 1): ?>
                    <a href="?page=data_transaksi&tab=inventory&p=<?= $page_num-1 ?>&q=<?=h($q)?>&i_start=<?=$i_start?>&i_end=<?=$i_end?>&i_type=<?=$i_type?>" class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">Prev</a>
                <?php endif; ?>
                <?php if($page_num < $total_i_pages): ?>
                    <a href="?page=data_transaksi&tab=inventory&p=<?= $page_num+1 ?>&q=<?=h($q)?>&i_start=<?=$i_start?>&i_end=<?=$i_end?>&i_type=<?=$i_type?>" class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>