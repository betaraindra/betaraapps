<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP', 'ADMIN_KEUANGAN']);

// Filter Tanggal untuk performa Keuangan & Aktivitas
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');

// 1. Ambil Semua Gudang
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

// 2. Siapkan Data Dashboard Per Wilayah
$dashboard_data = [];

foreach ($warehouses as $wh) {
    $wh_id = $wh['id'];
    $wh_name = $wh['name'];

    // A. HITUNG TOTAL NILAI ASET (STOK FISIK SAAT INI)
    // Query ini menghitung stok saat ini (IN - OUT) * Harga Beli
    // Tidak dipengaruhi filter tanggal (karena aset adalah snapshot saat ini)
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
    // Dipengaruhi Filter Tanggal
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

    // C. HITUNG JUMLAH AKTIVITAS (TRANSAKSI OUT/PEMAKAIAN)
    // Dipengaruhi Filter Tanggal
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

    $dashboard_data[] = [
        'id' => $wh_id,
        'name' => $wh_name,
        'location' => $wh['location'],
        'asset_value' => $asset_value,
        'income' => $fin_data['income'] ?: 0,
        'expense' => $fin_data['expense'] ?: 0,
        'activity_count' => $activity_count
    ];
}
?>

<div class="space-y-6">
    <!-- Header & Filter -->
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-white p-6 rounded-lg shadow border-l-4 border-indigo-600">
        <div>
            <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-map-marked-alt text-indigo-600"></i> Data Wilayah</h2>
            <p class="text-sm text-gray-500">Dashboard performa & aset per lokasi gudang.</p>
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

    <!-- Grid Wilayah -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
        <?php foreach($dashboard_data as $d): 
            $profit = $d['income'] - $d['expense'];
        ?>
        <div class="bg-white rounded-lg shadow-lg overflow-hidden border border-gray-100 hover:shadow-xl transition-shadow duration-300 flex flex-col">
            <!-- Header Card -->
            <div class="bg-gradient-to-r from-gray-800 to-gray-700 p-4 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-white"><?= htmlspecialchars($d['name']) ?></h3>
                    <p class="text-xs text-gray-300"><i class="fas fa-map-pin mr-1"></i> <?= htmlspecialchars($d['location']) ?></p>
                </div>
                <div class="bg-gray-700 p-2 rounded-full text-white">
                    <i class="fas fa-warehouse"></i>
                </div>
            </div>

            <!-- Body Statistics -->
            <div class="p-4 space-y-4 flex-1">
                
                <!-- Nilai Aset (Static) -->
                <div class="flex justify-between items-center border-b pb-2">
                    <span class="text-xs font-bold text-gray-500 uppercase">Total Aset Fisik</span>
                    <span class="font-bold text-blue-600 text-lg"><?= formatRupiah($d['asset_value']) ?></span>
                </div>

                <!-- Financials (Filtered) -->
                <div class="bg-gray-50 p-3 rounded border border-gray-200">
                    <p class="text-[10px] text-gray-400 text-center mb-2 uppercase tracking-widest font-bold">Performa Periode Ini</p>
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between text-green-700">
                            <span><i class="fas fa-arrow-down text-xs"></i> Pemasukan</span>
                            <span class="font-bold"><?= formatRupiah($d['income']) ?></span>
                        </div>
                        <div class="flex justify-between text-red-600">
                            <span><i class="fas fa-arrow-up text-xs"></i> Pengeluaran</span>
                            <span class="font-bold"><?= formatRupiah($d['expense']) ?></span>
                        </div>
                        <div class="flex justify-between font-bold border-t pt-1 mt-1 <?= $profit >= 0 ? 'text-blue-600' : 'text-red-600' ?>">
                            <span>Net Profit</span>
                            <span><?= formatRupiah($profit) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Activity Count -->
                <div class="flex justify-between items-center">
                    <span class="text-xs font-bold text-gray-500">Aktivitas/Pemakaian</span>
                    <span class="bg-purple-100 text-purple-700 px-2 py-1 rounded text-xs font-bold">
                        <?= number_format($d['activity_count']) ?> Transaksi
                    </span>
                </div>
            </div>

            <!-- Footer Action -->
            <div class="p-4 bg-gray-50 border-t">
                <a href="?page=detail_gudang&id=<?= $d['id'] ?>&start=<?= $start_date ?>&end=<?= $end_date ?>" class="block w-full bg-blue-600 text-white text-center py-2 rounded font-bold hover:bg-blue-700 transition">
                    <i class="fas fa-search-plus mr-2"></i> Lihat Dashboard Detail
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if(empty($dashboard_data)): ?>
            <div class="col-span-3 p-10 text-center bg-white rounded shadow">
                <p class="text-gray-500">Belum ada data wilayah. Silakan tambahkan lokasi gudang terlebih dahulu di menu Pengaturan.</p>
            </div>
        <?php endif; ?>
    </div>
</div>