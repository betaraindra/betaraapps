<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- SELF-HEALING: AUTO ADD COLUMN NOTES ---
try {
    $pdo->query("SELECT notes FROM products LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN notes TEXT DEFAULT NULL AFTER unit");
    } catch (Exception $ex) { }
}

// --- AMBIL DATA MASTER UNTUK FORM ---
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$category_accounts = $pdo->query("SELECT * FROM accounts WHERE code IN ('3003', '2005', '2105') ORDER BY code ASC")->fetchAll();

// --- HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    // 1. DELETE
    if (isset($_POST['delete_id'])) {
        $id = $_POST['delete_id'];
        
        $chk = $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE product_id=$id")->fetchColumn();
        if ($chk > 0) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: Barang ini memiliki riwayat transaksi.'];
        } else {
            $img = $pdo->query("SELECT image_url FROM products WHERE id=$id")->fetchColumn();
            if ($img && file_exists($img)) unlink($img);
            
            $pdo->prepare("DELETE FROM product_serials WHERE product_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang berhasil dihapus.'];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }

    // 2. SAVE (ADD/EDIT)
    if (isset($_POST['save_product'])) {
        try {
            $sku = trim($_POST['sku']);
            $name = trim($_POST['name']);
            $category = trim($_POST['category']);
            $unit = trim($_POST['unit']);
            $notes = trim($_POST['notes'] ?? ''); 
            $buy_price = cleanNumber($_POST['buy_price']);
            $sell_price = cleanNumber($_POST['sell_price']);
            
            // Upload Image Logic
            $image_path = null;
            if (!empty($_FILES['image']['name'])) {
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $filename = "prod_" . time() . "_" . rand(100,999) . "." . $ext;
                if (!is_dir('uploads')) mkdir('uploads');
                $target = "uploads/" . $filename;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                    $image_path = $target;
                }
            }

            if (!empty($_POST['edit_id'])) {
                // === UPDATE PRODUCT ===
                $id = $_POST['edit_id'];
                $oldData = $pdo->query("SELECT stock, image_url FROM products WHERE id=$id")->fetch();
                $old_stock = (int)$oldData['stock'];
                $new_stock = (int)$_POST['initial_stock'];
                $old_img_path = $oldData['image_url'];
                
                $pdo->beginTransaction();

                $sql_set = "sku=?, name=?, category=?, unit=?, buy_price=?, sell_price=?, notes=?";
                $params = [$sku, $name, $category, $unit, $buy_price, $sell_price, $notes];
                
                if ($image_path) {
                    $sql_set .= ", image_url = ?";
                    $params[] = $image_path;
                    if ($old_img_path && file_exists($old_img_path)) unlink($old_img_path);
                } elseif (isset($_POST['delete_img']) && $_POST['delete_img'] == '1') {
                    $sql_set .= ", image_url = NULL";
                    if ($old_img_path && file_exists($old_img_path)) unlink($old_img_path);
                }
                
                $params[] = $id;
                
                $stmt = $pdo->prepare("UPDATE products SET $sql_set WHERE id=?");
                $stmt->execute($params);

                // Handle Adjustment Stok
                if ($new_stock != $old_stock) {
                    if ($new_stock < $old_stock) {
                        throw new Exception("Pengurangan stok tidak diizinkan di sini. Gunakan menu Barang Keluar.");
                    }
                    $delta = $new_stock - $old_stock;
                    $wh_id = $_POST['warehouse_id'] ?? null;
                    $sn_string = $_POST['sn_list_text'] ?? '';

                    if (empty($wh_id)) throw new Exception("Gudang Penempatan wajib dipilih untuk penambahan stok.");

                    $ref = "ADJ/" . date('ymd') . "/" . rand(100,999);
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, 'Adjustment via Edit', ?)")
                        ->execute([$id, $wh_id, $delta, $ref, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();

                    $sns = array_filter(array_map('trim', explode(',', $sn_string)));
                    $needed = $delta - count($sns);
                    for($i=0; $i<$needed; $i++) $sns[] = "SN" . time() . rand(1000,9999);
                    $sns = array_slice($sns, 0, $delta);

                    $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach($sns as $sn) $stmtSn->execute([$id, $sn, $wh_id, $trx_id]);

                    $pdo->prepare("UPDATE products SET stock = ? WHERE id = ?")->execute([$new_stock, $id]);

                    $total_val = $delta * $buy_price;
                    $acc3003 = $pdo->query("SELECT id FROM accounts WHERE code='3003'")->fetchColumn();
                    if ($acc3003 && $total_val > 0) {
                        $wh_name = $pdo->query("SELECT name FROM warehouses WHERE id=$wh_id")->fetchColumn();
                        $desc_fin = "Penambahan Stok (Adj): $name ($delta) [Wilayah: $wh_name]";
                        $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (CURDATE(), 'EXPENSE', ?, ?, ?, ?)")
                            ->execute([$acc3003, $total_val, $desc_fin, $_SESSION['user_id']]);
                    }
                }

                $pdo->commit();
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data barang diperbarui.'];

            } else {
                // === INSERT NEW PRODUCT ===
                $check = $pdo->prepare("SELECT id FROM products WHERE sku=?");
                $check->execute([$sku]);
                if ($check->rowCount() > 0) throw new Exception("SKU $sku sudah ada!");

                $initial_stock = (int)($_POST['initial_stock'] ?? 0);
                $wh_id = $_POST['warehouse_id'] ?? null;
                $sn_string = $_POST['sn_list_text'] ?? '';

                if ($initial_stock > 0 && empty($wh_id)) throw new Exception("Gudang Penempatan wajib dipilih untuk stok awal.");

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, image_url, has_serial_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
                $stmt->execute([$sku, $name, $category, $unit, $buy_price, $sell_price, $initial_stock, $image_path, $notes]);
                $new_id = $pdo->lastInsertId();

                if ($initial_stock > 0) {
                    $ref = "INIT/" . date('ymd') . "/" . rand(100,999);
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, 'Saldo Awal', ?)")
                        ->execute([$new_id, $wh_id, $initial_stock, $ref, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();

                    $sns = array_filter(array_map('trim', explode(',', $sn_string)));
                    $needed = $initial_stock - count($sns);
                    for($i=0; $i<$needed; $i++) $sns[] = "SN" . time() . rand(1000,9999);
                    $sns = array_slice($sns, 0, $initial_stock);

                    $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach($sns as $sn) $stmtSn->execute([$new_id, $sn, $wh_id, $trx_id]);

                    $total_val = $initial_stock * $buy_price;
                    $acc3003 = $pdo->query("SELECT id FROM accounts WHERE code='3003'")->fetchColumn();
                    if ($acc3003 && $total_val > 0) {
                        $wh_name = $pdo->query("SELECT name FROM warehouses WHERE id=$wh_id")->fetchColumn();
                        $desc_fin = "Stok Awal Baru: $name ($initial_stock) [Wilayah: $wh_name]";
                        $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (CURDATE(), 'EXPENSE', ?, ?, ?, ?)")
                            ->execute([$acc3003, $total_val, $desc_fin, $_SESSION['user_id']]);
                    }
                }
                
                $pdo->commit();
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang baru ditambahkan.'];
            }

        } catch (Exception $e) {
            if($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Error: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }

    // 3. IMPORT DATA BARANG
    if (isset($_POST['import_products'])) {
        $json_data = $_POST['import_json'];
        $items = json_decode($json_data, true);
        
        if (empty($items)) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Data Excel kosong atau format salah.'];
        } else {
            $pdo->beginTransaction();
            try {
                $def_wh = $pdo->query("SELECT id FROM warehouses ORDER BY id ASC LIMIT 1")->fetchColumn();
                if (!$def_wh) throw new Exception("Harap buat minimal 1 Data Gudang sebelum import.");
                $acc3003 = $pdo->query("SELECT id FROM accounts WHERE code='3003'")->fetchColumn();

                $count_new = 0; $count_update = 0;

                foreach ($items as $row) {
                    $row = array_change_key_case($row, CASE_LOWER);
                    $sku = trim($row['sku'] ?? '');
                    $name = trim($row['nama'] ?? ($row['nama barang'] ?? ''));
                    if (empty($sku) || empty($name)) continue;

                    $buy = isset($row['beli (hpp)']) ? cleanNumber($row['beli (hpp)']) : (isset($row['beli']) ? cleanNumber($row['beli']) : 0);
                    $sell = isset($row['jual']) ? cleanNumber($row['jual']) : (isset($row['harga jual']) ? cleanNumber($row['harga jual']) : 0);
                    $stock = isset($row['stok']) ? (int)$row['stok'] : 0;
                    $unit = trim($row['sat'] ?? ($row['satuan'] ?? 'Pcs'));
                    $cat = trim($row['kategori'] ?? ($row['category'] ?? 'Umum'));
                    $note = trim($row['catatan'] ?? ($row['notes'] ?? ''));
                    $sn_raw = trim($row['sn'] ?? '');

                    $stmtCheck = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
                    $stmtCheck->execute([$sku]);
                    $exists = $stmtCheck->fetch();

                    if ($exists) {
                        $pdo->prepare("UPDATE products SET name=?, category=?, unit=?, buy_price=?, sell_price=?, notes=? WHERE id=?")
                            ->execute([$name, $cat, $unit, $buy, $sell, $note, $exists['id']]);
                        $count_update++;
                    } else {
                        $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, has_serial_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)")
                            ->execute([$sku, $name, $cat, $unit, $buy, $sell, $stock, $note]);
                        $new_id = $pdo->lastInsertId();
                        $count_new++;

                        if ($stock > 0) {
                            $ref = "IMP/" . date('ymd') . "/" . rand(100,999);
                            $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, 'Import Excel Awal', ?)")
                                ->execute([$new_id, $def_wh, $stock, $ref, $_SESSION['user_id']]);
                            $trx_id = $pdo->lastInsertId();

                            $sns = [];
                            if (!empty($sn_raw)) $sns = array_map('trim', explode(',', $sn_raw));
                            
                            $needed = $stock - count($sns);
                            for($i=0; $i<$needed; $i++) $sns[] = "SN" . time() . rand(1000,9999) . $i;
                            $sns = array_slice($sns, 0, $stock);

                            $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                            foreach($sns as $sn) $stmtSn->execute([$new_id, $sn, $def_wh, $trx_id]);

                            $total_val = $stock * $buy;
                            if ($acc3003 && $total_val > 0) {
                                $wh_name = $pdo->query("SELECT name FROM warehouses WHERE id=$def_wh")->fetchColumn();
                                $desc_fin = "Stok Awal Import: $name ($stock) [Wilayah: $wh_name]";
                                $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (CURDATE(), 'EXPENSE', ?, ?, ?, ?)")
                                    ->execute([$acc3003, $total_val, $desc_fin, $_SESSION['user_id']]);
                            }
                        }
                    }
                }
                $pdo->commit();
                $_SESSION['flash'] = ['type'=>'success', 'message'=>"Import Selesai. Data Baru: $count_new, Data Update: $count_update"];
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal Import: '.$e->getMessage()];
            }
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }
}

// --- FILTER & QUERY DATA ---
$search = $_GET['q'] ?? '';
$cat_filter = $_GET['category'] ?? 'ALL';
$sort_by = $_GET['sort'] ?? 'newest';

$existing_cats = $pdo->query("SELECT DISTINCT category FROM products ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// --- PRE-FETCH STOK PER GUDANG (EAGER LOADING) ---
// Tujuannya agar tidak query di dalam loop (N+1 Problem)
$stock_map = [];
$sql_stock_all = "SELECT product_id, warehouse_id,
                  (COALESCE(SUM(CASE WHEN type='IN' THEN quantity ELSE 0 END), 0) -
                   COALESCE(SUM(CASE WHEN type='OUT' THEN quantity ELSE 0 END), 0)) as qty
                  FROM inventory_transactions
                  GROUP BY product_id, warehouse_id
                  HAVING qty != 0";
$stmt_s = $pdo->query($sql_stock_all);
while($r = $stmt_s->fetch()) {
    $stock_map[$r['product_id']][$r['warehouse_id']] = $r['qty'];
}

// --- PRE-FETCH SN COUNT (Untuk Indikator SN) ---
$sn_map = [];
$sql_sn_count = "SELECT product_id, COUNT(*) as sn_count FROM product_serials WHERE status='AVAILABLE' GROUP BY product_id";
$stmt_sn = $pdo->query($sql_sn_count);
while($r = $stmt_sn->fetch()) {
    $sn_map[$r['product_id']] = $r['sn_count'];
}

// --- BUILD MAIN QUERY ---
$sql = "SELECT p.* FROM products p WHERE (p.name LIKE ? OR p.sku LIKE ? OR p.notes LIKE ?)";
$params = ["%$search%", "%$search%", "%$search%"];

if ($cat_filter !== 'ALL') {
    $sql .= " AND p.category = ?";
    $params[] = $cat_filter;
}

if ($sort_by === 'name_asc') $sql .= " ORDER BY p.name ASC";
elseif ($sort_by === 'stock_high') $sql .= " ORDER BY p.stock DESC";
else $sql .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$export_data = [];
$total_asset_group = 0;

$edit_item = null;
if (isset($_GET['edit_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$_GET['edit_id']]);
    $edit_item = $stmt->fetch();
}
?>

<!-- Import Libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bwip-js/3.4.4/bwip-js-min.js"></script>
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<style>
    @media print {
        aside, header, footer, .no-print, .main-layout { display: none !important; }
        .page-content-wrapper { display: none !important; }
        #label_print_area { display: flex !important; visibility: visible !important; position: fixed; left: 0; top: 0; width: 100%; height: 100%; background: white; align-items: center; justify-content: center; z-index: 9999; }
        #label_print_area * { visibility: visible !important; }
        @page { size: 50mm 30mm; margin: 0; }
        .label-box { width: 48mm; height: 28mm; border: 1px solid #000; padding: 2px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; font-family: sans-serif; overflow: hidden; }
        canvas { max-width: 95% !important; max-height: 20mm !important; }
    }
    #label_print_area { display: none; }
</style>

<!-- Hidden Print Area -->
<div id="label_print_area">
    <div class="label-box">
        <canvas id="lbl_barcode_canvas"></canvas>
        <div class="font-bold text-[8px] leading-tight mt-1 truncate w-full px-1" id="lbl_name">NAMA BARANG</div>
    </div>
</div>

<!-- HEADER & FILTER -->
<div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4 no-print">
    <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-box text-blue-600"></i> Data Barang</h2>
    <div class="flex gap-2">
        <button onclick="document.getElementById('importModal').classList.remove('hidden')" class="bg-indigo-600 text-white px-3 py-2 rounded text-sm font-bold shadow hover:bg-indigo-700"><i class="fas fa-file-import"></i> Import Excel</button>
        <button onclick="exportToExcel()" class="bg-green-600 text-white px-3 py-2 rounded text-sm font-bold shadow hover:bg-green-700"><i class="fas fa-file-export"></i> Export Excel</button>
    </div>
</div>

<!-- FILTER BAR -->
<div class="bg-white p-4 rounded-lg shadow mb-6 border-l-4 border-indigo-600 no-print">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
        <input type="hidden" name="page" value="data_barang">
        <div class="lg:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="w-full border p-2 rounded text-sm" placeholder="Nama / SKU / Ref...">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Kategori</label>
            <select name="category" class="w-full border p-2 rounded text-sm bg-white">
                <option value="ALL">-- Semua --</option>
                <?php foreach($existing_cats as $c): ?>
                    <option value="<?= $c ?>" <?= $cat_filter==$c?'selected':'' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Urutkan</label>
            <select name="sort" class="w-full border p-2 rounded text-sm bg-white">
                <option value="newest" <?= $sort_by=='newest'?'selected':'' ?>>Update Terbaru</option>
                <option value="name_asc" <?= $sort_by=='name_asc'?'selected':'' ?>>Nama (A-Z)</option>
                <option value="stock_high" <?= $sort_by=='stock_high'?'selected':'' ?>>Stok Terbanyak</option>
            </select>
        </div>
        <div>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold hover:bg-indigo-700 w-full shadow text-sm h-[38px]">
                <i class="fas fa-filter"></i> Filter
            </button>
        </div>
    </form>
</div>

<!-- SPLIT LAYOUT -->
<div class="grid grid-cols-1 lg:grid-cols-4 gap-6 page-content-wrapper">
    <!-- LEFT: FORM (1 COL) -->
    <div class="lg:col-span-1 no-print order-1 lg:order-1">
        <?php include 'views/data_barang_form.php'; ?>
    </div>

    <!-- RIGHT: TABLE (3 COLS) -->
    <div class="lg:col-span-3 order-2 lg:order-2">
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left border-collapse border border-gray-300">
                    <!-- HEADER BARIS 1 -->
                    <thead class="bg-gray-100 text-gray-800 font-bold uppercase text-center border-b-2 border-gray-300">
                        <tr>
                            <th rowspan="2" class="p-2 border border-gray-300 w-10 align-middle">Gbr</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle w-24">SKU</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle w-10">SN</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle">Nama Barang</th>
                            <th rowspan="2" class="p-2 border border-gray-300 text-right align-middle w-20">Beli (HPP)</th>
                            <th rowspan="2" class="p-2 border border-gray-300 text-right align-middle w-20">Jual</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle w-10">Sat</th>
                            
                            <!-- DYNAMIC WAREHOUSE HEADER -->
                            <th colspan="<?= count($warehouses) ?>" class="p-2 border border-gray-300 align-middle bg-blue-50 text-blue-900">
                                Ringkasan STOK WILAYAH
                            </th>
                            
                            <th rowspan="1" class="p-2 border border-gray-300 align-middle bg-yellow-50 text-yellow-900 w-16">TOTAL</th>
                            
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle">Catatan</th>
                            <th rowspan="2" class="p-2 border border-gray-300 text-center align-middle w-20">Ref / Cetak</th>
                            <th rowspan="2" class="p-2 border border-gray-300 text-center align-middle w-16">Aksi</th>
                        </tr>
                        <!-- HEADER BARIS 2 -->
                        <tr>
                            <!-- LIST GUDANG -->
                            <?php foreach($warehouses as $wh): ?>
                                <th class="p-1 border border-gray-300 text-center bg-blue-50 text-[10px] whitespace-nowrap px-2">
                                    <?= htmlspecialchars($wh['name']) ?>
                                </th>
                            <?php endforeach; ?>
                            
                            <!-- SUB HEADER TOTAL -->
                            <th class="p-1 border border-gray-300 text-center bg-yellow-50 text-[10px]">TOTAL Material/Stok</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($products as $p): 
                            $prod_id = $p['id'];
                            $total_stock_row = 0;
                            $sn_available = $sn_map[$prod_id] ?? 0;
                            
                            // Hitung Total Aset untuk Export
                            $total_asset_group += ($p['stock'] * $p['buy_price']);
                            
                            $export_row = [
                                'SKU' => $p['sku'],
                                'Nama' => $p['name'],
                                'HPP' => $p['buy_price'],
                                'Jual' => $p['sell_price'],
                                'Sat' => $p['unit'],
                                'Total' => $p['stock'],
                                'Catatan' => $p['notes']
                            ];
                        ?>
                        <tr class="hover:bg-gray-50 group">
                            <!-- GAMBAR -->
                            <td class="p-1 border text-center align-middle">
                                <?php if(!empty($p['image_url'])): ?><img src="<?= $p['image_url'] ?>" class="w-8 h-8 object-cover rounded border bg-white mx-auto cursor-pointer" onclick="window.open(this.src)"><?php endif; ?>
                            </td>
                            <!-- SKU -->
                            <td class="p-2 border font-mono text-blue-600 whitespace-nowrap align-middle"><?= htmlspecialchars($p['sku']) ?></td>
                            
                            <!-- SN -->
                            <td class="p-1 border text-center align-middle">
                                <?php if($sn_available > 0): ?>
                                    <span class="bg-purple-100 text-purple-700 px-1 rounded text-[10px] font-bold" title="<?= $sn_available ?> SN Available"><?= $sn_available ?></span>
                                <?php else: ?>
                                    <span class="text-gray-300 text-[9px]">-</span>
                                <?php endif; ?>
                            </td>

                            <!-- NAMA -->
                            <td class="p-2 border font-bold text-gray-700 align-middle"><?= htmlspecialchars($p['name']) ?></td>
                            
                            <!-- HARGA -->
                            <td class="p-2 border text-right text-red-600 font-medium align-middle"><?= number_format($p['buy_price'],0,',','.') ?></td>
                            <td class="p-2 border text-right text-green-600 font-bold align-middle"><?= number_format($p['sell_price'],0,',','.') ?></td>
                            <td class="p-2 border text-center text-gray-500 align-middle"><?= htmlspecialchars($p['unit']) ?></td>

                            <!-- DYNAMIC STOK GUDANG -->
                            <?php foreach($warehouses as $wh): 
                                $qty = $stock_map[$prod_id][$wh['id']] ?? 0;
                                $total_stock_row += $qty;
                                $export_row[$wh['name']] = $qty;
                            ?>
                                <td class="p-2 border text-center font-medium <?= $qty > 0 ? 'text-gray-800' : 'text-gray-300' ?> align-middle">
                                    <?= $qty > 0 ? number_format($qty) : '-' ?>
                                </td>
                            <?php endforeach; ?>
                            <?php $export_data[] = $export_row; ?>

                            <!-- TOTAL ROW -->
                            <td class="p-2 border text-center font-bold text-lg <?= $total_stock_row < 10 ? 'text-red-600 bg-red-50' : 'text-blue-800 bg-yellow-50' ?> align-middle">
                                <?= number_format($total_stock_row) ?>
                            </td>

                            <!-- CATATAN -->
                            <td class="p-2 border text-[10px] text-gray-600 italic align-middle truncate max-w-[100px]" title="<?= htmlspecialchars($p['notes']) ?>">
                                <?= htmlspecialchars($p['notes']) ?>
                            </td>

                            <!-- CETAK -->
                            <td class="p-1 border text-center align-middle">
                                <div class="flex gap-1 justify-center">
                                    <button onclick="printLabelDirect('<?= h($p['sku']) ?>', '<?= h($p['name']) ?>')" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-2 py-1 rounded text-[9px] border border-gray-300 font-bold"><i class="fas fa-barcode"></i></button>
                                </div>
                            </td>

                            <!-- AKSI -->
                            <td class="p-1 border text-center align-middle">
                                <div class="flex justify-center gap-1">
                                    <a href="?page=data_barang&edit_id=<?= $p['id'] ?>" class="bg-yellow-100 text-yellow-700 p-1 rounded hover:bg-yellow-200"><i class="fas fa-pencil-alt"></i></a>
                                    <form method="POST" onsubmit="return confirm('Hapus barang?')" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                        <button class="bg-red-100 text-red-700 p-1 rounded hover:bg-red-200"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($products)): ?><tr><td colspan="<?= 12 + count($warehouses) ?>" class="p-8 text-center text-gray-400">Tidak ada data barang.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- IMPORT MODAL -->
<div id="importModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <button onclick="document.getElementById('importModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-green-700 flex items-center gap-2"><i class="fas fa-file-excel"></i> Import Data Barang</h3>
        <div class="text-sm text-gray-600 mb-4 bg-yellow-50 p-3 rounded border border-yellow-200">
            <p class="font-bold mb-1">Panduan Import:</p>
            <ul class="list-disc ml-4">
                <li>Kolom: <b>Nama, SKU, Stok, Beli, Jual, Satuan, Kategori, Catatan, SN</b>.</li>
                <li>Nama Kolom <b>TIDAK</b> Case Sensitive.</li>
            </ul>
        </div>
        <form id="form_import" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="import_products" value="1">
            <input type="hidden" name="import_json" id="import_json_data">
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Pilih File Excel (.xlsx)</label>
                <input type="file" id="file_excel" accept=".xlsx, .xls" class="w-full border p-2 rounded text-sm bg-gray-50">
            </div>
            <button type="button" onclick="processImport()" class="w-full bg-green-600 text-white py-2 rounded font-bold hover:bg-green-700 shadow">Upload & Proses</button>
        </form>
    </div>
</div>

<!-- SN APPEND SCANNER MODAL -->
<div id="sn_append_modal" class="hidden fixed inset-0 bg-black z-[99] flex flex-col">
    <div class="bg-gray-900 p-4 flex justify-between items-center text-white">
        <h3 class="font-bold"><i class="fas fa-plus"></i> Scan SN (Append)</h3>
        <button onclick="stopSnAppendScan()" class="text-red-500 text-xl"><i class="fas fa-times"></i></button>
    </div>
    <div id="sn_append_reader" class="flex-1 bg-black"></div>
</div>

<script>
// --- GLOBAL EXPORT VAR ---
const exportData = <?= json_encode($export_data) ?>;

// --- EXPORT TO EXCEL ---
function exportToExcel() {
    if(exportData.length === 0) return alert("Tidak ada data untuk diexport.");
    const ws = XLSX.utils.json_to_sheet(exportData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Data Barang");
    XLSX.writeFile(wb, "Data_Barang_Master_" + new Date().toISOString().slice(0,10) + ".xlsx");
}

// --- IMPORT EXCEL ---
function processImport() {
    const fileInput = document.getElementById('file_excel');
    if(!fileInput.files.length) return alert("Pilih file excel dulu!");
    const file = fileInput.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            const jsonData = XLSX.utils.sheet_to_json(worksheet);
            if (jsonData.length === 0) { alert("File kosong/format salah."); return; }
            document.getElementById('import_json_data').value = JSON.stringify(jsonData);
            document.getElementById('form_import').submit();
        } catch (ex) { alert("Gagal baca Excel."); }
    };
    reader.readAsArrayBuffer(file);
}

// --- SCANNER LOGIC ---
let html5QrCode;
let snAppendQrCode;
let currentFacingMode = "environment";
let isFlashOn = false;

async function initCamera() {
    document.getElementById('scanner_area').classList.remove('hidden');
    if(html5QrCode) { try{ await html5QrCode.stop(); html5QrCode.clear(); }catch(e){} }
    html5QrCode = new Html5Qrcode("reader");
    try {
        await html5QrCode.start({ facingMode: currentFacingMode }, { fps: 10, qrbox: { width: 250, height: 250 } }, (d)=>{ checkAndFillProduct(d); stopScan(); }, ()=>{});
        try {
            const capabilities = html5QrCode.getRunningTrackCameraCapabilities();
            if (capabilities && capabilities.torchFeature().isSupported()) {
                document.getElementById('btn_flash').classList.remove('hidden');
            }
        } catch(e) {}
    } catch(e) { alert("Cam Error: "+e); stopScan(); }
}
function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => { document.getElementById('scanner_area').classList.add('hidden'); html5QrCode.clear(); }); }
async function switchCamera() { if(html5QrCode) { await html5QrCode.stop(); html5QrCode.clear(); } currentFacingMode = (currentFacingMode=="environment")?"user":"environment"; initCamera(); }
async function toggleFlash() { if(html5QrCode) { isFlashOn=!isFlashOn; await html5QrCode.applyVideoConstraints({ advanced: [{ torch: isFlashOn }] }); } }

async function checkAndFillProduct(sku) {
    document.getElementById('form_sku').value = sku;
    try {
        const res = await fetch('api.php?action=get_product_by_sku&sku=' + encodeURIComponent(sku));
        const data = await res.json();
        if(data.id) {
            document.getElementById('form_name').value = data.name;
            document.getElementById('form_buy').value = new Intl.NumberFormat('id-ID').format(data.buy_price);
            document.getElementById('form_sell').value = new Intl.NumberFormat('id-ID').format(data.sell_price);
            document.getElementById('form_unit').value = data.unit;
            document.getElementById('form_category').value = data.category;
            if(data.notes) document.getElementById('form_notes').value = data.notes;
            document.getElementById('form_name').classList.add('bg-green-100');
            setTimeout(() => document.getElementById('form_name').classList.remove('bg-green-100'), 1000);
        } else { document.getElementById('form_name').focus(); }
    } catch(e) {}
}

function formatRupiah(input) { let value = input.value.replace(/\D/g, ''); if(value === '') { input.value = ''; return; } input.value = new Intl.NumberFormat('id-ID').format(value); }
function toggleSnInput() { /* ... existing logic ... */ }
async function generateSku() { try { const r = await fetch('api.php?action=generate_sku'); const d = await r.json(); if(d.sku) document.getElementById('form_sku').value = d.sku; } catch(e) {} }
function printLabelDirect(sku, name) { document.getElementById('lbl_name').innerText = name; try { bwipjs.toCanvas('lbl_barcode_canvas', { bcid: 'code128', text: sku, scale: 2, height: 10, includetext: true, textxalign: 'center', }); window.print(); } catch(e) { alert('Gagal render barcode'); } }
function activateUSB() { const input = document.getElementById('form_sku'); input.focus(); input.select(); }
function handleFileScan(input) { if (input.files.length === 0) return; const file = input.files[0]; const h = new Html5Qrcode("reader"); h.scanFile(file, true).then(d => { checkAndFillProduct(d); }).catch(e => alert("Gagal baca.")); }

// SN APPEND SCANNERS
async function openSnAppendScanner() { document.getElementById('sn_append_modal').classList.remove('hidden'); if(snAppendQrCode) { try{ await snAppendQrCode.stop(); snAppendQrCode.clear(); }catch(e){} } snAppendQrCode = new Html5Qrcode("sn_append_reader"); try { await snAppendQrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: { width: 250, height: 250 } }, (txt) => { const ta = document.getElementById('sn_list_input'); let cur = ta.value.trim(); if(!cur.includes(txt)) { ta.value = cur ? (cur + ", " + txt) : txt; } }, () => {} ); } catch(e) { alert("Camera Error: "+e); stopSnAppendScan(); } }
function stopSnAppendScan() { if(snAppendQrCode) snAppendQrCode.stop().then(() => { document.getElementById('sn_append_modal').classList.add('hidden'); snAppendQrCode.clear(); }).catch(() => document.getElementById('sn_append_modal').classList.add('hidden')); }
function generateBatchSN() { /* ... existing logic ... */ }
</script>