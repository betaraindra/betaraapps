<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

$view = $_GET['view'] ?? 'ALL';
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

// --- DATA QUERIES ---

// 1. SEMUA TRANSAKSI
if ($view == 'ALL') {
    $stmt = $pdo->prepare("
        SELECT f.*, a.name as acc_name, a.code as acc_code, u.username 
        FROM finance_transactions f
        JOIN accounts a ON f.account_id = a.id
        JOIN users u ON f.user_id = u.id
        WHERE f.date BETWEEN ? AND ?
        ORDER BY f.date DESC, f.created_at DESC
    ");
    $stmt->execute([$start, $end]);
    $transactions = $stmt->fetchAll();
}

// 2. CASHFLOW & ARUS KAS (Daily)
if ($view == 'CASHFLOW' || $view == 'ARUS_KAS') {
    $stmt = $pdo->prepare("
        SELECT date, 
               SUM(CASE WHEN type='INCOME' THEN amount ELSE 0 END) as income,
               SUM(CASE WHEN type='EXPENSE' THEN amount ELSE 0 END) as expense
        FROM finance_transactions
        WHERE date BETWEEN ? AND ?
        GROUP BY date
        ORDER BY date ASC
    ");
    $stmt->execute([$start, $end]);
    $daily_stats = $stmt->fetchAll();
    
    $total_in = 0; $total_out = 0;
    foreach($daily_stats as $d) { $total_in += $d['income']; $total_out += $d['expense']; }
}

// 3. PER WILAYAH (Parsing Description)
if ($view == 'WILAYAH') {
    $stmt = $pdo->prepare("SELECT * FROM finance_transactions WHERE date BETWEEN ? AND ? ORDER BY date DESC");
    $stmt->execute([$start, $end]);
    $raw_trx = $stmt->fetchAll();
    
    $wilayah_stats = [];
    $global_in = 0; $global_out = 0;

    foreach($raw_trx as $trx) {
        $wilayah = 'Umum / Kantor Pusat'; // Default
        // Regex extract [Wilayah: Nama]
        if (preg_match('/\[Wilayah:\s*(.*?)\]/', $trx['description'], $matches)) {
            $wilayah = trim($matches[1]);
        }
        
        if (!isset($wilayah_stats[$wilayah])) {
            $wilayah_stats[$wilayah] = ['in' => 0, 'out' => 0, 'trx_count' => 0];
        }
        
        if ($trx['type'] == 'INCOME') {
            $wilayah_stats[$wilayah]['in'] += $trx['amount'];
            $global_in += $trx['amount'];
        } else {
            $wilayah_stats[$wilayah]['out'] += $trx['amount'];
            $global_out += $trx['amount'];
        }
        $wilayah_stats[$wilayah]['trx_count']++;
    }
    // Sort by name
    ksort($wilayah_stats);
}

// 4. CUSTOM (Logic sendiri di bawah)
?>

<div class="mb-6 no-print">
    <h2 class="text-2xl font-bold text-gray-800 mb-4"><i class="fas fa-file-invoice-dollar text-blue-600"></i> Laporan Internal & Audit</h2>
    
    <!-- TABS NAVIGATION -->
    <div class="flex flex-wrap border-b border-gray-300 bg-white rounded-t-lg shadow-sm overflow-x-auto">
        <a href="?page=laporan_internal&view=ALL" class="px-5 py-3 font-bold text-sm border-b-4 hover:bg-blue-50 transition <?= $view=='ALL' ? 'border-blue-600 text-blue-700 bg-blue-50' : 'border-transparent text-gray-500' ?>">
            <i class="fas fa-list mr-1"></i> Semua Transaksi
        </a>
        <a href="?page=laporan_internal&view=CASHFLOW" class="px-5 py-3 font-bold text-sm border-b-4 hover:bg-green-50 transition <?= $view=='CASHFLOW' ? 'border-green-600 text-green-700 bg-green-50' : 'border-transparent text-gray-500' ?>">
            <i class="fas fa-chart-line mr-1"></i> Cashflow (In/Out)
        </a>
        <a href="?page=laporan_internal&view=ARUS_KAS" class="px-5 py-3 font-bold text-sm border-b-4 hover:bg-teal-50 transition <?= $view=='ARUS_KAS' ? 'border-teal-600 text-teal-700 bg-teal-50' : 'border-transparent text-gray-500' ?>">
            <i class="fas fa-calendar-alt mr-1"></i> Arus Kas Harian
        </a>
        <a href="?page=laporan_internal&view=WILAYAH" class="px-5 py-3 font-bold text-sm border-b-4 hover:bg-purple-50 transition <?= $view=='WILAYAH' ? 'border-purple-600 text-purple-700 bg-purple-50' : 'border-transparent text-gray-500' ?>">
            <i class="fas fa-map-marked-alt mr-1"></i> Per Wilayah
        </a>
        <a href="?page=laporan_internal&view=CUSTOM" class="px-5 py-3 font-bold text-sm border-b-4 hover:bg-orange-50 transition <?= $view=='CUSTOM' ? 'border-orange-600 text-orange-700 bg-orange-50' : 'border-transparent text-gray-500' ?>">
            <i class="fas fa-filter mr-1"></i> Custom Filter
        </a>
    </div>
</div>

<div class="bg-white p-6 rounded-b-lg shadow min-h-screen">
    
    <!-- HEADER FILTER (GLOBAL) -->
    <?php if($view !== 'CUSTOM'): ?>
    <div class="flex flex-col md:flex-row justify-between items-center mb-6 no-print bg-gray-50 p-4 rounded border border-gray-200">
        <form class="flex flex-wrap gap-4 items-end">
            <input type="hidden" name="page" value="laporan_internal">
            <input type="hidden" name="view" value="<?= $view ?>">
            <div>
                <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
                <input type="date" name="start" value="<?= $start ?>" class="border p-2 rounded text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
                <input type="date" name="end" value="<?= $end ?>" class="border p-2 rounded text-sm">
            </div>
            <button class="bg-blue-600 text-white px-4 py-2 rounded font-bold hover:bg-blue-700 text-sm shadow h-[38px]">
                <i class="fas fa-filter"></i> Tampilkan
            </button>
        </form>
        <button onclick="window.print()" class="bg-gray-700 text-white px-4 py-2 rounded font-bold hover:bg-gray-800 text-sm shadow">
            <i class="fas fa-print"></i> Cetak Laporan
        </button>
    </div>
    <?php endif; ?>

    <!-- CONTENT: ALL TRANSACTIONS -->
    <?php if($view == 'ALL'): ?>
        <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2">Detail Semua Transaksi (<?= count($transactions) ?> Data)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse border border-gray-300">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-2 border">Tanggal</th>
                        <th class="p-2 border">Akun</th>
                        <th class="p-2 border">Deskripsi</th>
                        <th class="p-2 border text-right">Debit (Masuk)</th>
                        <th class="p-2 border text-right">Kredit (Keluar)</th>
                        <th class="p-2 border text-center">User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sum_in = 0; $sum_out = 0;
                    foreach($transactions as $t): 
                        if($t['type'] == 'INCOME') $sum_in += $t['amount']; else $sum_out += $t['amount'];
                    ?>
                    <tr class="hover:bg-gray-50 border-b">
                        <td class="p-2 border whitespace-nowrap"><?= date('d/m/Y', strtotime($t['date'])) ?></td>
                        <td class="p-2 border">
                            <b><?= $t['acc_name'] ?></b> <span class="text-xs text-gray-500">(<?= $t['acc_code'] ?>)</span>
                        </td>
                        <td class="p-2 border"><?= $t['description'] ?></td>
                        <td class="p-2 border text-right text-green-600"><?= $t['type']=='INCOME' ? formatRupiah($t['amount']) : '-' ?></td>
                        <td class="p-2 border text-right text-red-600"><?= $t['type']=='EXPENSE' ? formatRupiah($t['amount']) : '-' ?></td>
                        <td class="p-2 border text-center text-xs text-gray-500"><?= $t['username'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-100 font-bold">
                    <tr>
                        <td colspan="3" class="p-2 border text-right">TOTAL</td>
                        <td class="p-2 border text-right text-green-700"><?= formatRupiah($sum_in) ?></td>
                        <td class="p-2 border text-right text-red-700"><?= formatRupiah($sum_out) ?></td>
                        <td class="p-2 border"></td>
                    </tr>
                    <tr>
                        <td colspan="3" class="p-2 border text-right uppercase">Net Cashflow</td>
                        <td colspan="2" class="p-2 border text-center text-lg <?= ($sum_in-$sum_out)>=0?'text-blue-700':'text-red-600' ?>">
                            <?= formatRupiah($sum_in - $sum_out) ?>
                        </td>
                        <td class="border"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

    <!-- CONTENT: CASHFLOW & ARUS KAS -->
    <?php elseif($view == 'CASHFLOW' || $view == 'ARUS_KAS'): ?>
        <h3 class="font-bold text-lg mb-4 text-green-800 border-b pb-2">Ringkasan Cashflow Harian</h3>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-green-50 p-4 rounded border border-green-200 text-center">
                <p class="text-xs text-green-700 font-bold uppercase">Total Pemasukan</p>
                <p class="text-2xl font-bold text-green-800"><?= formatRupiah($total_in) ?></p>
            </div>
            <div class="bg-red-50 p-4 rounded border border-red-200 text-center">
                <p class="text-xs text-red-700 font-bold uppercase">Total Pengeluaran</p>
                <p class="text-2xl font-bold text-red-800"><?= formatRupiah($total_out) ?></p>
            </div>
            <div class="bg-blue-50 p-4 rounded border border-blue-200 text-center">
                <p class="text-xs text-blue-700 font-bold uppercase">Surplus / Defisit</p>
                <p class="text-2xl font-bold <?= ($total_in-$total_out)>=0?'text-blue-800':'text-red-800' ?>">
                    <?= formatRupiah($total_in - $total_out) ?>
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-center border-collapse border border-gray-300">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border">Tanggal</th>
                        <th class="p-3 border text-green-700">Pemasukan</th>
                        <th class="p-3 border text-red-700">Pengeluaran</th>
                        <th class="p-3 border text-blue-700">Selisih (Net)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($daily_stats as $d): 
                        $net = $d['income'] - $d['expense'];
                    ?>
                    <tr class="hover:bg-gray-50 border-b">
                        <td class="p-3 border font-bold"><?= date('d F Y', strtotime($d['date'])) ?></td>
                        <td class="p-3 border text-green-600"><?= formatRupiah($d['income']) ?></td>
                        <td class="p-3 border text-red-600"><?= formatRupiah($d['expense']) ?></td>
                        <td class="p-3 border font-bold <?= $net>=0?'text-blue-600':'text-red-600' ?>">
                            <?= formatRupiah($net) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <!-- CONTENT: PER WILAYAH -->
    <?php elseif($view == 'WILAYAH'): ?>
        <h3 class="font-bold text-lg mb-4 text-purple-800 border-b pb-2">Laporan Keuangan Per Wilayah (Berdasarkan Tag)</h3>
        <p class="text-sm text-gray-500 mb-4">*Data dikelompokkan berdasarkan tag [Wilayah: Nama] pada deskripsi transaksi.</p>
        
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse border border-gray-300">
                <thead class="bg-purple-100 text-purple-900">
                    <tr>
                        <th class="p-3 border">Nama Wilayah / Gudang</th>
                        <th class="p-3 border text-center">Jml Transaksi</th>
                        <th class="p-3 border text-right">Total Pemasukan</th>
                        <th class="p-3 border text-right">Total Pengeluaran</th>
                        <th class="p-3 border text-right">Profit / Sisa Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($wilayah_stats as $name => $stat): 
                        $net = $stat['in'] - $stat['out'];
                    ?>
                    <tr class="hover:bg-gray-50 border-b">
                        <td class="p-3 border font-bold"><?= $name ?></td>
                        <td class="p-3 border text-center"><?= $stat['trx_count'] ?></td>
                        <td class="p-3 border text-right text-green-600"><?= formatRupiah($stat['in']) ?></td>
                        <td class="p-3 border text-right text-red-600"><?= formatRupiah($stat['out']) ?></td>
                        <td class="p-3 border text-right font-bold <?= $net>=0?'text-blue-600':'text-red-600' ?>">
                            <?= formatRupiah($net) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-100 font-bold">
                    <tr>
                        <td colspan="2" class="p-3 border text-right">TOTAL GLOBAL</td>
                        <td class="p-3 border text-right text-green-700"><?= formatRupiah($global_in) ?></td>
                        <td class="p-3 border text-right text-red-700"><?= formatRupiah($global_out) ?></td>
                        <td class="p-3 border text-right text-blue-700"><?= formatRupiah($global_in - $global_out) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

    <!-- CONTENT: CUSTOM VIEW (Reusing Logic from previous step) -->
    <?php elseif($view == 'CUSTOM'): 
        $c_acc = $_GET['account_id'] ?? 'ALL';
        $c_type = $_GET['type'] ?? 'ALL'; // Restored 'ALL' capability but Default logic applies below
        
        $sql = "SELECT f.*, a.name as acc_name, a.code as acc_code, u.username 
                FROM finance_transactions f
                JOIN accounts a ON f.account_id = a.id
                JOIN users u ON f.user_id = u.id
                WHERE f.date BETWEEN ? AND ?";
        $params = [$start, $end];

        if ($c_acc !== 'ALL') { $sql .= " AND f.account_id = ?"; $params[] = $c_acc; }
        
        if ($c_type === 'INCOME') { $sql .= " AND f.type = 'INCOME'"; }
        elseif ($c_type === 'EXPENSE') { $sql .= " AND f.type = 'EXPENSE'"; }
        // If ALL, no filter needed

        $sql .= " ORDER BY f.date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $custom_trx = $stmt->fetchAll();
        
        $total_custom = 0;
        foreach($custom_trx as $t) $total_custom += $t['amount'];
        $all_accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();
    ?>
        <h3 class="font-bold text-lg mb-4 text-orange-800 border-b pb-2">Custom Filter Report</h3>
        
        <form method="GET" class="space-y-4 mb-6 no-print">
            <input type="hidden" name="page" value="laporan_internal">
            <input type="hidden" name="view" value="CUSTOM">
            
            <div class="flex flex-wrap gap-4 items-end bg-orange-50 p-4 rounded border border-orange-200">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Akun</label>
                    <select name="account_id" class="border p-2 rounded text-sm w-full bg-white">
                        <option value="ALL">Semua Akun</option>
                        <?php foreach($all_accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>" <?= $c_acc == $acc['id'] ? 'selected' : '' ?>>
                                <?= $acc['code'] ?> - <?= $acc['name'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex-1 min-w-[150px]">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Tipe</label>
                    <select name="type" class="border p-2 rounded text-sm w-full bg-white">
                        <option value="ALL" <?= $c_type=='ALL'?'selected':'' ?>>Semua</option>
                        <option value="INCOME" <?= $c_type=='INCOME'?'selected':'' ?>>Pemasukan</option>
                        <option value="EXPENSE" <?= $c_type=='EXPENSE'?'selected':'' ?>>Pengeluaran</option>
                    </select>
                </div>

                <div class="flex-1 min-w-[120px]">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
                    <input type="date" name="start" value="<?= $start ?>" class="border p-2 rounded text-sm w-full">
                </div>
                <div class="flex-1 min-w-[120px]">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
                    <input type="date" name="end" value="<?= $end ?>" class="border p-2 rounded text-sm w-full">
                </div>
                
                <div class="flex gap-2">
                    <button class="bg-orange-600 text-white px-4 py-2 rounded font-bold hover:bg-orange-700 text-sm h-[38px]">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" onclick="window.print()" class="bg-gray-700 text-white px-4 py-2 rounded font-bold hover:bg-gray-800 text-sm h-[38px]">
                        <i class="fas fa-print"></i> Cetak
                    </button>
                </div>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse border border-gray-300">
                <thead class="bg-orange-100 text-orange-900">
                    <tr>
                        <th class="p-2 border">Tanggal</th>
                        <th class="p-2 border">Akun</th>
                        <th class="p-2 border">Tipe</th>
                        <th class="p-2 border">Deskripsi</th>
                        <th class="p-2 border text-right">Nominal</th>
                        <th class="p-2 border text-center">User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($custom_trx)): ?>
                        <tr><td colspan="6" class="p-6 text-center text-gray-500 italic">Tidak ada data.</td></tr>
                    <?php else: ?>
                        <?php foreach($custom_trx as $row): ?>
                        <tr class="hover:bg-gray-50 border-b">
                            <td class="p-2 border whitespace-nowrap"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                            <td class="p-2 border">
                                <span class="font-bold"><?= $row['acc_name'] ?></span>
                                <br><span class="text-xs text-gray-500"><?= $row['acc_code'] ?></span>
                            </td>
                            <td class="p-2 border text-center">
                                <span class="px-2 py-1 rounded text-xs font-bold <?= $row['type']=='INCOME'?'bg-green-100 text-green-800':'bg-red-100 text-red-800' ?>">
                                    <?= $row['type'] ?>
                                </span>
                            </td>
                            <td class="p-2 border"><?= $row['description'] ?></td>
                            <td class="p-2 border text-right font-mono font-bold">
                                <?= formatRupiah($row['amount']) ?>
                            </td>
                            <td class="p-2 border text-center text-xs text-gray-500"><?= $row['username'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-yellow-50 font-bold border-t-2 border-gray-400">
                            <td colspan="4" class="p-3 border text-right uppercase">Total Nominal (Filtered)</td>
                            <td class="p-3 border text-right text-blue-800 text-lg"><?= formatRupiah($total_custom) ?></td>
                            <td class="border"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

</div>