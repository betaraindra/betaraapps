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

// Statistik
$total_in = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='IN' AND $cond")->fetchColumn() ?: 0;
$total_out = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='OUT' AND $cond")->fetchColumn() ?: 0;
$total_items = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$low_stock = $pdo->query("SELECT COUNT(*) FROM products WHERE stock < 10")->fetchColumn();

// Chart Data (Barang Masuk vs Keluar per Hari)
$labels = []; $data_in = []; $data_out = [];
for($i=6; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('d/m', strtotime($d));
    $data_in[] = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='IN' AND date='$d'")->fetchColumn() ?: 0;
    $data_out[] = $pdo->query("SELECT SUM(quantity) FROM inventory_transactions WHERE type='OUT' AND date='$d'")->fetchColumn() ?: 0;
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

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="bg-white p-6 rounded-lg shadow lg:col-span-2">
        <h3 class="font-bold text-gray-800 mb-4">Aktivitas Gudang (7 Hari Terakhir)</h3>
        <canvas id="warehouseChart" height="150"></canvas>
    </div>
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-gray-800 mb-4">Top 5 Stok Terbanyak</h3>
        <ul class="divide-y">
            <?php 
            $top_stock = $pdo->query("SELECT name, stock, unit FROM products ORDER BY stock DESC LIMIT 5")->fetchAll();
            foreach($top_stock as $item): 
            ?>
            <li class="py-2 flex justify-between text-sm">
                <span><?= $item['name'] ?></span>
                <span class="font-bold"><?= $item['stock'] ?> <?= $item['unit'] ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<script>
const ctx = document.getElementById('warehouseChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            { label: 'Masuk', data: <?= json_encode($data_in) ?>, backgroundColor: '#10b981' },
            { label: 'Keluar', data: <?= json_encode($data_out) ?>, backgroundColor: '#ef4444' }
        ]
    },
    options: { scales: { y: { beginAtZero: true } } }
});
</script>