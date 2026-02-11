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

// --- PRE-FETCH STOK PER GUDANG (LOGIKA BARU: TOTAL ASET = READY + USED/SOLD) ---
$stock_map = [];

// 1. Ambil Stok Barang Ber-SN (Breakdown: READY vs USED/SOLD)
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

// 2. Fallback untuk Barang NON-SN
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

<style>
    /* CSS UNTUK PRINT LABEL PRESISI (Misal: 50mm x 30mm) */
    @media print {
        @page { 
            size: 50mm 30mm; /* Atur ukuran kertas ke ukuran label standar */
            margin: 0; /* Margin 0 untuk menghilangkan Header/Footer browser */
        }
        
        body { 
            margin: 0 !important; 
            padding: 0 !important; 
            background-color: white !important; 
        }

        /* Sembunyikan semua elemen lain */
        body * { 
            visibility: hidden; 
            height: 0; 
            overflow: hidden; 
        }

        /* Tampilkan hanya area print */
        #label_print_area, #label_print_area * { 
            visibility: visible; 
            height: auto; 
            overflow: visible;
        }

        #label_print_area {
            position: fixed;
            left: 0;
            top: 0;
            width: 50mm; /* Lebar Label */
            height: 30mm; /* Tinggi Label */
            display: flex !important;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: white;
            z-index: 9999;
        }

        .label-box {
            width: 100%;
            height: 100%;
            padding: 2px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
        }

        /* Styling Canvas Barcode */
        canvas { 
            max-width: 95% !important; 
            max-height: 18mm !important; /* Maksimal tinggi barcode agar muat teks */
        }
        
        /* Styling Teks SKU & Nama */
        .lbl-sku { font-family: monospace; font-size: 8pt; font-weight: bold; margin-bottom: 1px; }
        .lbl-name { font-family: Arial, sans-serif; font-size: 7pt; font-weight: bold; line-height: 1.1; margin-top: 1px; max-height: 2.2em; overflow: hidden; }
    }
    
    /* Sembunyikan area print di layar normal */
    #label_print_area { display: none; }
</style>

<!-- Hidden Print Area (Legacy/Direct Print) -->
<div id="label_print_area">
    <div class="label-box">
        <div class="lbl-sku" id="lbl_sku">SKU</div>
        <canvas id="lbl_barcode_canvas"></canvas>
        <div class="lbl-name" id="lbl_name">NAMA BARANG</div>
    </div>
</div>

<!-- BARCODE PREVIEW MODAL (New) -->
<div id="barcodeModal" class="hidden fixed inset-0 bg-black bg-opacity-75 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-sm w-full p-6 text-center relative animate-fade-in-up">
        <button onclick="document.getElementById('barcodeModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Label Barcode</h3>
        
        <div class="flex justify-center mb-6 bg-gray-50 border p-4 rounded">
            <!-- Canvas ini akan digambar manual (Text + Barcode + Text) -->
            <canvas id="preview_canvas" class="shadow-md bg-white"></canvas>
        </div>
        
        <div class="grid grid-cols-2 gap-3">
            <button onclick="downloadBarcodeImg()" class="bg-purple-600 text-white py-2 rounded hover:bg-purple-700 font-bold flex items-center justify-center gap-2 shadow transition">
                <i class="fas fa-download"></i> Save Image
            </button>
            <button onclick="printBarcodeLabel()" class="bg-blue-600 text-white py-2 rounded hover:bg-blue-700 font-bold flex items-center justify-center gap-2 shadow transition">
                <i class="fas fa-print"></i> Print Label
            </button>
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
    <!-- LEFT: FORM (1 COL) -->
    <div class="lg:col-span-1 no-print order-1 lg:order-1">
        <?php include 'views/data_barang_form.php'; ?>
        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded text-xs text-blue-800">
            <i class="fas fa-info-circle mr-1"></i> <b>Info:</b> Stok ditampilkan adalah <b>Total Aset</b> (Ready + Terpakai/Sold untuk Aktivitas).
        </div>
    </div>

    <!-- RIGHT: TABLE (3 COLS) -->
    <div class="lg:col-span-3 order-2 lg:order-2">
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left border-collapse border border-gray-300">
                    <!-- HEADER -->
                    <thead class="bg-gray-100 text-gray-800 font-bold uppercase text-center border-b-2 border-gray-300">
                        <tr>
                            <th rowspan="2" class="p-2 border border-gray-300 w-10 align-middle">Gbr</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle w-24">SKU</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle w-10">SN</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle">Nama Barang</th>
                            <th rowspan="2" class="p-2 border border-gray-300 text-right align-middle w-20">Beli (HPP)</th>
                            <th rowspan="2" class="p-2 border border-gray-300 text-right align-middle w-20">Jual</th>
                            <th rowspan="2" class="p-2 border border-gray-300 align-middle w-10">Sat</th>
                            
                            <th colspan="<?= count($warehouses) ?>" class="p-2 border border-gray-300 align-middle bg-blue-50 text-blue-900">
                                Stok Wilayah (Total Aset = Ready + Terpakai)
                            </th>
                            
                            <th rowspan="1" class="p-2 border border-gray-300 align-middle bg-yellow-50 text-yellow-900 w-16">TOTAL</th>
                            
                            <th rowspan="2" class="p-2 border border-gray-300 text-center align-middle w-20">Aksi</th>
                        </tr>
                        <tr>
                            <?php foreach($warehouses as $wh): ?>
                                <th class="p-1 border border-gray-300 text-center bg-blue-50 text-[10px] whitespace-nowrap px-2">
                                    <?= htmlspecialchars($wh['name']) ?>
                                </th>
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
                            <!-- GAMBAR -->
                            <td class="p-1 border text-center align-middle">
                                <?php if(!empty($p['image_url'])): ?><img src="<?= $p['image_url'] ?>" class="w-8 h-8 object-cover rounded border bg-white mx-auto cursor-pointer" onclick="window.open(this.src)"><?php endif; ?>
                            </td>
                            <!-- SKU -->
                            <td class="p-2 border font-mono text-blue-600 whitespace-nowrap align-middle">
                                <?= htmlspecialchars($p['sku']) ?>
                                <div class="mt-1">
                                    <button onclick="openBarcodePreview('<?= h($p['sku']) ?>', '<?= h($p['name']) ?>')" class="text-[9px] text-gray-500 hover:text-gray-800 border px-1 rounded bg-gray-50 flex items-center gap-1 w-full justify-center"><i class="fas fa-barcode"></i> Label</button>
                                </div>
                            </td>
                            
                            <!-- SN MANAGEMENT BADGE -->
                            <td class="p-1 border text-center align-middle">
                                <button onclick="manageSN(<?= $p['id'] ?>, '<?= h($p['name']) ?>')" class="bg-purple-100 hover:bg-purple-200 text-purple-700 px-1 py-1 rounded text-[10px] font-bold w-full" title="Klik untuk Edit SN">
                                    SN
                                </button>
                            </td>

                            <!-- NAMA -->
                            <td class="p-2 border font-bold text-gray-700 align-middle"><?= htmlspecialchars($p['name']) ?></td>
                            
                            <!-- HARGA -->
                            <td class="p-2 border text-right text-red-600 font-medium align-middle"><?= number_format($p['buy_price'],0,',','.') ?></td>
                            <td class="p-2 border text-right text-green-600 font-bold align-middle"><?= number_format($p['sell_price'],0,',','.') ?></td>
                            <td class="p-2 border text-center text-gray-500 align-middle"><?= htmlspecialchars($p['unit']) ?></td>

                            <!-- DYNAMIC STOK GUDANG (LOGIC UTAMA) -->
                            <?php foreach($warehouses as $wh): 
                                $cell_total = 0;
                                $cell_ready = 0;
                                $cell_used = 0;
                                $is_sn_item = ($p['has_serial_number'] == 1);

                                if ($is_sn_item) {
                                    // Logic Barang SN: Ambil dari Stock Map (Ready + Used)
                                    if (isset($stock_map[$prod_id][$wh['id']])) {
                                        $d = $stock_map[$prod_id][$wh['id']];
                                        $cell_total = $d['total'];
                                        $cell_ready = $d['ready'];
                                        $cell_used = $d['used'];
                                    }
                                } else {
                                    // Logic Non-SN: Ambil dari TRX Map
                                    if (isset($trx_map[$prod_id][$wh['id']])) {
                                        $cell_total = $trx_map[$prod_id][$wh['id']];
                                        $cell_ready = $cell_total; 
                                    }
                                }
                                
                                $total_stock_row += $cell_total;
                                $export_row[$wh['name']] = $cell_total;
                            ?>
                                <td class="p-2 border text-center align-middle">
                                    <?php if($cell_total > 0): ?>
                                        <div class="font-bold text-gray-800 text-sm"><?= number_format($cell_total) ?></div>
                                        <?php if($cell_used > 0): ?>
                                            <!-- Breakdown Detail (Ready | Used) -->
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

                            <!-- TOTAL ROW -->
                            <td class="p-2 border text-center font-bold text-lg <?= $total_stock_row < 10 ? 'text-red-600 bg-red-50' : 'text-blue-800 bg-yellow-50' ?> align-middle">
                                <?= number_format($total_stock_row) ?>
                            </td>

                            <!-- AKSI -->
                            <td class="p-1 border text-center align-middle">
                                <div class="flex flex-col gap-1 items-center">
                                    <a href="?page=data_barang&edit_id=<?= $p['id'] ?>" class="bg-yellow-100 text-yellow-700 p-1 rounded hover:bg-yellow-200 w-full text-[10px] font-bold"><i class="fas fa-pencil-alt"></i> Edit</a>
                                    
                                    <form method="POST" onsubmit="return confirm('Hapus barang?')" class="w-full">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                        <button class="bg-red-100 text-red-700 p-1 rounded hover:bg-red-200 w-full text-[10px] font-bold"><i class="fas fa-trash"></i> Hapus</button>
                                    </form>
                                    
                                    <!-- Shortcut SN -->
                                    <button onclick="manageSN(<?= $p['id'] ?>, '<?= h($p['name']) ?>')" class="bg-purple-100 text-purple-700 p-1 rounded hover:bg-purple-200 w-full text-[10px] font-bold">
                                        <i class="fas fa-list-ol"></i> Edit SN
                                    </button>
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

<!-- IMPORT MODAL & SN MODAL (SAME AS BEFORE) -->
<div id="importModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
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

// --- NEW BARCODE MODAL LOGIC ---
let currentPrintSku = '';
let currentPrintName = '';

function openBarcodePreview(sku, name) {
    currentPrintSku = sku;
    currentPrintName = name;
    
    document.getElementById('barcodeModal').classList.remove('hidden');
    
    // Generate Canvas Image
    const canvas = document.getElementById('preview_canvas');
    const ctx = canvas.getContext('2d');
    
    // Set Size (Approx 50mm x 30mm @ High DPI for Screen)
    // 8 dots/mm = 400px width for 50mm
    canvas.width = 400; 
    canvas.height = 240;
    
    // Background White
    ctx.fillStyle = 'white';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    
    // Border visual check
    ctx.lineWidth = 1;
    ctx.strokeStyle = '#ccc';
    ctx.strokeRect(0,0, canvas.width, canvas.height);
    
    // Draw SKU (Top)
    ctx.fillStyle = 'black';
    ctx.font = 'bold 24px monospace';
    ctx.textAlign = 'center';
    ctx.fillText(sku, canvas.width / 2, 35);
    
    // Draw Name (Bottom)
    ctx.font = 'bold 18px Arial';
    let dispName = name.length > 25 ? name.substring(0, 25) + '...' : name;
    ctx.fillText(dispName, canvas.width / 2, canvas.height - 20);
    
    // Draw Barcode (Middle) using bwip-js offscreen
    try {
        let cvs = document.createElement('canvas');
        bwipjs.toCanvas(cvs, {
            bcid:        'code128',
            text:        sku,
            scale:       2,
            height:      10, // Approx height
            includetext: false,
        });
        // Center barcode image
        const x = (canvas.width - cvs.width) / 2;
        const y = 50; 
        ctx.drawImage(cvs, x, y);
    } catch (e) {
        ctx.font = '12px Arial';
        ctx.fillText("Barcode Error", canvas.width/2, canvas.height/2);
    }
}

function downloadBarcodeImg() {
    const canvas = document.getElementById('preview_canvas');
    const link = document.createElement('a');
    link.download = `Label-${currentPrintSku}.png`;
    link.href = canvas.toDataURL("image/png");
    link.click();
}

function printBarcodeLabel() {
    // Gunakan fungsi print direct yang sudah ada di hidden area, lebih presisi untuk printer thermal
    printLabelDirect(currentPrintSku, currentPrintName);
}

// Existing Print Function (Used by table direct button and modal)
function printLabelDirect(sku, name) {
    document.getElementById('lbl_name').innerText = name.substring(0, 40); 
    document.getElementById('lbl_sku').innerText = sku;
    
    try {
        bwipjs.toCanvas('lbl_barcode_canvas', {
            bcid:        'code128',       
            text:        sku,             
            scale:       2,               
            height:      10,              
            includetext: false,           
            textxalign:  'center',
        });
        
        setTimeout(() => window.print(), 300);
    } catch (e) {
        // Fallback
        bwipjs.toCanvas('lbl_barcode_canvas', {
            bcid:        'datamatrix',
            text:        sku,
            scale:       3,
            height:      10,
            includetext: false,
        });
        setTimeout(() => window.print(), 300);
    }
}
</script>