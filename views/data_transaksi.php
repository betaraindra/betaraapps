<?php
checkRole(['SUPER_ADMIN', 'SVP', 'MANAGER', 'ADMIN_GUDANG', 'ADMIN_KEUANGAN']);

// --- 1. HANDLE DELETE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_type'])) {
    $id = $_POST['delete_id'];
    $type = $_POST['delete_type'];
    
    // Permission Check
    // Admin Keuangan hanya boleh hapus Keuangan
    if ($type === 'FINANCE' && !in_array($_SESSION['role'], ['SUPER_ADMIN', 'SVP', 'MANAGER', 'ADMIN_KEUANGAN'])) {
        die("Akses Ditolak: Anda bukan Admin Keuangan");
    }
    // Admin Gudang hanya boleh hapus Inventori
    if ($type === 'INVENTORY' && !in_array($_SESSION['role'], ['SUPER_ADMIN', 'SVP', 'MANAGER', 'ADMIN_GUDANG'])) {
        die("Akses Ditolak: Anda bukan Admin Gudang");
    }

    $pdo->beginTransaction();
    try {
        if ($type === 'FINANCE') {
            $pdo->prepare("DELETE FROM finance_transactions WHERE id = ?")->execute([$id]);
            logActivity($pdo, 'DELETE_FINANCE', "Hapus Transaksi Keuangan ID: $id");
        } elseif ($type === 'INVENTORY') {
            // Get transaction details first to rollback stock
            $stmt = $pdo->prepare("SELECT * FROM inventory_transactions WHERE id = ?");
            $stmt->execute([$id]);
            $trx = $stmt->fetch();
            
            if ($trx) {
                // Rollback Logic
                $operator = ($trx['type'] === 'IN') ? '-' : '+';
                $pdo->prepare("UPDATE products SET stock = stock $operator ? WHERE id = ?")->execute([$trx['quantity'], $trx['product_id']]);
                
                $pdo->prepare("DELETE FROM inventory_transactions WHERE id = ?")->execute([$id]);
                logActivity($pdo, 'DELETE_INVENTORY', "Hapus Transaksi Inventori ID: $id (Stok Rollback)");
            }
        }
        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Transaksi berhasil dihapus.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Gagal menghapus: ' . $e->getMessage()];
    }
    echo "<script>window.location='?page=data_transaksi&tab=" . strtolower($type) . "';</script>";
    exit;
}

// --- 2. PREPARE DATA & FILTERS ---

// Active Tab
$tab = $_GET['tab'] ?? 'finance'; // 'finance' or 'inventory'

// Master Data for Dropdowns
$accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

// --- LOGIC KEUANGAN ---
$f_start = $_GET['f_start'] ?? date('Y-m-01');
$f_end = $_GET['f_end'] ?? date('Y-m-d');
$f_type = $_GET['f_type'] ?? 'ALL';
$f_acc = $_GET['f_account'] ?? 'ALL';

$f_sql = "SELECT f.*, a.name as acc_name, a.code as acc_code, u.username 
          FROM finance_transactions f 
          JOIN accounts a ON f.account_id=a.id 
          JOIN users u ON f.user_id=u.id 
          WHERE f.date BETWEEN ? AND ?";
$f_params = [$f_start, $f_end];

if ($f_type !== 'ALL') {
    $f_sql .= " AND f.type = ?";
    $f_params[] = $f_type;
}
if ($f_acc !== 'ALL') {
    $f_sql .= " AND f.account_id = ?";
    $f_params[] = $f_acc;
}
$f_sql .= " ORDER BY f.date DESC, f.created_at DESC";
$finance_data = $pdo->prepare($f_sql);
$finance_data->execute($f_params);
$finance_result = $finance_data->fetchAll();


// --- LOGIC INVENTORI ---
$i_start = $_GET['i_start'] ?? date('Y-m-01');
$i_end = $_GET['i_end'] ?? date('Y-m-d');
$i_type = $_GET['i_type'] ?? 'ALL';
$i_wh = $_GET['i_wh'] ?? 'ALL';

$i_sql = "SELECT i.*, p.name as prod_name, p.sku, w.name as wh_name, u.username 
          FROM inventory_transactions i 
          JOIN products p ON i.product_id=p.id 
          JOIN warehouses w ON i.warehouse_id=w.id
          JOIN users u ON i.user_id=u.id 
          WHERE i.date BETWEEN ? AND ?";
$i_params = [$i_start, $i_end];

if ($i_type !== 'ALL') {
    $i_sql .= " AND i.type = ?";
    $i_params[] = $i_type;
}
if ($i_wh !== 'ALL') {
    $i_sql .= " AND i.warehouse_id = ?";
    $i_params[] = $i_wh;
}
$i_sql .= " ORDER BY i.date DESC, i.created_at DESC";
$inventory_data = $pdo->prepare($i_sql);
$inventory_data->execute($i_params);
$inventory_result = $inventory_data->fetchAll();

?>

<div class="mb-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-2xl font-bold text-gray-800">Data Transaksi</h2>
    </div>

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
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
                    <input type="date" name="f_start" value="<?= $f_start ?>" class="border p-2 rounded text-sm w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
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
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Filter Akun</label>
                    <select name="f_account" class="border p-2 rounded text-sm w-full bg-white">
                        <option value="ALL">-- Semua Akun --</option>
                        <?php foreach($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>" <?= $f_acc==$acc['id']?'selected':'' ?>>
                                <?= $acc['code'] ?> - <?= $acc['name'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded font-bold text-sm hover:bg-blue-700 shadow">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" onclick="exportTableToExcel('table_finance', 'Laporan_Keuangan_<?= date('Ymd') ?>')" class="bg-green-600 text-white px-4 py-2 rounded font-bold text-sm hover:bg-green-700 shadow">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </div>
            </div>
        </form>

        <!-- TABLE -->
        <div class="overflow-x-auto">
            <table id="table_finance" class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700 border-b-2 border-gray-200">
                    <tr>
                        <th class="p-3">ID</th>
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
                    <?php 
                    $total_income = 0; 
                    $total_expense = 0;
                    foreach($finance_result as $row): 
                        if($row['type'] == 'INCOME') $total_income += $row['amount'];
                        else $total_expense += $row['amount'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 text-gray-500 text-xs">#<?= $row['id'] ?></td>
                        <td class="p-3 whitespace-nowrap"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                        <td class="p-3">
                            <span class="font-bold text-gray-800"><?= $row['acc_name'] ?></span>
                            <div class="text-xs text-gray-500 font-mono"><?= $row['acc_code'] ?></div>
                        </td>
                        <td class="p-3">
                            <span class="px-2 py-1 rounded-full text-xs font-bold <?= $row['type']=='INCOME'?'bg-green-100 text-green-700':'bg-red-100 text-red-700' ?>">
                                <?= $row['type']=='INCOME' ? 'Pemasukan' : 'Pengeluaran' ?>
                            </span>
                        </td>
                        <td class="p-3 font-mono font-bold <?= $row['type']=='INCOME'?'text-green-700':'text-red-700' ?>">
                            <?= number_format($row['amount'], 0, ',', '.') ?>
                        </td>
                        <td class="p-3 text-gray-600 max-w-xs truncate" title="<?= htmlspecialchars($row['description']) ?>">
                            <?= $row['description'] ?>
                        </td>
                        <td class="p-3 text-gray-500 text-xs"><?= $row['username'] ?></td>
                        <td class="p-3 text-center no-print">
                            <?php if(in_array($_SESSION['role'], ['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP'])): ?>
                            <form method="POST" onsubmit="return confirm('Hapus data ini? Saldo akan berubah.')">
                                <input type="hidden" name="delete_type" value="FINANCE">
                                <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                <button class="text-red-500 hover:text-red-700 bg-red-50 p-2 rounded" title="Hapus">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-100 font-bold border-t-2 border-gray-300">
                    <tr>
                        <td colspan="4" class="p-3 text-right">TOTAL PERIODE INI:</td>
                        <td class="p-3 text-green-700">Masuk: <?= number_format($total_income) ?></td>
                        <td colspan="3" class="p-3 text-red-700">Keluar: <?= number_format($total_expense) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

<?php else: ?>
    <!-- TAB INVENTORI -->
    <div class="bg-white p-6 rounded-b-lg shadow">
        
        <!-- FILTER BAR -->
        <form method="GET" class="mb-6 bg-gray-50 p-4 rounded border border-gray-200">
            <input type="hidden" name="page" value="data_transaksi">
            <input type="hidden" name="tab" value="inventory">
            
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
                    <input type="date" name="i_start" value="<?= $i_start ?>" class="border p-2 rounded text-sm w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
                    <input type="date" name="i_end" value="<?= $i_end ?>" class="border p-2 rounded text-sm w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Tipe</label>
                    <select name="i_type" class="border p-2 rounded text-sm w-32 bg-white">
                        <option value="ALL">Semua</option>
                        <option value="IN" <?= $i_type=='IN'?'selected':'' ?>>Barang Masuk</option>
                        <option value="OUT" <?= $i_type=='OUT'?'selected':'' ?>>Barang Keluar</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Gudang</label>
                    <select name="i_wh" class="border p-2 rounded text-sm w-full bg-white">
                        <option value="ALL">-- Semua Gudang --</option>
                        <?php foreach($warehouses as $wh): ?>
                            <option value="<?= $wh['id'] ?>" <?= $i_wh==$wh['id']?'selected':'' ?>>
                                <?= $wh['name'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-orange-600 text-white px-4 py-2 rounded font-bold text-sm hover:bg-orange-700 shadow">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" onclick="exportTableToExcel('table_inventory', 'Laporan_Inventori_<?= date('Ymd') ?>')" class="bg-green-600 text-white px-4 py-2 rounded font-bold text-sm hover:bg-green-700 shadow">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>
                </div>
            </div>
        </form>

        <!-- TABLE -->
        <div class="overflow-x-auto">
            <table id="table_inventory" class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700 border-b-2 border-gray-200">
                    <tr>
                        <th class="p-3">Tanggal</th>
                        <th class="p-3">Ref/Surat Jalan</th>
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
                        <td class="p-3 font-mono text-xs"><?= $row['reference'] ?></td>
                        <td class="p-3">
                            <div class="font-bold text-gray-800"><?= $row['prod_name'] ?></div>
                            <div class="text-xs text-gray-500"><?= $row['sku'] ?></div>
                        </td>
                        <td class="p-3 text-gray-600"><?= $row['wh_name'] ?></td>
                        <td class="p-3 text-center">
                            <span class="px-2 py-1 rounded text-xs font-bold <?= $row['type']=='IN'?'bg-green-100 text-green-700':'bg-red-100 text-red-700' ?>">
                                <?= $row['type']=='IN'?'MASUK':'KELUAR' ?>
                            </span>
                        </td>
                        <td class="p-3 text-right font-bold"><?= $row['quantity'] ?></td>
                        <td class="p-3 text-xs text-gray-500"><?= $row['username'] ?></td>
                        <td class="p-3 text-center no-print">
                            <div class="flex justify-center gap-2">
                                <a href="?page=cetak_surat_jalan&id=<?= $row['id'] ?>" target="_blank" class="text-blue-500 hover:text-blue-700 p-1" title="Cetak Surat Jalan">
                                    <i class="fas fa-print"></i>
                                </a>
                                <?php if(in_array($_SESSION['role'], ['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP'])): ?>
                                <form method="POST" onsubmit="return confirm('PERINGATAN: Stok barang akan dikembalikan (Rollback). Lanjutkan?')">
                                    <input type="hidden" name="delete_type" value="INVENTORY">
                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                    <button class="text-red-500 hover:text-red-700 p-1" title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
function exportTableToExcel(tableId, filename = 'Data_Transaksi') {
    let table = document.getElementById(tableId);
    
    // Clone table untuk membuang kolom "Aksi" saat export
    let clone = table.cloneNode(true);
    let rows = clone.querySelectorAll('tr');
    
    rows.forEach(row => {
        // Hapus sel terakhir (kolom Aksi)
        if(row.cells.length > 0) {
            row.deleteCell(-1); 
        }
    });

    let wb = XLSX.utils.table_to_book(clone, {sheet: "Sheet1"});
    XLSX.writeFile(wb, filename + ".xlsx");
}
</script>