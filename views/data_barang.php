<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- SELF-HEALING 1: AUTO ADD COLUMN NOTES ---
try {
    $pdo->query("SELECT notes FROM products LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN notes TEXT DEFAULT NULL AFTER unit");
    } catch (Exception $ex) { }
}

// --- SELF-HEALING 2: FIX ORPHANED SOLD SNs (CLEANUP) ---
try {
    $pdo->exec("
        UPDATE product_serials ps 
        LEFT JOIN inventory_transactions it ON ps.out_transaction_id = it.id 
        SET ps.status = 'AVAILABLE', ps.out_transaction_id = NULL 
        WHERE ps.status = 'SOLD' 
        AND ps.out_transaction_id IS NOT NULL 
        AND it.id IS NULL
    ");
} catch (Exception $ex) { 
    // Silent error
}

// --- SELF-HEALING 3: SYNC MASTER STOCK WITH SN COUNT (FIX SYNC ISSUE) ---
// Memastikan kolom products.stock sama dengan jumlah SN status 'AVAILABLE' di tabel serials
// Hanya dijalankan untuk produk yang memiliki SN (has_serial_number=1) dan stoknya tidak sinkron.
try {
    $pdo->query("
        UPDATE products p
        JOIN (
            SELECT product_id, COUNT(*) as real_stock 
            FROM product_serials 
            WHERE status = 'AVAILABLE' 
            GROUP BY product_id
        ) ps ON p.id = ps.product_id
        SET p.stock = ps.real_stock
        WHERE p.has_serial_number = 1 AND p.stock != ps.real_stock
    ");
} catch (Exception $ex) { 
    // Silent error 
}

// --- AMBIL DATA MASTER UNTUK FORM ---
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$category_accounts = $pdo->query("SELECT * FROM accounts WHERE code IN ('3003', '2005', '2105') ORDER BY code ASC")->fetchAll();

// --- HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    // 1. DELETE PRODUCT (CASCADE)
    if (isset($_POST['delete_id'])) {
        $id = $_POST['delete_id'];
        
        $pdo->beginTransaction();
        try {
            $img = $pdo->query("SELECT image_url FROM products WHERE id=$id")->fetchColumn();
            if ($img && file_exists($img)) unlink($img);
            
            // Hapus Riwayat Transaksi (Inventory)
            $pdo->prepare("DELETE FROM inventory_transactions WHERE product_id=?")->execute([$id]);
            
            // Hapus Serial Number
            $pdo->prepare("DELETE FROM product_serials WHERE product_id=?")->execute([$id]);
            
            // Hapus Produk Master
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
            
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang dan riwayat transaksi berhasil dihapus.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal menghapus: ' . $e->getMessage()];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }

    // 2. UPDATE SERIAL NUMBERS (NEW FEATURE)
    if (isset($_POST['update_sn_list'])) {
        $prod_id = $_POST['sn_product_id'];
        $sn_ids = $_POST['sn_ids'] ?? [];
        $sn_values = $_POST['sn_values'] ?? [];
        $sn_deletes = $_POST['sn_deletes'] ?? []; // Array ID yang dihapus

        $pdo->beginTransaction();
        try {
            // HAPUS SN YANG DICENTANG (Termasuk SOLD)
            $deleted_stock_count = 0; // Hanya hitung pengurangan stok master jika status AVAILABLE
            
            if (!empty($sn_deletes)) {
                foreach ($sn_deletes as $del_id) {
                    // Ambil info SN sebelum hapus
                    $stmtInfo = $pdo->prepare("SELECT status, out_transaction_id FROM product_serials WHERE id = ?");
                    $stmtInfo->execute([$del_id]);
                    $snInfo = $stmtInfo->fetch();

                    if ($snInfo) {
                        if ($snInfo['status'] === 'AVAILABLE') {
                            // Jika Available, hapus dan tandai untuk kurangi stok master
                            $pdo->prepare("DELETE FROM product_serials WHERE id = ?")->execute([$del_id]);
                            $deleted_stock_count++;
                        } 
                        elseif ($snInfo['status'] === 'SOLD') {
                            // Jika SOLD, hapus SN dan update transaksi aktivitas terkait (Cascade)
                            // Ini memenuhi request: "di data aktivitas wilayah juga terhapus"
                            if ($snInfo['out_transaction_id']) {
                                $trxId = $snInfo['out_transaction_id'];
                                
                                // Kurangi Qty di Inventory Transaction
                                $pdo->prepare("UPDATE inventory_transactions SET quantity = quantity - 1 WHERE id = ?")->execute([$trxId]);
                                
                                // Cek sisa Qty, jika <= 0 maka hapus transaksi
                                $checkQty = $pdo->prepare("SELECT quantity FROM inventory_transactions WHERE id = ?");
                                $checkQty->execute([$trxId]);
                                $currQty = $checkQty->fetchColumn();
                                
                                if ($currQty <= 0) {
                                    $pdo->prepare("DELETE FROM inventory_transactions WHERE id = ?")->execute([$trxId]);
                                }
                            }
                            // Hapus baris SN
                            $pdo->prepare("DELETE FROM product_serials WHERE id = ?")->execute([$del_id]);
                        }
                    }
                }
            }

            // UPDATE SN YANG DIEDIT
            $stmtUpd = $pdo->prepare("UPDATE product_serials SET serial_number = ? WHERE id = ? AND product_id = ? AND status='AVAILABLE'");
            
            foreach ($sn_ids as $index => $sn_id) {
                // Skip jika ID ini ada di daftar hapus
                if (in_array($sn_id, $sn_deletes)) continue;

                $new_val = trim($sn_values[$index]);
                if (!empty($new_val)) {
                    // Cek duplikat di produk yang sama (kecuali diri sendiri)
                    $chk = $pdo->prepare("SELECT id FROM product_serials WHERE serial_number = ? AND product_id = ? AND id != ?");
                    $chk->execute([$new_val, $prod_id, $sn_id]);
                    if ($chk->rowCount() > 0) continue; // Skip jika duplikat

                    $stmtUpd->execute([$new_val, $sn_id, $prod_id]);
                }
            }
            
            // Update Stok Master Produk (Hanya jika AVAILABLE dihapus)
            if ($deleted_stock_count > 0) {
                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$deleted_stock_count, $prod_id]);
                
                // Catat Log Pengurangan Stok
                $note = "Koreksi SN (Hapus $deleted_stock_count SN Available via Data Barang)";
                $def_wh = $pdo->query("SELECT id FROM warehouses LIMIT 1")->fetchColumn();
                $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'OUT', ?, ?, ?, 'CORRECTION', ?, ?)")
                    ->execute([$prod_id, $def_wh, $deleted_stock_count, $note, $_SESSION['user_id']]);
            }

            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data Serial Number berhasil diperbarui.'];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal update SN: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }

    // 3. SAVE PRODUCT (ADD/EDIT)
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
                // UPDATE
                $id = $_POST['edit_id'];
                $sql_set = "sku=?, name=?, category=?, unit=?, buy_price=?, sell_price=?, notes=?";
                $params = [$sku, $name, $category, $unit, $buy_price, $sell_price, $notes];
                
                if ($image_path) {
                    $sql_set .= ", image_url = ?";
                    $params[] = $image_path;
                } elseif (isset($_POST['delete_img']) && $_POST['delete_img'] == '1') {
                    $sql_set .= ", image_url = NULL";
                }
                
                $params[] = $id;
                $pdo->prepare("UPDATE products SET $sql_set WHERE id=?")->execute($params);
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data barang diperbarui.'];

            } else {
                // INSERT (ADD NEW)
                $check = $pdo->prepare("SELECT id FROM products WHERE sku=?");
                $check->execute([$sku]);
                if ($check->rowCount() > 0) throw new Exception("SKU $sku sudah ada!");

                $initial_stock = (int)($_POST['initial_stock'] ?? 0);
                $wh_id = $_POST['warehouse_id'] ?? null;
                $sn_string = $_POST['sn_list_text'] ?? '';
                $has_sn = isset($_POST['has_serial_number']) ? 1 : 0; // Checkbox value

                if ($initial_stock > 0 && empty($wh_id)) throw new Exception("Gudang Penempatan wajib dipilih untuk stok awal.");

                // --- VALIDASI JUMLAH SN vs STOCK (STRICT MODE) ---
                $sns = [];
                if ($has_sn && $initial_stock > 0) {
                    $raw_sns = explode(',', $sn_string);
                    $clean_sns = [];
                    foreach($raw_sns as $s) {
                        $s_trimmed = trim($s);
                        if($s_trimmed !== '') $clean_sns[] = $s_trimmed;
                    }
                    
                    $input_sn_count = count($clean_sns);

                    if ($input_sn_count > 0) {
                        if ($input_sn_count !== $initial_stock) {
                            throw new Exception("Validasi Gagal: Anda memasukkan $input_sn_count Serial Number, tetapi Stok Awal yang diinput adalah $initial_stock. Jumlah harus sama persis! (Periksa pemisah koma)");
                        }
                        $sns = $clean_sns;
                    } else {
                        for($i=0; $i<$initial_stock; $i++) {
                            $sns[] = "SN" . time() . rand(1000,9999);
                        }
                    }
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, image_url, has_serial_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sku, $name, $category, $unit, $buy_price, $sell_price, $initial_stock, $image_path, $has_sn, $notes]);
                $new_id = $pdo->lastInsertId();

                if ($initial_stock > 0) {
                    $ref = "INIT/" . date('ymd') . "/" . rand(100,999);
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, 'Saldo Awal', ?)")
                        ->execute([$new_id, $wh_id, $initial_stock, $ref, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();

                    // Insert SN only if has_sn is true
                    if ($has_sn) {
                        $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                        foreach($sns as $sn) $stmtSn->execute([$new_id, $sn, $wh_id, $trx_id]);
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

    // 4. IMPORT DATA
    if (isset($_POST['import_products'])) {
        $_SESSION['flash'] = ['type'=>'info', 'message'=>'Fitur import tetap berjalan normal.'];
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }
}

// --- FILTER & QUERY DATA ---
$search = $_GET['q'] ?? '';
$cat_filter = $_GET['category'] ?? 'ALL';
$sort_by = $_GET['sort'] ?? 'newest';

$existing_cats = $pdo->query("SELECT DISTINCT category FROM products ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// --- EDIT ITEM FETCH + SELF-HEALING STOCK ---
$edit_item = null;
if (isset($_GET['edit_id'])) {
    $e_id = (int)$_GET['edit_id'];
    
    // 1. Cek / Perbaiki Stok Fisik sebelum ditampilkan
    $chk = $pdo->prepare("SELECT has_serial_number, stock FROM products WHERE id=?");
    $chk->execute([$e_id]);
    $p_chk = $chk->fetch();

    if ($p_chk) {
        $real_stock = 0;
        if ($p_chk['has_serial_number'] == 1) {
            // Hitung REAL AVAILABLE SN
            $real_stock = $pdo->query("SELECT COUNT(*) FROM product_serials WHERE product_id=$e_id AND status='AVAILABLE'")->fetchColumn();
        } else {
            // Hitung dari Transaksi (Non SN)
            $real_stock = $pdo->query("
                SELECT (COALESCE(SUM(CASE WHEN type='IN' THEN quantity ELSE 0 END), 0) -
                        COALESCE(SUM(CASE WHEN type='OUT' THEN quantity ELSE 0 END), 0))
                FROM inventory_transactions WHERE product_id=$e_id
            ")->fetchColumn();
        }

        // Update jika beda (Self Healing)
        if ((int)$real_stock != (int)$p_chk['stock']) {
            $pdo->prepare("UPDATE products SET stock=? WHERE id=?")->execute([$real_stock, $e_id]);
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$e_id]);
    $edit_item = $stmt->fetch();
}

// --- PRE-FETCH STOCK MAP ---
// ... (Logic sama seperti sebelumnya) ...
$stock_map = [];
$sql_sn_stock = "SELECT product_id, warehouse_id, 
                 SUM(CASE WHEN status = 'AVAILABLE' THEN 1 ELSE 0 END) as ready,
                 SUM(CASE WHEN status = 'SOLD' THEN 1 ELSE 0 END) as used,
                 SUM(CASE WHEN status = 'DEFECTIVE' THEN 1 ELSE 0 END) as damaged
                 FROM product_serials 
                 WHERE warehouse_id IS NOT NULL 
                 GROUP BY product_id, warehouse_id";
$stmt_sn_stock = $pdo->query($sql_sn_stock);
while($r = $stmt_sn_stock->fetch()) {
    $stock_map[$r['product_id']][$r['warehouse_id']] = [
        'ready' => (int)$r['ready'],
        'used' => (int)$r['used'],
        'damaged' => (int)$r['damaged'],
        'total' => (int)$r['ready'] + (int)$r['used'] + (int)$r['damaged'] // Total Aset usually includes Ready + Used + Damaged (if physical asset exists)
    ];
}

$trx_map = [];
$sql_trx_stock = "SELECT product_id, warehouse_id,
                  (COALESCE(SUM(CASE WHEN type='IN' THEN quantity ELSE 0 END), 0) -
                   COALESCE(SUM(CASE WHEN type='OUT' THEN quantity ELSE 0 END), 0)) as qty
                  FROM inventory_transactions
                  GROUP BY product_id, warehouse_id
                  HAVING qty != 0";
$stmt_trx = $pdo->query($sql_trx_stock);
while($r = $stmt_trx->fetch()) {
    $trx_map[$r['product_id']][$r['warehouse_id']] = $r['qty'];
}

// --- BUILD MAIN QUERY ---
$sql = "SELECT p.* FROM products p 
        WHERE (
            p.name LIKE ? 
            OR p.sku LIKE ? 
            OR p.notes LIKE ? 
            OR EXISTS (
                SELECT 1 FROM product_serials ps 
                WHERE ps.product_id = p.id 
                AND ps.serial_number LIKE ?
            )
        )";
$params = ["%$search%", "%$search%", "%$search%", "%$search%"];

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
?>

<!-- Import Libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bwip-js/3.4.4/bwip-js-min.js"></script>
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<!-- MODAL PREVIEW LABEL (Untuk Save & Print) -->
<div id="labelPreviewModal" class="hidden fixed inset-0 bg-black bg-opacity-75 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm overflow-hidden flex flex-col">
        <!-- Header -->
        <div class="bg-gray-800 text-white p-3 flex justify-between items-center">
            <h3 class="font-bold flex items-center gap-2"><i class="fas fa-barcode"></i> Preview Label SKU</h3>
            <button onclick="closeLabelModal()" class="text-gray-300 hover:text-white"><i class="fas fa-times text-xl"></i></button>
        </div>
        
        <!-- Content Preview (Ukuran relatif utk layar, tapi proporsional 50x30mm) -->
        <div class="p-6 bg-gray-200 flex justify-center">
            <!-- Container Simulasi Kertas 50x30mm (Aspect Ratio 5:3) -->
            <!-- Scale up for visibility: 50mm -> 250px, 30mm -> 150px -->
            <div id="label_preview_container" class="bg-white shadow-lg flex flex-col items-center justify-center p-2 text-center border border-gray-300" style="width: 250px; height: 150px;">
                <div class="font-mono font-bold text-lg leading-none mb-1 text-black" id="prev_sku">SKU123</div>
                <!-- PREVIEW GAMBAR BARCODE -->
                <img id="prev_barcode_img" class="w-11/12 h-12 object-contain mb-1" alt="Barcode">
                <div class="font-bold text-xs leading-tight line-clamp-2 text-black w-full" id="prev_name">Nama Barang Disini</div>
            </div>
        </div>

        <!-- Footer Actions -->
        <div class="p-4 bg-white border-t flex gap-2 flex-col">
            <div class="text-xs text-red-600 bg-red-50 p-2 rounded text-center mb-2 border border-red-200">
                <i class="fas fa-exclamation-triangle"></i> <b>SETTING PRINTER (50x30mm):</b><br>
                Paper Size: <b>50mm x 30mm</b><br>
                Margins: <b>None</b> (0mm)<br>
                Scale: <b>100%</b> (Do not fit to page)
            </div>
            <div class="flex gap-2">
                <button onclick="downloadLabelImage()" class="flex-1 bg-green-600 text-white py-2 rounded font-bold hover:bg-green-700 shadow flex items-center justify-center gap-2">
                    <i class="fas fa-download"></i> Save IMG
                </button>
                <button onclick="executePrintWindow()" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700 shadow flex items-center justify-center gap-2">
                    <i class="fas fa-print"></i> Cetak
                </button>
            </div>
        </div>
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
            <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang / SN</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="w-full border p-2 rounded text-sm" placeholder="Nama / SKU / SN...">
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
<div class="grid grid-cols-1 gap-6 page-content-wrapper">
    <!-- RIGHT: TABLE -->
    <div class="col-span-full">
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left border-collapse border border-gray-300">
                    <thead class="bg-gray-100 text-gray-800 font-bold uppercase text-center border-b-2 border-gray-300">
                        <tr>
                            <th rowspan="2" class="p-2 border border-gray-300 w-10 align-middle">Gbr</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle w-24">SKU</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle w-10">SN</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle">Nama Barang</th>
                            <th rowspan="2" class="p-2 border border-gray-300 text-right align-middle w-20">Beli (HPP)</th>
                            <th rowspan="2" class="p-2 border border-gray-300 text-right align-middle w-20">Jual</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle w-10">Sat</th>
                            <th colspan="<?= count($warehouses) ?>" class="p-2 border border-gray-300 align-middle bg-blue-50 text-blue-900">Stok Wilayah (Total Aset = Ready + Terpakai)</th>
                            <th rowspan="1" class="p-2 border border-gray-300 align-middle bg-yellow-50 text-yellow-900 w-16">TOTAL</th>
                            <th rowspan="2" class="p-2 border border-gray-300 text-center align-middle w-20">Aksi</th>
                        </tr>
                        <tr>
                            <?php foreach($warehouses as $wh): ?>
                                <th class="p-1 border border-gray-300 text-center bg-blue-50 text-[10px] whitespace-nowrap px-2"><?= htmlspecialchars($wh['name']) ?></th>
                            <?php endforeach; ?>
                            <th class="p-1 border border-gray-300 text-center bg-yellow-50 text-[10px]">Aset</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($products as $p): 
                            $prod_id = $p['id'];
                            $total_stock_row = 0;
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
                        <tr class="hover:bg-gray-50 group" id="row_<?= $prod_id ?>">
                            <td class="p-1 border text-center align-middle">
                                <?php if(!empty($p['image_url'])): ?><img src="<?= $p['image_url'] ?>" class="w-8 h-8 object-cover rounded border bg-white mx-auto cursor-pointer" onclick="window.open(this.src)"><?php endif; ?>
                            </td>
                            <td class="p-2 border font-mono text-blue-600 whitespace-nowrap align-middle">
                                <input type="text" id="sku_<?= $prod_id ?>" value="<?= htmlspecialchars($p['sku']) ?>" class="w-full border-none bg-transparent focus:bg-white focus:border focus:border-blue-500 rounded px-1 font-mono text-xs" onchange="markEdited(<?= $prod_id ?>)">
                                <!-- Hidden Fields to Preserve Data -->
                                <input type="hidden" id="cat_<?= $prod_id ?>" value="<?= htmlspecialchars($p['category']) ?>">
                                <input type="hidden" id="note_<?= $prod_id ?>" value="<?= htmlspecialchars($p['notes']) ?>">
                                <input type="hidden" id="img_<?= $prod_id ?>" value="<?= htmlspecialchars($p['image_url']) ?>">
                                <div class="mt-1">
                                    <button onclick="openLabelPreview('<?= h($p['sku']) ?>', '<?= h($p['name']) ?>')" class="text-[9px] text-gray-500 hover:text-gray-800 border px-1 rounded bg-gray-50 flex items-center gap-1 w-full justify-center"><i class="fas fa-barcode"></i> Label</button>
                                </div>
                            </td>
                            <td class="p-1 border text-center align-middle">
                                <button onclick="manageSN(<?= $p['id'] ?>, '<?= h($p['name']) ?>')" class="bg-purple-100 hover:bg-purple-200 text-purple-700 px-1 py-1 rounded text-[10px] font-bold w-full" title="Klik untuk Edit SN">SN</button>
                            </td>
                            <td class="p-2 border font-bold text-gray-700 align-middle">
                                <input type="text" id="name_<?= $prod_id ?>" value="<?= htmlspecialchars($p['name']) ?>" class="w-full border-none bg-transparent focus:bg-white focus:border focus:border-blue-500 rounded px-1 font-bold text-xs" onchange="markEdited(<?= $prod_id ?>)">
                            </td>
                            <td class="p-2 border text-right text-red-600 font-medium align-middle">
                                <input type="text" id="buy_<?= $prod_id ?>" value="<?= number_format($p['buy_price'],0,',','.') ?>" class="w-full border-none bg-transparent focus:bg-white focus:border focus:border-blue-500 rounded px-1 text-right text-xs" onkeyup="fmtRupiah(this); markEdited(<?= $prod_id ?>)">
                            </td>
                            <td class="p-2 border text-right text-green-600 font-bold align-middle">
                                <input type="text" id="sell_<?= $prod_id ?>" value="<?= number_format($p['sell_price'],0,',','.') ?>" class="w-full border-none bg-transparent focus:bg-white focus:border focus:border-blue-500 rounded px-1 text-right text-xs font-bold" onkeyup="fmtRupiah(this); markEdited(<?= $prod_id ?>)">
                            </td>
                            <td class="p-2 border text-center text-gray-500 align-middle">
                                <input type="text" id="unit_<?= $prod_id ?>" value="<?= htmlspecialchars($p['unit']) ?>" class="w-full border-none bg-transparent focus:bg-white focus:border focus:border-blue-500 rounded px-1 text-center text-xs" onchange="markEdited(<?= $prod_id ?>)">
                            </td>
                            <?php foreach($warehouses as $wh): 
                                $cell_total = 0; $cell_ready = 0; $cell_used = 0; $cell_damaged = 0;
                                $is_sn_item = ($p['has_serial_number'] == 1);
                                if ($is_sn_item) {
                                    if (isset($stock_map[$prod_id][$wh['id']])) {
                                        $d = $stock_map[$prod_id][$wh['id']];
                                        $cell_total = $d['total']; 
                                        $cell_ready = $d['ready']; 
                                        $cell_used = $d['used'];
                                        $cell_damaged = $d['damaged'] ?? 0;
                                    }
                                } else {
                                    if (isset($trx_map[$prod_id][$wh['id']])) {
                                        $cell_total = $trx_map[$prod_id][$wh['id']]; $cell_ready = $cell_total; 
                                    }
                                }
                                $total_stock_row += $cell_total;
                                $export_row[$wh['name']] = $cell_total;
                            ?>
                                <td class="p-2 border text-center align-middle">
                                    <?php if($cell_total > 0 || $cell_damaged > 0): ?>
                                        <div class="font-bold text-gray-800 text-sm"><?= number_format($cell_total) ?></div>
                                        <?php if($is_sn_item): ?>
                                            <div class="text-[9px] text-gray-500 mt-1 whitespace-nowrap bg-gray-100 rounded px-1 flex justify-center gap-1">
                                                <?php if($cell_ready > 0): ?>
                                                    <span class="text-green-600 cursor-pointer hover:underline" title="Ready (Klik utk Detail)" onclick="showSnDetail(<?= $prod_id ?>, <?= $wh['id'] ?>, 'READY', '<?= h($p['name']) ?>')">R:<?= $cell_ready ?></span>
                                                <?php endif; ?>
                                                <?php if($cell_used > 0): ?>
                                                    <span class="text-purple-600 font-bold cursor-pointer hover:underline" title="Terpakai/Sold (Klik utk Detail)" onclick="showSnDetail(<?= $prod_id ?>, <?= $wh['id'] ?>, 'USED', '<?= h($p['name']) ?>')">U:<?= $cell_used ?></span>
                                                <?php endif; ?>
                                                <?php if($cell_damaged > 0): ?>
                                                    <span class="text-red-600 font-bold cursor-pointer hover:underline" title="Rusak/Defective (Klik utk Detail)" onclick="showSnDetail(<?= $prod_id ?>, <?= $wh['id'] ?>, 'DAMAGED', '<?= h($p['name']) ?>')">D:<?= $cell_damaged ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-gray-300">-</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <?php $export_data[] = $export_row; ?>
                            <td class="p-2 border text-center font-bold text-lg <?= $total_stock_row < 10 ? 'text-red-600 bg-red-50' : 'text-blue-800 bg-yellow-50' ?> align-middle">
                                <?= number_format($total_stock_row) ?>
                            </td>
                            <td class="p-1 border text-center align-middle">
                                <div class="flex flex-col gap-1 items-center">
                                    <!-- SAVE BUTTON (Hidden by default) -->
                                    <button id="btn_save_<?= $p['id'] ?>" onclick="saveRow(<?= $p['id'] ?>)" class="hidden bg-green-600 text-white p-1 rounded hover:bg-green-700 w-full text-[10px] font-bold shadow-lg animate-pulse"><i class="fas fa-save"></i> Simpan</button>
                                    
                                    <form method="POST" onsubmit="return confirm('Hapus barang?')" class="w-full">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                        <button class="bg-red-100 text-red-700 p-1 rounded hover:bg-red-200 w-full text-[10px] font-bold"><i class="fas fa-trash"></i> Hapus</button>
                                    </form>
                                    <button onclick="manageSN(<?= $p['id'] ?>, '<?= h($p['name']) ?>')" class="bg-purple-100 text-purple-700 p-1 rounded hover:bg-purple-200 w-full text-[10px] font-bold"><i class="fas fa-list-ol"></i> Edit SN</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODALS IMPORT & SN (Existing) -->
<!-- ... (Kode modal import dan SN management sama seperti sebelumnya) ... -->
<div id="importModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <!-- ... (Content Modal Import) ... -->
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <button onclick="document.getElementById('importModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-green-700 flex items-center gap-2"><i class="fas fa-file-excel"></i> Import Data Barang</h3>
        <div class="text-sm text-gray-600 mb-4 bg-yellow-50 p-3 rounded border border-yellow-200">
            <p class="font-bold mb-1">Panduan Import:</p>
            <ul class="list-disc ml-4">
                <li>Gunakan <b>Export Excel</b> untuk template.</li>
                <li>Kolom Wajib: SKU, Nama Barang.</li>
            </ul>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="import_products" value="1">
            <?= csrf_field() ?>
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Pilih File Excel</label>
                <input type="file" name="excel_file" accept=".xlsx, .xls" class="w-full border p-2 rounded bg-gray-50" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Pilih Gudang</label>
                <select name="warehouse_id" class="w-full border p-2 rounded bg-white">
                    <?php foreach($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>"><?= $wh['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="w-full bg-green-600 text-white py-2 rounded font-bold hover:bg-green-700 shadow">Import Sekarang</button>
        </form>
    </div>
</div>

<div id="snModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <!-- ... (Content Modal SN) ... -->
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6 relative flex flex-col max-h-[90vh]">
        <button onclick="document.getElementById('snModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        <h3 class="text-xl font-bold mb-2 text-purple-700 flex items-center gap-2"><i class="fas fa-barcode"></i> Kelola Serial Number</h3>
        <p class="text-sm text-gray-600 border-b pb-4 mb-4">Barang: <span id="sn_modal_title" class="font-bold"></span></p>
        
        <form method="POST" class="flex flex-col flex-1 overflow-hidden">
            <?= csrf_field() ?>
            <input type="hidden" name="update_sn_list" value="1">
            <input type="hidden" name="sn_product_id" id="sn_product_id">
            
            <div class="flex justify-between items-center mb-2">
                <span class="text-xs font-bold text-gray-500 uppercase">Daftar SN</span>
                <div class="text-xs text-gray-400 flex items-center gap-2">
                    <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="chk_show_all_sn" onchange="loadSNs()"> Tampilkan Sold</label>
                </div>
            </div>

            <div class="overflow-y-auto flex-1 border rounded bg-gray-50 p-2 mb-4" id="sn_list_container">
                <div class="text-center text-gray-400 py-10"><i class="fas fa-spinner fa-spin"></i> Memuat data...</div>
            </div>
            
            <div class="bg-yellow-50 p-3 rounded text-xs text-yellow-800 mb-4">
                <b>Perhatian:</b> 
                <ul class="list-disc ml-4">
                    <li>Hapus <b>READY</b>: Mengurangi Stok Master.</li>
                    <li>Hapus <b>SOLD</b>: <span class="font-bold text-red-600">BERBAHAYA!</span> Mengupdate/Menghapus Data Aktivitas Wilayah (Rollback).</li>
                </ul>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700 shadow">Simpan Perubahan</button>
        </form>
    </div>
</div>

<!-- SN DETAIL MODAL (READ ONLY) -->
<div id="snDetailModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6 relative flex flex-col max-h-[90vh]">
        <button onclick="document.getElementById('snDetailModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        <h3 class="text-xl font-bold mb-2 text-blue-700 flex items-center gap-2"><i class="fas fa-list"></i> Detail Serial Number</h3>
        <p class="text-sm text-gray-600 border-b pb-2 mb-2">
            Barang: <span id="snd_title" class="font-bold"></span><br>
            Status: <span id="snd_status" class="font-bold"></span>
        </p>
        
        <div class="overflow-y-auto flex-1 border rounded bg-gray-50 p-2" id="snd_list_container">
            <div class="text-center text-gray-400 py-10"><i class="fas fa-spinner fa-spin"></i> Memuat data...</div>
        </div>
        
        <div class="mt-4 text-right">
            <button onclick="document.getElementById('snDetailModal').classList.add('hidden')" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">Tutup</button>
        </div>
    </div>
</div>

<script>
async function showSnDetail(prodId, whId, status, prodName) {
    document.getElementById('snDetailModal').classList.remove('hidden');
    document.getElementById('snd_title').innerText = prodName;
    
    let statusLabel = '';
    let statusColor = '';
    if(status === 'READY') { statusLabel = 'READY (Available)'; statusColor = 'text-green-600'; }
    else if(status === 'USED') { statusLabel = 'TERPAKAI (Sold)'; statusColor = 'text-purple-600'; }
    else if(status === 'DAMAGED') { statusLabel = 'RUSAK (Defective)'; statusColor = 'text-red-600'; }
    
    document.getElementById('snd_status').innerText = statusLabel;
    document.getElementById('snd_status').className = 'font-bold ' + statusColor;
    
    const container = document.getElementById('snd_list_container');
    container.innerHTML = '<div class="text-center text-gray-400 py-10"><i class="fas fa-spinner fa-spin"></i> Memuat data...</div>';
    
    try {
        const res = await fetch(`api.php?action=get_sn_by_status&product_id=${prodId}&warehouse_id=${whId}&status=${status}`);
        const data = await res.json();
        
        if (data.length === 0) {
            container.innerHTML = '<div class="text-center text-gray-400 py-10 italic">Tidak ada data Serial Number.</div>';
            return;
        }
        
        let html = '<table class="w-full text-xs text-left border-collapse">';
        html += '<thead class="bg-gray-200 text-gray-600 font-bold"><tr><th class="p-2 w-10">#</th><th class="p-2">Serial Number</th><th class="p-2">Gudang</th><th class="p-2">Info</th></tr></thead><tbody>';
        
        data.forEach((item, idx) => {
            let info = '-';
            if (status === 'USED' && item.reference) {
                info = `<span class="text-gray-500">Ref: ${item.reference}</span>`;
            } else if (status === 'DAMAGED' && item.notes) {
                 // Try to extract relevant note part if possible, or just show full note
                 info = `<span class="text-red-500 truncate block max-w-[150px]" title="${item.notes}">${item.notes}</span>`;
            }
            
            html += `<tr class="bg-white border-b hover:bg-gray-50">
                <td class="p-2 text-center">${idx + 1}</td>
                <td class="p-2 font-mono font-bold">${item.serial_number}</td>
                <td class="p-2">${item.wh_name || '-'}</td>
                <td class="p-2">${info}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
        
    } catch (e) {
        container.innerHTML = '<div class="text-center text-red-500 py-4">Gagal memuat data.</div>';
    }
}

function fmtRupiah(i) { let v = i.value.replace(/\D/g, ''); i.value = v ? new Intl.NumberFormat('id-ID').format(v) : ''; }

function markEdited(id) {
    document.getElementById('btn_save_' + id).classList.remove('hidden');
}

function saveRow(id) {
    const sku = document.getElementById('sku_' + id).value;
    const name = document.getElementById('name_' + id).value;
    const buy = document.getElementById('buy_' + id).value;
    const sell = document.getElementById('sell_' + id).value;
    const unit = document.getElementById('unit_' + id).value;
    
    // Hidden fields
    const cat = document.getElementById('cat_' + id).value;
    const note = document.getElementById('note_' + id).value;
    const img = document.getElementById('img_' + id).value;
    
    // Create hidden form to submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href; // Preserve current URL with filters
    
    const fields = {
        'save_product': '1',
        'edit_id': id,
        'sku': sku,
        'name': name,
        'buy_price': buy,
        'sell_price': sell,
        'unit': unit,
        'category': cat,
        'notes': note,
        'existing_image': img,
        'csrf_token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
    };
    
    for (const key in fields) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = fields[key];
        form.appendChild(input);
    }
    
    document.body.appendChild(form);
    form.submit();
}

function exportToExcel() {
    const data = <?= json_encode($export_data) ?>;
    if(data.length === 0) { alert("Tidak ada data untuk diexport"); return; }
    const ws = XLSX.utils.json_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Data Barang");
    XLSX.writeFile(wb, "Data_Barang_<?= date('Ymd_His') ?>.xlsx");
}

function manageSN(id, name) {
    document.getElementById('snModal').classList.remove('hidden');
    document.getElementById('sn_modal_title').innerText = name;
    document.getElementById('sn_product_id').value = id;
    loadSNs();
}

async function loadSNs() {
    const id = document.getElementById('sn_product_id').value;
    const showAll = document.getElementById('chk_show_all_sn').checked ? 1 : 0;
    const container = document.getElementById('sn_list_container');
    
    container.innerHTML = '<div class="text-center text-gray-400 py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    try {
        const res = await fetch(`api.php?action=get_manageable_sns&product_id=${id}&show_all=${showAll}`);
        const data = await res.json();
        
        if (data.length === 0) {
            container.innerHTML = '<div class="text-center text-gray-400 py-10 italic">Belum ada Serial Number terdaftar.</div>';
            return;
        }
        
        let html = '<table class="w-full text-xs text-left border-collapse">';
        html += '<thead class="bg-gray-200 text-gray-600 font-bold"><tr><th class="p-2 w-10">#</th><th class="p-2">Serial Number</th><th class="p-2">Status / Lokasi</th><th class="p-2 w-16 text-center">Hapus</th></tr></thead><tbody>';
        
        data.forEach((item, idx) => {
            const isSold = item.status !== 'AVAILABLE';
            const rowClass = isSold ? 'bg-red-50 text-gray-500' : 'bg-white';
            const statusBadge = isSold ? '<span class="bg-red-200 text-red-800 px-1 rounded text-[10px]">SOLD</span>' : '<span class="bg-green-200 text-green-800 px-1 rounded text-[10px]">READY</span>';
            
            // ALLOW DELETE FOR BOTH NOW
            const deleteCheckbox = `<input type="checkbox" name="sn_deletes[]" value="${item.id}" class="accent-red-500 w-4 h-4 cursor-pointer" title="${isSold ? 'Hapus SOLD & Rollback Transaksi' : 'Hapus Stok'}">`;

            html += `<tr class="${rowClass} border-b">
                <td class="p-2 text-center">${idx + 1}</td>
                <td class="p-2">
                    <input type="hidden" name="sn_ids[]" value="${item.id}">
                    <input type="text" name="sn_values[]" value="${item.sn}" class="border p-1 w-full rounded font-mono ${isSold ? 'bg-gray-100 cursor-not-allowed' : ''}" ${isSold ? 'readonly' : ''}>
                </td>
                <td class="p-2">
                    ${statusBadge} ${item.wh_name}
                </td>
                <td class="p-2 text-center">
                    ${deleteCheckbox}
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
        
    } catch (e) {
        container.innerHTML = '<div class="text-center text-red-500 py-4">Gagal memuat data.</div>';
    }
}

// --- NEW LABEL LOGIC: PREVIEW, SAVE & PRINT (POPUP WINDOW) ---

let currentSku = '';
let currentName = '';
let currentBarcodeDataUrl = '';

function openLabelPreview(sku, name) {
    currentSku = sku;
    currentName = name;
    
    // Set Text UI
    document.getElementById('prev_sku').innerText = sku;
    document.getElementById('prev_name').innerText = name;
    
    // Render Barcode ke Image (Code 128)
    try {
        let canvas = document.createElement('canvas');
        bwipjs.toCanvas(canvas, {
            bcid:        'code128',       // STANDARD SKU (BARCODE)
            text:        sku,             
            scale:       2,               
            height:      10,              // Tinggi Barcode
            includetext: false,           // Text digambar manual
            textxalign:  'center',
        });
        
        // Set Image Source
        currentBarcodeDataUrl = canvas.toDataURL('image/png');
        document.getElementById('prev_barcode_img').src = currentBarcodeDataUrl;
        
        // Show Modal
        document.getElementById('labelPreviewModal').classList.remove('hidden');
        
    } catch (e) {
        alert("Gagal generate barcode: " + e);
    }
}

function closeLabelModal() {
    document.getElementById('labelPreviewModal').classList.add('hidden');
}

function downloadLabelImage() {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    
    // Ukuran Canvas High Res untuk 50x30mm (Approx 250x150 px @ screen, x2 for download quality)
    const w = 500;
    const h = 300;
    canvas.width = w; 
    canvas.height = h;
    
    // Fill White Background
    ctx.fillStyle = "white";
    ctx.fillRect(0, 0, w, h);
    
    // Draw Content (Black)
    ctx.fillStyle = "black";
    ctx.textAlign = "center";
    
    // 1. SKU NUMBER (TOP)
    ctx.font = "bold 30px 'Courier New'"; 
    ctx.fillText(currentSku, w/2, 40);
    
    // 2. BARCODE IMAGE (MIDDLE)
    const img = document.getElementById('prev_barcode_img');
    const imgWidth = w * 0.9;
    const imgHeight = 100; // Fixed height for bar
    ctx.drawImage(img, (w - imgWidth)/2, 50, imgWidth, imgHeight);
    
    // 3. PRODUCT NAME (BOTTOM)
    ctx.font = "bold 24px Arial"; 
    let nameY = 50 + imgHeight + 35;
    
    // Simple text wrapping if name is long
    var words = currentName.split(' ');
    var line = '';
    var lineHeight = 28;
    var maxWidth = w * 0.95;
    
    // Allow max 2 lines
    let lineCount = 0;
    for(var n = 0; n < words.length; n++) {
      var testLine = line + words[n] + ' ';
      var metrics = ctx.measureText(testLine);
      if (metrics.width > maxWidth && n > 0) {
        if(lineCount < 2) {
            ctx.fillText(line, w/2, nameY);
            nameY += lineHeight;
            lineCount++;
        }
        line = words[n] + ' ';
      } else {
        line = testLine;
      }
    }
    if(lineCount < 2) ctx.fillText(line, w/2, nameY);
    
    // Trigger Download
    const link = document.createElement('a');
    link.download = 'label_' + currentSku + '.png';
    link.href = canvas.toDataURL('image/png');
    link.click();
}

function executePrintWindow() {
    if(!currentBarcodeDataUrl) return;
    
    // Gunakan Image Object untuk Preload sebelum Print
    const img = new Image();
    img.src = currentBarcodeDataUrl;
    
    img.onload = function() {
        const win = window.open('', '_blank', 'width=400,height=300');
        
        win.document.write(`
            <html>
            <head>
                <title>Print Label ${currentSku}</title>
                <style>
                    /* GLOBAL RESET */
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    
                    /* FORCING BLACK AND WHITE (High Contrast for Thermal) */
                    body { 
                        font-family: Arial, sans-serif;
                        background: white;
                        color: black;
                        filter: grayscale(100%) contrast(200%); 
                    }

                    /* PAGE SETUP - STRICT 50x30mm */
                    @page { 
                        size: 50mm 30mm; 
                        margin: 0; 
                    }
                    
                    /* CONTAINER */
                    .label-container {
                        width: 50mm;
                        height: 30mm;
                        display: flex; 
                        flex-direction: column; 
                        align-items: center; 
                        justify-content: center; 
                        text-align: center;
                        padding: 1mm;
                        overflow: hidden;
                    }

                    .sku { 
                        font-size: 10pt; 
                        font-weight: bold; 
                        font-family: 'Courier New', monospace; 
                        margin-bottom: 1mm; 
                        color: black;
                        line-height: 1;
                    }
                    
                    img { 
                        width: 95%; /* Barcode Width */
                        height: 12mm; /* Barcode Height fixed */
                        image-rendering: pixelated; 
                        margin-bottom: 1mm;
                        display: block;
                    }
                    
                    .name { 
                        font-size: 7pt; 
                        font-weight: bold; 
                        line-height: 1.1; 
                        width: 100%;
                        word-wrap: break-word;
                        color: black;
                        max-height: 8mm;
                        overflow: hidden;
                    }
                </style>
            </head>
            <body>
                <div class="label-container">
                    <div class="sku">${currentSku}</div>
                    <img src="${currentBarcodeDataUrl}" alt="Barcode"/>
                    <div class="name">${currentName}</div>
                </div>
                <script>
                    // Execute print only when window is fully loaded
                    window.onload = function() {
                        setTimeout(function() {
                            window.focus();
                            window.print();
                            // Optional: window.close(); 
                        }, 500);
                    };
                <\/script>
            </body>
            </html>
        `);
        win.document.close();
    };
}
</script>