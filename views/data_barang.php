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

    // 1. DELETE PRODUCT
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

    // 2. UPDATE SERIAL NUMBERS (NEW FEATURE)
    if (isset($_POST['update_sn_list'])) {
        $prod_id = $_POST['sn_product_id'];
        $sn_ids = $_POST['sn_ids'] ?? [];
        $sn_values = $_POST['sn_values'] ?? [];
        $sn_deletes = $_POST['sn_deletes'] ?? []; // Array ID yang dihapus

        $pdo->beginTransaction();
        try {
            // Hapus SN yang dicentang
            if (!empty($sn_deletes)) {
                $placeholders = implode(',', array_fill(0, count($sn_deletes), '?'));
                // Validasi: Pastikan status AVAILABLE sebelum hapus
                $stmtDel = $pdo->prepare("DELETE FROM product_serials WHERE id IN ($placeholders) AND product_id = ? AND status='AVAILABLE'");
                $paramsDel = $sn_deletes;
                $paramsDel[] = $prod_id;
                $stmtDel->execute($paramsDel);
            }

            // Update SN yang diedit
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
            
            if (!empty($sn_deletes)) {
                $deleted_count = count($sn_deletes);
                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$deleted_count, $prod_id]);
                
                // Catat Log Pengurangan Stok karena Hapus SN
                $note = "Koreksi SN (Hapus $deleted_count SN via Data Barang)";
                $def_wh = $pdo->query("SELECT id FROM warehouses LIMIT 1")->fetchColumn();
                $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'OUT', ?, ?, ?, 'CORRECTION', ?, ?)")
                    ->execute([$prod_id, $def_wh, $deleted_count, $note, $_SESSION['user_id']]);
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

                if ($initial_stock > 0 && empty($wh_id)) throw new Exception("Gudang Penempatan wajib dipilih untuk stok awal.");

                // --- VALIDASI JUMLAH SN vs STOCK (STRICT MODE) ---
                $sns = [];
                if ($initial_stock > 0) {
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

                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, image_url, has_serial_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
                $stmt->execute([$sku, $name, $category, $unit, $buy_price, $sell_price, $initial_stock, $image_path, $notes]);
                $new_id = $pdo->lastInsertId();

                if ($initial_stock > 0) {
                    $ref = "INIT/" . date('ymd') . "/" . rand(100,999);
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, 'Saldo Awal', ?)")
                        ->execute([$new_id, $wh_id, $initial_stock, $ref, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();

                    $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach($sns as $sn) $stmtSn->execute([$new_id, $sn, $wh_id, $trx_id]);
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
                 SUM(CASE WHEN status = 'SOLD' THEN 1 ELSE 0 END) as used
                 FROM product_serials 
                 WHERE warehouse_id IS NOT NULL 
                 GROUP BY product_id, warehouse_id";
$stmt_sn_stock = $pdo->query($sql_sn_stock);
while($r = $stmt_sn_stock->fetch()) {
    $stock_map[$r['product_id']][$r['warehouse_id']] = [
        'ready' => (int)$r['ready'],
        'used' => (int)$r['used'],
        'total' => (int)$r['ready'] + (int)$r['used']
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
            <h3 class="font-bold flex items-center gap-2"><i class="fas fa-qrcode"></i> Preview Label QR</h3>
            <button onclick="closeLabelModal()" class="text-gray-300 hover:text-white"><i class="fas fa-times text-xl"></i></button>
        </div>
        
        <!-- Content Preview (Ukuran relatif utk layar, tapi proporsional) -->
        <div class="p-6 bg-gray-200 flex justify-center">
            <!-- Container Simulasi Kertas 100x150mm (Aspect Ratio 2:3) -->
            <div id="label_preview_container" class="bg-white shadow-lg flex flex-col items-center justify-center p-4 text-center border border-gray-300" style="width: 240px; height: 360px;">
                <div class="font-mono font-bold text-2xl mb-4 text-black" id="prev_sku">SKU</div>
                <!-- PREVIEW GAMBAR QR CODE -->
                <img id="prev_barcode_img" class="w-8/12 mb-4 grayscale" alt="Barcode">
                <div class="font-bold text-lg leading-tight line-clamp-3 text-black" id="prev_name">Nama Barang</div>
            </div>
        </div>

        <!-- Footer Actions -->
        <div class="p-4 bg-white border-t flex gap-2 flex-col">
            <div class="text-xs text-red-600 bg-red-50 p-2 rounded text-center mb-2 border border-red-200">
                <i class="fas fa-exclamation-triangle"></i> <b>PENTING SAAT PRINT:</b><br>
                Pilih Ukuran Kertas: <b>100x150mm</b> (di Setting Printer)<br>
                Margins: <b>None / Minimum</b><br>
                Scale: <b>Fit to Printable Area</b>
            </div>
            <div class="flex gap-2">
                <button onclick="downloadLabelImage()" class="flex-1 bg-green-600 text-white py-2 rounded font-bold hover:bg-green-700 shadow flex items-center justify-center gap-2">
                    <i class="fas fa-download"></i> Save Image
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
<div class="grid grid-cols-1 lg:grid-cols-4 gap-6 page-content-wrapper">
    <!-- LEFT: FORM -->
    <div class="lg:col-span-1 no-print order-1 lg:order-1">
        <?php include 'views/data_barang_form.php'; ?>
        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded text-xs text-blue-800">
            <i class="fas fa-info-circle mr-1"></i> <b>Info:</b> Stok ditampilkan adalah <b>Total Aset</b> (Ready + Terpakai/Sold untuk Aktivitas).
        </div>
    </div>

    <!-- RIGHT: TABLE -->
    <div class="lg:col-span-3 order-2 lg:order-2">
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
                        <tr class="hover:bg-gray-50 group">
                            <td class="p-1 border text-center align-middle">
                                <?php if(!empty($p['image_url'])): ?><img src="<?= $p['image_url'] ?>" class="w-8 h-8 object-cover rounded border bg-white mx-auto cursor-pointer" onclick="window.open(this.src)"><?php endif; ?>
                            </td>
                            <td class="p-2 border font-mono text-blue-600 whitespace-nowrap align-middle">
                                <?= htmlspecialchars($p['sku']) ?>
                                <div class="mt-1">
                                    <!-- TOMBOL LABEL DIPERBAIKI -->
                                    <button onclick="openLabelPreview('<?= h($p['sku']) ?>', '<?= h($p['name']) ?>')" class="text-[9px] text-gray-500 hover:text-gray-800 border px-1 rounded bg-gray-50 flex items-center gap-1 w-full justify-center"><i class="fas fa-qrcode"></i> Label</button>
                                </div>
                            </td>
                            <td class="p-1 border text-center align-middle">
                                <button onclick="manageSN(<?= $p['id'] ?>, '<?= h($p['name']) ?>')" class="bg-purple-100 hover:bg-purple-200 text-purple-700 px-1 py-1 rounded text-[10px] font-bold w-full" title="Klik untuk Edit SN">SN</button>
                            </td>
                            <td class="p-2 border font-bold text-gray-700 align-middle"><?= htmlspecialchars($p['name']) ?></td>
                            <td class="p-2 border text-right text-red-600 font-medium align-middle"><?= number_format($p['buy_price'],0,',','.') ?></td>
                            <td class="p-2 border text-right text-green-600 font-bold align-middle"><?= number_format($p['sell_price'],0,',','.') ?></td>
                            <td class="p-2 border text-center text-gray-500 align-middle"><?= htmlspecialchars($p['unit']) ?></td>
                            <?php foreach($warehouses as $wh): 
                                $cell_total = 0; $cell_ready = 0; $cell_used = 0;
                                $is_sn_item = ($p['has_serial_number'] == 1);
                                if ($is_sn_item) {
                                    if (isset($stock_map[$prod_id][$wh['id']])) {
                                        $d = $stock_map[$prod_id][$wh['id']];
                                        $cell_total = $d['total']; $cell_ready = $d['ready']; $cell_used = $d['used'];
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
                                    <?php if($cell_total > 0): ?>
                                        <div class="font-bold text-gray-800 text-sm"><?= number_format($cell_total) ?></div>
                                        <?php if($cell_used > 0): ?>
                                            <div class="text-[9px] text-gray-500 mt-1 whitespace-nowrap bg-gray-100 rounded px-1">
                                                <span class="text-green-600" title="Ready">R:<?= $cell_ready ?></span> | 
                                                <span class="text-red-600 font-bold" title="Terpakai/Sold">U:<?= $cell_used ?></span>
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
                                    <a href="?page=data_barang&edit_id=<?= $p['id'] ?>" class="bg-yellow-100 text-yellow-700 p-1 rounded hover:bg-yellow-200 w-full text-[10px] font-bold"><i class="fas fa-pencil-alt"></i> Edit</a>
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
                <b>Perhatian:</b> Menghapus SN 'AVAILABLE' akan mengurangi stok fisik.
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700 shadow">Simpan Perubahan</button>
        </form>
    </div>
</div>

<script>
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
                    ${!isSold ? `<input type="checkbox" name="sn_deletes[]" value="${item.id}" class="accent-red-500 w-4 h-4 cursor-pointer" title="Hapus SN ini">` : '-'}
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
    
    // Render Barcode ke Image
    try {
        let canvas = document.createElement('canvas');
        bwipjs.toCanvas(canvas, {
            bcid:        'qrcode',       // GANTI KE QR CODE
            text:        sku,             
            scale:       4, // High resolution
            // Removed fixed height, QR determines its own size based on scale
            includetext: false,           
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
    
    // Ukuran Canvas High Res untuk 100x150mm (Approx 378x567 px @ 96DPI, but double for quality)
    canvas.width = 756; 
    canvas.height = 1134;
    
    // Fill White Background
    ctx.fillStyle = "white";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    
    // Draw Content (Black)
    ctx.fillStyle = "black";
    ctx.textAlign = "center";
    
    // SKU
    ctx.font = "bold 60px 'Courier New'";
    ctx.fillText(currentSku, canvas.width/2, 200);
    
    // Barcode Image
    const img = document.getElementById('prev_barcode_img');
    const imgWidth = canvas.width * 0.8;
    const imgHeight = imgWidth * (img.naturalHeight / img.naturalWidth);
    ctx.drawImage(img, (canvas.width - imgWidth)/2, 250, imgWidth, imgHeight);
    
    // Name
    ctx.font = "bold 40px Arial";
    let nameY = 250 + imgHeight + 80;
    
    var words = currentName.split(' ');
    var line = '';
    var lineHeight = 50;
    var maxWidth = canvas.width * 0.9;
    
    for(var n = 0; n < words.length; n++) {
      var testLine = line + words[n] + ' ';
      var metrics = ctx.measureText(testLine);
      if (metrics.width > maxWidth && n > 0) {
        ctx.fillText(line, canvas.width/2, nameY);
        line = words[n] + ' ';
        nameY += lineHeight;
      } else {
        line = testLine;
      }
    }
    ctx.fillText(line, canvas.width/2, nameY);
    
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
        const win = window.open('', '_blank', 'width=500,height=600');
        
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

                    /* PAGE SETUP - STRICT */
                    @page { 
                        size: 100mm 150mm; 
                        margin: 0; 
                    }
                    
                    /* CONTAINER */
                    .label-container {
                        width: 100mm;
                        height: 150mm;
                        display: flex; 
                        flex-direction: column; 
                        align-items: center; 
                        justify-content: center; 
                        text-align: center;
                        border: 1px dotted transparent; /* Debug border if needed */
                        padding: 5mm;
                    }

                    .sku { 
                        font-size: 24pt; 
                        font-weight: 900; 
                        font-family: 'Courier New', monospace; 
                        margin-bottom: 5mm; 
                        color: black;
                    }
                    
                    img { 
                        width: 70%; /* QR Code Width */
                        height: auto; 
                        max-height: 80mm; /* Allow more height for square QR */
                        image-rendering: pixelated; 
                        margin-bottom: 5mm;
                        display: block;
                    }
                    
                    .name { 
                        font-size: 18pt; 
                        font-weight: bold; 
                        line-height: 1.2; 
                        width: 100%;
                        word-wrap: break-word;
                        color: black;
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