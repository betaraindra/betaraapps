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

            // A. HITUNG TOTAL NILAI ASET (STOK FISIK SAAT INI - REAL TIME)
            // Logika Hybrid: 
            // 1. Barang SN: Hitung baris di product_serials (status AVAILABLE)
            // 2. Barang Non-SN: Hitung dari Inventory Transactions (IN - OUT)
            $sql_asset = "
                SELECT SUM(sub_total) as asset_value FROM (
                    -- 1. Nilai Aset dari Barang Ber-SN (AVAILABLE Only)
                    SELECT SUM(p.buy_price) as sub_total
                    FROM product_serials ps
                    JOIN products p ON ps.product_id = p.id
                    WHERE ps.warehouse_id = ? AND ps.status = 'AVAILABLE'

                    UNION ALL

                    -- 2. Nilai Aset dari Barang NON-SN (Berdasarkan Transaksi)
                    SELECT SUM(trx_stock.qty * p.buy_price) as sub_total
                    FROM products p
                    JOIN (
                        SELECT product_id, SUM(IF(type='IN', quantity, -quantity)) as qty
                        FROM inventory_transactions
                        WHERE warehouse_id = ?
                        GROUP BY product_id
                    ) trx_stock ON p.id = trx_stock.product_id
                    WHERE p.has_serial_number = 0 AND trx_stock.qty > 0
                ) as grand_total
            ";
            $stmt_asset = $pdo->prepare($sql_asset);
            $stmt_asset->execute([$wh_id, $wh_id]);
            $asset_value = $stmt_asset->fetchColumn() ?: 0;

            // B. HITUNG TOTAL QUANTITY (STOK FISIK)
            $sql_qty = "
                SELECT SUM(qty) as total_qty FROM (
                    -- 1. Qty Barang SN (Available)
                    SELECT COUNT(*) as qty
                    FROM product_serials ps
                    WHERE ps.warehouse_id = ? AND ps.status = 'AVAILABLE'

                    UNION ALL

                    -- 2. Qty Barang Non-SN (Trx IN - OUT)
                    SELECT SUM(IF(t.type='IN', t.quantity, -t.quantity)) as qty
                    FROM inventory_transactions t
                    JOIN products p ON t.product_id = p.id
                    WHERE t.warehouse_id = ? AND p.has_serial_number = 0
                ) as grand_qty
            ";
            $stmt_qty = $pdo->prepare($sql_qty);
            $stmt_qty->execute([$wh_id, $wh_id]);
            $total_stock_qty = $stmt_qty->fetchColumn() ?: 0;

            // C. HITUNG KEUANGAN (BERDASARKAN TAG [Wilayah: Nama])
            // UPDATE: Exclude Expense tipe 'ASSET' DAN Akun Material (2105) agar Net Profit Operasional tidak minus
            $sql_fin = "
                SELECT 
                    SUM(CASE WHEN f.type='INCOME' THEN f.amount ELSE 0 END) as income,
                    SUM(CASE 
                        WHEN f.type='EXPENSE' 
                             AND a.type != 'ASSET' 
                             AND a.code NOT IN ('2105', '3003') -- Exclude Material & Stok
                        THEN f.amount ELSE 0 
                    END) as expense
                FROM finance_transactions f
                JOIN accounts a ON f.account_id = a.id
                WHERE f.date BETWEEN ? AND ? 
                AND f.description LIKE ?
            ";
            $stmt_fin = $pdo->prepare($sql_fin);
            $stmt_fin->execute([$start_date, $end_date, "%[Wilayah: $wh_name]%"]);
            $fin_data = $stmt_fin->fetch();
            $profit = ($fin_data['income']??0) - ($fin_data['expense']??0);

            // D. HITUNG JUMLAH AKTIVITAS (TRANSAKSI OUT/PEMAKAIAN)
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

            // E. TOP 10 MATERIAL PER WILAYAH
            $sql_top = "
                SELECT 
                    p.id, p.name, p.unit, p.has_serial_number,
                    COALESCE(SUM(CASE WHEN i.date BETWEEN ? AND ? AND i.type='IN' THEN i.quantity ELSE 0 END), 0) as qty_in,
                    COALESCE(SUM(CASE WHEN i.date BETWEEN ? AND ? AND i.type='OUT' THEN i.quantity ELSE 0 END), 0) as qty_out,
                    COALESCE(SUM(CASE WHEN i.date BETWEEN ? AND ? AND i.type='OUT' AND (i.notes LIKE 'Aktivitas:%' OR i.notes LIKE '%[PEMAKAIAN]%') THEN i.quantity ELSE 0 END), 0) as qty_used,
                    COALESCE(SUM(CASE WHEN i.date BETWEEN ? AND ? AND i.type='OUT' AND i.notes LIKE 'Rusak:%' THEN i.quantity ELSE 0 END), 0) as qty_damaged_trx,
                    (
                        CASE 
                            WHEN p.has_serial_number = 1 THEN 
                                (SELECT COUNT(*) FROM product_serials ps WHERE ps.product_id = p.id AND ps.warehouse_id = ? AND ps.status = 'AVAILABLE')
                            ELSE 
                                (SELECT COALESCE(SUM(IF(t.type='IN', t.quantity, -t.quantity)), 0) FROM inventory_transactions t WHERE t.product_id = p.id AND t.warehouse_id = ?)
                        END
                    ) as ready_stock,
                    (
                        CASE 
                            WHEN p.has_serial_number = 1 THEN 
                                (SELECT COUNT(*) FROM product_serials ps WHERE ps.product_id = p.id AND ps.warehouse_id = ? AND ps.status = 'DEFECTIVE')
                            ELSE 
                                0 -- Untuk Non-SN, kita pakai qty_damaged_trx di atas (akumulasi periode) atau mau total? 
                                  -- Request user: 'Kolom Unit Rusak'. Biasanya stok saat ini.
                                  -- Tapi Non-SN tidak punya status 'DEFECTIVE'.
                                  -- Jadi kita anggap Non-SN Rusak = Akumulasi Transaksi Rusak (Periode ini? Atau Selamanya?)
                                  -- Untuk konsistensi tabel 'Top 10' yg berbasis periode, kita pakai qty_damaged_trx.
                        END
                    ) as damaged_stock_sn
                FROM inventory_transactions i
                JOIN products p ON i.product_id = p.id
                WHERE i.warehouse_id = ?
                GROUP BY p.id, p.name, p.unit, p.has_serial_number
                HAVING ready_stock > 0 OR qty_used > 0 OR qty_in > 0 OR qty_out > 0 OR qty_damaged_trx > 0 OR damaged_stock_sn > 0
                ORDER BY ready_stock DESC, qty_used DESC
                LIMIT 10
            ";
            $stmt_top = $pdo->prepare($sql_top);
            $stmt_top->execute([
                $start_date, $end_date, 
                $start_date, $end_date, 
                $start_date, $end_date, 
                $start_date, $end_date,
                $wh_id, $wh_id, 
                $wh_id, // For damaged_stock_sn subquery
                $wh_id
            ]);
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

            <!-- Body Statistics (5 Grid now) -->
            <div class="p-4 grid grid-cols-2 md:grid-cols-5 gap-4 border-b border-gray-100 bg-gray-50">
                <div class="text-center border-r border-gray-200 last:border-0 md:border-r">
                    <p class="text-[10px] text-gray-500 uppercase font-bold">Total Stok (Qty)</p>
                    <p class="font-bold text-orange-600 text-sm truncate"><?= number_format($total_stock_qty) ?> Unit</p>
                </div>
                <div class="text-center border-r border-gray-200 last:border-0 md:border-r">
                    <p class="text-[10px] text-gray-500 uppercase font-bold">Total Aset (Rp)</p>
                    <p class="font-bold text-blue-600 text-sm truncate"><?= formatRupiah($asset_value) ?></p>
                </div>
                <div class="text-center border-r border-gray-200 last:border-0 md:border-r">
                    <p class="text-[10px] text-gray-500 uppercase font-bold">Pemasukan</p>
                    <p class="font-bold text-green-600 text-sm truncate"><?= formatRupiah($fin_data['income']??0) ?></p>
                </div>
                <div class="text-center border-r border-gray-200 last:border-0 md:border-r">
                    <p class="text-[10px] text-gray-500 uppercase font-bold">Pengeluaran (Ops)</p>
                    <p class="font-bold text-red-600 text-sm truncate"><?= formatRupiah($fin_data['expense']??0) ?></p>
                </div>
                <div class="text-center">
                    <p class="text-[10px] text-gray-500 uppercase font-bold">Net Profit (Ops)</p>
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
                                <th class="p-2 text-center w-20 text-blue-600 font-bold">Ready</th>
                                <th class="p-2 text-center text-red-600 font-bold">Rusak</th>
                                <th class="p-2 text-center text-green-600">Masuk</th>
                                <th class="p-2 text-center text-red-600">Keluar</th>
                                <th class="p-2 pr-4 text-right text-purple-700 font-bold bg-purple-50">Terpakai</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if(empty($top_items)): ?>
                                <tr><td colspan="6" class="p-4 text-center text-gray-400 italic">Belum ada pergerakan material pada periode ini.</td></tr>
                            <?php else: ?>
                                <?php foreach($top_items as $item): 
                                    $damaged = ($item['has_serial_number'] == 1) ? $item['damaged_stock_sn'] : $item['qty_damaged_trx'];
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-2 pl-4 font-medium text-gray-700 truncate max-w-[150px]" title="<?= htmlspecialchars($item['name']) ?>">
                                        <?= htmlspecialchars($item['name']) ?>
                                    </td>
                                    <!-- KOLOM READY STOCK -->
                                    <td class="p-2 text-center text-blue-700 font-bold text-[10px]">
                                        <?= number_format($item['ready_stock']) ?> <?= htmlspecialchars($item['unit']) ?>
                                    </td>
                                    <!-- KOLOM RUSAK -->
                                    <td class="p-2 text-center text-red-600 font-bold text-[10px]">
                                        <?= number_format($damaged) ?>
                                    </td>
                                    <td class="p-2 text-center text-green-600 font-medium">
                                        <?= $item['qty_in'] > 0 ? '+'.$item['qty_in'] : '-' ?>
                                    </td>
                                    <td class="p-2 text-center text-red-600 font-medium">
                                        <?= $item['qty_out'] > 0 ? '-'.$item['qty_out'] : '-' ?>
                                    </td>
                                    <td class="p-2 pr-4 text-right font-bold text-purple-700 bg-purple-50">
                                        <?= $item['qty_used'] > 0 ? $item['qty_used'] : '-' ?>
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