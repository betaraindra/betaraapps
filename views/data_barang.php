<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- AMBIL DATA MASTER UNTUK FORM ---
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
// Akun Kategori Persediaan/Biaya (Sesuai Barang Masuk)
$category_accounts = $pdo->query("SELECT * FROM accounts WHERE code IN ('3003', '2005', '2105') ORDER BY code ASC")->fetchAll();

// --- HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    // 1. DELETE
    if (isset($_POST['delete_id'])) {
        $id = $_POST['delete_id'];
        
        // Cek ketergantungan
        $chk = $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE product_id=$id")->fetchColumn();
        if ($chk > 0) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: Barang ini memiliki riwayat transaksi.'];
        } else {
            // Hapus gambar lama
            $img = $pdo->query("SELECT image_url FROM products WHERE id=$id")->fetchColumn();
            if ($img && file_exists($img)) unlink($img);
            
            // Hapus SN
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
            $buy_price = cleanNumber($_POST['buy_price']);
            $sell_price = cleanNumber($_POST['sell_price']);
            
            // Upload Image
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
                
                // Ambil Data Lama (Stok)
                $oldData = $pdo->query("SELECT stock FROM products WHERE id=$id")->fetch();
                $old_stock = (int)$oldData['stock'];
                $new_stock = (int)$_POST['initial_stock']; // Input dari form
                
                $pdo->beginTransaction();

                // 1. Update Informasi Dasar (Termasuk Kategori)
                $sql_img = "";
                $params = [$sku, $name, $category, $unit, $buy_price, $sell_price];
                
                if ($image_path) {
                    $sql_img = ", image_url = ?";
                    $params[] = $image_path;
                    // Hapus gambar lama
                    $old_img_path = $pdo->query("SELECT image_url FROM products WHERE id=$id")->fetchColumn();
                    if ($old_img_path && file_exists($old_img_path)) unlink($old_img_path);
                }
                
                $params[] = $id; // ID untuk WHERE
                
                $stmt = $pdo->prepare("UPDATE products SET sku=?, name=?, category=?, unit=?, buy_price=?, sell_price=? $sql_img WHERE id=?");
                $stmt->execute($params);

                // 2. Handle Perubahan Stok
                if ($new_stock != $old_stock) {
                    if ($new_stock < $old_stock) {
                        throw new Exception("Pengurangan stok tidak diizinkan melalui menu Edit. Gunakan menu Barang Keluar agar pencatatan SN akurat.");
                    }
                    
                    // Jika Stok Bertambah (Adjustment IN)
                    $delta = $new_stock - $old_stock;
                    $wh_id = $_POST['warehouse_id'] ?? null;
                    $sn_string = $_POST['sn_list_text'] ?? '';

                    if (empty($wh_id)) {
                        throw new Exception("Gudang Penempatan wajib dipilih karena ada penambahan stok ($delta unit).");
                    }

                    // Buat Transaksi IN
                    $ref = "ADJ/" . date('ymd') . "/" . rand(100,999);
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, 'Adjustment via Edit Produk', ?)")
                        ->execute([$id, $wh_id, $delta, $ref, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();

                    // Insert SN Baru
                    $sns = array_filter(array_map('trim', explode(',', $sn_string)));
                    // Auto generate SN jika kurang
                    $needed = $delta - count($sns);
                    for($i=0; $i<$needed; $i++) {
                        $sns[] = "SN" . time() . rand(1000,9999);
                    }
                    // Ambil sejumlah delta saja
                    $sns = array_slice($sns, 0, $delta);

                    $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach($sns as $sn) {
                        // Cek duplikat SN
                        $existSn = $pdo->query("SELECT COUNT(*) FROM product_serials WHERE product_id=$id AND serial_number='$sn'")->fetchColumn();
                        if ($existSn > 0) throw new Exception("SN $sn sudah ada di sistem.");
                        
                        $stmtSn->execute([$id, $sn, $wh_id, $trx_id]);
                    }

                    // Update Total Stok di Tabel Products
                    $pdo->prepare("UPDATE products SET stock = ? WHERE id = ?")->execute([$new_stock, $id]);
                }

                $pdo->commit();
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data barang diperbarui.'];

            } else {
                // === INSERT NEW PRODUCT ===
                // Cek SKU
                $check = $pdo->prepare("SELECT id FROM products WHERE sku=?");
                $check->execute([$sku]);
                if ($check->rowCount() > 0) throw new Exception("SKU $sku sudah ada!");

                $initial_stock = (int)($_POST['initial_stock'] ?? 0);
                $wh_id = $_POST['warehouse_id'] ?? null;
                $sn_string = $_POST['sn_list_text'] ?? ''; // SN dipisah koma

                // Validasi Stok Awal
                if ($initial_stock > 0 && empty($wh_id)) {
                    throw new Exception("Jika mengisi Stok Awal, Gudang Penempatan harus dipilih.");
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, image_url, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$sku, $name, $category, $unit, $buy_price, $sell_price, $initial_stock, $image_path]);
                $new_id = $pdo->lastInsertId();

                // Handle Initial Stock & SN
                if ($initial_stock > 0) {
                    // Create Transaction
                    $ref = "INIT/" . date('ymd') . "/" . rand(100,999);
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, 'Saldo Awal', ?)")
                        ->execute([$new_id, $wh_id, $initial_stock, $ref, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();

                    // Parse SNs
                    $sns = array_filter(array_map('trim', explode(',', $sn_string)));
                    // Auto generate sisanya jika kurang
                    $needed = $initial_stock - count($sns);
                    for($i=0; $i<$needed; $i++) {
                        $sns[] = "SN" . time() . rand(1000,9999);
                    }
                    // Cut if too many
                    $sns = array_slice($sns, 0, $initial_stock);

                    $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach($sns as $sn) {
                        $stmtSn->execute([$new_id, $sn, $wh_id, $trx_id]);
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

    // 3. IMPORT FROM EXCEL
    if (isset($_POST['import_products'])) {
        $import_data = json_decode($_POST['import_json'], true);
        $imported = 0;
        $updated = 0;
        $errors = 0;

        if (is_array($import_data)) {
            $pdo->beginTransaction();
            try {
                // Pre-fetch warehouse map (Name -> ID)
                $whMap = [];
                $whStmt = $pdo->query("SELECT id, name FROM warehouses");
                while($w = $whStmt->fetch()) {
                    $whMap[strtoupper(trim($w['name']))] = $w['id'];
                }
                // Default Warehouse (First one)
                $defaultWhId = $pdo->query("SELECT id FROM warehouses LIMIT 1")->fetchColumn();

                // Prepared Statements
                $stmtCheck = $pdo->prepare("SELECT id, stock FROM products WHERE sku = ?");
                $stmtUpdate = $pdo->prepare("UPDATE products SET name=?, buy_price=?, sell_price=?, unit=? WHERE id=?");
                $stmtInsert = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmtTrx = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, 'Import Awal', ?)");
                $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");

                foreach ($import_data as $row) {
                    // Mapping Columns from Excel Keys
                    $r = array_change_key_case($row, CASE_LOWER);
                    
                    // Extract fields & sanitize numbers
                    $sku = trim($r['sku'] ?? $r['kode'] ?? '');
                    if(empty($sku)) continue;

                    $name = trim($r['nama'] ?? $r['nama barang'] ?? 'Barang Tanpa Nama');
                    $sn_raw = $r['sn'] ?? '';
                    
                    $buy = cleanNumber($r['beli (hpp)'] ?? $r['beli'] ?? 0);
                    $sell = cleanNumber($r['jual'] ?? $r['harga jual'] ?? 0);
                    $stock = (int)cleanNumber($r['stok'] ?? 0);
                    
                    $unit = trim($r['sat'] ?? $r['satuan'] ?? 'Pcs');
                    $wh_name = trim($r['gudang'] ?? '');

                    // Resolve Warehouse
                    $wh_id = $defaultWhId;
                    if (!empty($wh_name) && isset($whMap[strtoupper($wh_name)])) {
                        $wh_id = $whMap[strtoupper($wh_name)];
                    }

                    // Check Existing
                    $stmtCheck->execute([$sku]);
                    $exists = $stmtCheck->fetch();

                    if ($exists) {
                        // UPDATE (Only details, NOT Stock to prevent history corruption)
                        $stmtUpdate->execute([$name, $buy, $sell, $unit, $exists['id']]);
                        $updated++;
                    } else {
                        // INSERT NEW
                        $stmtInsert->execute([$sku, $name, '3003', $unit, $buy, $sell, $stock]);
                        $new_id = $pdo->lastInsertId();
                        $imported++;

                        // Handle Initial Stock for New Item
                        if ($stock > 0) {
                            $ref = "IMP/" . date('ymd') . "/" . rand(1000,9999);
                            $stmtTrx->execute([$new_id, $wh_id, $stock, $ref, $_SESSION['user_id']]);
                            $trx_id = $pdo->lastInsertId();

                            // Handle SNs
                            $sns = [];
                            if (!empty($sn_raw)) {
                                $sns = array_filter(array_map('trim', explode(',', $sn_raw)));
                            }
                            // Generate missing SNs
                            $needed = $stock - count($sns);
                            for($i=0; $i<$needed; $i++) {
                                $sns[] = "SN" . time() . rand(1000,9999);
                            }
                            // Slice if too many
                            $sns = array_slice($sns, 0, $stock);

                            foreach($sns as $sn) {
                                try {
                                    $stmtSn->execute([$new_id, $sn, $wh_id, $trx_id]);
                                } catch(Exception $ex) {} 
                            }
                        }
                    }
                }
                $pdo->commit();
                $_SESSION['flash'] = ['type'=>'success', 'message'=>"Import Selesai: $imported Baru, $updated Diupdate."];
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal Import: ' . $e->getMessage()];
            }
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }
}

// --- FILTER & QUERY DATA ---
$start_date = $_GET['start'] ?? date('Y-01-01');
$end_date = $_GET['end'] ?? date('Y-12-31'); // Future date default
$search = $_GET['q'] ?? '';
$cat_filter = $_GET['category'] ?? 'ALL';
$wh_filter = $_GET['warehouse'] ?? 'ALL'; // Filter Gudang Baru
$sort_by = $_GET['sort'] ?? 'newest';

// Master Data for Filter (Distinct existing categories)
$existing_cats = $pdo->query("SELECT DISTINCT category FROM products ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// Query Utama
$sql = "SELECT p.*,
        (SELECT date FROM inventory_transactions WHERE product_id = p.id ORDER BY date DESC LIMIT 1) as last_update,
        (SELECT reference FROM inventory_transactions WHERE product_id = p.id ORDER BY date DESC LIMIT 1) as last_ref,
        (SELECT id FROM inventory_transactions WHERE product_id = p.id ORDER BY date DESC LIMIT 1) as last_trx_id
        FROM products p 
        WHERE (p.name LIKE ? OR p.sku LIKE ?)";
$params = ["%$search%", "%$search%"];

if ($cat_filter !== 'ALL') {
    $sql .= " AND p.category = ?";
    $params[] = $cat_filter;
}

// LOGIK FILTER GUDANG
if ($wh_filter !== 'ALL') {
    // Tampilkan produk yang pernah memiliki transaksi di gudang tersebut
    $sql .= " AND EXISTS (SELECT 1 FROM inventory_transactions it WHERE it.product_id = p.id AND it.warehouse_id = ?)";
    $params[] = $wh_filter;
}

if ($sort_by === 'name_asc') $sql .= " ORDER BY p.name ASC";
elseif ($sort_by === 'stock_high') $sql .= " ORDER BY p.stock DESC";
else $sql .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Prepare Data for Export & Display
$export_data = [];
$total_asset_group = 0;

foreach($products as &$p) {
    // 1. Get SN
    $sn_stmt_all = $pdo->prepare("SELECT serial_number FROM product_serials WHERE product_id=? AND status='AVAILABLE' ORDER BY serial_number ASC");
    $sn_stmt_all->execute([$p['id']]);
    $all_sns = $sn_stmt_all->fetchAll(PDO::FETCH_COLUMN);
    $full_sn_text = implode(', ', $all_sns);

    $count_sn = count($all_sns);
    $p['sn_text'] = '';
    if ($count_sn > 0) {
        $slice = array_slice($all_sns, 0, 3);
        $p['sn_text'] = implode(', ', $slice);
        if ($count_sn > 3) $p['sn_text'] .= " ... (+$count_sn)";
    } else {
        $p['sn_text'] = '-';
    }

    // 2. Get Warehouse
    $wh_stmt = $pdo->prepare("SELECT w.name FROM inventory_transactions i JOIN warehouses w ON i.warehouse_id=w.id WHERE i.product_id=? ORDER BY i.date DESC LIMIT 1");
    $wh_stmt->execute([$p['id']]);
    $last_wh = $wh_stmt->fetchColumn() ?: '-';
    $p['last_wh'] = $last_wh;

    // 3. Export Data
    $export_data[] = [
        'Nama' => $p['name'],
        'SKU' => $p['sku'],
        'Beli (HPP)' => $p['buy_price'],
        'Gudang' => $last_wh,
        'Jual' => $p['sell_price'],
        'Stok' => $p['stock'],
        'Sat' => $p['unit'],
        'SN' => $full_sn_text
    ];

    $total_asset_group += ($p['stock'] * $p['buy_price']);
}
unset($p);

// Data Edit
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
    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
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
                    <option value="<?= $c ?>" <?= $cat_filter==$c?'selected':'' ?>>
                        <?= $c ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- NEW WAREHOUSE FILTER -->
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Wilayah / Gudang</label>
            <select name="warehouse" class="w-full border p-2 rounded text-sm bg-white font-medium text-blue-800">
                <option value="ALL">-- Semua --</option>
                <?php foreach($warehouses as $w): ?>
                    <option value="<?= $w['id'] ?>" <?= $wh_filter == $w['id'] ? 'selected' : '' ?>>
                        <?= $w['name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Urutkan (Sort)</label>
            <select name="sort" class="w-full border p-2 rounded text-sm bg-white">
                <option value="newest" <?= $sort_by=='newest'?'selected':'' ?>>Update Terbaru</option>
                <option value="name_asc" <?= $sort_by=='name_asc'?'selected':'' ?>>Nama (A-Z)</option>
                <option value="stock_high" <?= $sort_by=='stock_high'?'selected':'' ?>>Stok Terbanyak</option>
            </select>
        </div>
        <div class="lg:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Update Terakhir</label>
            <div class="flex gap-1">
                <input type="date" name="start" value="<?= $start_date ?>" class="border p-2 rounded text-xs w-full">
                <!-- <input type="date" name="end" value="<?= $end_date ?>" class="border p-2 rounded text-xs w-full"> -->
            </div>
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
    <div class="lg:col-span-1 no-print">
        <div class="bg-white p-6 rounded-lg shadow sticky top-6">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2 flex justify-between items-center">
                <span><i class="fas fa-edit"></i> <?= $edit_item ? 'Edit Barang' : 'Tambah Barang' ?></span>
            </h3>
            
            <!-- SCANNER BUTTONS -->
            <div class="flex gap-2 mb-4">
                <button type="button" onclick="activateUSB()" class="flex-1 bg-gray-700 text-white py-1 px-2 rounded text-xs font-bold hover:bg-gray-800" title="Scanner USB">
                    <i class="fas fa-keyboard"></i> USB
                </button>
                <input type="file" id="scan_image_file" accept="image/*" class="hidden" onchange="handleFileScan(this)">
                <button type="button" onclick="document.getElementById('scan_image_file').click()" class="flex-1 bg-purple-600 text-white py-1 px-2 rounded text-xs font-bold hover:bg-purple-700">
                    <i class="fas fa-camera"></i> Foto
                </button>
                <button type="button" onclick="initCamera()" class="flex-1 bg-indigo-600 text-white py-1 px-2 rounded text-xs font-bold hover:bg-indigo-700">
                    <i class="fas fa-video"></i> Live
                </button>
            </div>

            <!-- SCANNER AREA -->
            <div id="scanner_area" class="hidden mb-4 bg-black rounded-lg relative overflow-hidden shadow-inner border-4 border-gray-800">
                <div class="absolute top-2 left-2 z-20">
                    <select id="camera_select" class="bg-white text-gray-800 text-xs py-1 px-2 rounded shadow border border-gray-300 focus:outline-none"><option value="">Memuat Kamera...</option></select>
                </div>
                <div id="reader" class="w-full h-48 bg-black"></div>
                <div class="absolute top-2 right-2 z-10">
                    <button type="button" onclick="stopScan()" class="bg-red-600 text-white w-8 h-8 rounded-full shadow hover:bg-red-700 flex items-center justify-center"><i class="fas fa-times"></i></button>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="save_product" value="1">
                <?php if($edit_item): ?>
                    <input type="hidden" name="edit_id" value="<?= $edit_item['id'] ?>">
                    <input type="hidden" id="edit_mode_flag" value="1">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">SKU (Barcode)</label>
                    <div class="flex gap-1">
                        <input type="text" name="sku" id="form_sku" value="<?= $edit_item['sku']??'' ?>" class="w-full border p-2 rounded text-sm uppercase font-mono font-bold text-blue-700 focus:ring-2 focus:ring-blue-500" placeholder="SCAN/GENERATE..." required>
                        <button type="button" onclick="generateSku()" class="bg-yellow-500 text-white px-2 rounded hover:bg-yellow-600" title="Generate Auto SKU"><i class="fas fa-bolt"></i></button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Nama Barang</label>
                    <input type="text" name="name" value="<?= $edit_item['name']??'' ?>" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-blue-500" required>
                </div>

                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Harga Beli (HPP)</label>
                        <input type="text" name="buy_price" value="<?= isset($edit_item['buy_price']) ? number_format($edit_item['buy_price'],0,',','.') : '' ?>" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right font-mono" placeholder="0">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Harga Jual</label>
                        <input type="text" name="sell_price" value="<?= isset($edit_item['sell_price']) ? number_format($edit_item['sell_price'],0,',','.') : '' ?>" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right font-mono" placeholder="0">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Kategori (Akun)</label>
                        <!-- Kategori Dropdown selalu aktif -->
                        <select name="category" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-blue-500">
                            <?php foreach($category_accounts as $acc): ?>
                                <option value="<?= $acc['code'] ?>" <?= ($edit_item && $edit_item['category'] == $acc['code']) ? 'selected' : '' ?>>
                                    <?= $acc['code'] ?> - <?= $acc['name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Satuan</label>
                        <input type="text" name="unit" value="<?= $edit_item['unit']??'Pcs' ?>" class="w-full border p-2 rounded text-sm">
                    </div>
                </div>

                <div class="mb-3 bg-gray-50 p-3 rounded border border-gray-200">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Stok <?= $edit_item ? ' (Edit = Tambah Stok)' : ' Awal' ?></label>
                    <div class="grid grid-cols-2 gap-2">
                        <!-- Stok bisa diedit sekarang -->
                        <input type="number" name="initial_stock" id="init_qty" 
                               value="<?= $edit_item['stock']??0 ?>" 
                               data-original="<?= $edit_item['stock']??0 ?>"
                               class="w-full border p-2 rounded text-sm text-center font-bold" 
                               onkeyup="toggleSnInput()" onchange="toggleSnInput()">
                        
                        <select name="warehouse_id" id="init_wh" class="w-full border p-2 rounded text-sm text-gray-700" <?= $edit_item ? 'disabled' : '' ?>>
                            <option value="">-- Lokasi --</option>
                            <?php foreach($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if($edit_item): ?>
                        <p class="text-[9px] text-blue-600 mt-1" id="stock_hint">Ubah angka untuk menambah stok.</p>
                    <?php endif; ?>
                </div>

                <div id="sn_area" class="mb-3 <?= $edit_item ? 'hidden' : '' ?>">
                    <label class="block text-xs font-bold text-purple-700 mb-1">Serial Number (Pisahkan Koma)</label>
                    <textarea name="sn_list_text" id="sn_list_input" class="w-full border p-2 rounded text-xs font-mono uppercase h-20 bg-purple-50" placeholder="SN1, SN2, SN3..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Gambar</label>
                    <?php if(!empty($edit_item['image_url'])): ?>
                        <img src="<?= $edit_item['image_url'] ?>" class="h-16 w-auto border mb-2 rounded">
                    <?php endif; ?>
                    <input type="file" name="image" class="w-full border p-1 rounded text-xs">
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700 text-sm shadow">Simpan</button>
                    <a href="?page=data_barang" class="flex-1 bg-gray-200 text-gray-700 py-2 rounded font-bold hover:bg-gray-300 text-sm text-center">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- RIGHT: TABLE (3 COLS) -->
    <div class="lg:col-span-3">
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <!-- Header Merah -->
            <div class="bg-red-300 px-4 py-3 border-b border-red-400">
                <h3 class="font-bold text-red-900 text-sm">
                    Periode <?= date('F Y') ?> (Total Aset Group: <?= formatRupiah($total_asset_group) ?>)
                </h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left border-collapse border border-gray-200">
                    <!-- Header Kuning -->
                    <thead class="bg-yellow-400 text-gray-900 font-bold uppercase text-center">
                        <tr>
                            <th class="p-2 border border-gray-300 w-10">Gbr</th>
                            <th class="p-2 border border-gray-300">Nama</th>
                            <th class="p-2 border border-gray-300">SKU</th>
                            <th class="p-2 border border-gray-300 w-32">SN</th>
                            <th class="p-2 border border-gray-300 text-right">Beli (HPP)</th>
                            <th class="p-2 border border-gray-300 w-24">Gudang</th>
                            <th class="p-2 border border-gray-300 text-right">Jual</th>
                            <th class="p-2 border border-gray-300 w-16">Stok</th>
                            <th class="p-2 border border-gray-300 w-10">Sat</th>
                            <th class="p-2 border border-gray-300">Catatan / Ket</th>
                            <th class="p-2 border border-gray-300 w-20">Update</th>
                            <th class="p-2 border border-gray-300 text-center w-24">Ref / Cetak</th>
                            <th class="p-2 border border-gray-300 text-center w-20">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($products as $p): ?>
                        <tr class="hover:bg-gray-50 group">
                            <!-- Gbr -->
                            <td class="p-2 border text-center">
                                <?php if(!empty($p['image_url'])): ?>
                                    <img src="<?= $p['image_url'] ?>" class="w-8 h-8 object-cover rounded border bg-white mx-auto cursor-pointer" onclick="window.open(this.src)">
                                <?php else: ?>
                                    <div class="w-8 h-8 bg-gray-100 rounded flex items-center justify-center text-gray-300 mx-auto"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Nama -->
                            <td class="p-2 border font-bold text-gray-700"><?= htmlspecialchars($p['name']) ?></td>

                            <!-- SKU -->
                            <td class="p-2 border font-mono text-blue-600 whitespace-nowrap"><?= htmlspecialchars($p['sku']) ?></td>
                            
                            <!-- SN -->
                            <td class="p-2 border text-[9px] font-mono break-all text-gray-600 bg-gray-50">
                                <?= $p['sn_text'] ?>
                            </td>
                            
                            <!-- Beli -->
                            <td class="p-2 border text-right text-red-600 font-medium"><?= formatRupiah($p['buy_price']) ?></td>
                            
                            <!-- Gudang -->
                            <td class="p-2 border text-center text-[10px]"><?= $p['last_wh'] ?></td>
                            
                            <!-- Jual -->
                            <td class="p-2 border text-right text-green-600 font-bold"><?= formatRupiah($p['sell_price']) ?></td>
                            
                            <!-- Stok -->
                            <td class="p-2 border text-center font-bold text-lg <?= $p['stock'] < 10 ? 'text-red-600 bg-red-50' : 'text-blue-800 bg-blue-50' ?>">
                                <?= number_format($p['stock']) ?>
                            </td>
                            
                            <!-- Sat -->
                            <td class="p-2 border text-center"><?= htmlspecialchars($p['unit']) ?></td>
                            
                            <!-- Catatan/Ket (Category) MOVED HERE -->
                            <td class="p-2 border text-[10px] text-gray-500 text-center"><?= htmlspecialchars($p['category']) ?></td>
                            
                            <!-- Update -->
                            <td class="p-2 border text-center text-[9px] text-gray-500">
                                <?= $p['last_update'] ? date('d/m/y', strtotime($p['last_update'])) : '-' ?>
                            </td>
                            
                            <!-- Ref / Cetak -->
                            <td class="p-2 border text-center">
                                <div class="text-[9px] text-blue-500 font-mono mb-1 font-bold"><?= $p['last_ref'] ?? '-' ?></div>
                                <div class="flex gap-1 justify-center">
                                    <?php if(!empty($p['last_trx_id'])): ?>
                                        <a href="?page=cetak_surat_jalan&id=<?= $p['last_trx_id'] ?>" target="_blank" class="bg-blue-100 hover:bg-blue-200 text-blue-700 px-2 py-1 rounded text-[9px] border border-blue-300 font-bold" title="Cetak Surat Jalan Transaksi Terakhir">
                                            <i class="fas fa-print"></i> SJ
                                        </a>
                                    <?php endif; ?>
                                    <button onclick="printLabelDirect('<?= h($p['sku']) ?>', '<?= h($p['name']) ?>')" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-2 py-1 rounded text-[9px] border border-gray-300 font-bold" title="Cetak Label Barcode">
                                        <i class="fas fa-barcode"></i> LBL
                                    </button>
                                </div>
                            </td>
                            
                            <!-- Aksi -->
                            <td class="p-2 border text-center">
                                <div class="flex justify-center gap-1">
                                    <a href="?page=data_barang&edit_id=<?= $p['id'] ?>" class="bg-yellow-100 text-yellow-700 p-1 rounded hover:bg-yellow-200" title="Edit">
                                        <i class="fas fa-pencil-alt"></i>
                                    </a>
                                    <form method="POST" onsubmit="return confirm('Hapus barang <?= $p['name'] ?>? Data transaksi terkait akan error jika tidak dibersihkan manual.')" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                        <button class="bg-red-100 text-red-700 p-1 rounded hover:bg-red-200" title="Hapus"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($products)): ?>
                            <tr><td colspan="13" class="p-8 text-center text-gray-400">Tidak ada data barang.</td></tr>
                        <?php endif; ?>
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
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-green-700 flex items-center gap-2">
            <i class="fas fa-file-excel"></i> Import Data Barang
        </h3>
        <div class="text-sm text-gray-600 mb-4 bg-yellow-50 p-3 rounded border border-yellow-200">
            <p class="font-bold mb-1">Panduan Import:</p>
            <ul class="list-disc ml-4">
                <li>Gunakan <b>Export Excel</b> untuk mendapatkan template.</li>
                <li>Kolom Wajib: <b>Nama, SKU, Stok, Beli (HPP)</b>.</li>
                <li>Kolom <b>SN</b> pisahkan dengan koma (cth: SN1, SN2).</li>
                <li>Data SKU yang sama akan <b>diupdate</b> (Nama/Harga).</li>
                <li>Stok hanya diisi untuk <b>Barang Baru</b>.</li>
            </ul>
        </div>
        
        <form id="form_import" method="POST">
            <?= csrf_field() ?> <!-- Fix: Added CSRF Token -->
            <input type="hidden" name="import_products" value="1">
            <input type="hidden" name="import_json" id="import_json_data">
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Pilih File Excel (.xlsx)</label>
                <input type="file" id="file_excel" accept=".xlsx, .xls" class="w-full border p-2 rounded text-sm bg-gray-50">
            </div>
            
            <button type="button" onclick="processImport()" class="w-full bg-green-600 text-white py-2 rounded font-bold hover:bg-green-700 shadow">
                Upload & Proses
            </button>
        </form>
    </div>
</div>

<script>
let html5QrCode;
let audioCtx = null;

// --- SCANNER FUNCTIONS ---
function initAudio() { if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)(); if (audioCtx.state === 'suspended') audioCtx.resume(); }
function playBeep() { if (!audioCtx) return; try { const o = audioCtx.createOscillator(); const g = audioCtx.createGain(); o.connect(g); g.connect(audioCtx.destination); o.type = 'square'; o.frequency.setValueAtTime(1200, audioCtx.currentTime); g.gain.setValueAtTime(0.1, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.1); o.start(); o.stop(audioCtx.currentTime + 0.15); } catch (e) {} }

function activateUSB() { 
    const input = document.getElementById('form_sku'); 
    input.focus(); input.select(); 
    input.classList.add('ring-4', 'ring-yellow-400', 'border-yellow-500'); 
    setTimeout(() => { input.classList.remove('ring-4', 'ring-yellow-400', 'border-yellow-500'); }, 1500); 
}

function handleFileScan(input) { 
    if (input.files.length === 0) return; 
    const file = input.files[0]; 
    initAudio(); 
    const i = document.getElementById('form_sku'); 
    const p = i.placeholder; 
    i.value = ""; i.placeholder = "Processing..."; 
    const h = new Html5Qrcode("reader"); 
    h.scanFile(file, true).then(d => { playBeep(); i.value = d; i.placeholder = p; input.value = ''; }).catch(e => { alert("Gagal baca."); i.placeholder = p; input.value = ''; }); 
}

async function initCamera() { 
    initAudio(); 
    document.getElementById('scanner_area').classList.remove('hidden'); 
    try { 
        await navigator.mediaDevices.getUserMedia({ video: true }); 
        const d = await Html5Qrcode.getCameras(); 
        const s = document.getElementById('camera_select'); s.innerHTML = ""; 
        if (d && d.length) { 
            let id = d[0].id; 
            d.forEach(dev => { if(dev.label.toLowerCase().includes('back')) id = dev.id; const o = document.createElement("option"); o.value = dev.id; o.text = dev.label; s.appendChild(o); }); 
            s.value = id; startScan(id); 
            s.onchange = () => { if(html5QrCode) html5QrCode.stop().then(() => startScan(s.value)); }; 
        } else { alert("Kamera tidak ditemukan."); } 
    } catch (e) { alert("Gagal akses kamera."); document.getElementById('scanner_area').classList.add('hidden'); } 
}

function startScan(id) { 
    if(html5QrCode) try { html5QrCode.stop(); } catch(e) {} 
    html5QrCode = new Html5Qrcode("reader"); 
    const config = { fps: 10, qrbox: { width: 250, height: 250 } }; 
    html5QrCode.start(id, config, (d) => { 
        playBeep(); document.getElementById('form_sku').value = d; stopScan(); 
    }, () => {}); 
}

function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => { document.getElementById('scanner_area').classList.add('hidden'); html5QrCode.clear(); }); }

// --- GENERAL FUNCTIONS ---
async function generateSku() {
    try {
        const res = await fetch('api.php?action=generate_sku');
        const data = await res.json();
        if(data.sku) document.getElementById('form_sku').value = data.sku;
    } catch(e) { alert('Gagal Auto SKU'); }
}

function formatRupiah(input) {
    let value = input.value.replace(/\D/g, '');
    if(value === '') { input.value = ''; return; }
    input.value = new Intl.NumberFormat('id-ID').format(value);
}

function toggleSnInput() {
    const qtyInput = document.getElementById('init_qty');
    const newQty = parseInt(qtyInput.value) || 0;
    const snArea = document.getElementById('sn_area');
    const snText = document.getElementById('sn_list_input');
    const whSelect = document.getElementById('init_wh');
    const stockHint = document.getElementById('stock_hint');
    const editMode = document.getElementById('edit_mode_flag') ? true : false;
    
    let showSn = false;

    if (editMode) {
        // Mode Edit: Cek Selisih Stok
        const originalStock = parseInt(qtyInput.getAttribute('data-original')) || 0;
        const delta = newQty - originalStock;
        
        if (delta > 0) {
            showSn = true;
            snText.placeholder = `Masukkan ${delta} SN baru...`;
            stockHint.innerText = `Menambah ${delta} stok. Wajib input SN & Pilih Gudang.`;
            stockHint.classList.remove('text-blue-600', 'text-red-500');
            stockHint.classList.add('text-green-600', 'font-bold');
            whSelect.removeAttribute('disabled');
        } else if (delta < 0) {
            stockHint.innerText = "PERINGATAN: Pengurangan stok tidak bisa dilakukan di sini. Gunakan Barang Keluar.";
            stockHint.classList.add('text-red-500', 'font-bold');
            whSelect.setAttribute('disabled', 'disabled');
        } else {
            stockHint.innerText = "Ubah angka untuk menambah stok.";
            stockHint.classList.remove('text-red-500', 'font-bold', 'text-green-600');
            stockHint.classList.add('text-blue-600');
            whSelect.setAttribute('disabled', 'disabled');
        }
    } else {
        // Mode Baru
        if (newQty > 0) {
            showSn = true;
            whSelect.removeAttribute('disabled');
        } else {
            whSelect.setAttribute('disabled', 'disabled');
            whSelect.value = "";
        }
    }

    if (showSn) {
        snArea.classList.remove('hidden');
    } else {
        snArea.classList.add('hidden');
    }
}

function printLabelDirect(sku, name) {
    document.getElementById('lbl_name').innerText = name;
    try {
        bwipjs.toCanvas('lbl_barcode_canvas', {
            bcid:        'code128',
            text:        sku,
            scale:       2,
            height:      10,
            includetext: true,
            textxalign:  'center',
        });
        window.print();
    } catch(e) { alert('Gagal render barcode'); }
}

// --- IMPORT EXPORT FUNCTIONS ---
// Data prepared in PHP
const exportData = <?= json_encode($export_data) ?>;

function exportToExcel() {
    if(exportData.length === 0) return alert("Tidak ada data.");
    
    // Create Worksheet from Clean Data
    const ws = XLSX.utils.json_to_sheet(exportData);
    
    // Auto fit column width logic (simple)
    const wscols = [
        {wch:30}, // Nama
        {wch:15}, // SKU
        {wch:15}, // Beli
        {wch:20}, // Gudang
        {wch:15}, // Jual
        {wch:10}, // Stok
        {wch:10}, // Sat
        {wch:30}  // SN
    ];
    ws['!cols'] = wscols;

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Data Barang");
    
    XLSX.writeFile(wb, "Data_Barang_Master_" + new Date().toISOString().slice(0,10) + ".xlsx");
}

function processImport() {
    const fileInput = document.getElementById('file_excel');
    if(!fileInput.files.length) return alert("Pilih file excel dulu!");
    
    const file = fileInput.files[0];
    const reader = new FileReader();
    
    reader.onload = function(e) {
        const data = new Uint8Array(e.target.result);
        const workbook = XLSX.read(data, {type: 'array'});
        // Get First Sheet
        const firstSheetName = workbook.SheetNames[0];
        const worksheet = workbook.Sheets[firstSheetName];
        
        // Convert to JSON
        const jsonData = XLSX.utils.sheet_to_json(worksheet);
        
        if (jsonData.length === 0) {
            alert("File kosong atau format salah.");
            return;
        }

        // Put JSON into hidden input
        document.getElementById('import_json_data').value = JSON.stringify(jsonData);
        
        // Submit Form
        document.getElementById('form_import').submit();
    };
    
    reader.readAsArrayBuffer(file);
}
</script>