<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG']);

$id = $_GET['id'] ?? 0;
$wh = $pdo->prepare("SELECT * FROM warehouses WHERE id = ?");
$wh->execute([$id]);
$warehouse = $wh->fetch();

if(!$warehouse) { echo "Gudang tidak ditemukan"; exit; }

$txs = $pdo->prepare("SELECT i.*, p.name as prod_name, p.sku FROM inventory_transactions i JOIN products p ON i.product_id = p.id WHERE i.warehouse_id = ? ORDER BY i.date DESC LIMIT 100");
$txs->execute([$id]);
$transactions = $txs->fetchAll();
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-warehouse"></i> <?= $warehouse['name'] ?></h2>
        <p class="text-gray-500"><?= $warehouse['location'] ?></p>
    </div>
    <a href="?page=lokasi_gudang" class="bg-gray-500 text-white px-4 py-2 rounded">Kembali</a>
</div>

<div class="bg-white p-6 rounded shadow">
    <h3 class="font-bold mb-4">Aktivitas Gudang (100 Transaksi Terakhir)</h3>
    <table class="w-full text-sm border text-left">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-3">Tanggal</th>
                <th class="p-3">Tipe</th>
                <th class="p-3">SKU</th>
                <th class="p-3">Nama Barang</th>
                <th class="p-3">Jumlah</th>
                <th class="p-3">Ref</th>
                <th class="p-3">Catatan</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($transactions as $t): ?>
            <tr class="border-b hover:bg-gray-50">
                <td class="p-3"><?= date('d/m/Y', strtotime($t['date'])) ?></td>
                <td class="p-3">
                    <span class="px-2 py-1 rounded text-xs text-white <?= $t['type']=='IN'?'bg-green-500':'bg-red-500' ?>">
                        <?= $t['type']=='IN'?'MASUK':'KELUAR' ?>
                    </span>
                </td>
                <td class="p-3 font-mono"><?= $t['sku'] ?></td>
                <td class="p-3 font-bold"><?= $t['prod_name'] ?></td>
                <td class="p-3"><?= $t['quantity'] ?></td>
                <td class="p-3"><?= $t['reference'] ?></td>
                <td class="p-3 text-gray-500"><?= $t['notes'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($transactions)): ?>
            <tr><td colspan="7" class="p-4 text-center text-gray-500">Belum ada aktivitas di gudang ini.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>