<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

// --- CONFIG & SETTINGS ---
$config_query = $pdo->query("SELECT * FROM settings");
$config = [];
while ($row = $config_query->fetch()) $config[$row['setting_key']] = $row['setting_value'];

// --- FILTER ---
$start_date = $_GET['start'] ?? date('Y-01-01');
$end_date = $_GET['end'] ?? date('Y-12-31');

// --- DATA PROCESSING ---
// 1. CALCULATE EXACT TOTALS FIRST (Matches Laporan Internal / Cashflow Report)
$totals = [
    'omzet' => $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date BETWEEN '$start_date' AND '$end_date'")->fetchColumn() ?: 0,
    'total_expense' => $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date BETWEEN '$start_date' AND '$end_date'")->fetchColumn() ?: 0
];
$totals['net_profit'] = $totals['omzet'] - $totals['total_expense'];

// 2. CHART DATA (Group By Month for Visual Trend)
$report_data = [];
$current_ts = strtotime($start_date);
$end_ts = strtotime($end_date);

// Normalize loop start/end to cover full months for the chart xAxis
$current_ts = strtotime(date('Y-m-01', $current_ts));
$final_month_ts = strtotime(date('Y-m-01', $end_ts));

while ($current_ts <= $final_month_ts) {
    $date_start = date('Y-m-01', $current_ts);
    $date_end = date('Y-m-t', $current_ts);
    
    $month_num = date('n', $current_ts);
    $year_num = date('Y', $current_ts);
    $indo_months = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'Mei', 6=>'Jun', 7=>'Jul', 8=>'Agu', 9=>'Sep', 10=>'Okt', 11=>'Nov', 12=>'Des'];
    $month_label = $indo_months[$month_num] . ' ' . $year_num;

    // Monthly Data
    $m_omzet = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;
    $m_expense = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;
    $m_profit = $m_omzet - $m_expense;

    // Breakdown Visual (Not impacting total)
    $hpp_real = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND a.code='2001' AND f.date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;
    $opex = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND a.code LIKE '2%' AND a.code != '2001' AND f.date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;
    $asset_spend = $pdo->query("SELECT SUM(f.amount) FROM finance_transactions f JOIN accounts a ON f.account_id=a.id WHERE f.type='EXPENSE' AND a.code LIKE '3%' AND f.date BETWEEN '$date_start' AND '$date_end'")->fetchColumn() ?: 0;

    $report_data[] = [
        'label' => $month_label,
        'omzet' => $m_omzet,
        'expense' => $m_expense,
        'profit' => $m_profit,
        'breakdown' => [
            'hpp' => $hpp_real,
            'opex' => $opex,
            'asset' => $asset_spend
        ]
    ];

    $current_ts = strtotime("+1 month", $current_ts);
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- FILTER SECTION -->
<div class="bg-white p-6 rounded shadow mb-6 no-print border-l-4 border-blue-600">
    <div class="flex justify-between items-center mb-4">
        <h3 class="font-bold text-gray-700"><i class="fas fa-chart-line"></i> Dashboard Keuangan (Cashflow Basis)</h3>
    </div>
    <form class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        <input type="hidden" name="page" value="dashboard_keuangan">
        
        <div class="md:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
            <input type="date" name="start" value="<?= $start_date ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500">
        </div>

        <div class="md:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
            <input type="date" name="end" value="<?= $end_date ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500">
        </div>
        
        <div class="flex gap-2 md:col-span-1">
            <button class="bg-blue-600 text-white px-4 py-2 rounded font-bold hover:bg-blue-700 flex-1 text-sm shadow">
                <i class="fas fa-search"></i> Update Dashboard
            </button>
        </div>
    </form>
</div>

<!-- INFO CARDS SUMMARY -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
    <div class="bg-white p-6 rounded shadow border-t-4 border-green-500">
        <p class="text-xs text-gray-500 font-bold uppercase">Total Pemasukan (Cash In)</p>
        <h3 class="text-2xl font-bold text-green-700 mt-2"><?= formatRupiah($totals['omzet']) ?></h3>
    </div>
    <div class="bg-white p-6 rounded shadow border-t-4 border-red-500">
        <p class="text-xs text-gray-500 font-bold uppercase">Total Pengeluaran (Cash Out)</p>
        <h3 class="text-2xl font-bold text-red-600 mt-2"><?= formatRupiah($totals['total_expense']) ?></h3>
    </div>
    <div class="bg-white p-6 rounded shadow border-t-4 border-blue-600 bg-blue-50">
        <p class="text-xs text-blue-800 font-bold uppercase">Surplus / (Defisit) Kas</p>
        <h3 class="text-2xl font-bold <?= $totals['net_profit']>=0?'text-blue-700':'text-red-600' ?> mt-2"><?= formatRupiah($totals['net_profit']) ?></h3>
        <p class="text-xs text-gray-500 mt-1">
            *Selisih Arus Masuk & Keluar
        </p>
    </div>
</div>

<!-- GRAFIK & TABLE -->
<div class="bg-white p-6 rounded shadow">
    
    <!-- Grafik Visual -->
    <div class="mb-8 h-80 w-full no-print">
        <h3 class="font-bold text-gray-700 mb-4 text-center">Tren Arus Kas Bulanan</h3>
        <canvas id="profitChart"></canvas>
    </div>

    <!-- TABEL GABUNGAN -->
    <div class="mb-8">
        <h3 class="font-bold text-lg mb-2 text-gray-800 no-print border-b pb-2">Rincian Performa Bulanan</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right border-collapse border border-gray-300">
                <thead>
                    <tr class="text-white text-xs uppercase tracking-wider h-14 bg-gray-800">
                        <th class="p-2 text-center w-24 align-middle border-r border-gray-600">Periode</th>
                        <th class="p-2 text-center align-middle bg-green-700 border-r border-green-600">Total Masuk<br>(Income)</th>
                        <th class="p-2 text-center align-middle bg-red-700 border-r border-red-600">Total Keluar<br>(Expense)</th>
                        <th class="p-2 text-center align-middle bg-blue-700 border-r border-blue-600 text-yellow-100">SURPLUS / (DEFISIT)<br>KAS</th>
                        <th class="p-2 text-center align-middle bg-gray-100 text-gray-600 border-l border-gray-300">HPP<br>(2001)</th>
                        <th class="p-2 text-center align-middle bg-gray-100 text-gray-600 border-l border-gray-300">Ops<br>(2xxx)</th>
                        <th class="p-2 text-center align-middle bg-gray-100 text-gray-600 border-l border-gray-300">Aset<br>(3xxx)</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700">
                    <?php 
                    foreach($report_data as $d): 
                    ?>
                    <tr class="hover:bg-gray-50 border-b border-gray-200 align-top">
                        <td class="p-3 text-center font-bold bg-gray-50 align-middle border-r"><?= $d['label'] ?></td>
                        
                        <td class="p-3 border-r text-green-700 font-bold"><?= formatRupiah($d['omzet']) ?></td>
                        
                        <td class="p-3 border-r text-red-600">(<?= formatRupiah($d['expense']) ?>)</td>
                        
                        <td class="p-3 border-r bg-blue-50 font-bold text-lg <?= $d['profit']>=0?'text-blue-700':'text-red-600' ?>">
                            <?= formatRupiah($d['profit']) ?>
                        </td>
                        
                        <!-- Breakdown info (Light gray) -->
                        <td class="p-3 border-r text-gray-500 text-xs bg-gray-50"><?= formatRupiah($d['breakdown']['hpp']) ?></td>
                        <td class="p-3 border-r text-gray-500 text-xs bg-gray-50"><?= formatRupiah($d['breakdown']['opex']) ?></td>
                        <td class="p-3 text-gray-500 text-xs bg-gray-50"><?= formatRupiah($d['breakdown']['asset']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if(empty($report_data)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-gray-500 italic">Tidak ada data untuk periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded text-xs text-gray-600">
            <strong>Catatan:</strong><br>
            Dashboard ini menampilkan <b>Arus Kas Riil</b> (Cashflow) berdasarkan transaksi keuangan yang diinput. 
            Saldo Surplus/Defisit adalah selisih antara Uang Masuk dan Uang Keluar pada periode tersebut.
        </div>
    </div>
</div>

<script>
// CHART VISUALIZATION
const ctx = document.getElementById('profitChart').getContext('2d');

const labels = <?= json_encode(array_column($report_data, 'label')) ?>;
const omzetData = <?= json_encode(array_column($report_data, 'omzet')) ?>;
const profitData = <?= json_encode(array_column($report_data, 'profit')) ?>;
const expenseData = <?= json_encode(array_column($report_data, 'expense')) ?>;

new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Surplus Kas',
                data: profitData,
                type: 'line',
                borderColor: '#2563eb', // Blue
                backgroundColor: '#2563eb',
                borderWidth: 3,
                tension: 0.3,
                yAxisID: 'y',
                order: 0
            },
            {
                label: 'Pemasukan',
                data: omzetData,
                backgroundColor: 'rgba(34, 197, 94, 0.6)', // Green
                borderWidth: 0,
                order: 2
            },
            {
                label: 'Pengeluaran',
                data: expenseData,
                backgroundColor: 'rgba(239, 68, 68, 0.6)', // Red
                borderWidth: 0,
                order: 3
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f3f4f6' },
                ticks: { callback: function(value) { return 'Rp ' + value.toLocaleString('id-ID', {notation: 'compact'}); } }
            }
        },
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) label += ': ';
                        if (context.parsed.y !== null) {
                            label += new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(context.parsed.y);
                        }
                        return label;
                    }
                }
            }
        }
    }
});
</script>