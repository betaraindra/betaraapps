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

    // 3. IMPORT DATA BARANG (Existing code...)
}

// --- FILTER & QUERY DATA ---
$start_date = $_GET['start'] ?? date('Y-01-01');
$end_date = $_GET['end'] ?? date('Y-12-31');
$search = $_GET['q'] ?? '';
$cat_filter = $_GET['category'] ?? 'ALL';
$wh_filter = $_GET['warehouse'] ?? 'ALL';
$sort_by = $_GET['sort'] ?? 'newest';

$existing_cats = $pdo->query("SELECT DISTINCT category FROM products ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$sql = "SELECT p.*,
        (SELECT date FROM inventory_transactions WHERE product_id = p.id ORDER BY date DESC LIMIT 1) as last_update,
        (SELECT reference FROM inventory_transactions WHERE product_id = p.id ORDER BY date DESC LIMIT 1) as last_ref,
        (SELECT id FROM inventory_transactions WHERE product_id = p.id ORDER BY date DESC LIMIT 1) as last_trx_id
        FROM products p 
        WHERE (p.name LIKE ? OR p.sku LIKE ? OR p.notes LIKE ?)";
$params = ["%$search%", "%$search%", "%$search%"];

if ($cat_filter !== 'ALL') {
    $sql .= " AND p.category = ?";
    $params[] = $cat_filter;
}
if ($wh_filter !== 'ALL') {
    $sql .= " AND EXISTS (SELECT 1 FROM inventory_transactions it WHERE it.product_id = p.id AND it.warehouse_id = ?)";
    $params[] = $wh_filter;
}

if ($sort_by === 'name_asc') $sql .= " ORDER BY p.name ASC";
elseif ($sort_by === 'stock_high') $sql .= " ORDER BY p.stock DESC";
else $sql .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$export_data = [];
$total_asset_group = 0;

foreach($products as &$p) {
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

    $wh_stmt = $pdo->prepare("SELECT w.name FROM inventory_transactions i JOIN warehouses w ON i.warehouse_id=w.id WHERE i.product_id=? ORDER BY i.date DESC LIMIT 1");
    $wh_stmt->execute([$p['id']]);
    $last_wh = $wh_stmt->fetchColumn() ?: '-';
    $p['last_wh'] = $last_wh;

    $export_data[] = [
        'Nama' => $p['name'],
        'SKU' => $p['sku'],
        'Beli (HPP)' => $p['buy_price'],
        'Gudang' => $last_wh,
        'Jual' => $p['sell_price'],
        'Stok' => $p['stock'],
        'Sat' => $p['unit'],
        'Catatan' => $p['notes'],
        'SN' => $full_sn_text
    ];
    $total_asset_group += ($p['stock'] * $p['buy_price']);
}
unset($p);

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
<!-- (Content same as original, keeping layout structure) -->
<div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4 no-print">
    <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-box text-blue-600"></i> Data Barang</h2>
    <div class="flex gap-2">
        <button onclick="document.getElementById('importModal').classList.remove('hidden')" class="bg-indigo-600 text-white px-3 py-2 rounded text-sm font-bold shadow hover:bg-indigo-700"><i class="fas fa-file-import"></i> Import Excel</button>
        <button onclick="exportToExcel()" class="bg-green-600 text-white px-3 py-2 rounded text-sm font-bold shadow hover:bg-green-700"><i class="fas fa-file-export"></i> Export Excel</button>
    </div>
</div>

<!-- FILTER BAR -->
<div class="bg-white p-4 rounded-lg shadow mb-6 border-l-4 border-indigo-600 no-print">
    <!-- Filter form same as original -->
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
            <div id="scanner_area" class="hidden mb-4 bg-black rounded-lg relative overflow-hidden shadow-inner border-4 border-gray-800 group h-48">
                <div id="reader" class="w-full h-full"></div>
                
                <div class="absolute bottom-4 left-0 right-0 flex justify-center gap-4 z-20">
                    <button type="button" onclick="switchCamera()" class="bg-white/20 backdrop-blur text-white p-3 rounded-full hover:bg-white/40 transition shadow-lg border border-white/30" title="Ganti Kamera">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button type="button" onclick="toggleFlash()" id="btn_flash" class="hidden bg-white/20 backdrop-blur text-white p-3 rounded-full hover:bg-white/40 transition shadow-lg border border-white/30" title="Flash / Senter">
                        <i class="fas fa-bolt"></i>
                    </button>
                </div>

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

                <!-- ... (Input fields lainnya sama) ... -->
                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Nama Barang</label>
                    <input type="text" name="name" id="form_name" value="<?= $edit_item['name']??'' ?>" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-blue-500" required>
                </div>

                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Harga Beli</label>
                        <input type="text" name="buy_price" id="form_buy" value="<?= isset($edit_item['buy_price']) ? number_format($edit_item['buy_price'],0,',','.') : '' ?>" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right font-mono" placeholder="0">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Harga Jual</label>
                        <input type="text" name="sell_price" id="form_sell" value="<?= isset($edit_item['sell_price']) ? number_format($edit_item['sell_price'],0,',','.') : '' ?>" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right font-mono" placeholder="0">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Kategori</label>
                        <select name="category" id="form_category" class="w-full border p-2 rounded text-sm bg-white">
                            <?php foreach($category_accounts as $acc): ?>
                                <option value="<?= $acc['code'] ?>" <?= ($edit_item && $edit_item['category'] == $acc['code']) ? 'selected' : '' ?>><?= $acc['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Satuan</label>
                        <input type="text" name="unit" id="form_unit" value="<?= $edit_item['unit']??'Pcs' ?>" class="w-full border p-2 rounded text-sm">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Catatan</label>
                    <textarea name="notes" id="form_notes" class="w-full border p-2 rounded text-sm" rows="2"><?= htmlspecialchars($edit_item['notes']??'') ?></textarea>
                </div>

                <!-- STOCK & SN SECTION -->
                <div class="mb-3 bg-gray-50 p-3 rounded border border-gray-200">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Stok <?= $edit_item ? ' (Edit = Tambah Stok)' : ' Awal' ?></label>
                    <div class="grid grid-cols-2 gap-2">
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
                    <div class="flex justify-between items-center mb-1">
                        <label class="block text-xs font-bold text-purple-700">List Serial Number</label>
                        <div class="flex gap-1">
                            <button type="button" onclick="openSnAppendScanner()" class="bg-gray-200 text-gray-700 px-2 py-1 rounded text-[10px] font-bold hover:bg-gray-300" title="Scan & Append">
                                <i class="fas fa-camera"></i> Scan
                            </button>
                            <button type="button" onclick="generateBatchSN()" class="bg-yellow-100 text-yellow-700 px-2 py-1 rounded text-[10px] font-bold hover:bg-yellow-200" title="Generate Otomatis">
                                <i class="fas fa-magic"></i> Auto
                            </button>
                        </div>
                    </div>
                    <textarea name="sn_list_text" id="sn_list_input" class="w-full border p-2 rounded text-xs font-mono uppercase h-20 bg-purple-50" placeholder="SN1, SN2, SN3... (Pisahkan Koma)"></textarea>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Gambar</label>
                    <input type="file" name="image" class="w-full border p-1 rounded text-xs">
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700 text-sm shadow">Simpan</button>
                    <a href="?page=data_barang" class="flex-1 bg-gray-200 text-gray-700 py-2 rounded font-bold hover:bg-gray-300 text-sm text-center">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- RIGHT: TABLE (Same as original) -->
    <div class="lg:col-span-3">
        <!-- ... (Tabel Barang, kode sama seperti sebelumnya) ... -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="bg-red-300 px-4 py-3 border-b border-red-400">
                <h3 class="font-bold text-red-900 text-sm">
                    Periode <?= date('F Y') ?> (Total Aset Group: <?= formatRupiah($total_asset_group) ?>)
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left border-collapse border border-gray-200">
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
                            <th class="p-2 border border-gray-300 text-center w-20">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($products as $p): ?>
                        <tr class="hover:bg-gray-50 group">
                            <td class="p-2 border text-center">
                                <?php if(!empty($p['image_url'])): ?>
                                    <img src="<?= $p['image_url'] ?>" class="w-8 h-8 object-cover rounded border bg-white mx-auto">
                                <?php endif; ?>
                            </td>
                            <td class="p-2 border font-bold text-gray-700"><?= htmlspecialchars($p['name']) ?></td>
                            <td class="p-2 border font-mono text-blue-600 whitespace-nowrap"><?= htmlspecialchars($p['sku']) ?></td>
                            <td class="p-2 border text-[9px] font-mono break-all text-gray-600 bg-gray-50"><?= $p['sn_text'] ?></td>
                            <td class="p-2 border text-right text-red-600 font-medium"><?= formatRupiah($p['buy_price']) ?></td>
                            <td class="p-2 border text-center text-[10px]"><?= $p['last_wh'] ?></td>
                            <td class="p-2 border text-right text-green-600 font-bold"><?= formatRupiah($p['sell_price']) ?></td>
                            <td class="p-2 border text-center font-bold text-lg <?= $p['stock'] < 10 ? 'text-red-600 bg-red-50' : 'text-blue-800 bg-blue-50' ?>"><?= number_format($p['stock']) ?></td>
                            <td class="p-2 border text-center"><?= htmlspecialchars($p['unit']) ?></td>
                            <td class="p-2 border text-center">
                                <div class="flex justify-center gap-1">
                                    <a href="?page=data_barang&edit_id=<?= $p['id'] ?>" class="bg-yellow-100 text-yellow-700 p-1 rounded hover:bg-yellow-200" title="Edit"><i class="fas fa-pencil-alt"></i></a>
                                    <form method="POST" onsubmit="return confirm('Hapus barang?')" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                        <button class="bg-red-100 text-red-700 p-1 rounded hover:bg-red-200" title="Hapus"><i class="fas fa-trash"></i></button>
                                    </form>
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

<!-- SN APPEND SCANNER MODAL -->
<div id="sn_append_modal" class="hidden fixed inset-0 bg-black z-[99] flex flex-col">
    <div class="bg-gray-900 p-4 flex justify-between items-center text-white">
        <h3 class="font-bold"><i class="fas fa-plus"></i> Scan SN (Append)</h3>
        <button onclick="stopSnAppendScan()" class="text-red-500 text-xl"><i class="fas fa-times"></i></button>
    </div>
    <div id="sn_append_reader" class="flex-1 bg-black"></div>
    <div class="bg-gray-900 p-4 text-center">
        <p class="text-xs text-gray-400">Scan barcode SN satu per satu. Hasil akan ditambahkan ke list.</p>
    </div>
</div>

<!-- IMPORT MODAL (Include only) -->
<?php include 'views/modal_import.php'; // Simplified if reusing code ?>

<script>
let html5QrCode;
let snAppendQrCode;
let currentFacingMode = "environment";
let isFlashOn = false;

// ... (Existing helper functions: formatRupiah, etc.) ...
function formatRupiah(input) { let value = input.value.replace(/\D/g, ''); if(value === '') { input.value = ''; return; } input.value = new Intl.NumberFormat('id-ID').format(value); }

// --- GENERATE BATCH SN ---
function generateBatchSN() {
    const qtyInput = document.getElementById('init_qty');
    const skuInput = document.getElementById('form_sku');
    const snTextarea = document.getElementById('sn_list_input');
    const editMode = document.getElementById('edit_mode_flag') ? true : false;
    
    let targetQty = parseInt(qtyInput.value) || 0;
    
    if (editMode) {
        const originalStock = parseInt(qtyInput.getAttribute('data-original')) || 0;
        targetQty = targetQty - originalStock; // Generate only for delta
    }
    
    if(targetQty <= 0) {
        alert("Jumlah penambahan stok harus lebih dari 0 untuk generate SN.");
        return;
    }

    const sku = skuInput.value.substring(0, 5) || 'ITM';
    let sns = [];
    const prefix = sku.toUpperCase() + "-" + Date.now().toString().slice(-4);
    
    for(let i=0; i<targetQty; i++) {
        sns.push(prefix + Math.floor(Math.random() * 10000));
    }
    
    // Append or Replace? If textarea empty replace, else append
    let currentVal = snTextarea.value.trim();
    if(currentVal) snTextarea.value = currentVal + ", " + sns.join(', ');
    else snTextarea.value = sns.join(', ');
}

// --- SCAN APPEND SN ---
async function openSnAppendScanner() {
    document.getElementById('sn_append_modal').classList.remove('hidden');
    
    if(snAppendQrCode) { try{ await snAppendQrCode.stop(); snAppendQrCode.clear(); }catch(e){} }
    
    snAppendQrCode = new Html5Qrcode("sn_append_reader");
    try {
        await snAppendQrCode.start(
            { facingMode: "environment" }, 
            { fps: 10, qrbox: { width: 250, height: 250 } }, 
            (txt) => {
                const ta = document.getElementById('sn_list_input');
                let cur = ta.value.trim();
                // Avoid duplicates in textarea visually
                if(!cur.includes(txt)) {
                    ta.value = cur ? (cur + ", " + txt) : txt;
                    // Beep or Visual feedback could be added here
                }
            },
            () => {}
        );
    } catch(e) { alert("Camera Error: "+e); stopSnAppendScan(); }
}

function stopSnAppendScan() {
    if(snAppendQrCode) snAppendQrCode.stop().then(() => {
        document.getElementById('sn_append_modal').classList.add('hidden');
        snAppendQrCode.clear();
    }).catch(() => document.getElementById('sn_append_modal').classList.add('hidden'));
}

// --- MAIN SCANNER FUNCTIONS (Existing) ---
// (Copy scan logic from previous response for main product scan if not present)
// Simplified here for brevity as focus is SN features
async function initCamera() { /* ... existing ... */ }
function toggleSnInput() {
    const qtyInput = document.getElementById('init_qty');
    const newQty = parseInt(qtyInput.value) || 0;
    const snArea = document.getElementById('sn_area');
    const whSelect = document.getElementById('init_wh');
    const stockHint = document.getElementById('stock_hint');
    const editMode = document.getElementById('edit_mode_flag') ? true : false;
    let showSn = false;

    if (editMode) {
        const originalStock = parseInt(qtyInput.getAttribute('data-original')) || 0;
        const delta = newQty - originalStock;
        
        if (delta > 0) {
            showSn = true;
            stockHint.innerText = `Menambah ${delta} stok. Wajib input SN & Pilih Gudang.`;
            stockHint.className = 'text-[9px] text-green-600 font-bold mt-1';
            whSelect.removeAttribute('disabled');
        } else if (delta < 0) {
            stockHint.innerText = "PERINGATAN: Pengurangan stok gunakan menu Barang Keluar.";
            stockHint.className = 'text-[9px] text-red-500 font-bold mt-1';
            whSelect.setAttribute('disabled', 'disabled');
        } else {
            stockHint.innerText = "Ubah angka untuk menambah stok.";
            stockHint.className = 'text-[9px] text-blue-600 mt-1';
            whSelect.setAttribute('disabled', 'disabled');
        }
    } else {
        if (newQty > 0) {
            showSn = true;
            whSelect.removeAttribute('disabled');
        } else {
            whSelect.setAttribute('disabled', 'disabled');
            whSelect.value = "";
        }
    }

    if (showSn) snArea.classList.remove('hidden'); else snArea.classList.add('hidden');
}
</script>
