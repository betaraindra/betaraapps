<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

// --- CONFIG & HEADER DATA ---
$config_query = $pdo->query("SELECT * FROM settings");
$config = [];
while ($row = $config_query->fetch()) $config[$row['setting_key']] = $row['setting_value'];

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
// Default view
$view_type = $_GET['view'] ?? 'ALL_TRANSAKSI';

// GET Budget input (sanitasi format rupiah dari URL param)
$budget_raw = $_GET['budget'] ?? '0';
$budget_input = cleanNumber($budget_raw);

// --- JUDUL LAPORAN ---
$titles = [
    'ALL_TRANSAKSI' => 'Jurnal Transaksi Keuangan & Material (Master)',
    'CASHFLOW'      => 'Laporan Cashflow (Pemasukan vs Pengeluaran)', 
    'ARUS_KAS'      => 'Laporan Arus Kas (Rincian Akun)',
    'ANGGARAN'      => 'Laporan Realisasi Pengeluaran vs Anggaran',
    'WILAYAH'       => 'Laporan Profitabilitas per Wilayah/Gudang',
    'CUSTOM'        => 'Laporan Custom (Filter Lengkap)'
];
$current_title = $titles[$view_type] ?? 'Laporan Internal';

// Ambil Data Master untuk Filter
$all_accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();
$all_warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll(); 
$all_users = $pdo->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll();

// ==========================================
// LOGIC PENGOLAHAN DATA BERDASARKAN VIEW
// ==========================================

// --- 0. SEMUA TRANSAKSI (GABUNGAN) ---
$combined_data = [];
$grouped_data = [];
$grand_totals = ['in'=>0, 'out'=>0, 'wilayah_net'=>0];
$grand_wh_income = [];
$grand_wh_expense = [];
foreach($all_warehouses as $wh) {
    $grand_wh_income[$wh['id']] = 0;
    $grand_wh_expense[$wh['id']] = 0;
}

if ($view_type == 'ALL_TRANSAKSI') {
    $f_acc = $_GET['account_id'] ?? 'ALL';
    $f_type = $_GET['type'] ?? 'ALL';
    $f_user = $_GET['user_id'] ?? 'ALL';
    $f_q = $_GET['q'] ?? '';
    $f_wh_id = $_GET['filter_warehouse_id'] ?? 'ALL'; 

    // UPDATE: Menggunakan LEFT JOIN agar data tetap muncul meski Akun/User terhapus
    $sql = "SELECT f.*, COALESCE(a.code, 'UNK') as code, COALESCE(a.name, 'Unknown Account') as acc_name, COALESCE(u.username, 'System') as username
            FROM finance_transactions f
            LEFT JOIN accounts a ON f.account_id = a.id
            LEFT JOIN users u ON f.user_id = u.id
            WHERE f.date BETWEEN ? AND ?";
    
    $params = [$start, $end];

    // Filter Deskripsi Wilayah
    if ($f_wh_id !== 'ALL') {
        $stmt_wh_name = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
        $stmt_wh_name->execute([$f_wh_id]);
        $wh_target_name = $stmt_wh_name->fetchColumn();
        if ($wh_target_name) {
            $sql .= " AND f.description LIKE ?";
            $params[] = "%[Wilayah: $wh_target_name]%";
        }
    }

    if ($f_acc !== 'ALL') { $sql .= " AND f.account_id = ?"; $params[] = $f_acc; }
    if ($f_type !== 'ALL') { $sql .= " AND f.type = ?"; $params[] = $f_type; }
    if ($f_user !== 'ALL') { $sql .= " AND f.user_id = ?"; $params[] = $f_user; }
    if (!empty($f_q)) {
        $sql .= " AND (f.description LIKE ? OR a.name LIKE ? OR a.code LIKE ?)";
        $params[] = "%$f_q%"; $params[] = "%$f_q%"; $params[] = "%$f_q%";
    }

    $sql .= " ORDER BY f.date ASC, f.created_at ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $combined_data = $stmt->fetchAll();

    // --- PRE-PROCESS: GROUPING & GRAND TOTALS ---
    foreach ($combined_data as $row) {
        $month_key = date('Y-m', strtotime($row['date']));
        $grouped_data[$month_key][] = $row;

        // Hitung Grand Total In/Out
        if ($row['type'] == 'INCOME') $grand_totals['in'] += $row['amount'];
        else $grand_totals['out'] += $row['amount'];

        // Hitung Grand Total Wilayah (Net: Income - Expense)
        $row_desc_str = (string)($row['description'] ?? '');
        $row_wil_net = 0;
        
        foreach($all_warehouses as $wh) {
            // Support format: [Wilayah: Nama] OR [Wilayah: @Nama]
            // Use regex to match [Wilayah: @?NAME followed by space or ]
            $p_name = preg_quote($wh['name'], '/');
            $pattern = "/\[Wilayah:\s*@?" . $p_name . "(\s|\])/i";
            $is_tagged = preg_match($pattern, $row_desc_str);
            
            if ($is_tagged) {
                if ($row['type'] == 'INCOME') {
                    $grand_wh_income[$wh['id']] += $row['amount'];
                    $row_wil_net += $row['amount'];
                } else {
                    $grand_wh_expense[$wh['id']] += $row['amount'];
                    $row_wil_net -= $row['amount'];
                }
            }
        }
        $grand_totals['wilayah_net'] += $row_wil_net;
    }
}

// --- 1. DATA CASHFLOW (SUMMARY HARIAN) ---
$cf_daily_data = [];
$cf_totals = ['in'=>0, 'out'=>0];

if ($view_type == 'CASHFLOW') {
    // Group by Date
    $sql = "SELECT date, 
            SUM(CASE WHEN type = 'INCOME' THEN amount ELSE 0 END) as total_in,
            SUM(CASE WHEN type = 'EXPENSE' THEN amount ELSE 0 END) as total_out
            FROM finance_transactions 
            WHERE date BETWEEN ? AND ?
            GROUP BY date ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start, $end]);
    $cf_daily_data = $stmt->fetchAll();

    foreach($cf_daily_data as $d) {
        $cf_totals['in'] += $d['total_in'];
        $cf_totals['out'] += $d['total_out'];
    }
}

// --- 2. ARUS KAS INTERNAL (GROUPED BY ACCOUNT) ---
$ak_saldo_awal = 0;
$ak_in_groups = [];
$ak_out_groups = [];
$ak_total_in = 0;
$ak_total_out = 0;

if ($view_type == 'ARUS_KAS') {
    $initial_capital = (float)($config['initial_capital'] ?? 0);
    
    // Hitung Saldo Sebelum Tanggal Start
    $in_before = $pdo->prepare("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date < ?"); 
    $in_before->execute([$start]); 
    $val_in_before = $in_before->fetchColumn() ?: 0;
    
    $out_before = $pdo->prepare("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date < ?"); 
    $out_before->execute([$start]); 
    $val_out_before = $out_before->fetchColumn() ?: 0;
    
    $ak_saldo_awal = $initial_capital + $val_in_before - $val_out_before;

    // Group Pemasukan per Akun
    $stmt_in = $pdo->prepare("SELECT a.code, a.name, SUM(f.amount) as total 
                              FROM finance_transactions f 
                              LEFT JOIN accounts a ON f.account_id=a.id 
                              WHERE f.type='INCOME' AND f.date BETWEEN ? AND ? 
                              GROUP BY a.id, a.code, a.name ORDER BY total DESC"); 
    $stmt_in->execute([$start, $end]); 
    $ak_in_groups = $stmt_in->fetchAll();
    
    // Group Pengeluaran per Akun
    $stmt_out = $pdo->prepare("SELECT a.code, a.name, SUM(f.amount) as total 
                               FROM finance_transactions f 
                               LEFT JOIN accounts a ON f.account_id=a.id 
                               WHERE f.type='EXPENSE' AND f.date BETWEEN ? AND ? 
                               GROUP BY a.id, a.code, a.name ORDER BY total DESC"); 
    $stmt_out->execute([$start, $end]); 
    $ak_out_groups = $stmt_out->fetchAll();
    
    foreach($ak_in_groups as $r) $ak_total_in += $r['total'];
    foreach($ak_out_groups as $r) $ak_total_out += $r['total'];
}

// --- 3. ANGGARAN (BUDGET vs ACTUAL) ---
$bg_total_actual = 0;
$bg_details = [];
$bg_wh_name = "";

if ($view_type == 'ANGGARAN') {
    $budget_warehouse = $_GET['budget_warehouse'] ?? 'ALL';
    $params = [$start, $end];
    $wh_clause = "";
    
    if ($budget_warehouse !== 'ALL') {
        $stmt_wh = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?"); 
        $stmt_wh->execute([$budget_warehouse]); 
        $bg_wh_name = $stmt_wh->fetchColumn();
        if ($bg_wh_name) { 
            $wh_clause = " AND f.description LIKE ?"; 
            $params[] = "%[Wilayah: $bg_wh_name]%"; 
        }
    }
    
    // Ambil Total Pengeluaran
    $sql_total = "SELECT SUM(amount) FROM finance_transactions f WHERE type='EXPENSE' AND date BETWEEN ? AND ? $wh_clause";
    $stmt = $pdo->prepare($sql_total); 
    $stmt->execute($params); 
    $bg_total_actual = $stmt->fetchColumn() ?: 0;

    // Ambil Detail per Akun Pengeluaran
    $sql_det = "SELECT a.code, a.name, SUM(f.amount) as total 
                FROM finance_transactions f
                LEFT JOIN accounts a ON f.account_id = a.id
                WHERE f.type='EXPENSE' AND f.date BETWEEN ? AND ? $wh_clause
                GROUP BY a.code, a.name ORDER BY total DESC";
    $stmt_det = $pdo->prepare($sql_det);
    $stmt_det->execute($params);
    $bg_details = $stmt_det->fetchAll();
}

// --- 4. WILAYAH (SUMMARY PER GUDANG) ---
$wilayah_summary = [];
$total_wilayah_rev = 0;
$total_wilayah_exp = 0;

if ($view_type == 'WILAYAH') {
    foreach ($all_warehouses as $wh) {
        $wh_name = $wh['name'];
        // Cari transaksi yang tag [Wilayah: NamaGudang]
        
        $rev = $pdo->prepare("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date BETWEEN ? AND ? AND description LIKE ?");
        $rev->execute([$start, $end, "%[Wilayah: $wh_name]%"]);
        $val_rev = $rev->fetchColumn() ?: 0;

        $exp = $pdo->prepare("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date BETWEEN ? AND ? AND description LIKE ?");
        $exp->execute([$start, $end, "%[Wilayah: $wh_name]%"]);
        $val_exp = $exp->fetchColumn() ?: 0;

        $wilayah_summary[] = [
            'id' => $wh['id'],
            'name' => $wh_name,
            'location' => $wh['location'],
            'revenue' => $val_rev,
            'expense' => $val_exp,
            'profit' => $val_rev - $val_exp
        ];
        $total_wilayah_rev += $val_rev;
        $total_wilayah_exp += $val_exp;
    }
}

// --- 5. CUSTOM (DYNAMIC FILTER LENGKAP) ---
$custom_results = [];
if ($view_type == 'CUSTOM') {
    // Parameter Filter
    $c_q = $_GET['q'] ?? '';
    $c_acc = $_GET['account_id'] ?? 'ALL';
    $c_type = $_GET['type'] ?? 'ALL';
    
    // Filter Baru
    $c_user = $_GET['filter_user_id'] ?? 'ALL';
    $c_wh = $_GET['filter_warehouse_id'] ?? 'ALL';
    $c_min = cleanNumber($_GET['min_amount'] ?? 0);
    $c_max = cleanNumber($_GET['max_amount'] ?? 0);
    $c_sort = $_GET['sort_order'] ?? 'date_asc';
    
    $sql = "SELECT f.*, COALESCE(a.code, 'UNK') as code, COALESCE(a.name, 'Unknown') as acc_name, COALESCE(u.username, 'System') as username
            FROM finance_transactions f
            LEFT JOIN accounts a ON f.account_id = a.id
            LEFT JOIN users u ON f.user_id = u.id
            WHERE f.date BETWEEN ? AND ?";
    $params = [$start, $end];

    // Filter Logic
    if ($c_acc !== 'ALL') { $sql .= " AND f.account_id = ?"; $params[] = $c_acc; }
    if ($c_type !== 'ALL') { $sql .= " AND f.type = ?"; $params[] = $c_type; }
    if ($c_user !== 'ALL') { $sql .= " AND f.user_id = ?"; $params[] = $c_user; }
    
    // Range Nominal
    if ($c_min > 0) { $sql .= " AND f.amount >= ?"; $params[] = $c_min; }
    if ($c_max > 0) { $sql .= " AND f.amount <= ?"; $params[] = $c_max; }

    // Keyword Search
    if (!empty($c_q)) {
        $sql .= " AND (f.description LIKE ? OR a.name LIKE ?)";
        $params[] = "%$c_q%"; $params[] = "%$c_q%";
    }

    // Filter Wilayah (Tag Description)
    if ($c_wh !== 'ALL') {
        $stmt_wh_name = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
        $stmt_wh_name->execute([$c_wh]);
        $wh_target_name = $stmt_wh_name->fetchColumn();
        if ($wh_target_name) {
            $sql .= " AND f.description LIKE ?";
            $params[] = "%[Wilayah: $wh_target_name]%";
        }
    }
    
    // Sorting Logic
    switch($c_sort) {
        case 'date_desc': $sql .= " ORDER BY f.date DESC, f.created_at DESC"; break;
        case 'amount_desc': $sql .= " ORDER BY f.amount DESC"; break;
        case 'amount_asc': $sql .= " ORDER BY f.amount ASC"; break;
        default: $sql .= " ORDER BY f.date ASC, f.created_at ASC"; // Default (date_asc)
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $custom_results = $stmt->fetchAll();

    // --- PRE-PROCESS CUSTOM DATA (SAMA SEPERTI ALL_TRANSAKSI) ---
    // Agar bisa menggunakan struktur tampilan tabel yang sama (Grouping, Grand Total)
    foreach ($custom_results as $row) {
        $month_key = date('Y-m', strtotime($row['date']));
        $grouped_data[$month_key][] = $row;

        // Hitung Grand Total In/Out
        if ($row['type'] == 'INCOME') $grand_totals['in'] += $row['amount'];
        else $grand_totals['out'] += $row['amount'];

        // Hitung Grand Total Wilayah (Net: Income - Expense)
        $row_desc_str = (string)($row['description'] ?? '');
        $row_wil_net = 0;
        
        foreach($all_warehouses as $wh) {
            // Support format: [Wilayah: Nama] OR [Wilayah: @Nama]
            // Use regex to match [Wilayah: @?NAME followed by space or ]
            $p_name = preg_quote($wh['name'], '/');
            $pattern = "/\[Wilayah:\s*@?" . $p_name . "(\s|\])/i";
            $is_tagged = preg_match($pattern, $row_desc_str);
            
            if ($is_tagged) {
                if ($row['type'] == 'INCOME') {
                    $grand_wh_income[$wh['id']] += $row['amount'];
                    $row_wil_net += $row['amount'];
                } else {
                    $grand_wh_expense[$wh['id']] += $row['amount'];
                    $row_wil_net -= $row['amount'];
                }
            }
        }
        $grand_totals['wilayah_net'] += $row_wil_net;
    }
}
?>

<!-- Load Library PDF -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<!-- STYLE KHUSUS PRINT & PDF -->
<style>
    @media print {
        @page { size: landscape; margin: 0mm; } 
        body { margin: 5mm; background: white; font-family: sans-serif; color: black; font-size: 9pt; }
        .no-print, aside, header, nav, .tabs-container, form { display: none !important; }
        .main-content, #report_content { margin: 0; padding: 0; box-shadow: none; border: none; width: 100%; max-width: none; background: white !important; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #000 !important; padding: 4px; font-size: 9pt; }
        .bg-red-100 { background-color: #fee2e2 !important; }
        .bg-red-200 { background-color: #fecaca !important; }
        .bg-gray-200 { background-color: #e5e7eb !important; }
        .bg-blue-100 { background-color: #dbeafe !important; }
        .bg-green-100 { background-color: #dcfce7 !important; }
        .header-print { display: block !important; margin-bottom: 10px; text-align: center; }
    }
    .header-print { display: none; }
</style>

<!-- FILTER & TABS (NO PRINT) -->
<div class="bg-white p-6 rounded shadow mb-6 border-l-4 border-indigo-600 no-print">
    <div class="flex justify-between items-center mb-4">
        <h3 class="font-bold text-gray-700">Laporan Keuangan Internal (Detail)</h3>
    </div>
    
    <!-- TABS -->
    <div class="flex flex-wrap border-b border-gray-200 mb-4 space-x-1 tabs-container">
        <a href="?page=laporan_internal&view=ALL_TRANSAKSI" class="px-3 py-2 text-xs md:text-sm font-bold rounded-t-lg <?=$view_type=='ALL_TRANSAKSI'?'bg-indigo-600 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200'?>">Semua Transaksi</a>
        <a href="?page=laporan_internal&view=CASHFLOW" class="px-3 py-2 text-xs md:text-sm font-bold rounded-t-lg <?=$view_type=='CASHFLOW'?'bg-indigo-600 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200'?>">Cashflow (Harian)</a>
        <a href="?page=laporan_internal&view=ARUS_KAS" class="px-3 py-2 text-xs md:text-sm font-bold rounded-t-lg <?=$view_type=='ARUS_KAS'?'bg-indigo-600 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200'?>">Arus Kas (Akun)</a>
        <a href="?page=laporan_internal&view=ANGGARAN" class="px-3 py-2 text-xs md:text-sm font-bold rounded-t-lg <?=$view_type=='ANGGARAN'?'bg-indigo-600 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200'?>">Anggaran</a>
        <a href="?page=laporan_internal&view=WILAYAH" class="px-3 py-2 text-xs md:text-sm font-bold rounded-t-lg <?=$view_type=='WILAYAH'?'bg-purple-600 text-white':'bg-purple-50 text-purple-600 hover:bg-purple-100'?>">Per Wilayah</a>
        <a href="?page=laporan_internal&view=CUSTOM" class="px-3 py-2 text-xs md:text-sm font-bold rounded-t-lg <?=$view_type=='CUSTOM'?'bg-gray-800 text-white':'bg-gray-100 text-gray-600 hover:bg-gray-200'?>">Custom (Filter+)</a>
    </div>

    <!-- FILTER FORM DINAMIS -->
    <form class="flex flex-wrap gap-4 items-end">
        <input type="hidden" name="page" value="laporan_internal">
        <input type="hidden" name="view" value="<?= $view_type ?>">
        
        <?php if($view_type == 'ANGGARAN'): ?>
            <!-- Input Khusus Anggaran -->
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-bold text-gray-600 mb-1">Target Anggaran (Rp)</label>
                <input type="text" name="budget" value="<?= $_GET['budget'] ?? '' ?>" onkeyup="formatRupiah(this)" class="border p-2 rounded text-sm w-full font-bold" placeholder="0">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-600 mb-1">Filter Gudang</label>
                <select name="budget_warehouse" class="border p-2 rounded text-sm w-full bg-white">
                    <option value="ALL">-- Semua --</option>
                    <?php foreach($all_warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= ($_GET['budget_warehouse']??'') == $wh['id'] ? 'selected' : '' ?>><?= $wh['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <!-- Standard Filters -->
            <?php if($view_type == 'ALL_TRANSAKSI' || $view_type == 'CUSTOM'): ?>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-bold text-gray-600 mb-1">Cari (Keyword)</label>
                <input type="text" name="q" value="<?= htmlspecialchars($_GET['q']??'') ?>" class="border p-2 rounded text-sm w-full" placeholder="Ket/Akun...">
            </div>
            
            <!-- FILTER TAMBAHAN UNTUK ALL_TRANSAKSI & CUSTOM -->
            <div>
                <label class="block text-xs font-bold text-gray-600 mb-1">Filter Wilayah</label>
                <select name="filter_warehouse_id" class="border p-2 rounded text-sm w-full bg-white">
                    <option value="ALL">-- Semua Wilayah --</option>
                    <?php foreach($all_warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= ($_GET['filter_warehouse_id']??'') == $wh['id'] ? 'selected' : '' ?>><?= $wh['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 mb-1">Filter Akun</label>
                <select name="account_id" class="border p-2 rounded text-sm w-full bg-white">
                    <option value="ALL">-- Semua --</option>
                    <?php foreach($all_accounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>" <?= ($_GET['account_id']??'') == $acc['id'] ? 'selected' : '' ?>><?= $acc['code'] ?> - <?= $acc['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- FILTER KHUSUS VIEW CUSTOM (Advanced) -->
            <?php if($view_type == 'CUSTOM'): ?>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Tipe Transaksi</label>
                    <select name="type" class="border p-2 rounded text-sm w-full bg-white">
                        <option value="ALL">Semua</option>
                        <option value="INCOME" <?= ($_GET['type']??'') == 'INCOME' ? 'selected' : '' ?>>Pemasukan</option>
                        <option value="EXPENSE" <?= ($_GET['type']??'') == 'EXPENSE' ? 'selected' : '' ?>>Pengeluaran</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Filter Admin/User</label>
                    <select name="filter_user_id" class="border p-2 rounded text-sm w-full bg-white">
                        <option value="ALL">-- Semua User --</option>
                        <?php foreach($all_users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= ($_GET['filter_user_id']??'') == $u['id'] ? 'selected' : '' ?>><?= $u['username'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Min Nominal</label>
                    <input type="text" name="min_amount" value="<?= $_GET['min_amount'] ?? '' ?>" class="border p-2 rounded text-sm w-24" placeholder="0" onkeyup="formatRupiah(this)">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Max Nominal</label>
                    <input type="text" name="max_amount" value="<?= $_GET['max_amount'] ?? '' ?>" class="border p-2 rounded text-sm w-24" placeholder="∞" onkeyup="formatRupiah(this)">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Urutan Data</label>
                    <select name="sort_order" class="border p-2 rounded text-sm w-full bg-white">
                        <option value="date_asc" <?= ($_GET['sort_order']??'') == 'date_asc' ? 'selected' : '' ?>>Tgl Terlama (A-Z)</option>
                        <option value="date_desc" <?= ($_GET['sort_order']??'') == 'date_desc' ? 'selected' : '' ?>>Tgl Terbaru (Z-A)</option>
                        <option value="amount_desc" <?= ($_GET['sort_order']??'') == 'amount_desc' ? 'selected' : '' ?>>Nominal Terbesar</option>
                        <option value="amount_asc" <?= ($_GET['sort_order']??'') == 'amount_asc' ? 'selected' : '' ?>>Nominal Terkecil</option>
                    </select>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Dari</label>
            <input type="date" name="start" value="<?= $start ?>" class="border p-2 rounded text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Sampai</label>
            <input type="date" name="end" value="<?= $end ?>" class="border p-2 rounded text-sm">
        </div>

        <div class="flex gap-2">
            <button class="bg-indigo-600 text-white px-4 py-2 rounded font-bold text-sm hover:bg-indigo-700">Filter</button>
            <?php if($view_type == 'ALL_TRANSAKSI' || $view_type == 'CUSTOM'): ?>
                <button type="button" onclick="exportToExcel()" class="bg-green-600 text-white px-4 py-2 rounded font-bold text-sm hover:bg-green-700"><i class="fas fa-file-excel"></i> Excel</button>
                <button type="button" onclick="savePDF()" class="bg-red-600 text-white px-4 py-2 rounded font-bold text-sm hover:bg-red-700"><i class="fas fa-file-pdf"></i> PDF</button>
            <?php else: ?>
                <button type="button" onclick="window.print()" class="bg-gray-700 text-white px-4 py-2 rounded font-bold text-sm hover:bg-gray-800"><i class="fas fa-print"></i> Cetak</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- REPORT CONTENT -->
<div id="report_content" class="bg-white p-4 rounded shadow min-h-[500px]">
    
    <!-- KOP SURAT (PRINT ONLY) -->
    <div class="header-print">
        <h2 class="text-xl font-bold uppercase text-center"><?= $config['company_name'] ?? 'NAMA PERUSAHAAN' ?></h2>
        <p class="text-center text-sm mb-4"><?= $current_title ?> (Periode: <?= date('d/m/Y', strtotime($start)) ?> - <?= date('d/m/Y', strtotime($end)) ?>)</p>
        <hr class="border-black mb-4">
    </div>

    <!-- 1. VIEW: ALL TRANSAKSI & CUSTOM -->
    <?php if ($view_type == 'ALL_TRANSAKSI' || $view_type == 'CUSTOM'): 
        $wh_count = count($all_warehouses);
    ?>
    <div class="overflow-x-auto">
        <table id="tbl_all_transaksi" class="w-full text-xs text-left border-collapse border border-black">
            <thead class="bg-red-200 font-bold text-gray-900 border-b-2 border-black">
                <tr>
                    <th rowspan="3" class="p-1 border border-black text-center align-middle w-16">Tanggal</th>
                    <th rowspan="3" class="p-1 border border-black text-center align-middle w-32">Akun & Kode</th>
                    <th rowspan="3" class="p-1 border border-black align-middle w-48 text-center">URAIAN</th>
                    
                    <th colspan="2" class="p-1 border border-black text-center">PEMASUKAN</th>
                    <th colspan="2" class="p-1 border border-black text-center">PENGELUARAN</th>
                    <th rowspan="3" class="p-1 border border-black text-center w-24 align-middle">Sisa Saldo / Selisih Perbulan</th>
                    <th rowspan="3" class="p-1 border border-black text-center w-24 align-middle">TOTAL PEMBAGIAN WIL<br>(RP)</th>
                    <th colspan="<?= $wh_count ?>" class="p-1 border border-black text-center bg-green-100">INPUT PEMBAGIAN PEMASUKAN BERDASARKAN WILAYAH (INCOME)</th>
                    <th colspan="<?= $wh_count ?>" class="p-1 border border-black text-center bg-red-100">INPUT PENGELUARAN BERDASARKAN WILAYAH (EXPENSE)</th>
                </tr>
                <tr>
                    <th rowspan="2" class="p-1 border border-black text-center w-24">PER HARI</th>
                    <th rowspan="2" class="p-1 border border-black text-center w-24">PER BULAN</th>
                    <th rowspan="2" class="p-1 border border-black text-center w-24">PER HARI</th>
                    <th rowspan="2" class="p-1 border border-black text-center w-24">PER BULAN</th>
                    <?php foreach($all_warehouses as $wh): ?>
                        <th rowspan="2" class="p-1 border border-black text-center w-20 bg-green-50 text-[9px]"><?= $wh['name'] ?></th>
                    <?php endforeach; ?>
                    <?php foreach($all_warehouses as $wh): ?>
                        <th rowspan="2" class="p-1 border border-black text-center w-20 bg-red-50 text-[9px]"><?= $wh['name'] ?></th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <!-- Empty row for rowspan alignment -->
                </tr>
            </thead>
            <tbody>
                <!-- GRAND TOTAL ROW -->
                <?php if(!empty($grouped_data)): ?>
                <tr class="bg-blue-200 font-bold text-gray-900 border-b-2 border-black">
                    <td colspan="3" class="p-1 border-r border-black text-center text-sm">TOTAL PERIODE</td>
                    <td class="p-1 border-r border-black text-right text-green-900"><?= formatRupiah($grand_totals['in']) ?></td>
                    <td class="p-1 border-r border-black bg-gray-300"></td>
                    <td class="p-1 border-r border-black text-right text-red-900"><?= formatRupiah($grand_totals['out']) ?></td>
                    <td class="p-1 border-r border-black bg-gray-300"></td>
                    <td class="p-1 border-r border-black text-right text-blue-900"><?= formatRupiah($grand_totals['in'] - $grand_totals['out']) ?></td>
                    <td class="p-1 border-r border-black text-right <?= $grand_totals['wilayah_net'] < 0 ? 'text-red-900' : 'text-blue-900' ?>"><?= formatRupiah($grand_totals['wilayah_net']) ?></td>
                    
                    <!-- INCOME PER WILAYAH -->
                    <?php foreach($all_warehouses as $wh): 
                        $val = $grand_wh_income[$wh['id']] ?? 0;
                    ?>
                        <td class="p-1 border-r border-black text-right bg-green-100 text-green-800 font-bold"><?= $val != 0 ? formatRupiah($val) : '-' ?></td>
                    <?php endforeach; ?>

                    <!-- EXPENSE PER WILAYAH -->
                    <?php foreach($all_warehouses as $wh): 
                        $val = $grand_wh_expense[$wh['id']] ?? 0;
                    ?>
                        <td class="p-1 border-r border-black text-right bg-red-100 text-red-800 font-bold"><?= $val != 0 ? formatRupiah($val) : '-' ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endif; ?>

                <?php 
                if(empty($grouped_data)): ?>
                    <tr><td colspan="<?= 9 + ($wh_count * 2) ?>" class="p-4 text-center text-gray-500 italic">Tidak ada transaksi.</td></tr>
                <?php else:
                    // LOOP PER BULAN
                    foreach($grouped_data as $month_key => $rows):
                        $month_in_run = 0; 
                        $month_out_run = 0;
                        
                        // Summary Bulan Ini
                        $sum_month_in = 0;
                        $sum_month_out = 0;
                        $sum_month_wh_in = []; 
                        $sum_month_wh_out = [];
                        foreach($all_warehouses as $wh) {
                            $sum_month_wh_in[$wh['id']] = 0;
                            $sum_month_wh_out[$wh['id']] = 0;
                        }

                        // DATA ROWS
                        foreach($rows as $row):
                            $amount = $row['amount'];
                            if ($row['type'] == 'INCOME') { 
                                $month_in_run += $amount;
                                $sum_month_in += $amount;
                            } else { 
                                $month_out_run += $amount;
                                $sum_month_out += $amount;
                            }
                            
                            // Wilayah Calculation Logic: Income (+) Expense (-)
                            $row_desc_str = (string)($row['description'] ?? '');
                            $row_wil_net = 0; // Netto untuk baris ini
                            $wh_amounts_in = [];
                            $wh_amounts_out = [];
                            
                            foreach($all_warehouses as $wh) {
                                // Support format: [Wilayah: Nama] OR [Wilayah: @Nama]
                                // Use regex to match [Wilayah: @?NAME followed by space or ]
                                $p_name = preg_quote($wh['name'], '/');
                                $pattern = "/\[Wilayah:\s*@?" . $p_name . "(\s|\])/i";
                                $is_tagged = preg_match($pattern, $row_desc_str);
                                
                                $val_in = 0;
                                $val_out = 0;

                                if ($is_tagged) {
                                    if ($row['type'] == 'INCOME') {
                                        $val_in = $amount;
                                        $row_wil_net += $amount;
                                        $sum_month_wh_in[$wh['id']] += $amount;
                                    } else {
                                        $val_out = $amount;
                                        $row_wil_net -= $amount;
                                        $sum_month_wh_out[$wh['id']] += $amount;
                                    }
                                }
                                
                                $wh_amounts_in[$wh['id']] = $val_in;
                                $wh_amounts_out[$wh['id']] = $val_out;
                            }
                    ?>
                        <tr class="hover:bg-gray-50 border-b border-gray-300">
                            <td class="p-1 border-r border-black text-center whitespace-nowrap"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                            
                            <!-- UPDATED COLUMN: Nama Akun & Kode -->
                            <td class="p-1 border-r border-black text-left">
                                <div class="font-bold text-[10px] leading-tight text-blue-900"><?= htmlspecialchars($row['acc_name']) ?></div>
                                <div class="font-mono text-[9px] text-gray-500"><?= $row['code'] ?></div>
                            </td>

                            <td class="p-1 border-r border-black truncate max-w-xs" title="<?= htmlspecialchars($row['description']) ?>"><?= htmlspecialchars($row['description']) ?></td>
                            
                            <td class="p-1 border-r border-black text-right <?= $row['type'] == 'INCOME' ? 'text-green-700 font-bold' : '' ?>"><?= $row['type'] == 'INCOME' ? formatRupiah($amount) : '-' ?></td>
                            <td class="p-1 border-r border-black text-right text-gray-600 bg-gray-50"><?= formatRupiah($month_in_run) ?></td>
                            <td class="p-1 border-r border-black text-right <?= $row['type'] == 'EXPENSE' ? 'text-red-700 font-bold' : '' ?>"><?= $row['type'] == 'EXPENSE' ? formatRupiah($amount) : '-' ?></td>
                            <td class="p-1 border-r border-black text-right text-gray-600 bg-gray-50"><?= formatRupiah($month_out_run) ?></td>
                            <td class="p-1 border-r border-black text-right font-bold text-blue-800 bg-blue-50"><?= formatRupiah($month_in_run - $month_out_run) ?></td>
                            <td class="p-1 border-r border-black text-right font-bold <?= $row_wil_net < 0 ? 'text-red-700' : ($row_wil_net > 0 ? 'text-green-700' : 'text-gray-400') ?> bg-gray-50"><?= $row_wil_net != 0 ? formatRupiah($row_wil_net) : '-' ?></td>
                            
                            <!-- INCOME COLUMNS -->
                            <?php foreach($all_warehouses as $wh): 
                                $val = $wh_amounts_in[$wh['id']];
                            ?>
                                <td class="p-1 border-r border-black text-right text-[10px] <?= $val > 0 ? 'text-green-600 font-bold' : 'text-gray-300' ?>">
                                    <?= $val != 0 ? formatRupiah($val) : '-' ?>
                                </td>
                            <?php endforeach; ?>

                            <!-- EXPENSE COLUMNS -->
                            <?php foreach($all_warehouses as $wh): 
                                $val = $wh_amounts_out[$wh['id']];
                            ?>
                                <td class="p-1 border-r border-black text-right text-[10px] <?= $val > 0 ? 'text-red-600 font-bold' : 'text-gray-300' ?>">
                                    <?= $val != 0 ? formatRupiah($val) : '-' ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>

                    <!-- ROW: MUTASI PERBULAN -->
                    <?php 
                        $month_net = $sum_month_in - $sum_month_out;
                        $en_m = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                        $id_m = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                        $month_name_disp = str_ireplace($en_m, $id_m, date('F Y', strtotime($month_key . '-01')));
                    ?>
                    <tr class="bg-gray-300 font-bold text-gray-900 border-t-2 border-black border-b-2">
                        <td colspan="3" class="p-1 border-r border-black text-center text-[10px] uppercase">
                            MUTASI ALL TRANSAKSI PERIODE <?= $month_name_disp ?>
                        </td>
                        
                        <!-- Summary Income -->
                        <td class="p-1 border-r border-black text-right text-green-800 bg-green-100"><?= formatRupiah($sum_month_in) ?></td>
                        <td class="p-1 border-r border-black bg-gray-400"></td> 
                        
                        <!-- Summary Expense -->
                        <td class="p-1 border-r border-black text-right text-red-800 bg-red-100"><?= formatRupiah($sum_month_out) ?></td>
                        <td class="p-1 border-r border-black bg-gray-400"></td> 
                        
                        <!-- Net -->
                        <td class="p-1 border-r border-black text-right text-blue-900 bg-blue-200"><?= formatRupiah($month_net) ?></td>
                        
                        <!-- Summary Total Wilayah (Empty Placeholder or Sum Net) -->
                        <td class="p-1 border-r border-black bg-gray-400"></td>
                        
                        <!-- Summary Wilayah INCOME -->
                        <?php foreach($all_warehouses as $wh): 
                            $val = $sum_month_wh_in[$wh['id']];
                        ?>
                            <td class="p-1 border-r border-black text-right text-[10px] bg-green-100 text-green-800">
                                <?= $val != 0 ? formatRupiah($val) : '-' ?>
                            </td>
                        <?php endforeach; ?>

                        <!-- Summary Wilayah EXPENSE -->
                        <?php foreach($all_warehouses as $wh): 
                            $val = $sum_month_wh_out[$wh['id']];
                        ?>
                            <td class="p-1 border-r border-black text-right text-[10px] bg-red-100 text-red-800">
                                <?= $val != 0 ? formatRupiah($val) : '-' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    
                    <!-- Spacer -->
                    <tr><td colspan="<?= 9 + ($wh_count * 2) ?>" class="h-4 bg-white border-none"></td></tr>

                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 2. VIEW: CASHFLOW (HARIAN) -->
    <?php elseif ($view_type == 'CASHFLOW'): ?>
    <div class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-green-50 p-4 border border-green-200 rounded">
                <h4 class="font-bold text-green-800">Total Pemasukan</h4>
                <p class="text-2xl font-bold"><?= formatRupiah($cf_totals['in']) ?></p>
            </div>
            <div class="bg-red-50 p-4 border border-red-200 rounded">
                <h4 class="font-bold text-red-800">Total Pengeluaran</h4>
                <p class="text-2xl font-bold"><?= formatRupiah($cf_totals['out']) ?></p>
            </div>
            <div class="bg-blue-50 p-4 border border-blue-200 rounded">
                <h4 class="font-bold text-blue-800">Surplus / Defisit</h4>
                <p class="text-2xl font-bold"><?= formatRupiah($cf_totals['in'] - $cf_totals['out']) ?></p>
            </div>
        </div>

        <table class="w-full text-sm border-collapse border border-gray-400">
            <thead class="bg-gray-100 text-gray-800 font-bold">
                <tr>
                    <th class="p-3 border border-gray-400 text-left">Tanggal</th>
                    <th class="p-3 border border-gray-400 text-right text-green-700">Total Masuk (IN)</th>
                    <th class="p-3 border border-gray-400 text-right text-red-700">Total Keluar (OUT)</th>
                    <th class="p-3 border border-gray-400 text-right text-blue-800">Selisih</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($cf_daily_data as $row): $diff = $row['total_in'] - $row['total_out']; ?>
                <tr class="hover:bg-gray-50 border-b border-gray-300">
                    <td class="p-3 border-r border-gray-400"><?= date('d F Y', strtotime($row['date'])) ?></td>
                    <td class="p-3 border-r border-gray-400 text-right font-medium"><?= formatRupiah($row['total_in']) ?></td>
                    <td class="p-3 border-r border-gray-400 text-right font-medium"><?= formatRupiah($row['total_out']) ?></td>
                    <td class="p-3 border-r border-gray-400 text-right font-bold <?= $diff>=0?'text-blue-700':'text-red-600' ?>"><?= formatRupiah($diff) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($cf_daily_data)): ?>
                    <tr><td colspan="4" class="p-4 text-center text-gray-500">Tidak ada data.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 3. VIEW: ARUS KAS (RINCIAN AKUN) -->
    <?php elseif ($view_type == 'ARUS_KAS'): ?>
    <div class="max-w-4xl mx-auto space-y-4 text-sm font-mono">
        <!-- Saldo Awal -->
        <div class="flex justify-between items-center bg-gray-100 p-3 font-bold border-b border-gray-400">
            <span>SALDO AWAL (Per <?= date('d/m/Y', strtotime($start)) ?>)</span>
            <span><?= formatRupiah($ak_saldo_awal) ?></span>
        </div>

        <!-- Pemasukan -->
        <div class="border border-green-200 rounded p-4 bg-green-50">
            <h4 class="font-bold text-green-800 border-b border-green-300 pb-2 mb-2 uppercase">Arus Masuk (Penerimaan)</h4>
            <?php foreach($ak_in_groups as $row): ?>
            <div class="flex justify-between py-1 border-b border-green-100 last:border-0">
                <span><?= $row['code'] ?> - <?= $row['name'] ?></span>
                <span><?= formatRupiah($row['total']) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="flex justify-between font-bold pt-2 mt-2 border-t border-green-400 text-lg">
                <span>TOTAL PEMASUKAN</span>
                <span><?= formatRupiah($ak_total_in) ?></span>
            </div>
        </div>

        <!-- Pengeluaran -->
        <div class="border border-red-200 rounded p-4 bg-red-50">
            <h4 class="font-bold text-red-800 border-b border-red-300 pb-2 mb-2 uppercase">Arus Keluar (Pengeluaran)</h4>
            <?php foreach($ak_out_groups as $row): ?>
            <div class="flex justify-between py-1 border-b border-red-100 last:border-0">
                <span><?= $row['code'] ?> - <?= $row['name'] ?></span>
                <span>(<?= formatRupiah($row['total']) ?>)</span>
            </div>
            <?php endforeach; ?>
            <div class="flex justify-between font-bold pt-2 mt-2 border-t border-red-400 text-lg">
                <span>TOTAL PENGELUARAN</span>
                <span>(<?= formatRupiah($ak_total_out) ?>)</span>
            </div>
        </div>

        <!-- Saldo Akhir -->
        <div class="flex justify-between items-center bg-blue-100 p-4 font-bold border-t-2 border-blue-600 text-lg">
            <span>SALDO AKHIR (Per <?= date('d/m/Y', strtotime($end)) ?>)</span>
            <span><?= formatRupiah($ak_saldo_awal + $ak_total_in - $ak_total_out) ?></span>
        </div>
    </div>

    <!-- 4. VIEW: ANGGARAN -->
    <?php elseif ($view_type == 'ANGGARAN'): 
        $variance = $budget_input - $bg_total_actual;
        $pct = $budget_input > 0 ? ($bg_total_actual / $budget_input) * 100 : 0;
        $status_color = $variance >= 0 ? 'text-green-600' : 'text-red-600';
    ?>
    <div class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
            <div class="bg-gray-100 p-4 rounded border text-center">
                <p class="text-xs text-gray-500 font-bold uppercase">Target Anggaran</p>
                <p class="text-xl font-bold"><?= formatRupiah($budget_input) ?></p>
            </div>
            <div class="bg-red-50 p-4 rounded border border-red-200 text-center">
                <p class="text-xs text-red-700 font-bold uppercase">Realisasi Pengeluaran</p>
                <p class="text-xl font-bold text-red-700"><?= formatRupiah($bg_total_actual) ?></p>
            </div>
            <div class="bg-white p-4 rounded border text-center border-l-4 <?= $variance>=0 ? 'border-green-500' : 'border-red-500' ?>">
                <p class="text-xs text-gray-500 font-bold uppercase">Sisa Anggaran (Varian)</p>
                <p class="text-xl font-bold <?= $status_color ?>"><?= formatRupiah($variance) ?></p>
                <p class="text-xs text-gray-400">Terpakai: <?= number_format($pct, 1) ?>%</p>
            </div>
        </div>

        <h4 class="font-bold border-b pb-2 mb-2">Rincian Pengeluaran vs Anggaran</h4>
        <table class="w-full text-sm border-collapse border border-gray-400">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-2 border text-left">Kode Akun</th>
                    <th class="p-2 border text-left">Kategori Pengeluaran</th>
                    <th class="p-2 border text-right">Realisasi (Actual)</th>
                    <th class="p-2 border text-right">% Kontribusi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($bg_details as $d): 
                    $contribution = $bg_total_actual > 0 ? ($d['total'] / $bg_total_actual) * 100 : 0;
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="p-2 border font-mono"><?= $d['code'] ?></td>
                    <td class="p-2 border"><?= $d['name'] ?></td>
                    <td class="p-2 border text-right font-medium"><?= formatRupiah($d['total']) ?></td>
                    <td class="p-2 border text-right text-gray-500"><?= number_format($contribution, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 5. VIEW: WILAYAH -->
    <?php elseif ($view_type == 'WILAYAH'): ?>
    <div class="space-y-6">
        <div class="flex justify-between items-center bg-gray-100 p-3 rounded">
            <h4 class="font-bold">Total Akumulasi Semua Wilayah</h4>
            <div class="text-sm">
                <span class="mr-4 text-green-700 font-bold">Total Masuk: <?= formatRupiah($total_wilayah_rev) ?></span>
                <span class="mr-4 text-red-700 font-bold">Total Keluar: <?= formatRupiah($total_wilayah_exp) ?></span>
                <span class="text-blue-700 font-bold">Net: <?= formatRupiah($total_wilayah_rev - $total_wilayah_exp) ?></span>
            </div>
        </div>

        <table class="w-full text-sm border-collapse border border-gray-400">
            <thead class="bg-purple-100 text-purple-900">
                <tr>
                    <th class="p-3 border border-gray-400 text-left">Nama Wilayah / Gudang</th>
                    <th class="p-3 border border-gray-400 text-left">Lokasi</th>
                    <th class="p-3 border border-gray-400 text-right">Tagged Income</th>
                    <th class="p-3 border border-gray-400 text-right">Tagged Expense</th>
                    <th class="p-3 border border-gray-400 text-right">Profitabilitas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($wilayah_summary as $w): ?>
                <tr class="hover:bg-gray-50">
                    <td class="p-3 border border-gray-400 font-bold"><?= $w['name'] ?></td>
                    <td class="p-3 border border-gray-400 text-gray-600"><?= $w['location'] ?></td>
                    <td class="p-3 border border-gray-400 text-right text-green-700"><?= formatRupiah($w['revenue']) ?></td>
                    <td class="p-3 border border-gray-400 text-right text-red-700"><?= formatRupiah($w['expense']) ?></td>
                    <td class="p-3 border border-gray-400 text-right font-bold <?= $w['profit']>=0?'text-blue-700':'text-red-700' ?>">
                        <?= formatRupiah($w['profit']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($wilayah_summary)): ?>
                    <tr><td colspan="5" class="p-4 text-center italic">Tidak ada data wilayah yang ditemukan. Pastikan deskripsi transaksi menggunakan tag [Wilayah: Nama].</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="text-xs text-gray-500 mt-2 italic">* Data diambil berdasarkan tag [Wilayah: NamaGudang] pada deskripsi transaksi.</p>
    </div>

    <?php endif; ?>

</div>

<script>
function formatRupiah(input) {
    let value = input.value.replace(/\D/g, '');
    if(value === '') { input.value = ''; return; }
    input.value = new Intl.NumberFormat('id-ID').format(value);
}

function exportToExcel() {
    var tbl = document.getElementById('tbl_all_transaksi');
    if(!tbl) return alert('Data tabel Semua Transaksi tidak ditemukan. Pastikan Anda berada di view "Semua Transaksi".');
    
    // Gunakan SheetJS (xlsx)
    var wb = XLSX.utils.table_to_book(tbl, {sheet: "Laporan Internal"});
    XLSX.writeFile(wb, 'Laporan_Internal_<?= date("Ymd") ?>.xlsx');
}

function savePDF() {
    const element = document.getElementById('report_content');
    const header = document.querySelector('.header-print');
    
    if(header) header.style.display = 'block';

    const opt = {
        margin:       5,
        filename:     'Laporan_Internal_<?= date("Ymd") ?>.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'mm', format: 'a3', orientation: 'landscape' } // A3 agar muat banyak kolom
    };

    const btn = event.currentTarget;
    const oldText = btn.innerHTML;
    btn.innerHTML = '...';
    btn.disabled = true;

    html2pdf().set(opt).from(element).save().then(() => {
        if(header) header.style.display = '';
        btn.innerHTML = oldText;
        btn.disabled = false;
    });
}
</script>