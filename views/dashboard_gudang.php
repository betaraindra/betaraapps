<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'SVP', 'MANAGER']);

$range = $_GET['range'] ?? 'month';
$custom_start = $_GET['start'] ?? date('Y-m-01');
$custom_end = $_GET['end'] ?? date('Y-m-d');

$cond = "1=1";
if ($range == 'today') $cond .= " AND date = CURDATE()";
elseif ($range == 'week') $cond .= " AND YEARWEEK(date, 1) = YEARWEEK(CURDATE(), 1)";
elseif ($range == 'month') $cond .= " AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())";
elseif ($range == 'year') $cond .= " AND YEAR(date) = YEAR(CURDATE())";
elseif ($range == 'custom') $cond .= " AND date BETWEEN '$custom_start' AND '$custom_end'";

// Statistik Utama
$total_in = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='IN' AND $cond")->fetchColumn() ?: 0;
$total_out = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='OUT' AND $cond")->fetchColumn() ?: 0;
$total_items = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$low_stock = $pdo->query("SELECT COUNT(*) FROM products WHERE stock < 10")->fetchColumn();

// Chart Data 1: Aktivitas Harian (7 Hari Terakhir)
$labels = []; $data_in = []; $data_out = [];
for($i=6; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('d/m', strtotime($d));
    $data_in[] = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='IN' AND date='$d'")->fetchColumn() ?: 0;
    $data_out[] = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='OUT' AND date='$d'")->fetchColumn() ?: 0;
}

// Chart Data 2: Aktivitas per Gudang / Wilayah (Berdasarkan Filter Tanggal)
// Menghitung total volume barang (Masuk + Keluar) per gudang
$wh_query = "SELECT w.name, SUM(i.quantity) as total_qty 
             FROM inventory_transactions i
             JOIN warehouses w ON i.warehouse_id = w.id
             WHERE $cond
             GROUP BY w.id, w.name
             ORDER BY total_qty DESC";
$wh_stats = $pdo->query($wh_query)->fetchAll();

$wh_labels = [];
$wh_data = [];
foreach($wh_stats as $row) {
    $wh_labels[] = $row['name'];
    $wh_data[] = $row['total_qty'];
}
?>

<div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
    <h2 class="text-2xl font-bold text-gray-800">Dashboard Gudang</h2>
    <form method="GET" class="flex flex-wrap gap-2 items-center bg-white p-2 rounded shadow">
        <input type="hidden" name="page" value="dashboard_gudang">
        <select name="range" class="border p-1 rounded text-sm" onchange="this.form.submit()">
            <option value="today" <?= $range=='today'?'selected':'' ?>>Hari Ini</option>
            <option value="week" <?= $range=='week'?'selected':'' ?>>Minggu Ini</option>
            <option value="month" <?= $range=='month'?'selected':'' ?>>Bulan Ini</option>
            <option value="year" <?= $range=='year'?'selected':'' ?>>Tahun Ini</option>
            <option value="custom" <?= $range=='custom'?'selected':'' ?>>Custom</option>
        </select>
        <?php if($range=='custom'): ?>
            <input type="date" name="start" value="<?= $custom_start ?>" class="border p-1 rounded text-sm">
            <input type="date" name="end" value="<?= $custom_end ?>" class="border p-1 rounded text-sm">
            <button class="bg-blue-600 text-white px-2 py-1 rounded text-sm"><i class="fas fa-check"></i></button>
        <?php endif; ?>
    </form>
</div>

<!-- STATS CARDS -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-green-500">
        <p class="text-sm text-gray-500">Barang Masuk (Qty)</p>
        <h3 class="text-xl font-bold text-green-600"><?= number_format($total_in) ?></h3>
    </div>
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-red-500">
        <p class="text-sm text-gray-500">Barang Keluar (Qty)</p>
        <h3 class="text-xl font-bold text-red-600"><?= number_format($total_out) ?></h3>
    </div>
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
        <p class="text-sm text-gray-500">Total Jenis Produk</p>
        <h3 class="text-xl font-bold text-blue-600"><?= number_format($total_items) ?> Item</h3>
    </div>
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-orange-500">
        <p class="text-sm text-gray-500">Stok Menipis (< 10)</p>
        <h3 class="text-xl font-bold text-orange-600"><?= $low_stock ?> Item</h3>
    </div>
</div>

<!-- CHARTS SECTION -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Chart 1: Tren Harian -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wide border-b pb-2">
            <i class="fas fa-chart-bar text-blue-500 mr-2"></i> Aktivitas Harian (7 Hari)
        </h3>
        <div class="h-64">
            <canvas id="dailyChart"></canvas>
        </div>
    </div>

    <!-- Chart 2: Per Gudang -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-gray-800 mb-4 text-sm uppercase tracking-wide border-b pb-2">
            <i class="fas fa-map-marker-alt text-purple-500 mr-2"></i> Distribusi Aktivitas per Wilayah/Gudang
        </h3>
        <div class="h-64 flex justify-center">
            <?php if(empty($wh_data)): ?>
                <div class="flex items-center justify-center text-gray-400 italic">Belum ada data pada periode ini</div>
            <?php else: ?>
                <canvas id="warehouseChart"></canvas>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- TOP STOCK TABLE -->
<div class="bg-white p-6 rounded-lg shadow">
    <h3 class="font-bold text-gray-800 mb-4 border-b pb-2">Top 5 Stok Terbanyak</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="p-3">Nama Barang</th>
                    <th class="p-3 text-right">Stok</th>
                    <th class="p-3 text-center">Satuan</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php 
                $top_stock = $pdo->query("SELECT name, stock, unit FROM products ORDER BY stock DESC LIMIT 5")->fetchAll();
                foreach($top_stock as $item): 
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="p-3 font-medium text-gray-800"><?= $item['name'] ?></td>
                    <td class="p-3 text-right font-bold text-blue-600"><?= number_format($item['stock']) ?></td>
                    <td class="p-3 text-center text-gray-500"><?= $item['unit'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// CHART 1: DAILY ACTIVITY
const ctxDaily = document.getElementById('dailyChart').getContext('2d');
new Chart(ctxDaily, {
    type: 'bar',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            { label: 'Masuk', data: <?= json_encode($data_in) ?>, backgroundColor: '#10b981', borderRadius: 4 },
            { label: 'Keluar', data: <?= json_encode($data_out) ?>, backgroundColor: '#ef4444', borderRadius: 4 }
        ]
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        },
        scales: { 
            y: { beginAtZero: true, grid: { borderDash: [2, 4] } },
            x: { grid: { display: false } }
        } 
    }
});

// CHART 2: WAREHOUSE DISTRIBUTION
<?php if(!empty($wh_data)): ?>
const ctxWh = document.getElementById('warehouseChart').getContext('2d');
new Chart(ctxWh, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($wh_labels) ?>,
        datasets: [{
            data: <?= json_encode($wh_data) ?>,
            backgroundColor: [
                '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#6366f1'
            ],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.label || '';
                        let value = context.parsed;
                        let total = context.chart._metasets[context.datasetIndex].total;
                        let percentage = ((value / total) * 100).toFixed(1) + '%';
                        return label + ': ' + value + ' Qty (' + percentage + ')';
                    }
                }
            }
        }
    }
});
<?php endif; ?>
</script>