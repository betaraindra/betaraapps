<?php
// Cek Hak Akses Section
$role = $_SESSION['role'];
$access_finance = in_array($role, ['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);
$access_inventory = in_array($role, ['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- FILTER GLOBAL ---
// Default: Tahun ini (01 Jan - 31 Des) agar grafik keuangan terlihat bagus
$start_date = $_GET['start'] ?? date('Y-01-01');
$end_date = $_GET['end'] ?? date('Y-12-31');

// ==========================================
// 1. LOGIKA KEUANGAN
// ==========================================
$fin_report_data = [];
$fin_totals = ['omzet' => 0, 'total_keluar' => 0, 'profit' => 0];

if ($access_finance) {
    // A. HITUNG TOTAL
    $fin_totals['omzet'] = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date BETWEEN '$start_date' AND '$end_date'")->fetchColumn() ?: 0;
    $fin_totals['total_keluar'] = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date BETWEEN '$start_date' AND '$end_date'")->fetchColumn() ?: 0;
    $fin_totals['profit'] = $fin_totals['omzet'] - $fin_totals['total_keluar'];

    // B. HITUNG DATA GRAFIK
    $current_ts = strtotime(date('Y-m-01', strtotime($start_date))); 
    $final_month_ts = strtotime(date('Y-m-01', strtotime($end_date)));

    while ($current_ts <= $final_month_ts) {
        $date_start = date('Y-m-01', $current_ts);
        $date_end = date('Y-m-t', $current_ts);
        
        $indo_months = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'Mei', 6=>'Jun', 7=>'Jul', 8=>'Agu', 9=>'Sep', 10=>'Okt', 11=>'Nov', 12=>'Des'];
        $month_label = $indo_months[date('n', $current_ts)] . ' ' . date('Y', $current_ts);

        $m_omzet = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;
        $m_expense = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;
        
        $fin_report_data[] = [
            'label' => $month_label,
            'omzet' => $m_omzet,
            'total_keluar' => $m_expense,
            'profit' => $m_omzet - $m_expense
        ];
        $current_ts = strtotime("+1 month", $current_ts);
    }
}

// ==========================================
// 2. LOGIKA INVENTORI (DIGABUNG)
// ==========================================
$inv_stats = ['in'=>0, 'out'=>0, 'items'=>0, 'low'=>0];
$inv_chart_labels = [];
$inv_chart_in = [];
$inv_chart_out = [];
$inv_wh_labels = [];
$inv_wh_data = [];
$inv_top_stock = [];

if ($access_inventory) {
    // Condition based on global filter
    $cond = "date BETWEEN '$start_date' AND '$end_date'";

    $inv_stats['in'] = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='IN' AND $cond")->fetchColumn() ?: 0;
    $inv_stats['out'] = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='OUT' AND $cond")->fetchColumn() ?: 0;
    $inv_stats['items'] = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $inv_stats['low'] = $pdo->query("SELECT COUNT(*) FROM products WHERE stock < 10")->fetchColumn();
    
    // Hitung Total Rusak (SN Defective + Non-SN Trx Rusak)
    $dmg_sn = $pdo->query("SELECT COUNT(*) FROM product_serials WHERE status = 'DEFECTIVE'")->fetchColumn() ?: 0;
    $dmg_trx = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='OUT' AND notes LIKE 'Rusak:%'")->fetchColumn() ?: 0;
    $inv_stats['damaged'] = $dmg_sn + $dmg_trx;

    // Hitung Total Terpakai (SN Sold + Non-SN Trx Used)
    $used_sn = $pdo->query("SELECT COUNT(*) FROM product_serials WHERE status = 'SOLD'")->fetchColumn() ?: 0;
    // Asumsi Non-SN Terpakai adalah transaksi OUT yang bukan Rusak dan bukan Transfer (jika ada)
    // Atau lebih spesifik jika ada pola notes tertentu. Untuk saat ini kita ambil OUT yang bukan Rusak.
    // Namun, OUT bisa juga penjualan. Jika konteksnya "Terpakai" = "Sold/Used", maka semua OUT non-rusak bisa dianggap terpakai/terjual.
    // Sesuai request sebelumnya, "Terpakai" di detail gudang adalah "USED".
    $used_trx = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='OUT' AND notes NOT LIKE 'Rusak:%'")->fetchColumn() ?: 0;
    $inv_stats['used'] = $used_sn + $used_trx;

    // Chart 1: Daily Activity (7 Hari Terakhir dari End Date)
    $end_ts_fixed = strtotime($end_date);
    for($i=6; $i>=0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days", $end_ts_fixed));
        $inv_chart_labels[] = date('d/m', strtotime($d));
        $inv_chart_in[] = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='IN' AND date='$d'")->fetchColumn() ?: 0;
        $inv_chart_out[] = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='OUT' AND date='$d'")->fetchColumn() ?: 0;
    }

    // Chart 2: Warehouse Distribution
    $wh_rows = $pdo->query("SELECT w.name, SUM(i.quantity) as total_qty 
                 FROM inventory_transactions i
                 JOIN warehouses w ON i.warehouse_id = w.id
                 WHERE $cond
                 GROUP BY w.id, w.name
                 ORDER BY total_qty DESC")->fetchAll();
    foreach($wh_rows as $row) {
        $inv_wh_labels[] = $row['name'];
        $inv_wh_data[] = $row['total_qty'];
    }

    // Top Stock
    $inv_top_stock = $pdo->query("
        SELECT p.id, p.name, p.stock, p.unit, p.has_serial_number,
        (
            CASE 
                WHEN p.has_serial_number = 1 THEN (SELECT COUNT(*) FROM product_serials WHERE product_id = p.id AND status = 'DEFECTIVE')
                ELSE (SELECT COALESCE(SUM(quantity), 0) FROM inventory_transactions WHERE product_id = p.id AND type = 'OUT' AND notes LIKE 'Rusak:%')
            END
        ) as damaged_qty,
        (
            CASE 
                WHEN p.has_serial_number = 1 THEN (SELECT COUNT(*) FROM product_serials WHERE product_id = p.id AND status = 'SOLD')
                ELSE (SELECT COALESCE(SUM(quantity), 0) FROM inventory_transactions WHERE product_id = p.id AND type = 'OUT' AND notes NOT LIKE 'Rusak:%')
            END
        ) as used_qty
        FROM products p 
        ORDER BY p.stock DESC LIMIT 5
    ")->fetchAll();
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- HEADER & FILTER -->
<div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4 no-print">
    <div>
        <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-tachometer-alt text-indigo-600"></i> Dashboard Utama</h2>
        <p class="text-sm text-gray-500">Ringkasan aktivitas operasional perusahaan</p>
    </div>
    
    <form method="GET" class="flex flex-wrap gap-2 items-center bg-white p-3 rounded shadow border-l-4 border-indigo-500">
        <input type="hidden" name="page" value="dashboard">
        
        <div class="flex flex-col">
            <span class="text-[10px] font-bold text-gray-500 uppercase">Dari Tanggal</span>
            <input type="date" name="start" value="<?= $start_date ?>" class="border p-1 rounded text-sm focus:outline-none focus:border-indigo-500">
        </div>
        <div class="flex flex-col">
            <span class="text-[10px] font-bold text-gray-500 uppercase">Sampai Tanggal</span>
            <input type="date" name="end" value="<?= $end_date ?>" class="border p-1 rounded text-sm focus:outline-none focus:border-indigo-500">
        </div>
        <button class="bg-indigo-600 text-white px-4 py-2 rounded text-sm font-bold hover:bg-indigo-700 h-full mt-auto mb-1">
            <i class="fas fa-filter"></i>
        </button>
    </form>
</div>

<!-- ======================= SECTION KEUANGAN ======================= -->
<?php if($access_finance): ?>
<div class="mb-10 animate-fade-in-up">
    <div class="flex items-center gap-2 mb-4 border-b pb-2 border-gray-300">
        <div class="bg-blue-600 text-white p-2 rounded shadow"><i class="fas fa-chart-pie"></i></div>
        <h3 class="text-xl font-bold text-gray-800">Ikhtisar Keuangan (Cash Flow)</h3>
    </div>

    <!-- 1. SUMMARY CARDS -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-white p-6 rounded-lg shadow border-t-4 border-green-500 hover:shadow-lg transition">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Total Pemasukan (Cash In)</p>
            <h3 class="text-2xl font-bold text-green-700 mt-2"><?= formatRupiah($fin_totals['omzet']) ?></h3>
        </div>
        <div class="bg-white p-6 rounded-lg shadow border-t-4 border-red-500 hover:shadow-lg transition">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Total Pengeluaran (Cash Out)</p>
            <h3 class="text-2xl font-bold text-red-700 mt-2"><?= formatRupiah($fin_totals['total_keluar']) ?></h3>
        </div>
        <div class="bg-white p-6 rounded-lg shadow border-t-4 border-blue-500 hover:shadow-lg transition">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Surplus / (Defisit) Kas</p>
            <h3 class="text-2xl font-bold <?= $fin_totals['profit']>=0?'text-blue-700':'text-red-600' ?> mt-2"><?= formatRupiah($fin_totals['profit']) ?></h3>
            <p class="text-xs text-gray-400 mt-1">Margin: <?= $fin_totals['omzet']>0 ? number_format(($fin_totals['profit']/$fin_totals['omzet'])*100, 1) : 0 ?>%</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- 2. CHART KEUANGAN -->
        <div class="lg:col-span-1 bg-white p-4 rounded-lg shadow">
            <h4 class="font-bold text-gray-700 mb-4 text-sm text-center">Tren Arus Kas Bulanan</h4>
            <div class="h-64">
                <canvas id="finChart"></canvas>
            </div>
        </div>

        <!-- 3. DETAILED TABLE -->
        <div class="lg:col-span-2 bg-white p-4 rounded-lg shadow overflow-hidden flex flex-col">
            <h4 class="font-bold text-gray-700 mb-4 text-sm">Rincian Performa Bulanan</h4>
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-xs text-right border-collapse border border-gray-200">
                    <thead class="bg-gray-100 text-gray-700 font-bold uppercase">
                        <tr>
                            <th class="p-2 border text-left">Periode</th>
                            <th class="p-2 border text-green-700">Masuk (In)</th>
                            <th class="p-2 border text-red-600">Keluar (Out)</th>
                            <th class="p-2 border text-blue-700">Surplus/Defisit</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600">
                        <?php foreach($fin_report_data as $row): 
                            $pct_profit = $row['omzet'] > 0 ? ($row['profit'] / $row['omzet']) * 100 : 0;
                        ?>
                        <tr class="hover:bg-gray-50 border-b">
                            <td class="p-2 border text-left font-bold"><?= $row['label'] ?></td>
                            <td class="p-2 border font-medium text-green-700"><?= formatRupiah($row['omzet']) ?></td>
                            <td class="p-2 border text-red-600"><?= formatRupiah($row['total_keluar']) ?></td>
                            <td class="p-2 border font-bold <?= $row['profit']>=0?'text-blue-700':'text-red-600' ?>">
                                <?= formatRupiah($row['profit']) ?>
                                <div class="text-[10px] text-gray-400 font-normal"><?= number_format($pct_profit, 1) ?>%</div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($fin_report_data)): ?>
                            <tr><td colspan="4" class="p-4 text-center italic">Tidak ada data.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ======================= SECTION INVENTORI ======================= -->
<?php if($access_inventory): ?>
<div class="mb-6 animate-fade-in-up" style="animation-delay: 0.1s;">
    <div class="flex items-center gap-2 mb-4 border-b pb-2 border-gray-300">
        <div class="bg-orange-500 text-white p-2 rounded shadow"><i class="fas fa-warehouse"></i></div>
        <h3 class="text-xl font-bold text-gray-800">Aktivitas Gudang & Inventori</h3>
    </div>

    <!-- 1. INVENTORY STATS -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-green-500">
            <p class="text-xs font-bold text-gray-500 uppercase">Barang Masuk (Periode Ini)</p>
            <h3 class="text-xl font-bold text-green-600"><?= number_format($inv_stats['in']) ?> <span class="text-xs text-gray-400">Qty</span></h3>
        </div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-red-500">
            <p class="text-xs font-bold text-gray-500 uppercase">Barang Keluar (Periode Ini)</p>
            <h3 class="text-xl font-bold text-red-600"><?= number_format($inv_stats['out']) ?> <span class="text-xs text-gray-400">Qty</span></h3>
        </div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-purple-500">
            <p class="text-xs font-bold text-gray-500 uppercase">Total Produk Terdaftar</p>
            <h3 class="text-xl font-bold text-purple-600"><?= number_format($inv_stats['items']) ?> <span class="text-xs text-gray-400">Item</span></h3>
        </div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-orange-500">
            <p class="text-xs font-bold text-gray-500 uppercase">Stok Menipis (< 10)</p>
            <h3 class="text-xl font-bold text-orange-600"><?= $inv_stats['low'] ?> <span class="text-xs text-gray-400">Item</span></h3>
        </div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-red-700">
            <p class="text-xs font-bold text-gray-500 uppercase">Total Unit Rusak</p>
            <h3 class="text-xl font-bold text-red-700"><?= number_format($inv_stats['damaged']) ?> <span class="text-xs text-gray-400">Unit</span></h3>
        </div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-blue-700">
            <p class="text-xs font-bold text-gray-500 uppercase">Total Unit Terpakai</p>
            <h3 class="text-xl font-bold text-blue-700"><?= number_format($inv_stats['used']) ?> <span class="text-xs text-gray-400">Unit</span></h3>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- 2. CHART HARIAN -->
        <div class="lg:col-span-1 bg-white p-4 rounded-lg shadow">
            <h4 class="font-bold text-gray-700 mb-4 text-sm text-center">Mutasi Harian (7 Hari Terakhir)</h4>
            <div class="h-64">
                <canvas id="invDailyChart"></canvas>
            </div>
        </div>

        <!-- 3. TOP STOCK TABLE -->
        <div class="lg:col-span-1 bg-white p-4 rounded-lg shadow flex flex-col">
            <h4 class="font-bold text-gray-700 mb-4 text-sm">Top 5 Stok Terbanyak</h4>
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-xs text-left">
                    <thead class="bg-gray-100 text-gray-600">
                        <tr>
                            <th class="p-2 rounded-tl">Barang</th>
                            <th class="p-2 text-right">Stok</th>
                            <th class="p-2 text-right text-purple-600">Terpakai</th>
                            <th class="p-2 text-right text-red-600">Rusak</th>
                            <th class="p-2 text-center rounded-tr">Sat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($inv_top_stock as $item): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="p-2 font-medium text-gray-700 truncate max-w-[150px]"><?= $item['name'] ?></td>
                            <td class="p-2 text-right font-bold text-blue-600"><?= number_format($item['stock']) ?></td>
                            <td class="p-2 text-right font-bold text-purple-600"><?= number_format($item['used_qty']) ?></td>
                            <td class="p-2 text-right font-bold text-red-600"><?= number_format($item['damaged_qty']) ?></td>
                            <td class="p-2 text-center text-gray-500"><?= $item['unit'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 4. CHART GUDANG -->
        <div class="lg:col-span-1 bg-white p-4 rounded-lg shadow">
            <h4 class="font-bold text-gray-700 mb-4 text-sm text-center">Distribusi Aktivitas per Wilayah</h4>
            <div class="h-64 flex justify-center">
                <?php if(empty($inv_wh_data)): ?>
                    <p class="text-gray-400 italic self-center">Belum ada data aktivitas gudang.</p>
                <?php else: ?>
                    <canvas id="invWhChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- SCRIPTS UNTUK CHART -->
<script>
<?php if($access_finance): ?>
// FINANCE CHART
const ctxFin = document.getElementById('finChart').getContext('2d');
new Chart(ctxFin, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($fin_report_data, 'label')) ?>,
        datasets: [
            {
                label: 'Surplus/Defisit',
                data: <?= json_encode(array_column($fin_report_data, 'profit')) ?>,
                type: 'line', borderColor: '#2563eb', backgroundColor: '#2563eb', borderWidth: 2, tension: 0.3, order: 0
            },
            {
                label: 'Cash In',
                data: <?= json_encode(array_column($fin_report_data, 'omzet')) ?>,
                backgroundColor: 'rgba(34, 197, 94, 0.6)', order: 1
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, ticks: { callback: function(val) { return val/1000 + 'k'; } } } },
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
    }
});
<?php endif; ?>

<?php if($access_inventory): ?>
// INVENTORY DAILY CHART
const ctxInvDaily = document.getElementById('invDailyChart').getContext('2d');
new Chart(ctxInvDaily, {
    type: 'bar',
    data: {
        labels: <?= json_encode($inv_chart_labels) ?>,
        datasets: [
            { label: 'Masuk', data: <?= json_encode($inv_chart_in) ?>, backgroundColor: '#10b981', borderRadius: 2 },
            { label: 'Keluar', data: <?= json_encode($inv_chart_out) ?>, backgroundColor: '#ef4444', borderRadius: 2 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        scales: { y: { beginAtZero: true }, x: { grid: { display: false } } },
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
    }
});

// INVENTORY WAREHOUSE CHART
<?php if(!empty($inv_wh_data)): ?>
const ctxInvWh = document.getElementById('invWhChart').getContext('2d');
new Chart(ctxInvWh, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($inv_wh_labels) ?>,
        datasets: [{
            data: <?= json_encode($inv_wh_data) ?>,
            backgroundColor: ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#6366f1'],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } } }
    }
});
<?php endif; ?>
<?php endif; ?>
</script>