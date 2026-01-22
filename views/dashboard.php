<?php
$range = $_GET['range'] ?? 'month';
$custom_start = $_GET['start'] ?? date('Y-m-01');
$custom_end = $_GET['end'] ?? date('Y-m-d');

$condFin = "1=1";
if ($range == 'today') $condFin .= " AND date = CURDATE()";
elseif ($range == 'week') $condFin .= " AND YEARWEEK(date, 1) = YEARWEEK(CURDATE(), 1)";
elseif ($range == 'month') $condFin .= " AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())";
elseif ($range == 'year') $condFin .= " AND YEAR(date) = YEAR(CURDATE())";
elseif ($range == 'custom') $condFin .= " AND date BETWEEN '$custom_start' AND '$custom_end'";

$income = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND $condFin")->fetchColumn() ?: 0;
$expense = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND $condFin")->fetchColumn() ?: 0;
$low_stock = $pdo->query("SELECT COUNT(*) FROM products WHERE stock < 10")->fetchColumn();

// Chart Data
$labels = []; $data_in = []; $data_out = [];
for($i=6; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('d/m', strtotime($d));
    $data_in[] = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date='$d'")->fetchColumn() ?: 0;
    $data_out[] = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date='$d'")->fetchColumn() ?: 0;
}

// Data Transaksi Terakhir
$rec_finance = $pdo->query("SELECT f.*, a.name as acc_name, u.username FROM finance_transactions f JOIN accounts a ON f.account_id = a.id JOIN users u ON f.user_id = u.id ORDER BY f.created_at DESC LIMIT 5")->fetchAll();
$rec_inventory = $pdo->query("SELECT i.*, p.name as prod_name, u.username FROM inventory_transactions i JOIN products p ON i.product_id = p.id JOIN users u ON i.user_id = u.id ORDER BY i.created_at DESC LIMIT 5")->fetchAll();
?>

<div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
    <h2 class="text-2xl font-bold text-gray-800">Dashboard</h2>
    <form method="GET" class="flex flex-wrap gap-2 items-center bg-white p-2 rounded shadow">
        <input type="hidden" name="page" value="dashboard">
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

<!-- Statistik Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
        <p class="text-sm text-gray-500">Laba/Rugi</p>
        <h3 class="text-xl font-bold <?= ($income-$expense)>=0 ? 'text-blue-600' : 'text-red-600' ?>">
            <?= formatRupiah($income - $expense) ?>
        </h3>
    </div>
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-green-500">
        <p class="text-sm text-gray-500">Total Pemasukan</p>
        <h3 class="text-xl font-bold text-green-600"><?= formatRupiah($income) ?></h3>
    </div>
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-red-500">
        <p class="text-sm text-gray-500">Total Pengeluaran</p>
        <h3 class="text-xl font-bold text-red-600"><?= formatRupiah($expense) ?></h3>
    </div>
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-orange-500">
        <p class="text-sm text-gray-500">Stok Menipis</p>
        <h3 class="text-xl font-bold text-orange-600"><?= $low_stock ?> Barang</h3>
    </div>
</div>

<!-- Charts -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <div class="bg-white p-6 rounded-lg shadow lg:col-span-2">
        <h3 class="font-bold text-gray-800 mb-4">Tren Keuangan (7 Hari)</h3>
        <canvas id="financeChart" height="150"></canvas>
    </div>
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-gray-800 mb-4">Persentase</h3>
        <div class="relative h-64">
            <canvas id="pieChart"></canvas>
        </div>
    </div>
</div>

<!-- Recent Transactions -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Recent Finance -->
    <div class="bg-white p-6 rounded-lg shadow">
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h3 class="font-bold text-gray-800 text-lg">Keuangan Terakhir</h3>
            <a href="?page=transaksi_keuangan" class="text-xs text-blue-600 hover:underline">Lihat Semua</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="p-2">Tgl</th>
                        <th class="p-2">Ket</th>
                        <th class="p-2 text-right">Jml</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($rec_finance as $f): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-2 whitespace-nowrap">
                            <?= date('d/m', strtotime($f['date'])) ?><br>
                            <span class="text-xs text-gray-400"><?= $f['username'] ?></span>
                        </td>
                        <td class="p-2">
                            <span class="font-bold block text-gray-700"><?= $f['acc_name'] ?></span>
                            <span class="text-xs text-gray-500 truncate block max-w-[150px]"><?= $f['description'] ?></span>
                        </td>
                        <td class="p-2 text-right font-bold <?= $f['type']=='INCOME'?'text-green-600':'text-red-600' ?>">
                            <?= formatRupiah($f['amount']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($rec_finance)): ?>
                        <tr><td colspan="3" class="p-4 text-center text-gray-500">Belum ada transaksi.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Inventory -->
    <div class="bg-white p-6 rounded-lg shadow">
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h3 class="font-bold text-gray-800 text-lg">Inventori Terakhir</h3>
            <a href="?page=data_transaksi" class="text-xs text-blue-600 hover:underline">Lihat Semua</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="p-2">Tgl</th>
                        <th class="p-2">Barang</th>
                        <th class="p-2 text-right">Qty</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($rec_inventory as $i): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-2 whitespace-nowrap">
                            <?= date('d/m', strtotime($i['date'])) ?><br>
                            <span class="text-xs text-gray-400"><?= $i['username'] ?></span>
                        </td>
                        <td class="p-2">
                            <span class="font-bold block text-gray-700"><?= $i['prod_name'] ?></span>
                            <span class="text-xs text-gray-500 block">Ref: <?= $i['reference'] ?></span>
                        </td>
                        <td class="p-2 text-right">
                            <span class="px-2 py-1 rounded text-xs font-bold <?= $i['type']=='IN'?'bg-green-100 text-green-700':'bg-red-100 text-red-700' ?>">
                                <?= $i['type']=='IN' ? '+' : '-' ?> <?= $i['quantity'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($rec_inventory)): ?>
                        <tr><td colspan="3" class="p-4 text-center text-gray-500">Belum ada aktivitas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const ctx = document.getElementById('financeChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            { label: 'Masuk', data: <?= json_encode($data_in) ?>, borderColor: '#10b981', fill: false, tension: 0.1 },
            { label: 'Keluar', data: <?= json_encode($data_out) ?>, borderColor: '#ef4444', fill: false, tension: 0.1 }
        ]
    }
});
const ctxPie = document.getElementById('pieChart').getContext('2d');
new Chart(ctxPie, {
    type: 'doughnut',
    data: {
        labels: ['Masuk', 'Keluar'],
        datasets: [{
            data: [<?= $income ?>, <?= $expense ?>],
            backgroundColor: ['#10b981', '#ef4444']
        }]
    },
    options: {
        maintainAspectRatio: false,
    }
});
</script>