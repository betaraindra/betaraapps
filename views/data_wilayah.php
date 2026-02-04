<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP', 'ADMIN_KEUANGAN']);

// Filter Tanggal untuk performa Keuangan & Aktivitas
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');

// 1. Ambil Semua Gudang
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
?>

<div class="space-y-6">
    <!-- Header & Filter -->
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-white p-6 rounded-lg shadow border-l-4 border-indigo-600">
        <div>
            <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-map-marked-alt text-indigo-600"></i> Data Wilayah</h2>
            <p class="text-sm text-gray-500">Dashboard performa & monitoring material per lokasi gudang.</p>
        </div>
        
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <input type="hidden" name="page" value="data_wilayah">
            <div class="flex flex-col">
                <label class="text-[10px] uppercase font-bold text-gray-500">Dari Tanggal</label>
                <input type="date" name="start" value="<?= $start_date ?>" class="border p-2 rounded text-sm focus:ring-2 focus:ring-indigo-500">
            </div>
            <div class="flex flex-col">
                <label class="text-[10px] uppercase font-bold text-gray-500">Sampai Tanggal</label>
                <input type="date" name="end" value="<?= $end_date ?>" class="border p-2 rounded text-sm focus:ring-2 focus:ring-indigo-500">
            </div>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold hover:bg-indigo-700 h-[38px] shadow">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>
    </div>

    <!-- Grid Wilayah (Responsive 1 kolom di HP, 2 kolom di layar besar) -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <?php foreach($warehouses as $wh): 
            $wh_id = $wh['id'];
            $wh_name = $wh['name'];

            // A. HITUNG TOTAL NILAI ASET (STOK FISIK SAAT INI)
            $sql_asset = "
                SELECT SUM( (COALESCE(sub.in_qty,0) - COALESCE(sub.out_qty,0)) * p.buy_price ) as asset_value
                FROM products p
                LEFT JOIN (
                    SELECT product_id, 
                           SUM(CASE WHEN type='IN' THEN quantity ELSE 0 END) as in_qty,
                           SUM(CASE WHEN type='OUT' THEN quantity ELSE 0 END) as out_qty
                    FROM inventory_transactions
                    WHERE warehouse_id = ?
                    GROUP BY product_id
                ) sub ON p.id = sub.product_id
                WHERE (COALESCE(sub.in_qty,0) - COALESCE(sub.out_qty,0)) > 0
            ";
            $stmt_asset = $pdo->prepare($sql_asset);
            $stmt_asset->execute([$wh_id]);
            $asset_value = $stmt_asset->fetchColumn() ?: 0;

            // B. HITUNG KEUANGAN (BERDASARKAN TAG [Wilayah: Nama])
            $sql_fin = "
                SELECT 
                    SUM(CASE WHEN type='INCOME' THEN amount ELSE 0 END) as income,
                    SUM(CASE WHEN type='EXPENSE' THEN amount ELSE 0 END) as expense
                FROM finance_transactions 
                WHERE date BETWEEN ? AND ? 
                AND description LIKE ?
            ";
            $stmt_fin = $pdo->prepare($sql_fin);
            $stmt_fin->execute([$start_date, $end_date, "%[Wilayah: $wh_name]%"]);
            $fin_data = $stmt_fin->fetch();
            $profit = ($fin_data['income']??0) - ($fin_data['expense']??0);

            // C. HITUNG JUMLAH AKTIVITAS (TRANSAKSI OUT/PEMAKAIAN)
            $sql_act = "
                SELECT COUNT(*) 
                FROM inventory_transactions 
                WHERE warehouse_id = ? 
                AND type = 'OUT' 
                AND notes LIKE 'Aktivitas:%'
                AND date BETWEEN ? AND ?
            ";
            $stmt_act = $pdo->prepare($sql_act);
            $stmt_act->execute([$wh_id, $start_date, $end_date]);
            $activity_count = $stmt_act->fetchColumn() ?: 0;

            // D. TOP 10 PENGGUNAAN MATERIAL PER WILAYAH (PERIODE INI)
            $sql_top = "
                SELECT 
                    p.name, p.unit,
                    COALESCE(SUM(CASE WHEN i.type='IN' THEN i.quantity ELSE 0 END), 0) as qty_in,
                    COALESCE(SUM(CASE WHEN i.type='OUT' THEN i.quantity ELSE 0 END), 0) as qty_out,
                    COALESCE(SUM(CASE WHEN i.type='OUT' AND (i.notes LIKE 'Aktivitas:%' OR i.notes LIKE '%[PEMAKAIAN]%') THEN i.quantity ELSE 0 END), 0) as qty_used
                FROM inventory_transactions i
                JOIN products p ON i.product_id = p.id
                WHERE i.warehouse_id = ? AND i.date BETWEEN ? AND ?
                GROUP BY p.id, p.name, p.unit
                HAVING qty_in > 0 OR qty_out > 0
                ORDER BY qty_used DESC, qty_out DESC
                LIMIT 10
            ";
            $stmt_top = $pdo->prepare($sql_top);
            $stmt_top->execute([$wh_id, $start_date, $end_date]);
            $top_items = $stmt_top->fetchAll();
        ?>
        <div class="bg-white rounded-lg shadow-lg overflow-hidden border border-gray-100 flex flex-col hover:shadow-xl transition-shadow duration-300">
            <!-- Header Card -->
            <div class="bg-gradient-to-r from-gray-800 to-gray-700 p-4 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-white"><?= htmlspecialchars($wh_name) ?></h3>
                    <p class="text-xs text-gray-300"><i class="fas fa-map-pin mr-1"></i> <?= htmlspecialchars($wh['location']) ?></p>
                </div>
                <a href="?page=detail_gudang&id=<?= $wh_id ?>&start=<?= $start_date ?>&end=<?= $end_date ?>" class="bg-blue-600 hover:bg-blue-500 text-white text-xs px-3 py-1 rounded shadow font-bold">
                    Lihat Dashboard Detail <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>

            <!-- Body Statistics (4 Grid) -->
            <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 border-b border-gray-100 bg-gray-50">
                <div class="text-center border-r border-gray-200 last:border-0 md:border-r">
                    <p class="text-[10px] text-gray-500 uppercase font-bold">Total Aset Fisik</p>
                    <p class="font-bold text-blue-600 text-sm truncate"><?= formatRupiah($asset_value) ?></p>
                </div>
                <div class="text-center border-r border-gray-200 last:border-0 md:border-r">
                    <p class="text-[10px] text-gray-500 uppercase font-bold">Pemasukan</p>
                    <p class="font-bold text-green-600 text-sm truncate"><?= formatRupiah($fin_data['income']??0) ?></p>
                </div>
                <div class="text-center border-r border-gray-200 last:border-0 md:border-r">
                    <p class="text-[10px] text-gray-500 uppercase font-bold">Pengeluaran</p>
                    <p class="font-bold text-red-600 text-sm truncate"><?= formatRupiah($fin_data['expense']??0) ?></p>
                </div>
                <div class="text-center">
                    <p class="text-[10px] text-gray-500 uppercase font-bold">Net Profit</p>
                    <p class="font-bold <?= $profit>=0 ? 'text-blue-600' : 'text-red-600' ?> text-sm truncate"><?= formatRupiah($profit) ?></p>
                </div>
            </div>
            
            <div class="px-4 py-2 bg-purple-50 flex justify-between items-center text-xs text-purple-800 border-b border-purple-100">
                <span class="font-bold uppercase"><i class="fas fa-tools mr-1"></i> Aktivitas / Pemakaian</span>
                <span class="font-bold"><?= number_format($activity_count) ?> Transaksi</span>
            </div>

            <!-- Top 10 Material Table Inside Card -->
            <div class="p-0 flex-1 flex flex-col">
                <div class="bg-gray-100 px-4 py-2 text-xs font-bold text-gray-600 uppercase border-b border-gray-200">
                    <i class="fas fa-cubes mr-1"></i> Top 10 Penggunaan Material & Stok
                </div>
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-white text-gray-500 border-b border-gray-200">
                            <tr>
                                <th class="p-2 pl-4 w-1/3">Nama Barang</th>
                                <th class="p-2 text-center text-green-600">Masuk</th>
                                <th class="p-2 text-center text-red-600">Keluar</th>
                                <th class="p-2 pr-4 text-right text-purple-700 font-bold bg-purple-50">Terpakai</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if(empty($top_items)): ?>
                                <tr><td colspan="4" class="p-4 text-center text-gray-400 italic">Belum ada pergerakan material pada periode ini.</td></tr>
                            <?php else: ?>
                                <?php foreach($top_items as $item): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-2 pl-4 font-medium text-gray-700 truncate max-w-[150px]" title="<?= htmlspecialchars($item['name']) ?>">
                                        <?= htmlspecialchars($item['name']) ?>
                                    </td>
                                    <td class="p-2 text-center text-green-600 font-medium">
                                        <?= $item['qty_in'] > 0 ? '+'.$item['qty_in'] : '-' ?>
                                    </td>
                                    <td class="p-2 text-center text-red-600 font-medium">
                                        <?= $item['qty_out'] > 0 ? '-'.$item['qty_out'] : '-' ?>
                                    </td>
                                    <td class="p-2 pr-4 text-right font-bold text-purple-700 bg-purple-50">
                                        <?= $item['qty_used'] > 0 ? $item['qty_used'] : '-' ?> <span class="text-[9px] font-normal text-gray-400"><?= $item['unit'] ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if(empty($warehouses)): ?>
            <div class="col-span-2 p-10 text-center bg-white rounded shadow">
                <p class="text-gray-500">Belum ada lokasi gudang.</p>
            </div>
        <?php endif; ?>
    </div>
</div>