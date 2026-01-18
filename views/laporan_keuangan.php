<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN']);

// Filter Vars
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');
$report_type = $_GET['type'] ?? 'LABA_RUGI'; // LABA_RUGI, NERACA, ARUS_KAS, PERUBAHAN_MODAL

// === HELPERS ===
function getTotal($pdo, $type, $start, $end) {
    return $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='$type' AND date BETWEEN '$start' AND '$end'")->fetchColumn() ?: 0;
}
function getAccountSum($pdo, $acc_id, $start, $end) {
    return $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE account_id='$acc_id' AND date BETWEEN '$start' AND '$end'")->fetchColumn() ?: 0;
}
// Neraca butuh kumulatif sampai end date
function getBalanceUntil($pdo, $type, $end) {
    return $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='$type' AND date <= '$end'")->fetchColumn() ?: 0;
}

// === DATA PREPARATION ===
$initial_capital = $settings['initial_capital'] ?? 0;
// Laba Rugi Periode Ini
$period_income = getTotal($pdo, 'INCOME', $start_date, $end_date);
$period_expense = getTotal($pdo, 'EXPENSE', $start_date, $end_date);
$period_profit = $period_income - $period_expense;

// Kumulatif (Untuk Neraca & Modal)
$total_income_all = getBalanceUntil($pdo, 'INCOME', $end_date);
$total_expense_all = getBalanceUntil($pdo, 'EXPENSE', $end_date);
$retained_earnings = $total_income_all - $total_expense_all;

$inventory_val = $pdo->query("SELECT SUM(stock * buy_price) FROM products")->fetchColumn() ?: 0;
$cash_on_hand = $initial_capital + $retained_earnings; // Simplified logic: Modal + Laba = Kas

?>

<!-- FILTER SECTION -->
<div class="bg-white p-6 rounded-lg shadow mb-6 print:hidden">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
        <input type="hidden" name="page" value="laporan_keuangan">
        <div>
            <label class="block text-sm font-medium mb-1">Dari Tanggal</label>
            <input type="date" name="start" value="<?= $start_date ?>" class="border p-2 rounded w-full">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Sampai Tanggal</label>
            <input type="date" name="end" value="<?= $end_date ?>" class="border p-2 rounded w-full">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Jenis Laporan</label>
            <select name="type" class="border p-2 rounded w-full min-w-[200px]">
                <option value="LABA_RUGI" <?= $report_type=='LABA_RUGI'?'selected':'' ?>>Laporan Laba Rugi</option>
                <option value="NERACA" <?= $report_type=='NERACA'?'selected':'' ?>>Neraca (Balance Sheet)</option>
                <option value="ARUS_KAS" <?= $report_type=='ARUS_KAS'?'selected':'' ?>>Arus Kas (Cash Flow)</option>
                <option value="PERUBAHAN_MODAL" <?= $report_type=='PERUBAHAN_MODAL'?'selected':'' ?>>Perubahan Modal</option>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                <i class="fas fa-filter"></i> Tampilkan
            </button>
            <button type="button" onclick="window.print()" class="bg-gray-200 text-gray-800 px-4 py-2 rounded hover:bg-gray-300">
                <i class="fas fa-print"></i> Cetak
            </button>
        </div>
    </form>
</div>

<!-- REPORT CONTENT -->
<div class="bg-white p-8 rounded-lg shadow print:shadow-none print:border">
    
    <!-- HEADER LAPORAN -->
    <div class="text-center mb-8 border-b pb-4">
        <h2 class="text-2xl font-bold uppercase"><?= $settings['company_name'] ?? 'Perusahaan Anda' ?></h2>
        <h3 class="text-xl font-semibold mt-2">
            <?php 
                if ($report_type == 'LABA_RUGI') echo "LAPORAN LABA RUGI";
                elseif ($report_type == 'NERACA') echo "LAPORAN POSISI KEUANGAN (NERACA)";
                elseif ($report_type == 'ARUS_KAS') echo "LAPORAN ARUS KAS";
                elseif ($report_type == 'PERUBAHAN_MODAL') echo "LAPORAN PERUBAHAN MODAL";
            ?>
        </h3>
        <p class="text-gray-500 mt-1">Periode: <?= date('d M Y', strtotime($start_date)) ?> s/d <?= date('d M Y', strtotime($end_date)) ?></p>
    </div>

    <!-- 1. LABA RUGI -->
    <?php if ($report_type == 'LABA_RUGI'): ?>
    <div class="max-w-3xl mx-auto">
        <div class="space-y-4">
            <!-- Pendapatan -->
            <div>
                <h4 class="font-bold text-gray-700 border-b-2 border-gray-200 mb-2">PENDAPATAN USAHA</h4>
                <?php 
                $acc_inc = $pdo->query("SELECT * FROM accounts WHERE type='INCOME'")->fetchAll();
                foreach($acc_inc as $acc):
                    $val = getAccountSum($pdo, $acc['id'], $start_date, $end_date);
                    if($val > 0):
                ?>
                <div class="flex justify-between py-1 border-b border-dashed border-gray-100">
                    <span><?= $acc['name'] ?></span>
                    <span><?= formatRupiah($val) ?></span>
                </div>
                <?php endif; endforeach; ?>
                <div class="flex justify-between font-bold pt-2 text-green-700 bg-green-50 px-2 mt-2">
                    <span>Total Pendapatan</span>
                    <span><?= formatRupiah($period_income) ?></span>
                </div>
            </div>

            <!-- Beban -->
            <div class="mt-8">
                <h4 class="font-bold text-gray-700 border-b-2 border-gray-200 mb-2">BEBAN & PENGELUARAN</h4>
                <?php 
                $acc_exp = $pdo->query("SELECT * FROM accounts WHERE type='EXPENSE'")->fetchAll();
                foreach($acc_exp as $acc):
                    $val = getAccountSum($pdo, $acc['id'], $start_date, $end_date);
                    if($val > 0):
                ?>
                <div class="flex justify-between py-1 border-b border-dashed border-gray-100">
                    <span><?= $acc['name'] ?></span>
                    <span>(<?= formatRupiah($val) ?>)</span>
                </div>
                <?php endif; endforeach; ?>
                <div class="flex justify-between font-bold pt-2 text-red-700 bg-red-50 px-2 mt-2">
                    <span>Total Beban</span>
                    <span>(<?= formatRupiah($period_expense) ?>)</span>
                </div>
            </div>

            <!-- Total -->
            <div class="mt-8 pt-4 border-t-4 border-double border-gray-300 flex justify-between font-bold text-xl">
                <span>LABA / (RUGI) BERSIH</span>
                <span class="<?= $period_profit >= 0 ? 'text-blue-700' : 'text-red-700' ?>">
                    <?= formatRupiah($period_profit) ?>
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 2. NERACA -->
    <?php if ($report_type == 'NERACA'): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <!-- AKTIVA -->
        <div>
            <h4 class="bg-blue-100 text-blue-800 p-2 font-bold mb-4 text-center">AKTIVA (ASET)</h4>
            
            <h5 class="font-bold text-sm text-gray-600 border-b mt-2">Aset Lancar</h5>
            <div class="flex justify-between py-2 border-b border-gray-100">
                <span>Kas & Setara Kas</span>
                <span><?= formatRupiah($cash_on_hand) ?></span>
            </div>
            <div class="flex justify-between py-2 border-b border-gray-100">
                <span>Persediaan Barang (Stok)</span>
                <span><?= formatRupiah($inventory_val) ?></span>
            </div>
            
            <h5 class="font-bold text-sm text-gray-600 border-b mt-4">Aset Tetap</h5>
            <div class="flex justify-between py-2 border-b border-gray-100">
                <span class="text-gray-400 italic">Inventaris (Tidak diinput)</span>
                <span>Rp 0</span>
            </div>

            <div class="flex justify-between font-bold text-lg mt-6 pt-2 border-t-2 border-blue-500">
                <span>TOTAL AKTIVA</span>
                <span><?= formatRupiah($cash_on_hand + $inventory_val) ?></span>
            </div>
        </div>

        <!-- PASIVA -->
        <div>
            <h4 class="bg-red-100 text-red-800 p-2 font-bold mb-4 text-center">PASIVA (KEWAJIBAN & EKUITAS)</h4>
            
            <h5 class="font-bold text-sm text-gray-600 border-b mt-2">Kewajiban (Liabilitas)</h5>
            <div class="flex justify-between py-2 border-b border-gray-100">
                <span>Hutang Usaha</span>
                <span>Rp 0</span>
            </div>

            <h5 class="font-bold text-sm text-gray-600 border-b mt-4">Ekuitas (Modal)</h5>
            <div class="flex justify-between py-2 border-b border-gray-100">
                <span>Modal Disetor</span>
                <span><?= formatRupiah($initial_capital) ?></span>
            </div>
             <div class="flex justify-between py-2 border-b border-gray-100">
                <span>Laba Ditahan (Kumulatif)</span>
                <span><?= formatRupiah($retained_earnings) ?></span>
            </div>
            <div class="flex justify-between py-2 border-b border-gray-100 italic text-gray-500">
                <span>Penyesuaian Persediaan</span>
                <span><?= formatRupiah($inventory_val) ?></span>
            </div>

            <div class="flex justify-between font-bold text-lg mt-6 pt-2 border-t-2 border-red-500">
                <span>TOTAL PASIVA</span>
                <span><?= formatRupiah($initial_capital + $retained_earnings + $inventory_val) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 3. ARUS KAS -->
    <?php if ($report_type == 'ARUS_KAS'): ?>
    <div class="max-w-3xl mx-auto space-y-6">
        <!-- Arus Kas Masuk -->
        <div>
            <h4 class="font-bold text-green-700 bg-green-50 p-2 border-l-4 border-green-500">ARUS KAS MASUK (INFLOW)</h4>
            <table class="w-full text-sm mt-2">
                <?php 
                $inflows = $pdo->query("SELECT f.date, a.name, f.amount, f.description FROM finance_transactions f JOIN accounts a ON f.account_id = a.id WHERE f.type='INCOME' AND f.date BETWEEN '$start_date' AND '$end_date'")->fetchAll();
                foreach($inflows as $in): ?>
                <tr class="border-b border-gray-100">
                    <td class="py-1 w-24 text-gray-500"><?= $in['date'] ?></td>
                    <td class="py-1 font-medium"><?= $in['name'] ?> <span class="text-xs text-gray-400 block"><?= $in['description'] ?></span></td>
                    <td class="py-1 text-right"><?= formatRupiah($in['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="font-bold bg-gray-50">
                    <td colspan="2" class="py-2 text-right">Total Masuk</td>
                    <td class="py-2 text-right"><?= formatRupiah($period_income) ?></td>
                </tr>
            </table>
        </div>

        <!-- Arus Kas Keluar -->
        <div>
            <h4 class="font-bold text-red-700 bg-red-50 p-2 border-l-4 border-red-500">ARUS KAS KELUAR (OUTFLOW)</h4>
            <table class="w-full text-sm mt-2">
                <?php 
                $outflows = $pdo->query("SELECT f.date, a.name, f.amount, f.description FROM finance_transactions f JOIN accounts a ON f.account_id = a.id WHERE f.type='EXPENSE' AND f.date BETWEEN '$start_date' AND '$end_date'")->fetchAll();
                foreach($outflows as $out): ?>
                <tr class="border-b border-gray-100">
                    <td class="py-1 w-24 text-gray-500"><?= $out['date'] ?></td>
                    <td class="py-1 font-medium"><?= $out['name'] ?> <span class="text-xs text-gray-400 block"><?= $out['description'] ?></span></td>
                    <td class="py-1 text-right">(<?= formatRupiah($out['amount']) ?>)</td>
                </tr>
                <?php endforeach; ?>
                 <tr class="font-bold bg-gray-50">
                    <td colspan="2" class="py-2 text-right">Total Keluar</td>
                    <td class="py-2 text-right">(<?= formatRupiah($period_expense) ?>)</td>
                </tr>
            </table>
        </div>

        <!-- Net Cash -->
        <div class="flex justify-between font-bold text-xl border-t-2 border-black pt-4">
            <span>KENAIKAN / (PENURUNAN) KAS BERSIH</span>
            <span class="<?= $period_profit >= 0 ? 'text-blue-600' : 'text-red-600' ?>"><?= formatRupiah($period_profit) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- 4. PERUBAHAN MODAL -->
    <?php if ($report_type == 'PERUBAHAN_MODAL'): ?>
    <div class="max-w-2xl mx-auto space-y-4">
        <?php
        // Modal Awal (Sebelum tanggal start)
        $income_before = getBalanceUntil($pdo, 'INCOME', date('Y-m-d', strtotime($start_date . ' -1 day')));
        $expense_before = getBalanceUntil($pdo, 'EXPENSE', date('Y-m-d', strtotime($start_date . ' -1 day')));
        $modal_awal_periode = $initial_capital + ($income_before - $expense_before);
        $modal_akhir_periode = $modal_awal_periode + $period_profit;
        ?>

        <div class="flex justify-between py-2 border-b text-lg">
            <span>Modal Awal (Per <?= date('d M Y', strtotime($start_date)) ?>)</span>
            <span class="font-bold"><?= formatRupiah($modal_awal_periode) ?></span>
        </div>

        <div class="flex justify-between py-2 border-b text-lg text-green-700">
            <span>Laba Bersih Periode Ini</span>
            <span class="font-bold"><?= formatRupiah($period_profit) ?></span>
        </div>

        <div class="flex justify-between py-2 border-b text-lg text-red-700">
            <span>Prive (Penarikan Modal)</span>
            <span class="font-bold">Rp 0</span>
        </div>

        <div class="flex justify-between py-4 border-t-4 border-double border-gray-400 text-xl font-bold bg-gray-50 px-2 mt-4">
            <span>Modal Akhir (Per <?= date('d M Y', strtotime($end_date)) ?>)</span>
            <span class="text-blue-800"><?= formatRupiah($modal_akhir_periode) ?></span>
        </div>
    </div>
    <?php endif; ?>

</div>