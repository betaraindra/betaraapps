<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG']);

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$warehouse = $_GET['warehouse'] ?? 'all';
$search = $_GET['q'] ?? ''; // Search query

// Query
$sql = "SELECT i.*, p.sku, p.name as prod_name, w.name as wh_name, u.username 
        FROM inventory_transactions i 
        JOIN products p ON i.product_id = p.id 
        JOIN warehouses w ON i.warehouse_id = w.id
        JOIN users u ON i.user_id = u.id
        WHERE (i.date BETWEEN '$start' AND '$end')";

if ($warehouse != 'all') {
    $sql .= " AND i.warehouse_id = $warehouse";
}

// Tambahkan filter search
if (!empty($search)) {
    $sql .= " AND (p.sku LIKE '%$search%' OR i.reference LIKE '%$search%' OR p.name LIKE '%$search%')";
}

$sql .= " ORDER BY i.date DESC, i.id DESC";

$data = $pdo->query($sql)->fetchAll();
$warehouses = $pdo->query("SELECT * FROM warehouses")->fetchAll();
?>

<div class="bg-white p-6 rounded-lg shadow mb-6">
    <h3 class="text-lg font-bold mb-4">Laporan Gudang & Pencarian Arsip</h3>
    <form method="GET" class="flex flex-wrap gap-4 items-end">
        <input type="hidden" name="page" value="laporan_gudang">
        <div>
            <label class="block text-sm">Dari Tanggal</label>
            <input type="date" name="start" value="<?= $start ?>" class="border p-2 rounded">
        </div>
        <div>
            <label class="block text-sm">Sampai Tanggal</label>
            <input type="date" name="end" value="<?= $end ?>" class="border p-2 rounded">
        </div>
        <div>
            <label class="block text-sm">Gudang</label>
            <select name="warehouse" class="border p-2 rounded min-w-[150px]">
                <option value="all">Semua Gudang</option>
                <?php foreach($warehouses as $w): ?>
                    <option value="<?= $w['id'] ?>" <?= $warehouse == $w['id'] ? 'selected' : '' ?>><?= $w['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm">Cari (Scan Barcode / No. Ref)</label>
            <div class="flex">
                 <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="border p-2 rounded-l w-full" placeholder="Scan SKU atau Ketik No. SJ..." autofocus>
                 <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-r hover:bg-blue-700"><i class="fas fa-search"></i></button>
            </div>
        </div>
        <button type="button" onclick="window.print()" class="bg-gray-200 text-gray-800 px-4 py-2 rounded"><i class="fas fa-print"></i> Print Laporan</button>
    </form>
</div>

<div class="bg-white p-6 rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left border-collapse">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 border">Tanggal</th>
                    <th class="p-3 border">No. Ref / SJ</th>
                    <th class="p-3 border">SKU</th>
                    <th class="p-3 border">Nama Barang</th>
                    <th class="p-3 border text-center">Masuk</th>
                    <th class="p-3 border text-center">Keluar</th>
                    <th class="p-3 border">Ket</th>
                    <th class="p-3 border text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_in = 0; $total_out = 0;
                if(count($data) == 0): ?>
                    <tr><td colspan="8" class="p-4 text-center">Data tidak ditemukan.</td></tr>
                <?php else:
                foreach($data as $d): 
                    if($d['type']=='IN') $total_in += $d['quantity'];
                    else $total_out += $d['quantity'];
                ?>
                <tr>
                    <td class="p-2 border"><?= $d['date'] ?></td>
                    <td class="p-2 border font-mono text-blue-600 font-bold"><?= $d['reference'] ? $d['reference'] : '-' ?></td>
                    <td class="p-2 border font-mono text-xs"><?= $d['sku'] ?></td>
                    <td class="p-2 border"><?= $d['prod_name'] ?></td>
                    <td class="p-2 border text-center font-bold text-green-600 bg-green-50"><?= $d['type']=='IN' ? $d['quantity'] : '-' ?></td>
                    <td class="p-2 border text-center font-bold text-red-600 bg-red-50"><?= $d['type']=='OUT' ? $d['quantity'] : '-' ?></td>
                    <td class="p-2 border text-xs"><?= $d['notes'] ?></td>
                    <td class="p-2 border text-center print:hidden">
                        <a href="?page=cetak_surat_jalan&id=<?= $d['id'] ?>" target="_blank" class="text-gray-600 hover:text-black" title="Cetak Surat Jalan">
                            <i class="fas fa-print"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                <tr class="bg-gray-100 font-bold">
                    <td colspan="4" class="p-3 border text-right">Total</td>
                    <td class="p-3 border text-center"><?= $total_in ?></td>
                    <td class="p-3 border text-center"><?= $total_out ?></td>
                    <td colspan="2" class="border"></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>