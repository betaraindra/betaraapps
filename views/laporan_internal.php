<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

// --- 1. SETUP & FILTER ---
$view = $_GET['view'] ?? 'CUSTOM'; // Default ke tampilan detail
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

// Filter Inputs
$f_keyword = $_GET['q'] ?? '';
$f_wilayah = $_GET['f_wilayah'] ?? 'ALL'; // Filter Global Wilayah
$f_account = $_GET['f_account'] ?? 'ALL';
$f_user = $_GET['f_user'] ?? 'ALL';

// --- 2. DATA MASTER ---
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$all_accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();
$all_users = $pdo->query("SELECT * FROM users ORDER BY username ASC")->fetchAll();

// --- 3. QUERY TRANSAKSI ---
$sql = "SELECT f.*, a.name as acc_name, a.code as acc_code, u.username 
        FROM finance_transactions f
        JOIN accounts a ON f.account_id = a.id
        JOIN users u ON f.user_id = u.id
        WHERE f.date BETWEEN ? AND ?";
$params = [$start, $end];

// Apply Filters
if (!empty($f_keyword)) {
    $sql .= " AND (f.description LIKE ? OR a.name LIKE ?)";
    $params[] = "%$f_keyword%";
    $params[] = "%$f_keyword%";
}

if ($f_wilayah !== 'ALL') {
    // Cari text [Wilayah: NamaGudang] di deskripsi
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

$sql .= " ORDER BY f.date ASC, f.created_at ASC"; // ASC untuk running balance

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// --- 4. PERHITUNGAN SALDO AWAL (Opsional, start 0 untuk periode ini) ---
// Jika ingin saldo kumulatif:
// $saldo_awal = $pdo->query("SELECT SUM(IF(type='INCOME', amount, -amount)) FROM finance_transactions WHERE date < '$start'")->fetchColumn() ?: 0;
$running_balance = 0; 
?>

<!-- STYLE KHUSUS -->
<style>
    .tab-btn { padding: 8px 16px; font-size: 13px; font-weight: bold; border-radius: 6px; transition: all 0.2s; }
    .tab-active { background-color: #6366f1; color: white; box-shadow: 0 2px 4px rgba(99, 102, 241, 0.3); }
    .tab-inactive { background-color: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }
    .tab-inactive:hover { background-color: #e5e7eb; }
    
    /* Table Styles similar to screenshot */
    .complex-table th { border: 1px solid #9ca3af; padding: 8px; text-transform: uppercase; font-size: 11px; }
    .complex-table td { border: 1px solid #d1d5db; padding: 6px; font-size: 11px; vertical-align: middle; }
    .col-header-red { background-color: #fee2e2; color: #7f1d1d; } /* bg-red-100 */
    .col-header-gray { background-color: #f3f4f6; color: #1f2937; } /* bg-gray-100 */
    .col-header-total { background-color: #ffedd5; color: #7c2d12; } /* bg-orange-100 */
    .col-green { background-color: #dcfce7; }
    .col-red { background-color: #fee2e2; }
</style>

<div class="bg-white p-4 rounded shadow mb-6 no-print">
    <h2 class="text-xl font-bold text-gray-800 mb-4">Laporan Keuangan Internal (Detail)</h2>
    
    <!-- TABS MENU -->
    <div class="flex flex-wrap gap-2 mb-6">
        <a href="?page=laporan_internal&view=ALL" class="tab-btn <?= $view=='ALL'?'tab-active':'tab-inactive bg-blue-600 text-white' ?>">Semua Transaksi</a>
        <a href="?page=laporan_internal&view=CASHFLOW" class="tab-btn <?= $view=='CASHFLOW'?'tab-active':'tab-inactive' ?>">Cashflow (In/Out)</a>
        <a href="?page=laporan_internal&view=ARUS_KAS" class="tab-btn <?= $view=='ARUS_KAS'?'tab-active':'tab-inactive' ?>">Arus Kas</a>
        <a href="?page=laporan_internal&view=ANGGARAN" class="tab-btn <?= $view=='ANGGARAN'?'tab-active':'tab-inactive' ?>">Anggaran</a>
        <a href="?page=laporan_internal&view=WILAYAH" class="tab-btn <?= $view=='WILAYAH'?'tab-active text-purple-600 bg-purple-50':'tab-inactive' ?>">Per Wilayah</a>
        <a href="?page=laporan_internal&view=CUSTOM" class="tab-btn <?= $view=='CUSTOM'?'tab-active':'tab-inactive' ?> flex items-center gap-1">
            <i class="fas fa-pencil-alt text-xs"></i> Custom
        </a>
    </div>

    <!-- FILTER FORM (Sesuai Screenshot) -->
    <form method="GET" class="border p-4 rounded bg-gray-50">
        <input type="hidden" name="page" value="laporan_internal">
        <input type="hidden" name="view" value="CUSTOM">
        
        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            <!-- Keyword -->
            <div class="md:col-span-3">
                <label class="block text-xs font-bold text-gray-600 mb-1">Cari (Keyword)</label>
                <input type="text" name="q" value="<?= htmlspecialchars($f_keyword) ?>" class="w-full border p-2 rounded text-sm placeholder-gray-400" placeholder="Ket/Akun...">
            </div>

            <!-- Filter Wilayah -->
            <div class="md:col-span-2">
                <label class="block text-xs font-bold text-gray-600 mb-1">Filter Wilayah</label>
                <select name="f_wilayah" class="w-full border p-2 rounded text-sm bg-white">
                    <option value="ALL">-- Perusahaan (Global) --</option>
                    <?php foreach($warehouses as $w): ?>
                        <option value="<?= $w['id'] ?>" <?= $f_wilayah == $w['id'] ? 'selected' : '' ?>><?= $w['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Akun -->
            <div class="md:col-span-2">
                <label class="block text-xs font-bold text-gray-600 mb-1">Filter Akun</label>
                <select name="f_account" class="w-full border p-2 rounded text-sm bg-white">
                    <option value="ALL">-- Semua --</option>
                    <?php foreach($all_accounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>" <?= $f_account == $acc['id'] ? 'selected' : '' ?>><?= $acc['code'] ?> - <?= $acc['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- User -->
            <div class="md:col-span-1">
                <label class="block text-xs font-bold text-gray-600 mb-1">User</label>
                <select name="f_user" class="w-full border p-2 rounded text-sm bg-white">
                    <option value="ALL">-- Semua --</option>
                    <?php foreach($all_users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $f_user == $u['id'] ? 'selected' : '' ?>><?= $u['username'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dates -->
            <div class="md:col-span-2 flex gap-1">
                <div class="w-1/2">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Dari</label>
                    <input type="date" name="start" value="<?= $start ?>" class="w-full border p-2 rounded text-sm">
                </div>
                <div class="w-1/2">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Sampai</label>
                    <input type="date" name="end" value="<?= $end ?>" class="w-full border p-2 rounded text-sm">
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="md:col-span-2 flex gap-1">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-xs font-bold flex-1">
                    Filter & Tampilkan
                </button>
            </div>
        </div>
        <div class="mt-2 flex gap-2">
             <button type="button" onclick="window.print()" class="bg-gray-700 hover:bg-gray-800 text-white px-3 py-1 rounded text-xs font-bold flex items-center gap-1">
                <i class="fas fa-print"></i> Cetak
            </button>
            <button type="button" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-xs font-bold flex items-center gap-1">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
        </div>
    </form>
</div>

<!-- REPORT CONTENT -->
<div class="bg-white p-4 min-h-screen">
    
    <div class="text-center mb-6">
        <h3 class="text-lg font-bold text-gray-900 uppercase underline">JURNAL TRANSAKSI KEUANGAN & MATERIAL</h3>
        <p class="text-sm text-gray-500">Periode: <?= date('d F Y', strtotime($start)) ?> s.d <?= date('d F Y', strtotime($end)) ?></p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse complex-table">
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
                <?php if(empty($transactions)): ?>
                    <tr><td colspan="<?= 9 + count($warehouses) ?>" class="text-center p-8 text-gray-400 italic">Tidak ada data transaksi.</td></tr>
                <?php else: ?>
                    <?php 
                    $grand_debit = 0;
                    $grand_kredit = 0;
                    $grand_total_wil = 0;
                    
                    // Array untuk total per kolom gudang
                    $wh_col_totals = array_fill(0, count($warehouses), 0);

                    foreach($transactions as $row): 
                        $trx_in = ($row['type'] == 'INCOME') ? $row['amount'] : 0;
                        $trx_out = ($row['type'] == 'EXPENSE') ? $row['amount'] : 0;
                        
                        $grand_debit += $trx_in;
                        $grand_kredit += $trx_out;
                        
                        // Hitung Saldo Berjalan
                        $running_balance += ($trx_in - $trx_out);

                        // Parse Wilayah dari Deskripsi untuk Matriks
                        $trx_wh_name = '';
                        if (preg_match('/\[Wilayah:\s*(.*?)\]/', $row['description'], $matches)) {
                            $trx_wh_name = trim($matches[1]);
                        }
                        
                        // Bersihkan Deskripsi Tampilan (Hapus Tag)
                        $display_desc = $row['description'];
                        // Opsional: $display_desc = str_replace($matches[0] ?? '', '', $row['description']);
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
                            // Hanya tampilkan jika match gudang DAN merupakan pengeluaran (sesuai header "PEMBAGIAN PENGELUARAN")
                            // Atau Pemasukan juga jika diperlukan, tapi header bilang "PENGELUARAN".
                            // Asumsi: Hanya pengeluaran yang dipecah per wilayah di kolom tengah.
                            
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

                        <!-- Total Wilayah Row -->
                        <td class="text-right font-bold bg-orange-50 text-orange-800 border-l-2 border-orange-100">
                            <?= $row_wil_total > 0 ? number_format($row_wil_total, 0, ',', '.') : '-' ?>
                        </td>

                        <!-- Debit/Kredit -->
                        <td class="text-right font-bold text-green-700 col-green">
                            <?= $trx_in > 0 ? number_format($trx_in, 0, ',', '.') : '-' ?>
                        </td>
                        <td class="text-right font-bold text-red-700 col-red">
                            <?= $trx_out > 0 ? number_format($trx_out, 0, ',', '.') : '-' ?>
                        </td>
                        
                        <!-- Saldo -->
                        <td class="text-right font-bold bg-blue-50 text-blue-900 border-l border-blue-200">
                            <?= number_format($running_balance, 0, ',', '.') ?>
                        </td>
                        
                        <td class="text-center text-[9px] text-gray-500"><?= $row['username'] ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- FOOTER TOTALS -->
                    <tr class="font-bold bg-gray-200 border-t-2 border-gray-600">
                        <td colspan="4" class="text-right p-2 uppercase">TOTAL PERIODE INI</td>
                        
                        <!-- TOTAL PER GUDANG -->
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