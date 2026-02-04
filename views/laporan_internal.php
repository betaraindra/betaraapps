<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

// --- 1. SETUP & FILTER ---
$view = $_GET['view'] ?? 'ALL'; // Default to ALL if not set
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

// Filter Inputs
$f_keyword = $_GET['q'] ?? '';
$f_wilayah = $_GET['f_wilayah'] ?? 'ALL'; // Filter Global Wilayah
$f_account = $_GET['f_account'] ?? 'ALL';
$f_user = $_GET['f_user'] ?? 'ALL';
$target_budget = isset($_GET['target_budget']) ? cleanNumber($_GET['target_budget']) : 0; // Capture Target Anggaran

// --- 2. DATA MASTER ---
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$all_accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();
$all_users = $pdo->query("SELECT * FROM users ORDER BY username ASC")->fetchAll();

// --- 3. QUERY TRANSAKSI GLOBAL (DEFAULT FOR MATRIX) ---
$transactions = [];
if ($view === 'ALL' || $view === 'WILAYAH') {
    $sql = "SELECT f.*, a.name as acc_name, a.code as acc_code, u.username 
            FROM finance_transactions f
            JOIN accounts a ON f.account_id = a.id
            JOIN users u ON f.user_id = u.id
            WHERE f.date BETWEEN ? AND ?";
    $params = [$start, $end];

    if (!empty($f_keyword)) {
        $sql .= " AND (f.description LIKE ? OR a.name LIKE ?)";
        $params[] = "%$f_keyword%";
        $params[] = "%$f_keyword%";
    }

    if ($f_wilayah !== 'ALL') {
        $w_stmt = $pdo->prepare("SELECT name FROM warehouses WHERE id=?");
        $w_stmt->execute([$f_wilayah]);
        $w_name = $w_stmt->fetchColumn();
        if ($w_name) {
            $sql .= " AND f.description LIKE ?";
            $params[] = "%[Wilayah: $w_name]%";
        }
    }

    if ($f_account !== 'ALL') {
        $sql .= " AND f.account_id = ?";
        $params[] = $f_account;
    }

    if ($f_user !== 'ALL') {
        $sql .= " AND f.user_id = ?";
        $params[] = $f_user;
    }

    $sql .= " ORDER BY f.date ASC, f.created_at ASC"; 

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
}

// Nama Wilayah Terpilih untuk Header
$selected_wilayah_name = "PERUSAHAAN (GLOBAL)";
if ($f_wilayah !== 'ALL') {
    foreach($warehouses as $w) {
        if ($w['id'] == $f_wilayah) {
            $selected_wilayah_name = strtoupper($w['name']);
            break;
        }
    }
}

// --- 4. DATA PROCESSING FOR SPECIFIC VIEWS ---

// A. VIEW CASHFLOW (IN/OUT DETAILS)
$income_trx = [];
$expense_trx = [];
$total_in = 0;
$total_out = 0;

if ($view === 'CASHFLOW') {
    $sql = "SELECT f.*, a.name as acc_name, a.code as acc_code 
            FROM finance_transactions f
            JOIN accounts a ON f.account_id = a.id
            WHERE f.date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($f_wilayah !== 'ALL') {
        $w_stmt = $pdo->prepare("SELECT name FROM warehouses WHERE id=?");
        $w_stmt->execute([$f_wilayah]);
        $w_name = $w_stmt->fetchColumn();
        if ($w_name) {
            $sql .= " AND f.description LIKE ?";
            $params[] = "%[Wilayah: $w_name]%";
        }
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cf_trx = $stmt->fetchAll();

    foreach ($cf_trx as $t) {
        if ($t['type'] === 'INCOME') {
            $income_trx[] = $t;
            $total_in += $t['amount'];
        } else {
            $expense_trx[] = $t;
            $total_out += $t['amount'];
        }
    }
}

// B. VIEW ANGGARAN
$realisasi_anggaran = 0;
if ($view === 'ANGGARAN') {
    $sql = "SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($f_wilayah !== 'ALL') {
        $w_stmt = $pdo->prepare("SELECT name FROM warehouses WHERE id=?");
        $w_stmt->execute([$f_wilayah]);
        $w_name = $w_stmt->fetchColumn();
        if ($w_name) {
            $sql .= " AND description LIKE ?";
            $params[] = "%[Wilayah: $w_name]%";
        }
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $realisasi_anggaran = $stmt->fetchColumn() ?: 0;
}

// C. VIEW ARUS KAS
$saldo_awal = 0;
$kas_masuk_group = [];
$kas_keluar_group = [];
$total_masuk_periode = 0;
$total_keluar_periode = 0;

if ($view === 'ARUS_KAS') {
    $init_cap = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='initial_capital'")->fetchColumn() ?: 0;
    
    $cond_wh = "1=1";
    $params_wh = [];
    if ($f_wilayah !== 'ALL') {
        $w_stmt = $pdo->prepare("SELECT name FROM warehouses WHERE id=?");
        $w_stmt->execute([$f_wilayah]);
        $w_name_filter = $w_stmt->fetchColumn();
        if ($w_name_filter) {
            $cond_wh = "description LIKE ?";
            $params_wh[] = "%[Wilayah: $w_name_filter]%";
        }
    }

    $sql_prev_in = "SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date < ? AND $cond_wh";
    $stmt_pi = $pdo->prepare($sql_prev_in);
    $stmt_pi->execute(array_merge([$start], $params_wh));
    $prev_in = $stmt_pi->fetchColumn() ?: 0;

    $sql_prev_out = "SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date < ? AND $cond_wh";
    $stmt_po = $pdo->prepare($sql_prev_out);
    $stmt_po->execute(array_merge([$start], $params_wh));
    $prev_out = $stmt_po->fetchColumn() ?: 0;

    $modal_calc = ($f_wilayah === 'ALL') ? $init_cap : 0;
    $saldo_awal = $modal_calc + $prev_in - $prev_out;

    $sql_curr = "SELECT f.type, f.amount, a.name as acc_name 
                 FROM finance_transactions f
                 JOIN accounts a ON f.account_id = a.id
                 WHERE f.date BETWEEN ? AND ? AND $cond_wh";
    $stmt_curr = $pdo->prepare($sql_curr);
    $stmt_curr->execute(array_merge([$start, $end], $params_wh));
    $rows_curr = $stmt_curr->fetchAll();

    foreach ($rows_curr as $r) {
        if ($r['type'] === 'INCOME') {
            if (!isset($kas_masuk_group[$r['acc_name']])) $kas_masuk_group[$r['acc_name']] = 0;
            $kas_masuk_group[$r['acc_name']] += $r['amount'];
            $total_masuk_periode += $r['amount'];
        } else {
            if (!isset($kas_keluar_group[$r['acc_name']])) $kas_keluar_group[$r['acc_name']] = 0;
            $kas_keluar_group[$r['acc_name']] += $r['amount'];
            $total_keluar_periode += $r['amount'];
        }
    }
}

// D. VIEW CUSTOM (NEW)
$custom_trx_grouped = [];
$custom_stats = ['money_in'=>0, 'money_out'=>0, 'goods_in'=>0, 'goods_out'=>0, 'goods_in_val'=>0, 'goods_out_val'=>0];

if ($view === 'CUSTOM') {
    // Custom Filter Inputs
    $c_accounts = $_GET['c_accounts'] ?? []; // Array IDs
    $c_type = $_GET['c_type'] ?? 'ALL';
    $c_wh = $_GET['c_wh'] ?? 'ALL';
    $c_user = $_GET['c_user'] ?? 'ALL';
    
    // 1. Finance Query
    $sql = "SELECT f.*, a.name as acc_name, a.code as acc_code, u.username 
            FROM finance_transactions f
            JOIN accounts a ON f.account_id = a.id
            JOIN users u ON f.user_id = u.id
            WHERE f.date BETWEEN ? AND ?";
    $params = [$start, $end];

    if (!empty($f_keyword)) {
        $sql .= " AND (f.description LIKE ? OR a.name LIKE ?)";
        $params[] = "%$f_keyword%";
        $params[] = "%$f_keyword%";
    }

    if ($c_wh !== 'ALL') {
        $w_stmt = $pdo->prepare("SELECT name FROM warehouses WHERE id=?");
        $w_stmt->execute([$c_wh]);
        $w_name = $w_stmt->fetchColumn();
        if ($w_name) {
            $sql .= " AND f.description LIKE ?";
            $params[] = "%[Wilayah: $w_name]%";
        }
    }

    if ($c_type !== 'ALL') {
        $sql .= " AND f.type = ?";
        $params[] = $c_type; // INCOME / EXPENSE
    }

    if ($c_user !== 'ALL') {
        $sql .= " AND f.user_id = ?";
        $params[] = $c_user;
    }

    if (!empty($c_accounts)) {
        $placeholders = implode(',', array_fill(0, count($c_accounts), '?'));
        $sql .= " AND f.account_id IN ($placeholders)";
        $params = array_merge($params, $c_accounts);
    }

    $sql .= " ORDER BY a.code ASC, f.date DESC"; 

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_custom = $stmt->fetchAll();

    // Grouping & Stats
    foreach ($raw_custom as $row) {
        $grp = $row['acc_code'] . ' - ' . $row['acc_name'];
        if (!isset($custom_trx_grouped[$grp])) {
            $custom_trx_grouped[$grp] = ['total' => 0, 'items' => []];
        }
        $custom_trx_grouped[$grp]['items'][] = $row;
        
        // Signed total for group header
        $val = ($row['type'] == 'INCOME') ? $row['amount'] : -$row['amount'];
        $custom_trx_grouped[$grp]['total'] += $val;

        // Global stats
        if ($row['type'] == 'INCOME') $custom_stats['money_in'] += $row['amount'];
        else $custom_stats['money_out'] += $row['amount'];
    }

    // 2. Inventory Stats Query (With Valuation)
    // Menghitung nilai barang berdasarkan Harga Beli (HPP)
    $inv_sql = "
        SELECT i.type, 
               SUM(i.quantity) as qty,
               SUM(i.quantity * p.buy_price) as val 
        FROM inventory_transactions i
        JOIN products p ON i.product_id = p.id
        WHERE i.date BETWEEN ? AND ?
    ";
    $inv_params = [$start, $end];
    
    if ($c_wh !== 'ALL') {
        $inv_sql .= " AND i.warehouse_id = ?";
        $inv_params[] = $c_wh;
    }
    if ($c_user !== 'ALL') {
        $inv_sql .= " AND i.user_id = ?";
        $inv_params[] = $c_user;
    }
    
    $inv_sql .= " GROUP BY i.type";
    
    $stmt_inv = $pdo->prepare($inv_sql);
    $stmt_inv->execute($inv_params);
    while($row = $stmt_inv->fetch()) {
        if($row['type'] == 'IN') {
            $custom_stats['goods_in'] = $row['qty'];
            $custom_stats['goods_in_val'] = $row['val'];
        }
        if($row['type'] == 'OUT') {
            $custom_stats['goods_out'] = $row['qty'];
            $custom_stats['goods_out_val'] = $row['val'];
        }
    }
}
?>

<!-- STYLE KHUSUS -->
<style>
    .tab-btn { padding: 8px 16px; font-size: 13px; font-weight: bold; border-radius: 6px; transition: all 0.2s; }
    .tab-active { background-color: #6366f1; color: white; box-shadow: 0 2px 4px rgba(99, 102, 241, 0.3); }
    .tab-inactive { background-color: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }
    .tab-inactive:hover { background-color: #e5e7eb; }
    
    /* Table Styles */
    .complex-table th { border: 1px solid #9ca3af; padding: 8px; text-transform: uppercase; font-size: 11px; }
    .complex-table td { border: 1px solid #d1d5db; padding: 6px; font-size: 11px; vertical-align: middle; }
    .col-header-red { background-color: #fee2e2; color: #7f1d1d; } 
    .col-header-gray { background-color: #f3f4f6; color: #1f2937; }
    .col-header-total { background-color: #ffedd5; color: #7c2d12; }
    .col-green { background-color: #dcfce7; }
    .col-red { background-color: #fee2e2; }

    @media print {
        @page { size: landscape; margin: 5mm; }
        .no-print { display: none !important; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; font-size: 10px; }
        .grid-cols-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    }
</style>

<div class="bg-white p-4 rounded shadow mb-6 no-print">
    <h2 class="text-xl font-bold text-gray-800 mb-4">Laporan Keuangan Internal (Detail)</h2>
    
    <!-- TABS MENU -->
    <div class="flex flex-wrap gap-2 mb-6">
        <a href="?page=laporan_internal&view=ALL" class="tab-btn <?= $view=='ALL'?'tab-active':'tab-inactive' ?>">Semua Transaksi</a>
        <a href="?page=laporan_internal&view=CASHFLOW" class="tab-btn <?= $view=='CASHFLOW'?'tab-active bg-blue-600':'tab-inactive' ?>">Cashflow (In/Out)</a>
        <a href="?page=laporan_internal&view=ARUS_KAS" class="tab-btn <?= $view=='ARUS_KAS'?'tab-active bg-indigo-600':'tab-inactive' ?>">Arus Kas</a>
        <a href="?page=laporan_internal&view=ANGGARAN" class="tab-btn <?= $view=='ANGGARAN'?'tab-active bg-purple-600':'tab-inactive' ?>">Anggaran</a>
        <a href="?page=laporan_internal&view=WILAYAH" class="tab-btn <?= $view=='WILAYAH'?'tab-active bg-purple-500':'tab-inactive' ?>">Per Wilayah</a>
        <a href="?page=laporan_internal&view=CUSTOM" class="tab-btn <?= $view=='CUSTOM'?'tab-active bg-gray-800':'tab-inactive' ?> flex items-center gap-1">
            <i class="fas fa-pencil-alt text-xs"></i> Custom
        </a>
    </div>

    <!-- FILTER FORM (CONDITIONAL) -->
    <?php if ($view === 'CUSTOM'): ?>
        <form method="GET" class="border p-4 rounded bg-gray-50">
            <input type="hidden" name="page" value="laporan_internal">
            <input type="hidden" name="view" value="CUSTOM">
            
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
                
                <!-- Account Selection (Multi Checkbox) -->
                <div class="lg:col-span-6 bg-white p-3 rounded border h-40 overflow-y-auto">
                    <label class="block text-xs font-bold text-gray-600 mb-2">Pilih Akun Keuangan (Opsional)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <?php 
                        $c_accounts = $_GET['c_accounts'] ?? [];
                        foreach($all_accounts as $acc): 
                            $checked = in_array($acc['id'], $c_accounts) ? 'checked' : '';
                            $color = $acc['type'] == 'INCOME' ? 'text-green-700' : 'text-red-700';
                        ?>
                        <label class="flex items-center gap-2 text-xs cursor-pointer hover:bg-gray-50 p-1 rounded">
                            <input type="checkbox" name="c_accounts[]" value="<?= $acc['id'] ?>" <?= $checked ?>>
                            <span class="font-bold <?= $color ?>"><?= $acc['code'] ?></span> <?= $acc['name'] ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-2 text-[10px]">
                        <a href="#" onclick="document.querySelectorAll('input[name=\'c_accounts[]\']').forEach(el => el.checked=true); return false;" class="text-blue-600">Pilih Semua</a> | 
                        <a href="#" onclick="document.querySelectorAll('input[name=\'c_accounts[]\']').forEach(el => el.checked=false); return false;" class="text-red-600">Hapus Pilihan</a>
                    </div>
                </div>

                <!-- Other Filters -->
                <div class="lg:col-span-6 grid grid-cols-2 gap-4">
                    <!-- Tipe Transaksi -->
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Tipe Transaksi</label>
                        <select name="c_type" class="w-full border p-2 rounded text-sm bg-white font-bold text-blue-800">
                            <option value="ALL" <?= ($c_type ?? '') == 'ALL' ? 'selected' : '' ?>>-- Semua Transaksi --</option>
                            <option value="INCOME" <?= ($c_type ?? '') == 'INCOME' ? 'selected' : '' ?>>Pemasukan</option>
                            <option value="EXPENSE" <?= ($c_type ?? '') == 'EXPENSE' ? 'selected' : '' ?>>Pengeluaran</option>
                        </select>
                    </div>
                    <!-- Gudang -->
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Gudang / Wilayah</label>
                        <select name="c_wh" class="w-full border p-2 rounded text-sm bg-white">
                            <option value="ALL">-- Semua Gudang --</option>
                            <?php foreach($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>" <?= ($c_wh ?? '') == $w['id'] ? 'selected' : '' ?>><?= $w['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- User -->
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">User / Admin</label>
                        <select name="c_user" class="w-full border p-2 rounded text-sm bg-white">
                            <option value="ALL">-- Semua User --</option>
                            <?php foreach($all_users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= ($c_user ?? '') == $u['id'] ? 'selected' : '' ?>><?= $u['username'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Keyword -->
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Cari Kata Kunci</label>
                        <input type="text" name="q" value="<?= htmlspecialchars($f_keyword) ?>" placeholder="Nama, Ref, Ket..." class="w-full border p-2 rounded text-sm">
                    </div>
                </div>

                <!-- Dates & Action -->
                <div class="lg:col-span-12 grid grid-cols-1 md:grid-cols-6 gap-4 items-end mt-2">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
                        <input type="date" name="start" value="<?= $start ?>" class="w-full border p-2 rounded text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
                        <input type="date" name="end" value="<?= $end ?>" class="w-full border p-2 rounded text-sm">
                    </div>
                    <div class="md:col-span-2 flex gap-1">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-xs font-bold flex-1">
                            Filter
                        </button>
                        <button type="button" onclick="window.print()" class="bg-gray-700 hover:bg-gray-800 text-white px-3 py-2 rounded text-xs font-bold flex items-center gap-1">
                            <i class="fas fa-print"></i> Cetak
                        </button>
                        <button type="button" class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded text-xs font-bold flex items-center gap-1">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>
            </div>
        </form>
    <?php else: ?>
        <!-- Default Standard Filter Form -->
        <form method="GET" class="border p-4 rounded bg-gray-50">
            <input type="hidden" name="page" value="laporan_internal">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                <?php if($view === 'WILAYAH'): ?>
                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Pilih Gudang / Wilayah</label>
                    <select name="f_wilayah" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="ALL">-- Semua Wilayah --</option>
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>" <?= $f_wilayah == $w['id'] ? 'selected' : '' ?>><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Cari (Keyword)</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($f_keyword) ?>" placeholder="Ket/Akun..." class="w-full border p-2 rounded text-sm">
                </div>
                <?php elseif($view !== 'WILAYAH'): ?>
                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Filter Wilayah</label>
                    <select name="f_wilayah" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="ALL">-- Perusahaan (Global) --</option>
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>" <?= $f_wilayah == $w['id'] ? 'selected' : '' ?>><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Dari</label>
                    <input type="date" name="start" value="<?= $start ?>" class="w-full border p-2 rounded text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Sampai</label>
                    <input type="date" name="end" value="<?= $end ?>" class="w-full border p-2 rounded text-sm">
                </div>

                <?php if($view === 'ANGGARAN'): ?>
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Target Anggaran (Rp)</label>
                    <input type="text" name="target_budget" value="<?= number_format($target_budget, 0, ',', '.') ?>" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right font-bold text-purple-700">
                </div>
                <?php endif; ?>

                <div class="md:col-span-3 flex gap-1">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-xs font-bold flex-1">
                        Filter
                    </button>
                    <button type="button" onclick="window.print()" class="bg-gray-700 hover:bg-gray-800 text-white px-3 py-2 rounded text-xs font-bold flex items-center gap-1">
                        <i class="fas fa-print"></i> Cetak
                    </button>
                    <button type="button" class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded text-xs font-bold flex items-center gap-1">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button type="button" onclick="exportTableToExcel()" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded text-xs font-bold flex items-center gap-1">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- ============================================ -->
<!-- VIEW: CUSTOM (FLEXIBLE) -->
<!-- ============================================ -->
<?php if ($view === 'CUSTOM'): ?>
    <div class="bg-white p-6 min-h-screen">
        
        <div class="text-center mb-8">
            <h3 class="text-xl font-bold text-gray-900 uppercase underline decoration-2 underline-offset-4">LAPORAN CUSTOM (FLEKSIBEL)</h3>
            <p class="text-sm text-gray-600 mt-1">Periode: <?= date('d F Y', strtotime($start)) ?> s.d <?= date('d F Y', strtotime($end)) ?></p>
        </div>

        <!-- 4 SUMMARY CARDS -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <!-- Uang Masuk -->
            <div class="bg-green-50 border border-green-200 p-4 rounded shadow-sm">
                <div class="text-xs text-green-700 font-bold uppercase">Total Uang Masuk</div>
                <div class="text-lg font-bold text-green-900"><?= formatRupiah($custom_stats['money_in']) ?></div>
            </div>
            <!-- Uang Keluar -->
            <div class="bg-red-50 border border-red-200 p-4 rounded shadow-sm">
                <div class="text-xs text-red-700 font-bold uppercase">Total Uang Keluar</div>
                <div class="text-lg font-bold text-red-900"><?= formatRupiah($custom_stats['money_out']) ?></div>
            </div>
            <!-- Barang Masuk -->
            <div class="bg-blue-50 border border-blue-200 p-4 rounded shadow-sm">
                <div class="text-xs text-blue-700 font-bold uppercase">Total Barang Masuk</div>
                <div class="text-lg font-bold text-blue-900"><?= number_format($custom_stats['goods_in']) ?> Unit</div>
                <div class="text-xs text-blue-600 mt-1 font-bold">Valuasi: <?= formatRupiah($custom_stats['goods_in_val']) ?></div>
            </div>
            <!-- Barang Keluar -->
            <div class="bg-orange-50 border border-orange-200 p-4 rounded shadow-sm">
                <div class="text-xs text-orange-700 font-bold uppercase">Total Barang Keluar</div>
                <div class="text-lg font-bold text-orange-900"><?= number_format($custom_stats['goods_out']) ?> Unit</div>
                <div class="text-xs text-orange-600 mt-1 font-bold">Valuasi: <?= formatRupiah($custom_stats['goods_out_val']) ?></div>
            </div>
        </div>

        <!-- LIST GROUPED BY ACCOUNT -->
        <div class="space-y-6">
            <?php if(empty($custom_trx_grouped)): ?>
                <div class="text-center text-gray-400 py-10 border border-dashed rounded bg-gray-50">
                    <i class="fas fa-search mb-2 text-2xl"></i><br>Tidak ada transaksi keuangan yang sesuai dengan filter.
                </div>
            <?php else: ?>
                <?php foreach($custom_trx_grouped as $group_name => $group): ?>
                    <div class="border rounded-lg overflow-hidden shadow-sm">
                        <!-- Group Header -->
                        <div class="bg-gray-100 p-3 border-b flex justify-between items-center">
                            <div class="font-bold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-folder text-blue-600"></i> AKUN: <?= $group_name ?>
                            </div>
                            <div class="text-sm font-bold text-blue-800">
                                Saldo Grup: <?= formatRupiah($group['total']) ?>
                            </div>
                        </div>
                        <!-- Group Items -->
                        <table class="w-full text-sm text-left">
                            <thead class="bg-white text-gray-600 border-b text-xs">
                                <tr>
                                    <th class="p-3 w-24">Tanggal</th>
                                    <th class="p-3 w-20 text-center">Tipe</th>
                                    <th class="p-3">Uraian / Barang</th>
                                    <th class="p-3 text-center w-24">Qty (Unit)</th>
                                    <th class="p-3 text-right w-32">Jumlah (Rp)</th>
                                    <th class="p-3 text-center w-20">User</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach($group['items'] as $item): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-3 whitespace-nowrap text-gray-600"><?= date('d/m/y', strtotime($item['date'])) ?></td>
                                    <td class="p-3 text-center">
                                        <?php if($item['type'] == 'INCOME'): ?>
                                            <span class="bg-green-100 text-green-700 text-[10px] px-2 py-1 rounded font-bold">Uang Masuk</span>
                                        <?php else: ?>
                                            <span class="bg-red-100 text-red-700 text-[10px] px-2 py-1 rounded font-bold">Uang Keluar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3 text-gray-800"><?= $item['description'] ?></td>
                                    <td class="p-3 text-center text-gray-400">-</td>
                                    <td class="p-3 text-right font-bold <?= $item['type'] == 'INCOME' ? 'text-green-600' : 'text-green-600' ?>">
                                        <?= formatRupiah($item['amount']) ?>
                                    </td>
                                    <td class="p-3 text-center text-xs text-gray-400"><?= $item['username'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

<!-- ============================================ -->
<!-- VIEW: CASHFLOW (IN/OUT SPLIT) -->
<!-- ============================================ -->
<?php elseif ($view === 'CASHFLOW'): ?>
    <div class="bg-white p-6 min-h-screen">
        <!-- REPORT TITLE -->
        <div class="text-center mb-8">
            <h3 class="text-xl font-bold text-gray-900 uppercase underline decoration-2 underline-offset-4">LAPORAN CASHFLOW (PEMASUKAN VS PENGELUARAN)</h3>
            <p class="text-sm text-gray-600 mt-1">Periode: <?= date('d F Y', strtotime($start)) ?> s.d <?= date('d F Y', strtotime($end)) ?></p>
            <p class="text-xs font-bold text-blue-600 mt-1 uppercase">LINGKUP: <?= $selected_wilayah_name ?></p>
        </div>

        <!-- SUMMARY CARD -->
        <div class="border-l-4 border-yellow-400 bg-yellow-50 p-4 mb-6 rounded shadow-sm">
            <h4 class="font-bold text-gray-800 mb-3 border-b border-yellow-200 pb-1">Ringkasan Cashflow</h4>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-center">
                <div>
                    <div class="text-xs font-bold text-gray-500 uppercase">TOTAL PEMASUKAN</div>
                    <div class="text-xl font-bold text-green-600"><?= formatRupiah($total_in) ?></div>
                </div>
                <div>
                    <div class="text-xs font-bold text-gray-500 uppercase">TOTAL PENGELUARAN</div>
                    <div class="text-xl font-bold text-red-600"><?= formatRupiah($total_out) ?></div>
                </div>
                <div>
                    <div class="text-xs font-bold text-gray-500 uppercase">SURPLUS / (DEFISIT)</div>
                    <?php $balance = $total_in - $total_out; ?>
                    <div class="text-xl font-bold <?= $balance >= 0 ? 'text-red-600' : 'text-red-600' ?>">
                        <?= formatRupiah($balance) ?> <?= $balance < 0 ? 'p>' : '' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- SPLIT TABLES -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 print:grid-cols-2">
            <!-- LEFT: PEMASUKAN -->
            <div class="border rounded-t-lg overflow-hidden">
                <div class="bg-green-100 text-green-800 font-bold text-center py-2 border-b border-green-200 uppercase text-sm">
                    PEMASUKAN (IN)
                </div>
                <table class="w-full text-xs text-left">
                    <thead class="bg-green-50 text-green-900 border-b">
                        <tr><th class="p-2 border-r">Tgl</th><th class="p-2 border-r">Akun</th><th class="p-2 border-r">Uraian</th><th class="p-2 text-right">Nominal</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($income_trx as $in): ?>
                        <tr class="hover:bg-green-50">
                            <td class="p-2 whitespace-nowrap text-gray-600"><?= date('d/m/y', strtotime($in['date'])) ?></td>
                            <td class="p-2 font-bold text-gray-700 w-24 leading-tight"><?= $in['acc_name'] ?><br><span class="text-[9px] font-normal text-gray-500"><?= $in['acc_code'] ?></span></td>
                            <td class="p-2 text-gray-600 italic leading-tight"><?= htmlspecialchars($in['description']) ?></td>
                            <td class="p-2 text-right font-bold text-green-700 whitespace-nowrap"><?= formatRupiah($in['amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($income_trx)): ?><tr><td colspan="4" class="p-4 text-center text-gray-400 italic">Tidak ada pemasukan.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- RIGHT: PENGELUARAN -->
            <div class="border rounded-t-lg overflow-hidden">
                <div class="bg-red-100 text-red-800 font-bold text-center py-2 border-b border-red-200 uppercase text-sm">
                    PENGELUARAN (OUT)
                </div>
                <table class="w-full text-xs text-left">
                    <thead class="bg-red-50 text-red-900 border-b">
                        <tr><th class="p-2 border-r">Tgl</th><th class="p-2 border-r">Akun</th><th class="p-2 border-r">Uraian</th><th class="p-2 text-right">Nominal</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($expense_trx as $out): ?>
                        <tr class="hover:bg-red-50">
                            <td class="p-2 whitespace-nowrap text-gray-600"><?= date('d/m/y', strtotime($out['date'])) ?></td>
                            <td class="p-2 font-bold text-gray-700 w-24 leading-tight"><?= $out['acc_name'] ?><br><span class="text-[9px] font-normal text-gray-500"><?= $out['acc_code'] ?></span></td>
                            <td class="p-2 text-gray-600 italic leading-tight"><?= htmlspecialchars($out['description']) ?></td>
                            <td class="p-2 text-right font-bold text-red-700 whitespace-nowrap"><?= formatRupiah($out['amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($expense_trx)): ?><tr><td colspan="4" class="p-4 text-center text-gray-400 italic">Tidak ada pengeluaran.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<!-- ============================================ -->
<!-- VIEW: ARUS KAS (SUMMARY & SALDO) -->
<!-- ============================================ -->
<?php elseif ($view === 'ARUS_KAS'): ?>
    <div class="bg-white p-6 min-h-screen">
        <div class="text-center mb-10">
            <h3 class="text-xl font-bold text-gray-900 uppercase underline decoration-2 underline-offset-4">LAPORAN ARUS KAS (CASH FLOW)</h3>
            <p class="text-sm text-gray-600 mt-1">Periode: <?= date('d F Y', strtotime($start)) ?> s.d <?= date('d F Y', strtotime($end)) ?></p>
        </div>

        <div class="max-w-4xl mx-auto border border-gray-300 shadow-sm bg-white" id="exportTable">
            
            <!-- 1. SALDO AWAL -->
            <div class="flex justify-between items-center p-3 bg-gray-100 border-b border-gray-300">
                <div class="font-bold text-gray-800 uppercase">SALDO KAS AWAL (<?= date('d/m/Y', strtotime($start)) ?>)</div>
                <div class="font-bold text-gray-900 text-lg"><?= formatRupiah($saldo_awal) ?></div>
            </div>

            <!-- 2. KAS MASUK -->
            <div class="p-4 border-b border-gray-200">
                <h4 class="font-bold text-green-700 uppercase mb-2">ARUS KAS MASUK (+)</h4>
                <table class="w-full text-sm">
                    <?php if(empty($kas_masuk_group)): ?>
                        <tr><td class="text-gray-400 italic pl-4">- Tidak ada pemasukan -</td><td></td></tr>
                    <?php else: ?>
                        <?php foreach($kas_masuk_group as $acc_name => $amount): ?>
                        <tr>
                            <td class="pl-4 py-1 text-gray-600"><?= $acc_name ?></td>
                            <td class="text-right text-gray-800"><?= formatRupiah($amount) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="border-t border-gray-200 mt-2">
                        <td class="pt-2 font-bold text-green-800">Total Masuk</td>
                        <td class="pt-2 text-right font-bold text-green-800"><?= formatRupiah($total_masuk_periode) ?></td>
                    </tr>
                </table>
            </div>

            <!-- 3. KAS KELUAR -->
            <div class="p-4 border-b border-gray-200">
                <h4 class="font-bold text-red-700 uppercase mb-2">ARUS KAS KELUAR (-)</h4>
                <table class="w-full text-sm">
                    <?php if(empty($kas_keluar_group)): ?>
                        <tr><td class="text-gray-400 italic pl-4">- Tidak ada pengeluaran -</td><td></td></tr>
                    <?php else: ?>
                        <?php foreach($kas_keluar_group as $acc_name => $amount): ?>
                        <tr>
                            <td class="pl-4 py-1 text-gray-600"><?= $acc_name ?></td>
                            <td class="text-right text-gray-800">(<?= formatRupiah($amount) ?>)</td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="border-t border-gray-200 mt-2">
                        <td class="pt-2 font-bold text-red-800">Total Keluar</td>
                        <td class="pt-2 text-right font-bold text-red-800">(<?= formatRupiah($total_keluar_periode) ?>)</td>
                    </tr>
                </table>
            </div>

            <!-- 4. SALDO AKHIR -->
            <?php 
                $saldo_akhir = $saldo_awal + $total_masuk_periode - $total_keluar_periode; 
                $bg_akhir = $saldo_akhir >= 0 ? 'bg-blue-50 text-blue-900' : 'bg-red-50 text-red-900';
            ?>
            <div class="flex justify-between items-center p-4 border-t-2 border-gray-400 <?= $bg_akhir ?>">
                <div class="font-bold uppercase text-lg">SALDO KAS AKHIR (<?= date('d/m/Y', strtotime($end)) ?>)</div>
                <div class="font-bold text-xl"><?= formatRupiah($saldo_akhir) ?></div>
            </div>

        </div>
    </div>

<!-- ============================================ -->
<!-- VIEW: ANGGARAN (REALIZATION VS BUDGET) -->
<!-- ============================================ -->
<?php elseif ($view === 'ANGGARAN'): ?>
    <div class="bg-white p-6 min-h-screen">
        <!-- HEADER -->
        <div class="text-center mb-10">
            <h3 class="text-xl font-bold text-gray-900 uppercase underline decoration-2 underline-offset-4">LAPORAN REALISASI VS ANGGARAN</h3>
            <p class="text-sm text-gray-600 mt-1">Periode: <?= date('d F Y', strtotime($start)) ?> s.d <?= date('d F Y', strtotime($end)) ?></p>
        </div>

        <?php 
            // Calculation
            // Sisa = Anggaran - Realisasi.
            // Jika Realisasi > Anggaran, maka Sisa minus (Over Budget).
            $sisa_anggaran = $target_budget - $realisasi_anggaran;
            $persen_serap = ($target_budget > 0) ? ($realisasi_anggaran / $target_budget) * 100 : 0;
            if ($persen_serap > 100) $persen_serap = 100; // Cap visual at 100%
        ?>

        <!-- 3 CARDS -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- 1. Anggaran -->
            <div class="bg-gray-50 border border-gray-200 rounded p-6 text-center shadow-sm">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-wide">ANGGARAN (BUDGET)</div>
                <div class="text-2xl font-bold text-gray-800 mt-2"><?= formatRupiah($target_budget) ?></div>
            </div>

            <!-- 2. Realisasi -->
            <div class="bg-gray-50 border border-gray-200 rounded p-6 text-center shadow-sm">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-wide">REALISASI (ACTUAL)</div>
                <div class="text-2xl font-bold text-red-600 mt-2"><?= formatRupiah($realisasi_anggaran) ?></div>
            </div>

            <!-- 3. Sisa / Over -->
            <div class="<?= $sisa_anggaran < 0 ? 'bg-red-100 border-red-200' : 'bg-green-100 border-green-200' ?> border rounded p-6 text-center shadow-sm">
                <div class="text-xs font-bold text-gray-600 uppercase tracking-wide">SISA / (OVER)</div>
                <div class="text-2xl font-bold <?= $sisa_anggaran < 0 ? 'text-red-700' : 'text-green-700' ?> mt-2">
                    <?= formatRupiah($sisa_anggaran) ?>
                </div>
            </div>
        </div>

        <!-- PROGRESS BAR -->
        <div class="mb-4">
            <div class="flex justify-between items-end mb-1">
                <span class="text-xs font-bold text-blue-600 bg-blue-200 px-2 py-1 rounded">PENYERAPAN ANGGARAN</span>
                <span class="text-xs font-bold text-blue-600"><?= number_format(($target_budget > 0 ? ($realisasi_anggaran / $target_budget) * 100 : 0), 1) ?>%</span>
            </div>
            <div class="w-full bg-blue-100 rounded-full h-4 overflow-hidden">
                <div class="bg-blue-400 h-4 rounded-full transition-all duration-500" style="width: <?= $persen_serap ?>%"></div>
            </div>
        </div>

        <div class="text-xs text-gray-500 italic text-center mt-2">
            * Realisasi dihitung berdasarkan total transaksi PENGELUARAN (Expense) pada periode yang dipilih.
        </div>
    </div>

<!-- ============================================ -->
<!-- VIEW: WILAYAH (LAPORAN PER WILAYAH/GUDANG) -->
<!-- ============================================ -->
<?php elseif ($view === 'WILAYAH'): ?>
    <div class="bg-white p-6 min-h-screen">
        <!-- HEADER -->
        <div class="text-center mb-8">
            <h3 class="text-xl font-bold text-gray-900 uppercase underline decoration-2 underline-offset-4">LAPORAN KEUANGAN PER WILAYAH/GUDANG</h3>
            <p class="text-sm text-gray-600 mt-1">Periode: <?= date('d F Y', strtotime($start)) ?> s.d <?= date('d F Y', strtotime($end)) ?></p>
            <p class="text-xs font-bold text-blue-600 mt-1 uppercase">LINGKUP: <?= $selected_wilayah_name ?></p>
        </div>

        <!-- TABLE -->
        <div class="overflow-x-auto border border-gray-300 rounded shadow-sm">
            <table class="w-full text-sm text-left border-collapse" id="exportTable">
                <thead class="bg-blue-200 text-gray-800 border-b border-blue-300 uppercase text-xs">
                    <tr>
                        <th class="p-3 border-r border-blue-300">Tanggal</th>
                        <th class="p-3 border-r border-blue-300 w-16">Kode</th>
                        <th class="p-3 border-r border-blue-300">Nama Akun</th>
                        <th class="p-3 border-r border-blue-300 w-1/3">Uraian Transaksi</th>
                        <th class="p-3 border-r border-blue-300 text-right">Debet (Masuk)</th>
                        <th class="p-3 border-r border-blue-300 text-right">Kredit (Keluar)</th>
                        <th class="p-3 text-center w-24">User</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php 
                    $sum_debet = 0;
                    $sum_kredit = 0;
                    if (empty($transactions)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-gray-400 italic">Tidak ada transaksi untuk wilayah ini.</td></tr>
                    <?php else: ?>
                        <?php foreach($transactions as $row): 
                            $d_in = ($row['type'] == 'INCOME') ? $row['amount'] : 0;
                            $d_out = ($row['type'] == 'EXPENSE') ? $row['amount'] : 0;
                            $sum_debet += $d_in;
                            $sum_kredit += $d_out;
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="p-3 whitespace-nowrap text-gray-600 border-r"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                            <td class="p-3 font-mono text-xs text-gray-500 border-r"><?= $row['acc_code'] ?></td>
                            <td class="p-3 font-bold text-gray-700 border-r"><?= $row['acc_name'] ?></td>
                            <td class="p-3 text-gray-600 border-r italic"><?= htmlspecialchars($row['description']) ?></td>
                            
                            <td class="p-3 text-right font-bold text-green-600 border-r">
                                <?= $d_in > 0 ? formatRupiah($d_in) : '-' ?>
                            </td>
                            <td class="p-3 text-right font-bold text-red-600 border-r">
                                <?= $d_out > 0 ? formatRupiah($d_out) : '-' ?>
                            </td>
                            
                            <td class="p-3 text-center text-xs text-gray-400"><?= $row['username'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-400 font-bold text-gray-800">
                    <tr>
                        <td colspan="4" class="p-3 text-right uppercase">TOTAL</td>
                        <td class="p-3 text-right text-green-700 bg-green-50"><?= formatRupiah($sum_debet) ?></td>
                        <td class="p-3 text-right text-red-700 bg-red-50"><?= formatRupiah($sum_kredit) ?></td>
                        <td></td>
                    </tr>
                    <tr class="bg-yellow-100 border-t border-yellow-300 text-yellow-900">
                        <td colspan="5" class="p-3 text-right uppercase text-sm">SELISIH / SALDO AKHIR</td>
                        <td colspan="2" class="p-3 text-left text-lg font-bold">
                            <?php $final = $sum_debet - $sum_kredit; ?>
                            <span class="<?= $final >= 0 ? 'text-blue-800' : 'text-red-700' ?>">
                                <?= formatRupiah($final) ?>
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

<!-- ============================================ -->
<!-- VIEW: DEFAULT / CUSTOM -->
<!-- ============================================ -->
<?php else: ?>
    <!-- REPORT CONTENT (MATRIX VIEW) -->
    <div class="bg-white p-4 min-h-screen">
        
        <div class="text-center mb-6">
            <h3 class="text-lg font-bold text-gray-900 uppercase underline">JURNAL TRANSAKSI KEUANGAN & MATERIAL</h3>
            <p class="text-sm text-gray-500">Periode: <?= date('d F Y', strtotime($start)) ?> s.d <?= date('d F Y', strtotime($end)) ?></p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse complex-table" id="exportTable">
                <thead>
                    <tr>
                        <th rowspan="2" class="col-header-gray" style="width: 80px;">Tanggal</th>
                        <th rowspan="2" class="col-header-gray" style="width: 50px;">Kode</th>
                        <th rowspan="2" class="col-header-gray" style="width: 150px;">Nama Akun</th>
                        <th rowspan="2" class="col-header-gray" style="width: 250px;">Uraian Transaksi</th>
                        
                        <!-- DYNAMIC WAREHOUSE HEADER -->
                        <th colspan="<?= count($warehouses) ?>" class="col-header-red text-center border-b-0">
                            PEMBAGIAN PENGELUARAN BERDASARKAN WILAYAH
                        </th>
                        
                        <th rowspan="2" class="col-header-total text-center w-24">TOTAL PEMBAGIAN WIL (RP)</th>
                        <th rowspan="2" class="col-header-gray text-center w-24 col-green">Debit<br>(Masuk)</th>
                        <th rowspan="2" class="col-header-gray text-center w-24 col-red">Kredit<br>(Keluar)</th>
                        <th rowspan="2" class="col-header-gray text-center w-28 bg-blue-50">Saldo</th>
                        <th rowspan="2" class="col-header-gray text-center w-20">User</th>
                    </tr>
                    <tr>
                        <!-- SUB HEADER GUDANG -->
                        <?php foreach($warehouses as $wh): ?>
                            <th class="bg-white text-center text-[10px] w-20 break-words"><?= htmlspecialchars($wh['name']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $running_balance = 0;
                    if(empty($transactions)): ?>
                        <tr><td colspan="<?= 9 + count($warehouses) ?>" class="text-center p-8 text-gray-400 italic">Tidak ada data transaksi.</td></tr>
                    <?php else: ?>
                        <?php 
                        $grand_debit = 0;
                        $grand_kredit = 0;
                        $grand_total_wil = 0;
                        
                        $wh_col_totals = array_fill(0, count($warehouses), 0);

                        foreach($transactions as $row): 
                            $trx_in = ($row['type'] == 'INCOME') ? $row['amount'] : 0;
                            $trx_out = ($row['type'] == 'EXPENSE') ? $row['amount'] : 0;
                            
                            $grand_debit += $trx_in;
                            $grand_kredit += $trx_out;
                            $running_balance += ($trx_in - $trx_out);

                            $trx_wh_name = '';
                            if (preg_match('/\[Wilayah:\s*(.*?)\]/', $row['description'], $matches)) {
                                $trx_wh_name = trim($matches[1]);
                            }
                            $display_desc = $row['description'];
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="text-center whitespace-nowrap bg-gray-50"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                            <td class="text-center font-mono text-gray-600"><?= $row['acc_code'] ?></td>
                            <td class="font-bold text-gray-700"><?= $row['acc_name'] ?></td>
                            <td><?= htmlspecialchars($display_desc) ?></td>
                            
                            <!-- LOOP COLUMN GUDANG -->
                            <?php 
                            $row_wil_total = 0;
                            foreach($warehouses as $idx => $wh): 
                                $is_match = ($wh['name'] === $trx_wh_name && $trx_out > 0); 
                                $val = 0;
                                if ($is_match) {
                                    $val = $trx_out;
                                    $wh_col_totals[$idx] += $val;
                                    $row_wil_total += $val;
                                }
                            ?>
                                <td class="text-right text-[10px] <?= $is_match ? 'font-bold' : 'text-gray-300' ?>">
                                    <?= $val > 0 ? number_format($val, 0, ',', '.') : '-' ?>
                                </td>
                            <?php endforeach; 
                                $grand_total_wil += $row_wil_total;
                            ?>

                            <td class="text-right font-bold bg-orange-50 text-orange-800 border-l-2 border-orange-100">
                                <?= $row_wil_total > 0 ? number_format($row_wil_total, 0, ',', '.') : '-' ?>
                            </td>

                            <td class="text-right font-bold text-green-700 col-green">
                                <?= $trx_in > 0 ? number_format($trx_in, 0, ',', '.') : '-' ?>
                            </td>
                            <td class="text-right font-bold text-red-700 col-red">
                                <?= $trx_out > 0 ? number_format($trx_out, 0, ',', '.') : '-' ?>
                            </td>
                            
                            <td class="text-right font-bold bg-blue-50 text-blue-900 border-l border-blue-200">
                                <?= number_format($running_balance, 0, ',', '.') ?>
                            </td>
                            
                            <td class="text-center text-[9px] text-gray-500"><?= $row['username'] ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- FOOTER TOTALS -->
                        <tr class="font-bold bg-gray-200 border-t-2 border-gray-600">
                            <td colspan="4" class="text-right p-2 uppercase">TOTAL PERIODE INI</td>
                            <?php foreach($wh_col_totals as $tot): ?>
                                <td class="text-right text-[10px]"><?= $tot > 0 ? number_format($tot, 0, ',', '.') : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-right text-orange-900 bg-orange-100"><?= number_format($grand_total_wil, 0, ',', '.') ?></td>
                            <td class="text-right text-green-900 bg-green-100"><?= number_format($grand_debit, 0, ',', '.') ?></td>
                            <td class="text-right text-red-900 bg-red-100"><?= number_format($grand_kredit, 0, ',', '.') ?></td>
                            <td class="text-right bg-blue-100 text-blue-900"><?= number_format($running_balance, 0, ',', '.') ?></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
function exportTableToExcel() {
    const table = document.getElementById('exportTable');
    if(!table) {
        alert('Tabel data tidak ditemukan untuk diexport pada tampilan ini.');
        return;
    }
    // Convert table to workbook
    const wb = XLSX.utils.table_to_book(table, {sheet: "Laporan"});
    // Download file
    XLSX.writeFile(wb, 'Laporan_Internal_<?= date("Ymd_His") ?>.xlsx');
}
</script>