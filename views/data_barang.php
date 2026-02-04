<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    // 2. IMPORT EXCEL DATA
    if (isset($_POST['import_data'])) {
        $data = json_decode($_POST['import_data'], true);
        $count_insert = 0;
        $count_update = 0;
        
        $pdo->beginTransaction();
        try {
            foreach($data as $row) {
                // Mapping Kolom
                $sku = trim(strval($row['SKU'] ?? $row['sku'] ?? ''));
                $name = trim(strval($row['Nama'] ?? $row['Nama Barang'] ?? $row['nama'] ?? '')); 
                $cat = trim(strval($row['Kategori'] ?? $row['kategori'] ?? 'Umum')); 
                $unit = trim(strval($row['Sat'] ?? $row['Satuan'] ?? 'Pcs')); 
                
                $buyRaw = $row['Beli (HPP)'] ?? $row['Harga Beli'] ?? 0;
                $sellRaw = $row['Jual'] ?? $row['Harga Jual'] ?? 0;
                $stockRaw = $row['Stok'] ?? $row['stok'] ?? 0;

                // FORCE SN = 1
                $has_sn = 1;

                $buy = cleanNumber($buyRaw);
                $sell = cleanNumber($sellRaw);
                $stock = (int)cleanNumber($stockRaw);
                
                if(empty($sku) || empty($name)) continue;

                $check = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
                $check->execute([$sku]);
                $existing = $check->fetch();

                if($existing) {
                    $stmt = $pdo->prepare("UPDATE products SET name=?, category=?, unit=?, buy_price=?, sell_price=?, stock=?, has_serial_number=? WHERE id=?");
                    $stmt->execute([$name, $cat, $unit, $buy, $sell, $stock, $has_sn, $existing['id']]);
                    $count_update++;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$sku, $name, $cat, $unit, $buy, $sell, $stock, $has_sn]);
                    $count_insert++;
                }
            }
            
            $pdo->commit();
            $msg = "Proses Selesai! Ditambahkan: $count_insert, Diperbarui: $count_update item.";
            logActivity($pdo, 'IMPORT_BARANG', "Import Excel: +$count_insert, ~$count_update");
            $_SESSION['flash'] = ['type'=>'success', 'message'=>$msg];
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal Import: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }

    // 3. SAVE PRODUCT (ADD/EDIT)
    if (isset($_POST['save_product'])) {
        $id = isset($_POST['id']) ? trim($_POST['id']) : ''; 
        
        $sku = trim($_POST['sku']);
        $name = trim($_POST['name']);
        $cat = $_POST['category'];
        $unit = trim($_POST['unit']);
        $buy = cleanNumber($_POST['buy_price']);
        $sell = cleanNumber($_POST['sell_price']);
        $stock = (int)$_POST['stock'];
        $image_url = $_POST['old_image'] ?? '';
        $wh_id = $_POST['warehouse_id'] ?? ''; 
        
        // FORCE ALWAYS 1 (WAJIB SN)
        $has_sn = 1;
        
        $sn_list = $_POST['sn_list'] ?? [];
        $sn_list = array_filter($sn_list, function($v) { return !empty(trim($v)); });

        // SECURE FILE UPLOAD
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowed_types) && $_FILES['image']['size'] <= $max_size) {
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $filename = "prod_" . bin2hex(random_bytes(8)) . "." . $ext;
                $upload_dir = 'uploads/products/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename)) {
                    if (!empty($image_url) && file_exists($image_url)) unlink($image_url);
                    $image_url = $upload_dir . $filename;
                }
            }
        }

        $pdo->beginTransaction();

        try {
            if (!empty($id)) {
                // --- UPDATE PRODUCT ---
                $stmtOld = $pdo->prepare("SELECT stock, has_serial_number FROM products WHERE id=?");
                $stmtOld->execute([$id]);
                $old_data = $stmtOld->fetch();
                
                if (!$old_data) throw new Exception("Data barang tidak ditemukan di database.");
                
                $old_stock = (int)$old_data['stock'];
                $old_has_sn = (int)$old_data['has_serial_number'];
                
                $stock_diff = $stock - $old_stock;

                // VALIDASI: Jika stok berubah, Gudang WAJIB dipilih
                if ($stock_diff != 0 && empty($wh_id)) {
                    throw new Exception("GAGAL UPDATE: Stok berubah (Selisih: $stock_diff) tetapi Gudang belum dipilih.");
                }
                
                // VALIDASI SN SAAT EDIT (WAJIB SN)
                $required_sn_count = 0;
                
                if ($old_has_sn == 0) {
                    // Konversi dari Non-SN ke SN: Harus input SN untuk SEMUA stok yang ada
                    $required_sn_count = $stock; 
                } elseif ($stock_diff > 0) {
                    // Penambahan stok: Input SN untuk selisihnya
                    $required_sn_count = $stock_diff; 
                } elseif ($stock_diff < 0) {
                    // Pengurangan stok manual dilarang untuk barang SN
                    throw new Exception("GAGAL UPDATE: Pengurangan stok barang Serial Number HARUS melalui menu 'Barang Keluar'.");
                }
                
                if (count($sn_list) < $required_sn_count) {
                    throw new Exception("GAGAL UPDATE: Input SN kurang ($required_sn_count SN dibutuhkan). Silakan input Serial Number.");
                }

                $stmt = $pdo->prepare("UPDATE products SET sku=?, name=?, category=?, unit=?, buy_price=?, sell_price=?, stock=?, image_url=?, has_serial_number=? WHERE id=?");
                $stmt->execute([$sku, $name, $cat, $unit, $buy, $sell, $stock, $image_url, $has_sn, $id]);
                
                // TRANSAKSI KOREKSI STOK / KONVERSI SN
                if ($required_sn_count > 0) {
                    // Jika konversi (old=0, new=1), insert semua SN
                    // Jika penambahan (diff > 0), insert SN baru
                    // Kita perlu trx_id jika ini penambahan stok
                    
                    $trx_id = null;
                    if ($stock_diff > 0) {
                        $ref = 'ADJ-IN-' . date('ymdHi');
                        $note = 'Koreksi Stok (Edit Data)';
                        $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, ?, ?)")
                            ->execute([$id, $wh_id, $stock_diff, $ref, $note, $_SESSION['user_id']]);
                        $trx_id = $pdo->lastInsertId();
                    } 
                    
                    // Insert SNs
                    $stmtSN = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    
                    // Gunakan warehouse input atau default warehouse jika konversi stok lama
                    $target_wh = !empty($wh_id) ? $wh_id : ($pdo->query("SELECT id FROM warehouses LIMIT 1")->fetchColumn());

                    foreach($sn_list as $sn_code) {
                        // Cek Duplikat
                        $chk = $pdo->prepare("SELECT id FROM product_serials WHERE product_id=? AND serial_number=?");
                        $chk->execute([$id, $sn_code]);
                        if($chk->rowCount() == 0) {
                            $stmtSN->execute([$id, $sn_code, $target_wh, $trx_id]);
                        }
                    }
                }

                logActivity($pdo, 'UPDATE_BARANG', "Update barang: $name ($sku)");
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data Barang Berhasil Diperbarui'];
            
            } else {
                // --- ADD NEW PRODUCT ---
                $check = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
                $check->execute([$sku]);
                if($check->rowCount() > 0) throw new Exception("SKU $sku sudah ada!");

                if ($stock > 0 && empty($wh_id)) {
                    throw new Exception("Stok awal diisi ($stock). Harap pilih Gudang/Wilayah penyimpanan.");
                }
                
                // WAJIB SN MATCH STOCK
                if ($stock > 0) {
                    if (count($sn_list) != $stock) {
                        throw new Exception("Jumlah Serial Number tidak sesuai stok awal. Masukkan $stock Serial Number.");
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, image_url, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sku, $name, $cat, $unit, $buy, $sell, $stock, $image_url, $has_sn]);
                $new_id = $pdo->lastInsertId();

                if ($stock > 0) {
                    $ref = 'INIT-' . date('ymdHi');
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, 'Stok Awal Barang Baru', ?)")
                        ->execute([$new_id, $wh_id, $stock, $ref, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();
                    
                    $stmtSN = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach($sn_list as $sn_code) {
                        $stmtSN->execute([$new_id, $sn_code, $wh_id, $trx_id]);
                    }
                }

                logActivity($pdo, 'ADD_BARANG', "Tambah barang baru: $name ($sku)");
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang Baru Ditambahkan'];
            }
            
            $pdo->commit();
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>$e->getMessage()];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }

    // 4. DELETE PRODUCT (UPDATED: Cascade Delete to Inventory, SN, and Finance)
    if (isset($_POST['delete_id'])) {
        $del_id = (int)$_POST['delete_id'];
        
        $pdo->beginTransaction();
        try {
            // 1. Ambil Data Produk
            $stmt = $pdo->prepare("SELECT name, sku, image_url FROM products WHERE id = ?");
            $stmt->execute([$del_id]);
            $prod = $stmt->fetch();
            
            if (!$prod) throw new Exception("Barang tidak ditemukan.");
            
            $prodName = $prod['name'];
            $prodSku = $prod['sku'];
            $img = $prod['image_url'];

            // 2. Hapus Serial Numbers
            $pdo->prepare("DELETE FROM product_serials WHERE product_id = ?")->execute([$del_id]);

            // 3. Hapus Transaksi Inventori
            $pdo->prepare("DELETE FROM inventory_transactions WHERE product_id = ?")->execute([$del_id]);

            // 4. Hapus Transaksi Keuangan yang terkait (Berdasarkan kemunculan SKU/Nama di deskripsi)
            // Note: Ini dilakukan agar data keuangan bersih jika barang master dihapus (permintaan user).
            // PENTING: Menggunakan SKU sebagai kunci pencarian yang unik.
            if (!empty($prodSku)) {
                $pdo->prepare("DELETE FROM finance_transactions WHERE description LIKE ? OR description LIKE ?")
                    ->execute(["%$prodSku%", "%$prodName%"]);
            }

            // 5. Hapus Produk Utama
            $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$del_id]);
            
            // 6. Hapus Gambar jika ada
            if (!empty($img) && file_exists($img)) {
                @unlink($img);
            }

            logActivity($pdo, 'DELETE_BARANG', "Hapus barang & data terkait (Keuangan/Inv): $prodName ($prodSku)");
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang, riwayat inventori, dan transaksi keuangannya berhasil dihapus.'];
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal hapus: ' . $e->getMessage()];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }
}

// --- GET MASTER DATA ---
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$category_accounts = $pdo->query("SELECT * FROM accounts WHERE code IN ('3003', '2005', '2105') ORDER BY code ASC")->fetchAll();
$existing_categories = $pdo->query("SELECT DISTINCT p.category, a.name FROM products p LEFT JOIN accounts a ON p.category = a.code ORDER BY p.category ASC")->fetchAll();

// --- FILTER PARAMETERS ---
$search = $_GET['q'] ?? '';
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');
$warehouse_filter = $_GET['warehouse_id'] ?? 'ALL';
$cat_filter = $_GET['category'] ?? 'ALL';
$stock_status = $_GET['stock_status'] ?? 'ALL';
$sort_by = $_GET['sort'] ?? 'newest';

// --- BUILD QUERY ---
$sql = "SELECT p.*, 
        it.id as trx_id, 
        COALESCE(it.reference, '-') as reference, 
        COALESCE(it.date, DATE(p.created_at)) as trx_date, 
        COALESCE(it.notes, '') as trx_notes, 
        it.warehouse_id as current_wh_id, 
        w.name as warehouse_name 
        FROM products p 
        LEFT JOIN (
            SELECT id, product_id, reference, date, warehouse_id, notes 
            FROM inventory_transactions 
            WHERE id IN (
                SELECT MAX(id) FROM inventory_transactions GROUP BY product_id
            )
        ) it ON p.id = it.product_id 
        LEFT JOIN warehouses w ON it.warehouse_id = w.id 
        WHERE (p.name LIKE ? OR p.sku LIKE ? OR it.reference LIKE ? OR it.notes LIKE ?) 
        AND (COALESCE(it.date, DATE(p.created_at)) BETWEEN ? AND ?)";

$params = ["%$search%", "%$search%", "%$search%", "%$search%", $start_date, $end_date];

if ($warehouse_filter !== 'ALL') { $sql .= " AND it.warehouse_id = ?"; $params[] = $warehouse_filter; }
if ($cat_filter !== 'ALL') { $sql .= " AND p.category = ?"; $params[] = $cat_filter; }
if ($stock_status === 'LOW') $sql .= " AND p.stock < 10 AND p.stock > 0";
elseif ($stock_status === 'EMPTY') $sql .= " AND p.stock = 0";
elseif ($stock_status === 'AVAILABLE') $sql .= " AND p.stock > 0";

if ($sort_by === 'name_asc') $sql .= " ORDER BY p.name ASC";
elseif ($sort_by === 'name_desc') $sql .= " ORDER BY p.name DESC";
elseif ($sort_by === 'stock_high') $sql .= " ORDER BY p.stock DESC";
elseif ($sort_by === 'stock_low') $sql .= " ORDER BY p.stock ASC";
else $sql .= " ORDER BY trx_date DESC, p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// --- EXPORT DATA PREPARATION ---
$export_data_js = [];
foreach($products as $p) {
    $export_data_js[] = [
        'Nama' => $p['name'], 'SKU' => $p['sku'], 'SN' => $p['has_serial_number']?'YA':'TIDAK',
        'Beli (HPP)' => (float)$p['buy_price'], 'Gudang' => $p['warehouse_name']??'-',
        'Jual' => (float)$p['sell_price'], 'Stok' => (int)$p['stock'], 'Sat' => $p['unit']
    ];
}

$grouped_products = [];
foreach($products as $p) {
    $group_key = in_array($sort_by, ['newest']) ? "Periode " . date('F Y', strtotime($p['trx_date'])) : "Semua Barang";
    $grouped_products[$group_key]['items'][] = $p;
    if(!isset($grouped_products[$group_key]['total'])) $grouped_products[$group_key]['total'] = 0;
    $grouped_products[$group_key]['total'] += $p['buy_price'];
}
?>

<!-- JS Dependencies -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bwip-js/3.4.4/bwip-js-min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>

<!-- FLASH MESSAGE AREA -->
<?php if(isset($_SESSION['flash'])): ?>
<div class="mb-4 p-4 rounded-lg text-sm font-bold flex items-center justify-between no-print <?= $_SESSION['flash']['type'] == 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200' ?>">
    <div class="flex items-center gap-2">
        <i class="fas <?= $_SESSION['flash']['type'] == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
        <span><?= $_SESSION['flash']['message'] ?></span>
    </div>
    <button onclick="this.parentElement.remove()" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
</div>
<?php unset($_SESSION['flash']); endif; ?>

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

<div id="label_print_area">
    <div class="label-box">
        <canvas id="lbl_barcode_canvas"></canvas>
        <div class="font-bold text-[8px] leading-tight mt-1 truncate w-full px-1" id="lbl_name">NAMA BARANG</div>
    </div>
</div>

<!-- HEADER & BUTTONS -->
<div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4 no-print">
    <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-box text-blue-600"></i> Data Barang</h2>
    <div class="flex gap-2">
        <button onclick="document.getElementById('importModal').classList.remove('hidden')" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold hover:bg-indigo-700 shadow flex items-center gap-2">
            <i class="fas fa-file-import"></i> Import Excel
        </button>
        <button onclick="exportToExcel()" class="bg-green-600 text-white px-4 py-2 rounded font-bold hover:bg-green-700 shadow flex items-center gap-2">
            <i class="fas fa-file-export"></i> Export Excel
        </button>
    </div>
</div>

<!-- FILTER BAR -->
<div class="bg-white p-4 rounded-lg shadow mb-6 border-l-4 border-indigo-600 no-print">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
        <input type="hidden" name="page" value="data_barang">
        <div class="lg:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-indigo-500" placeholder="Nama / SKU / Ref...">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Kategori</label>
            <select name="category" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                <option value="ALL">-- Semua --</option>
                <?php foreach($existing_categories as $ec): ?>
                    <?php $label = $ec['category']; if(!empty($ec['name'])) $label .= " - " . $ec['name']; ?>
                    <option value="<?= $ec['category'] ?>" <?= $cat_filter==$ec['category'] ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Status Stok</label>
            <select name="stock_status" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                <option value="ALL">-- Semua --</option>
                <option value="AVAILABLE" <?= $stock_status=='AVAILABLE' ? 'selected' : '' ?>>Tersedia (> 0)</option>
                <option value="LOW" <?= $stock_status=='LOW' ? 'selected' : '' ?>>Menipis (< 10)</option>
                <option value="EMPTY" <?= $stock_status=='EMPTY' ? 'selected' : '' ?>>Habis (0)</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Urutkan</label>
            <select name="sort" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                <option value="newest" <?= $sort_by=='newest'?'selected':'' ?>>Update Terbaru</option>
                <option value="name_asc" <?= $sort_by=='name_asc'?'selected':'' ?>>Nama (A-Z)</option>
                <option value="stock_high" <?= $sort_by=='stock_high'?'selected':'' ?>>Stok Terbanyak</option>
            </select>
        </div>
        <div class="lg:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Update Terakhir</label>
            <div class="flex gap-1">
                <input type="date" name="start" value="<?= $start_date ?>" class="w-1/2 border p-2 rounded text-xs">
                <input type="date" name="end" value="<?= $end_date ?>" class="w-1/2 border p-2 rounded text-xs">
            </div>
        </div>
        <div>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold hover:bg-indigo-700 w-full shadow text-sm h-[38px]">
                <i class="fas fa-filter"></i> Filter
            </button>
        </div>
    </form>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6 page-content-wrapper">
    <!-- FORM SECTION -->
    <div class="lg:col-span-1 space-y-6 no-print">
        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2 flex justify-between items-center cursor-pointer" onclick="toggleFormMobile()">
                <span><i class="fas fa-plus"></i> Tambah / Edit Barang</span>
                <i id="form_toggle_icon" class="fas fa-chevron-down lg:hidden"></i>
            </h3>
            
            <div id="form_content">
                <!-- SCANNER AREA -->
                <div id="scanner_area" class="hidden mb-4 bg-black rounded-lg relative overflow-hidden shadow-inner border-4 border-gray-800">
                    <div class="absolute top-2 left-2 z-20">
                        <select id="camera_select" class="bg-white text-gray-800 text-xs py-1 px-2 rounded shadow border border-gray-300 focus:outline-none"><option value="">Memuat Kamera...</option></select>
                    </div>
                    <div id="reader" class="w-full h-48 bg-black"></div>
                    <div class="absolute top-2 right-2 z-10">
                        <button onclick="stopScan()" class="bg-red-600 text-white w-8 h-8 rounded-full shadow hover:bg-red-700 flex items-center justify-center"><i class="fas fa-times"></i></button>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="productForm">
                    <?= csrf_field() ?> 
                    <input type="hidden" name="save_product" value="1">
                    <input type="hidden" name="id" id="inp_id">
                    <input type="hidden" name="old_image" id="inp_old_image">
                    
                    <input type="hidden" id="inp_old_stock" value="0">
                    <input type="hidden" id="inp_old_has_sn" value="0">

                    <div class="mb-3">
                        <label class="block text-xs font-bold text-gray-700 mb-1">SKU (Barcode)</label>
                        <input type="file" id="scan_image_file" accept="image/*" capture="environment" class="hidden" onchange="handleFileScan(this)">
                        <div class="flex gap-1 flex-wrap">
                            <input type="text" name="sku" id="inp_sku" class="flex-1 min-w-0 w-full border p-2 rounded text-sm font-mono font-bold uppercase focus:ring-2 focus:ring-blue-500" placeholder="Scan/Generate..." oninput="previewBarcode()" onkeydown="handleEnter(event)" required>
                            <button type="button" onclick="initCamera()" class="bg-indigo-600 text-white px-2 py-2 rounded hover:bg-indigo-700 shadow" title="Live Scan"><i class="fas fa-video"></i></button>
                            <button type="button" onclick="generateSku()" id="btn_generate" class="bg-yellow-500 text-white px-2 py-2 rounded hover:bg-yellow-600 shadow" title="Generate Barcode"><i class="fas fa-magic"></i></button>
                        </div>
                        <div id="barcode_container" class="hidden mt-2 p-2 bg-gray-50 border border-gray-200 rounded text-center">
                            <div class="text-[10px] text-gray-400 font-bold mb-1 uppercase">Preview Barcode</div>
                            <div class="flex justify-center my-2"><canvas id="barcode_canvas"></canvas></div>
                            <button type="button" onclick="printSingleLabel()" class="mt-2 w-full bg-gray-800 text-white text-xs py-1 rounded hover:bg-black transition font-bold"><i class="fas fa-print"></i> Cetak Label 50x30mm</button>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Nama Barang</label>
                        <input type="text" name="name" id="inp_name" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-blue-500" required>
                    </div>

                    <div class="grid grid-cols-2 gap-2 mb-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Harga Beli</label>
                            <input type="text" name="buy_price" id="inp_buy" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right" placeholder="0">
                        </div>
                         <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Harga Jual</label>
                            <input type="text" name="sell_price" id="inp_sell" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right" placeholder="0">
                        </div>
                    </div>
                    
                     <div class="grid grid-cols-2 gap-2 mb-3">
                         <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Kategori (Akun)</label>
                            <select name="category" id="inp_category" class="w-full border p-2 rounded text-sm bg-white">
                                <?php foreach($category_accounts as $acc): ?>
                                    <option value="<?= h($acc['code']) ?>"><?= h($acc['code']) ?> - <?= h($acc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Satuan</label>
                            <input type="text" name="unit" id="inp_unit" class="w-full border p-2 rounded text-sm" placeholder="Pcs">
                        </div>
                     </div>

                    <div class="mb-3">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Stok & Gudang</label>
                        <div class="flex gap-2">
                            <input type="number" name="stock" id="inp_stock" onchange="renderInitialSnInputs()" onkeyup="renderInitialSnInputs()" class="w-1/2 border p-2 rounded text-sm font-bold" placeholder="0">
                            <select name="warehouse_id" id="inp_warehouse" class="w-1/2 border p-2 rounded text-sm bg-white text-gray-700 font-bold focus:ring-2 focus:ring-red-500">
                                <option value="">-- Pilih Gudang --</option>
                                <?php foreach($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="text-[10px] text-red-600 mt-1 font-bold">* WAJIB Pilih Gudang jika mengubah stok.</p>
                    </div>

                    <!-- SN SELALU WAJIB (HIDDEN FLAG) -->
                    <div id="sn_input_area" class="hidden mb-3 bg-white p-2 rounded border-2 border-purple-300 border-dashed">
                        <div class="text-xs font-bold text-purple-700 mb-2 flex justify-between items-center">
                            <span><i class="fas fa-barcode"></i> Input Serial Number (Wajib)</span>
                            <span id="sn_count_badge" class="bg-purple-600 text-white px-2 rounded-full text-[10px]">0</span>
                        </div>
                        <div id="sn_inputs_container" class="grid grid-cols-1 gap-2 max-h-40 overflow-y-auto p-1"></div>
                        <p id="sn_warning" class="text-[10px] text-red-500 mt-2 hidden italic font-bold">
                            * Error: Pengurangan stok harus via Barang Keluar.
                        </p>
                    </div>

                    <div class="mb-3">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Gambar</label>
                        <input type="file" name="image" id="inp_image" class="w-full border p-1 rounded text-xs" accept="image/png, image/jpeg">
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" id="btn_save" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold text-sm shadow hover:bg-blue-700">Simpan</button>
                        <button type="button" onclick="resetForm()" class="px-3 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Reset</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="hidden">
             <form id="form_import" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="import_data" id="import_json_data">
                <input type="file" id="file_excel" accept=".xlsx, .xls" onchange="processImportFile(this)">
            </form>
        </div>
    </div>

    <!-- TABLE DATA -->
    <div class="lg:col-span-3">
        <div id="table_content" class="overflow-x-auto bg-white">
            <table id="products_table" class="w-full text-xs text-left border-collapse border border-gray-400">
                <?php if(empty($grouped_products)): ?>
                    <tr><td class="p-4 text-center text-gray-500 bg-gray-50 border">Data tidak ditemukan.</td></tr>
                <?php else: ?>
                    <?php foreach($grouped_products as $period_label => $group): ?>
                    <tr class="bg-red-300 print:bg-red-200">
                        <td colspan="13" class="p-2 border border-gray-400 font-bold text-red-900 text-sm">
                            <?= h($period_label) ?> (Total Aset Group: <?= formatRupiah($group['total']) ?>)
                        </td>
                    </tr>
                    <tr class="bg-yellow-400 font-bold text-center">
                        <th class="p-2 border">Gbr</th>
                        <th class="p-2 border">Nama</th>
                        <th class="p-2 border">SKU</th>
                        <th class="p-2 border">SN</th>
                        <th class="p-2 border">Beli (HPP)</th>
                        <th class="p-2 border">Gudang</th>
                        <th class="p-2 border">Jual</th>
                        <th class="p-2 border">Stok</th>
                        <th class="p-2 border">Sat</th>
                        <th class="p-2 border">Update</th>
                        <th class="p-2 border">Ket</th>
                        <th class="p-2 border">Ref / Cetak</th> 
                        <th class="p-2 border no-print">Aksi</th>
                    </tr>
                    <?php foreach($group['items'] as $p): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-1 border text-center">
                            <?php if($p['image_url']): ?>
                                <img src="<?= h($p['image_url']) ?>" class="w-8 h-8 object-cover mx-auto">
                            <?php endif; ?>
                        </td>
                        <td class="p-2 border font-bold"><?= h($p['name']) ?></td>
                        <td class="p-2 border font-mono text-center"><?= h($p['sku']) ?></td>
                        <td class="p-2 border text-center">
                            <span class="bg-purple-100 text-purple-800 px-1 rounded font-bold text-[10px]">WAJIB</span>
                        </td>
                        <td class="p-2 border text-right"><?= formatRupiah($p['buy_price']) ?></td>
                        <td class="p-2 border text-center font-bold text-blue-700"><?= h($p['warehouse_name'] ?? '-') ?></td>
                        <td class="p-2 border text-right"><?= formatRupiah($p['sell_price']) ?></td>
                        <td class="p-2 border text-center font-bold"><?= h($p['stock']) ?></td>
                        <td class="p-2 border text-center"><?= h($p['unit']) ?></td>
                        <td class="p-2 border text-center"><?= date('d/m/Y', strtotime($p['trx_date'])) ?></td>
                        <td class="p-2 border"><?= h($p['trx_notes']) ?></td>
                        <td class="p-2 border text-center">
                            <div class="font-mono text-xs font-bold text-blue-600 mb-1"><?= h($p['reference']) ?></div>
                            <div class="flex gap-1 justify-center">
                                <?php if($p['trx_id']): ?>
                                    <a href="?page=cetak_surat_jalan&id=<?= $p['trx_id'] ?>" target="_blank" class="bg-gray-100 px-2 py-1 rounded border hover:bg-gray-200 text-gray-700 text-[10px]" title="SJ"><i class="fas fa-print"></i> SJ</a>
                                <?php endif; ?>
                                <button onclick="printLabelDirect('<?= h($p['sku']) ?>', '<?= h($p['name']) ?>')" class="bg-yellow-100 px-2 py-1 rounded border border-yellow-300 hover:bg-yellow-200 text-yellow-800 text-[10px]"><i class="fas fa-barcode"></i></button>
                            </div>
                        </td>
                        <td class="p-2 border text-center no-print flex justify-center gap-1">
                            <!-- SAFE JSON ENCODE FOR EDIT BUTTON -->
                            <button onclick="editProduct(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)" class="text-blue-600 hover:text-blue-800"><i class="fas fa-edit"></i></button>
                            
                            <form method="POST" onsubmit="return confirm('PERINGATAN: Menghapus barang akan menghapus SEMUA riwayat inventori dan transaksi keuangan yang terkait dengan barang ini. Lanjutkan?')" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                <button class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- IMPORT MODAL -->
<div id="importModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <button onclick="document.getElementById('importModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-green-700">Import Data Barang</h3>
        <p class="text-xs text-gray-500 mb-4 bg-gray-100 p-2 rounded">
            Pastikan format Excel (.xlsx) memiliki kolom: <b>Nama, SKU, Beli (HPP), Jual, Stok, Sat</b>.
        </p>
        <button onclick="document.getElementById('file_excel').click()" class="w-full bg-green-600 text-white py-3 rounded font-bold hover:bg-green-700 shadow gap-2 flex justify-center items-center"><i class="fas fa-upload"></i> Pilih File Excel (.xlsx)</button>
    </div>
</div>

<script>
// EXPORT & IMPORT JS
const productsData = <?= json_encode($export_data_js) ?>;
function exportToExcel() {
    if(productsData.length === 0) return alert("Tidak ada data.");
    const ws = XLSX.utils.json_to_sheet(productsData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Data Barang");
    XLSX.writeFile(wb, "Data_Barang_Export_" + new Date().toISOString().slice(0,10) + ".xlsx");
}
function processImportFile(input) {
    if(!input.files.length) return;
    const file = input.files[0];
    const reader = new FileReader();
    document.getElementById('importModal').classList.add('hidden');
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const jsonData = XLSX.utils.sheet_to_json(firstSheet);
            if(jsonData.length > 0) {
                if(confirm("Ditemukan " + jsonData.length + " baris data. Proses Import?")) {
                    document.getElementById('import_json_data').value = JSON.stringify(jsonData);
                    document.getElementById('form_import').submit();
                }
            } else { alert("File kosong/invalid."); }
        } catch(err) { alert("Error: " + err.message); }
        input.value = '';
    };
    reader.readAsArrayBuffer(file);
}

// MAIN LOGIC FUNCTIONS
let html5QrCode;
let audioCtx = null;

function initAudio() { if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)(); if (audioCtx.state === 'suspended') audioCtx.resume(); }
function playBeep() { if (!audioCtx) return; try { const o = audioCtx.createOscillator(); const g = audioCtx.createGain(); o.connect(g); g.connect(audioCtx.destination); o.type = 'square'; o.frequency.setValueAtTime(1500, audioCtx.currentTime); g.gain.setValueAtTime(0.2, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.1); o.start(); o.stop(audioCtx.currentTime + 0.15); } catch (e) {} }
function formatRupiah(input) { let value = input.value.replace(/\D/g, ''); if(value === '') { input.value = ''; return; } input.value = new Intl.NumberFormat('id-ID').format(value); }
function activateUSB() { const i = document.getElementById('inp_sku'); i.focus(); i.select(); i.classList.add('ring-4', 'ring-yellow-400'); setTimeout(() => i.classList.remove('ring-4', 'ring-yellow-400'), 1500); }
function handleFileScan(input) { if (!input.files.length) return; const file = input.files[0]; initAudio(); const i = document.getElementById('inp_sku'); i.placeholder = "Processing..."; const h = new Html5Qrcode("reader"); h.scanFile(file, true).then(t => { playBeep(); i.value = t; previewBarcode(); input.value = ''; }).catch(err => { alert("Gagal baca."); input.value = ''; }); }
function handleEnter(e) { if(e.key === 'Enter') e.preventDefault(); }
async function generateSku() { try { const r = await fetch('api.php?action=generate_sku'); const d = await r.json(); if(d.sku) { document.getElementById('inp_sku').value = d.sku; previewBarcode(); } } catch(e) { alert("Error gen SKU"); } }
function previewBarcode() { const s = document.getElementById('inp_sku').value.trim(); const c = document.getElementById('barcode_container'); if(s.length > 0) { c.classList.remove('hidden'); try { bwipjs.toCanvas('barcode_canvas', { bcid:'code128', text:s, scale:2, height:10, includetext:true, textxalign:'center' }); } catch(e){ c.classList.add('hidden'); } } else c.classList.add('hidden'); }
function printSingleLabel() { window.print(); }
function printLabelDirect(sku, name) { document.getElementById('lbl_name').innerText = name; try { bwipjs.toCanvas('lbl_barcode_canvas', { bcid:'code128', text:sku, scale:2, height:10, includetext:true, textxalign:'center' }); window.print(); } catch(e) {} }

// SN LOGIC FIX - ALWAYS REQUIRED
function renderInitialSnInputs() {
    const hasSn = true; // Always true
    const newStock = parseInt(document.getElementById('inp_stock').value) || 0;
    const oldStock = parseInt(document.getElementById('inp_old_stock').value) || 0;
    const oldHasSn = document.getElementById('inp_old_has_sn').value == '1';
    
    const container = document.getElementById('sn_input_area');
    const inputsDiv = document.getElementById('sn_inputs_container');
    const warning = document.getElementById('sn_warning');
    const badge = document.getElementById('sn_count_badge');
    const btnSave = document.getElementById('btn_save');
    
    let needed = 0;
    let allowInput = true;

    if (!oldHasSn) {
        // Konversi Barang Biasa -> SN (Baik barang baru atau lama yg belum SN)
        needed = newStock;
    } else {
        // Sudah Barang SN
        if (newStock > oldStock) {
            needed = newStock - oldStock; // Input SN hanya untuk selisih tambahan
        } else if (newStock < oldStock) {
            allowInput = false; // Kurangi stok harus via Barang Keluar
        }
    }

    if (!allowInput) {
        container.classList.remove('hidden');
        inputsDiv.innerHTML = '';
        warning.classList.remove('hidden');
        badge.innerText = "Error";
        btnSave.disabled = true;
        return;
    }

    btnSave.disabled = false;
    warning.classList.add('hidden');

    if (needed > 0) {
        container.classList.remove('hidden');
        badge.innerText = needed + " SN";
        
        const currentInputs = inputsDiv.querySelectorAll('input');
        if (currentInputs.length !== needed) {
            inputsDiv.innerHTML = '';
            for(let i=1; i<=needed; i++) {
                const div = document.createElement('div');
                div.innerHTML = `
                    <div class="relative">
                        <span class="absolute left-2 top-1.5 text-[10px] text-gray-400 font-mono">SN#${i}</span>
                        <input type="text" name="sn_list[]" class="w-full border p-1 pl-8 rounded text-sm font-mono uppercase bg-purple-50 focus:bg-white transition" placeholder="Scan/Ketik SN" required>
                    </div>
                `;
                inputsDiv.appendChild(div);
            }
        }
    } else {
        container.classList.add('hidden');
        inputsDiv.innerHTML = '';
    }
}

function editProduct(p) {
    document.getElementById('inp_id').value = p.id;
    document.getElementById('inp_sku').value = p.sku;
    document.getElementById('inp_name').value = p.name;
    document.getElementById('inp_buy').value = new Intl.NumberFormat('id-ID').format(p.buy_price);
    document.getElementById('inp_sell').value = new Intl.NumberFormat('id-ID').format(p.sell_price);
    document.getElementById('inp_category').value = p.category;
    document.getElementById('inp_unit').value = p.unit;
    document.getElementById('inp_stock').value = p.stock;
    document.getElementById('inp_old_image').value = p.image_url;
    
    // Checkbox dihapus, has_sn selalu true di backend logic
    
    const whSelect = document.getElementById('inp_warehouse');
    const currentWh = p.current_wh_id ? String(p.current_wh_id) : "";
    if (currentWh) {
        whSelect.value = currentWh;
    } else {
        if (whSelect.options.length > 1) whSelect.selectedIndex = 1; 
    }
    
    document.getElementById('inp_old_stock').value = p.stock;
    document.getElementById('inp_old_has_sn').value = p.has_serial_number;

    renderInitialSnInputs();
    previewBarcode();
    const content = document.getElementById('form_content');
    if(content.classList.contains('hidden')) toggleFormMobile();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('productForm').reset();
    document.getElementById('inp_id').value = '';
    document.getElementById('inp_old_image').value = '';
    document.getElementById('inp_old_stock').value = 0;
    document.getElementById('inp_old_has_sn').value = 0;
    document.getElementById('sn_input_area').classList.add('hidden');
    document.getElementById('btn_save').disabled = false;
    document.getElementById('barcode_container').classList.add('hidden');
}

function toggleFormMobile() {
    const c = document.getElementById('form_content');
    const i = document.getElementById('form_toggle_icon');
    c.classList.toggle('hidden');
    i.classList.toggle('fa-chevron-down');
    i.classList.toggle('fa-chevron-up');
}
if(window.innerWidth < 1024) document.getElementById('form_content').classList.add('hidden');

async function initCamera() { initAudio(); document.getElementById('scanner_area').classList.remove('hidden'); try { await navigator.mediaDevices.getUserMedia({ video: true }); const d = await Html5Qrcode.getCameras(); const s = document.getElementById('camera_select'); s.innerHTML = ""; if (d && d.length) { let id = d[0].id; d.forEach(dev => { if(dev.label.toLowerCase().includes('back')) id = dev.id; const o = document.createElement("option"); o.value = dev.id; o.text = dev.label; s.appendChild(o); }); s.value = id; startScan(id); s.onchange = () => { if(html5QrCode) html5QrCode.stop().then(() => startScan(s.value)); }; } else alert("No camera"); } catch (e) { alert("Camera error"); document.getElementById('scanner_area').classList.add('hidden'); } }
function startScan(id) { if(html5QrCode) try{html5QrCode.stop()}catch(e){} html5QrCode = new Html5Qrcode("reader"); html5QrCode.start(id, { fps: 10, qrbox: { width: 250, height: 250 } }, (t) => { document.getElementById('inp_sku').value = t; playBeep(); previewBarcode(); stopScan(); }, () => {}).catch(()=>{}); }
function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => { document.getElementById('scanner_area').classList.add('hidden'); html5QrCode.clear(); }); }
</script>