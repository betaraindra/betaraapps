<?php
// Hitung Ringkasan
$tgl = date('Y-m-d');

// Keuangan
$income = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME'")->fetchColumn() ?: 0;
$expense = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE'")->fetchColumn() ?: 0;
$balance = ($settings['initial_capital'] ?? 0) + $income - $expense;

// Inventori
$total_products = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$low_stock = $pdo->query("SELECT COUNT(*) FROM products WHERE stock < 10")->fetchColumn();

// Chart Data (Last 7 Days)
$labels = [];
$data_in = [];
$data_out = [];
for($i=6; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('d/m', strtotime($d));
    $data_in[] = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='INCOME' AND date='$d'")->fetchColumn() ?: 0;
    $data_out[] = $pdo->query("SELECT SUM(amount) FROM finance_transactions WHERE type='EXPENSE' AND date='$d'")->fetchColumn() ?: 0;
}
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800">Dashboard</h2>
    <p class="text-gray-600">Ringkasan aktivitas perusahaan.</p>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-blue-500">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm text-gray-500">Saldo Akhir</p>
                <h3 class="text-xl font-bold text-gray-800"><?= formatRupiah($balance) ?></h3>
            </div>
            <i class="fas fa-wallet text-blue-200 text-3xl"></i>
        </div>
    </div>
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-green-500">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm text-gray-500">Total Pemasukan</p>
                <h3 class="text-xl font-bold text-green-600"><?= formatRupiah($income) ?></h3>
            </div>
            <i class="fas fa-arrow-up text-green-200 text-3xl"></i>
        </div>
    </div>
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-red-500">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm text-gray-500">Total Pengeluaran</p>
                <h3 class="text-xl font-bold text-red-600"><?= formatRupiah($expense) ?></h3>
            </div>
            <i class="fas fa-arrow-down text-red-200 text-3xl"></i>
        </div>
    </div>
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-orange-500">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm text-gray-500">Stok Menipis</p>
                <h3 class="text-xl font-bold text-orange-600"><?= $low_stock ?> Barang</h3>
            </div>
            <i class="fas fa-exclamation-triangle text-orange-200 text-3xl"></i>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-gray-800 mb-4">Grafik Keuangan (7 Hari Terakhir)</h3>
        <canvas id="financeChart"></canvas>
    </div>
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-gray-800 mb-4">Transaksi Terakhir</h3>
        <div class="overflow-y-auto h-64">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50">
                    <tr><th class="p-2">Tgl</th><th class="p-2">Ket</th><th class="p-2 text-right">Jml</th></tr>
                </thead>
                <tbody>
                    <?php
                    $last_tx = $pdo->query("SELECT * FROM finance_transactions ORDER BY date DESC, id DESC LIMIT 5")->fetchAll();
                    foreach($last_tx as $tx):
                    ?>
                    <tr class="border-b">
                        <td class="p-2"><?= $tx['date'] ?></td>
                        <td class="p-2"><?= $tx['description'] ?></td>
                        <td class="p-2 text-right <?= $tx['type'] == 'INCOME' ? 'text-green-600' : 'text-red-600' ?>">
                            <?= $tx['type'] == 'INCOME' ? '+' : '-' ?> <?= formatRupiah($tx['amount']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
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
            { label: 'Pemasukan', data: <?= json_encode($data_in) ?>, borderColor: '#10b981', tension: 0.1 },
            { label: 'Pengeluaran', data: <?= json_encode($data_out) ?>, borderColor: '#ef4444', tension: 0.1 }
        ]
    }
});
</script>