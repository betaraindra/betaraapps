<?php
checkRole(['SUPER_ADMIN']);

if (isset($_GET['del_fin'])) {
    $pdo->query("DELETE FROM finance_transactions WHERE id=".$_GET['del_fin']);
    $_SESSION['flash'] = ['type'=>'success','message'=>'Data terhapus'];
    echo "<script>window.location='?page=data_transaksi';</script>";
    exit;
}
if (isset($_GET['del_inv'])) {
    // Perhatian: Menghapus log barang tidak mengembalikan stok secara otomatis di logika sederhana ini, 
    // idealnya harus reverse transaction. Disini kita hapus record saja.
    $pdo->query("DELETE FROM inventory_transactions WHERE id=".$_GET['del_inv']);
    $_SESSION['flash'] = ['type'=>'success','message'=>'Data terhapus'];
    echo "<script>window.location='?page=data_transaksi';</script>";
    exit;
}
?>

<div class="space-y-6">
    <!-- Keuangan -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="text-lg font-bold mb-4">Semua Transaksi Keuangan</h3>
        <div class="overflow-x-auto h-64">
            <table class="w-full text-sm text-left border">
                <thead class="bg-gray-100 sticky top-0">
                    <tr><th class="p-2 border">ID</th><th class="p-2 border">Tgl</th><th class="p-2 border">Akun</th><th class="p-2 border">Jml</th><th class="p-2 border">Ket</th><th class="p-2 border">Aksi</th></tr>
                </thead>
                <tbody>
                    <?php
                    $fins = $pdo->query("SELECT f.*, a.name as acc FROM finance_transactions f JOIN accounts a ON f.account_id=a.id ORDER BY date DESC LIMIT 100")->fetchAll();
                    foreach($fins as $f):
                    ?>
                    <tr>
                        <td class="p-2 border"><?= $f['id'] ?></td>
                        <td class="p-2 border"><?= $f['date'] ?></td>
                        <td class="p-2 border"><?= $f['acc'] ?> (<?= $f['type'] ?>)</td>
                        <td class="p-2 border"><?= formatRupiah($f['amount']) ?></td>
                        <td class="p-2 border"><?= $f['description'] ?></td>
                        <td class="p-2 border text-center"><a href="?page=data_transaksi&del_fin=<?= $f['id'] ?>" onclick="return confirm('Hapus?')" class="text-red-500">Hapus</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Inventori -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="text-lg font-bold mb-4">Semua Transaksi Barang</h3>
        <div class="overflow-x-auto h-64">
            <table class="w-full text-sm text-left border">
                <thead class="bg-gray-100 sticky top-0">
                    <tr><th class="p-2 border">ID</th><th class="p-2 border">Tgl</th><th class="p-2 border">Tipe</th><th class="p-2 border">Barang</th><th class="p-2 border">Qty</th><th class="p-2 border">Gudang</th><th class="p-2 border">Aksi</th></tr>
                </thead>
                <tbody>
                    <?php
                    $invs = $pdo->query("SELECT i.*, p.name as prod, w.name as wh FROM inventory_transactions i JOIN products p ON i.product_id=p.id JOIN warehouses w ON i.warehouse_id=w.id ORDER BY date DESC LIMIT 100")->fetchAll();
                    foreach($invs as $i):
                    ?>
                    <tr>
                        <td class="p-2 border"><?= $i['id'] ?></td>
                        <td class="p-2 border"><?= $i['date'] ?></td>
                        <td class="p-2 border font-bold <?= $i['type']=='IN'?'text-green-600':'text-red-600' ?>"><?= $i['type'] ?></td>
                        <td class="p-2 border"><?= $i['prod'] ?></td>
                        <td class="p-2 border"><?= $i['quantity'] ?></td>
                        <td class="p-2 border"><?= $i['wh'] ?></td>
                        <td class="p-2 border text-center"><a href="?page=data_transaksi&del_inv=<?= $i['id'] ?>" onclick="return confirm('Hapus? (Stok tidak berubah)')" class="text-red-500">Hapus</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>