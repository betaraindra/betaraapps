<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    // 2. IMPORT EXCEL DATA (LOGIC BARU)
    if (isset($_POST['import_data'])) {
        $data = json_decode($_POST['import_data'], true);
        $count_insert = 0;
        $count_update = 0;
        
        $pdo->beginTransaction();
        try {
            foreach($data as $row) {
                // Mapping Kolom (Harus sesuai dengan Export)
                // Fallback key lowercase jika user edit header excel manual
                $sku = trim(strval($row['SKU'] ?? $row['sku'] ?? ''));
                $name = trim(strval($row['Nama Barang'] ?? $row['nama barang'] ?? $row['Nama'] ?? ''));
                $cat = trim(strval($row['Kategori'] ?? $row['kategori'] ?? 'Umum'));
                $unit = trim(strval($row['Satuan'] ?? $row['satuan'] ?? 'Pcs'));
                
                // Sanitasi Harga & Stok (Hapus Rp, titik, koma desimal)
                $buyRaw = $row['Harga Beli'] ?? $row['harga beli'] ?? 0;
                $sellRaw = $row['Harga Jual'] ?? $row['harga jual'] ?? 0;
                $stockRaw = $row['Stok'] ?? $row['stok'] ?? 0;

                $buy = cleanNumber($buyRaw);
                $sell = cleanNumber($sellRaw);
                $stock = (int)cleanNumber($stockRaw);
                
                // Validasi Dasar
                if(empty($sku) || empty($name)) continue;

                // Cek Eksistensi SKU
                $check = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
                $check->execute([$sku]);
                $existing = $check->fetch();

                if($existing) {
                    // UPDATE DATA
                    $stmt = $pdo->prepare("UPDATE products SET name=?, category=?, unit=?, buy_price=?, sell_price=?, stock=? WHERE id=?");
                    $stmt->execute([$name, $cat, $unit, $buy, $sell, $stock, $existing['id']]);
                    $count_update++;
                } else {
                    // INSERT DATA BARU
                    $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$sku, $name, $cat, $unit, $buy, $sell, $stock]);
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
        $sku = trim($_POST['sku']);
        $name = trim($_POST['name']);
        $cat = $_POST['category'];
        $unit = trim($_POST['unit']);
        
        // Gunakan cleanNumber untuk membersihkan format separator
        $buy = cleanNumber($_POST['buy_price']);
        $sell = cleanNumber($_POST['sell_price']);
        
        $stock = (int)$_POST['stock'];
        $id = $_POST['id'] ?? '';
        $image_url = $_POST['old_image'] ?? '';
        $wh_id = $_POST['warehouse_id'] ?? ''; // Ambil Gudang ID
        $has_sn = isset($_POST['has_serial_number']) ? 1 : 0;
        
        // Ambil Data SN yang diinput
        $sn_list = $_POST['sn_list'] ?? [];
        // Filter empty SN
        $sn_list = array_filter($sn_list, function($v) { return !empty(trim($v)); });

        // SECURE FILE UPLOAD
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowed_types)) {
                $_SESSION['flash'] = ['type'=>'error', 'message'=>'Format file tidak valid (harus JPG/PNG/GIF).'];
                echo "<script>window.location='?page=data_barang';</script>";
                exit;
            }

            if ($_FILES['image']['size'] > $max_size) {
                $_SESSION['flash'] = ['type'=>'error', 'message'=>'Ukuran file maksimal 2MB.'];
                echo "<script>window.location='?page=data_barang';</script>";
                exit;
            }

            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = "prod_" . bin2hex(random_bytes(8)) . "." . $ext;
            
            $upload_dir = 'uploads/products/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename)) {
                if (!empty($image_url) && file_exists($image_url)) unlink($image_url);
                $image_url = $upload_dir . $filename;
            }
        }

        $pdo->beginTransaction(); // Start Transaction

        try {
            if ($id) {
                // --- UPDATE PRODUCT ---
                // Cek Stok Lama untuk menghitung selisih
                $stmtOld = $pdo->prepare("SELECT stock, has_serial_number FROM products WHERE id=?");
                $stmtOld->execute([$id]);
                $old_data = $stmtOld->fetch();
                $old_stock = (int)$old_data['stock'];
                $old_has_sn = (int)$old_data['has_serial_number'];
                
                $stock_diff = $stock - $old_stock;

                // VALIDASI: Jika stok berubah, Gudang WAJIB dipilih
                if ($stock_diff != 0 && empty($wh_id)) {
                    throw new Exception("Stok berubah (Selisih: $stock_diff). Harap pilih Gudang/Wilayah untuk mencatat perubahan ini.");
                }
                
                // VALIDASI SN SAAT EDIT
                if ($has_sn) {
                    $required_sn_count = 0;
                    
                    if ($old_has_sn == 0) {
                        // Kasus: Ubah Barang Biasa jadi Barang SN -> Butuh SN sejumlah Stok Total
                        $required_sn_count = $stock;
                    } elseif ($stock_diff > 0) {
                        // Kasus: Tambah Stok Barang SN -> Butuh SN sejumlah Selisih
                        $required_sn_count = $stock_diff;
                    } elseif ($stock_diff < 0) {
                        // Kasus: Kurang Stok -> Tidak diizinkan edit manual di sini (harus via Barang Keluar untuk pilih SN)
                        throw new Exception("Pengurangan stok barang Serial Number TIDAK BOLEH melalui menu Edit. Gunakan menu 'Barang Keluar' agar bisa memilih SN yang dihapus.");
                    }
                    
                    if (count($sn_list) < $required_sn_count) {
                        throw new Exception("Jumlah Serial Number yang diinput (" . count($sn_list) . ") kurang dari yang dibutuhkan ($required_sn_count).");
                    }
                }

                $stmt = $pdo->prepare("UPDATE products SET sku=?, name=?, category=?, unit=?, buy_price=?, sell_price=?, stock=?, image_url=?, has_serial_number=? WHERE id=?");
                $stmt->execute([$sku, $name, $cat, $unit, $buy, $sell, $stock, $image_url, $has_sn, $id]);
                
                // LOGIC TRANSAKSI KOREKSI
                if ($stock_diff > 0) {
                    $ref = 'ADJ-IN-' . date('ymdHi');
                    $note = 'Penambahan Stok (Edit Data Barang)';
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, ?, ?)")
                        ->execute([$id, $wh_id, $stock_diff, $ref, $note, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();
                    
                    // INSERT SN (Jika ada selisih positif atau konversi)
                    if ($has_sn) {
                        $stmtSN = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                        // Ambil sejumlah yang dibutuhkan dari list (sisanya mungkin sudah ada jika konversi)
                        // Logic simple: Insert semua yang baru
                        foreach($sn_list as $sn_code) {
                            // Cek duplikat
                            $chk = $pdo->prepare("SELECT id FROM product_serials WHERE product_id=? AND serial_number=?");
                            $chk->execute([$id, $sn_code]);
                            if($chk->rowCount() == 0) {
                                $stmtSN->execute([$id, $sn_code, $wh_id, $trx_id]);
                            }
                        }
                    }
                } elseif ($stock_diff < 0) {
                    $ref = 'ADJ-OUT-' . date('ymdHi');
                    $note = 'Pengurangan Stok (Edit Data Barang)';
                    // Jika Non-SN, boleh langsung kurang
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'OUT', ?, ?, ?, ?, ?, ?)")
                        ->execute([$id, $wh_id, abs($stock_diff), $ref, $note, $_SESSION['user_id']]);
                } else {
                    // Stok Sama, tapi mungkin konversi ke SN (0 -> 1)
                    if ($has_sn && $old_has_sn == 0 && $stock > 0) {
                        // Insert SN tanpa transaksi stok baru (karena stok qty tidak berubah, hanya status)
                        // Gunakan dummy transaction atau NULL
                        $stmtSN = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id) VALUES (?, ?, 'AVAILABLE', ?)");
                        foreach($sn_list as $sn_code) {
                            $stmtSN->execute([$id, $sn_code, $wh_id]);
                        }
                    }
                }

                logActivity($pdo, 'UPDATE_BARANG', "Update barang: $name ($sku)");
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data Barang Diperbarui'];
            
            } else {
                // --- ADD NEW PRODUCT ---
                $check = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
                $check->execute([$sku]);
                if($check->rowCount() > 0) throw new Exception("SKU $sku sudah ada!");

                // VALIDASI: Jika stok awal > 0, Gudang WAJIB dipilih
                if ($stock > 0 && empty($wh_id)) {
                    throw new Exception("Stok awal diisi ($stock). Harap pilih Gudang/Wilayah penyimpanan.");
                }
                
                // VALIDASI SN NEW PRODUCT
                if ($has_sn && $stock > 0) {
                    if (count($sn_list) != $stock) {
                        throw new Exception("Jumlah Serial Number (" . count($sn_list) . ") harus sama dengan Stok Awal ($stock).");
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, image_url, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sku, $name, $cat, $unit, $buy, $sell, $stock, $image_url, $has_sn]);
                $new_id = $pdo->lastInsertId();

                // LOGIC TRANSAKSI STOK AWAL
                if ($stock > 0) {
                    $ref = 'INIT-' . date('ymdHi'); // Ref Otomatis
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, 'Stok Awal Barang Baru', ?)")
                        ->execute([$new_id, $wh_id, $stock, $ref, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();
                    
                    // INSERT SN
                    if ($has_sn) {
                        $stmtSN = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                        foreach($sn_list as $sn_code) {
                            $stmtSN->execute([$new_id, $sn_code, $wh_id, $trx_id]);
                        }
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

    // 4. DELETE PRODUCT
    if (isset($_POST['delete_id'])) {
        try {
            $prod = $pdo->query("SELECT name FROM products WHERE id=".(int)$_POST['delete_id'])->fetch();
            $prodName = $prod ? $prod['name'] : 'Unknown';
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([(int)$_POST['delete_id']]);
            logActivity($pdo, 'DELETE_BARANG', "Hapus barang: $prodName");
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang dihapus'];
        } catch(Exception $e) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal hapus. Mungkin barang sudah memiliki transaksi.'];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }
}

// --- GET MASTER DATA ---
// Data Gudang untuk Dropdown
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

// Tambahkan 2105 ke dalam list kategori untuk form add/edit
$category_accounts = $pdo->query("SELECT * FROM accounts WHERE code IN ('3003', '2005', '2105') ORDER BY code ASC")->fetchAll();

// QUERY UNTUK FILTER KATEGORI: Join ke Accounts untuk dapat Nama
$existing_categories = $pdo->query("
    SELECT DISTINCT p.category, a.name 
    FROM products p 
    LEFT JOIN accounts a ON p.category = a.code 
    ORDER BY p.category ASC
")->fetchAll();

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

if ($warehouse_filter !== 'ALL') {
    $sql .= " AND it.warehouse_id = ?";
    $params[] = $warehouse_filter;
}
if ($cat_filter !== 'ALL') {
    $sql .= " AND p.category = ?";
    $params[] = $cat_filter;
}
if ($stock_status === 'LOW') {
    $sql .= " AND p.stock < 10 AND p.stock > 0";
} elseif ($stock_status === 'EMPTY') {
    $sql .= " AND p.stock = 0";
} elseif ($stock_status === 'AVAILABLE') {
    $sql .= " AND p.stock > 0";
}

if ($sort_by === 'name_asc') $sql .= " ORDER BY p.name ASC";
elseif ($sort_by === 'name_desc') $sql .= " ORDER BY p.name DESC";
elseif ($sort_by === 'stock_high') $sql .= " ORDER BY p.stock DESC";
elseif ($sort_by === 'stock_low') $sql .= " ORDER BY p.stock ASC";
elseif ($sort_by === 'price_high') $sql .= " ORDER BY p.buy_price DESC";
elseif ($sort_by === 'price_low') $sql .= " ORDER BY p.buy_price ASC";
else $sql .= " ORDER BY trx_date DESC, p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Array data untuk Export JS
$export_data_js = [];
foreach($products as $p) {
    $export_data_js[] = [
        'SKU' => $p['sku'],
        'Nama Barang' => $p['name'],
        'Kategori' => $p['category'],
        'Satuan' => $p['unit'],
        'Harga Beli' => (float)$p['buy_price'],
        'Harga Jual' => (float)$p['sell_price'],
        'Stok' => (int)$p['stock'],
        'Wajib SN' => $p['has_serial_number'] ? 'YA' : 'TIDAK'
    ];
}

// Grouping for View
$grouped_products = [];
foreach($products as $p) {
    if (in_array($sort_by, ['newest'])) {
        $timestamp = strtotime($p['trx_date']);
        $group_key = "Periode " . date('F Y', $timestamp);
    } else {
        $group_key = "Semua Barang (Urut: " . strtoupper(str_replace('_', ' ', $sort_by)) . ")";
    }
    $grouped_products[$group_key]['items'][] = $p;
    if(!isset($grouped_products[$group_key]['total'])) $grouped_products[$group_key]['total'] = 0;
    $grouped_products[$group_key]['total'] += $p['buy_price'];
}
?>

<!-- JS Dependencies -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bwip-js/3.4.4/bwip-js-min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>

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

<!-- HEADER BUTTONS -->
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
                    <?php 
                        // Format Label: Kode - Nama Akun
                        $label = $ec['category'];
                        if(!empty($ec['name'])) {
                            $label .= " - " . $ec['name'];
                        }
                    ?>
                    <option value="<?= $ec['category'] ?>" <?= $cat_filter==$ec['category'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
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
            <label class="block text-xs font-bold text-gray-600 mb-1">Urutkan (Sort)</label>
            <select name="sort" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                <option value="newest" <?= $sort_by=='newest'?'selected':'' ?>>Update Terbaru</option>
                <option value="name_asc" <?= $sort_by=='name_asc'?'selected':'' ?>>Nama (A-Z)</option>
                <option value="name_desc" <?= $sort_by=='name_desc'?'selected':'' ?>>Nama (Z-A)</option>
                <option value="stock_high" <?= $sort_by=='stock_high'?'selected':'' ?>>Stok Terbanyak</option>
                <option value="stock_low" <?= $sort_by=='stock_low'?'selected':'' ?>>Stok Sedikit</option>
                <option value="price_high" <?= $sort_by=='price_high'?'selected':'' ?>>Nilai Aset Tinggi</option>
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
    <div class="lg:col-span-1 space-y-6 no-print">
        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2 flex justify-between items-center cursor-pointer" onclick="toggleFormMobile()">
                <span><i class="fas fa-plus"></i> Tambah / Edit Barang</span>
                <i id="form_toggle_icon" class="fas fa-chevron-down lg:hidden"></i>
            </h3>
            
            <div id="form_content">
                <!-- SCANNER CAMERA AREA -->
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
                    
                    <!-- HIDDEN FIELDS FOR SN LOGIC -->
                    <input type="hidden" id="inp_old_stock" value="0">
                    <input type="hidden" id="inp_old_has_sn" value="0">

                    <div class="mb-3">
                        <label class="block text-xs font-bold text-gray-700 mb-1">SKU (Barcode)</label>
                        <input type="file" id="scan_image_file" accept="image/*" capture="environment" class="hidden" onchange="handleFileScan(this)">

                        <div class="flex gap-1 flex-wrap">
                            <input type="text" name="sku" id="inp_sku" class="flex-1 min-w-0 w-full border p-2 rounded text-sm font-mono font-bold uppercase focus:ring-2 focus:ring-blue-500" placeholder="Scan/Generate..." oninput="previewBarcode()" onkeydown="handleEnter(event)" required>
                            <button type="button" onclick="initCamera()" class="bg-indigo-600 text-white px-2 py-2 rounded hover:bg-indigo-700 shadow" title="Live Scan"><i class="fas fa-video"></i></button>
                            <button type="button" onclick="document.getElementById('scan_image_file').click()" class="bg-purple-600 text-white px-2 py-2 rounded hover:bg-purple-700 shadow" title="Foto Barcode"><i class="fas fa-camera"></i></button>
                            <button type="button" onclick="activateUSB()" class="bg-gray-700 text-white px-2 py-2 rounded hover:bg-gray-800 shadow" title="USB Mode"><i class="fas fa-keyboard"></i></button>
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
                            <select name="warehouse_id" id="inp_warehouse" class="w-1/2 border p-2 rounded text-sm bg-white text-gray-700">
                                <option value="">-- Pilih Gudang --</option>
                                <?php foreach($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="text-[10px] text-red-500 mt-1 font-bold">* WAJIB Pilih Gudang jika mengisi stok.</p>
                    </div>

                    <!-- NEW: SERIAL NUMBER CHECKBOX -->
                    <div class="mb-3 bg-purple-50 p-2 rounded border border-purple-200">
                        <label class="flex items-center space-x-2 cursor-pointer">
                            <input type="checkbox" name="has_serial_number" id="inp_has_sn" onchange="renderInitialSnInputs()" class="form-checkbox h-4 w-4 text-purple-600 rounded">
                            <span class="text-xs font-bold text-purple-800">Barang Wajib Serial Number (SN)?</span>
                        </label>
                        <p class="text-[10px] text-gray-500 mt-1 ml-6">Jika dicentang, wajib input/scan SN saat barang masuk/keluar.</p>
                    </div>

                    <!-- DYNAMIC SN INPUT -->
                    <div id="sn_input_area" class="hidden mb-3 bg-white p-2 rounded border-2 border-purple-300 border-dashed">
                        <div class="text-xs font-bold text-purple-700 mb-2 flex justify-between items-center">
                            <span><i class="fas fa-barcode"></i> Input Serial Number Awal</span>
                            <span id="sn_count_badge" class="bg-purple-600 text-white px-2 rounded-full text-[10px]">0</span>
                        </div>
                        <div id="sn_inputs_container" class="grid grid-cols-1 gap-2 max-h-40 overflow-y-auto p-1">
                            <!-- Inputs generated by JS -->
                        </div>
                        <p id="sn_warning" class="text-[10px] text-red-500 mt-2 hidden italic">
                            * Pengurangan stok barang SN (Edit) tidak diizinkan di sini. Gunakan menu 'Barang Keluar'.
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
        
        <!-- IMPORT FORM HIDDEN -->
        <div class="hidden">
             <form id="form_import" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="import_data" id="import_json_data">
                <input type="file" id="file_excel" accept=".xlsx, .xls" onchange="processImportFile(this)">
            </form>
        </div>
    </div>

    <!-- KOLOM KANAN: TABEL DATA -->
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
                                <img src="<?= h($p['image_url']) ?>" class="w-8 h-8 object-cover mx-auto" loading="lazy" width="32" height="32">
                            <?php endif; ?>
                        </td>
                        <td class="p-2 border font-bold"><?= h($p['name']) ?></td>
                        <td class="p-2 border font-mono text-center"><?= h($p['sku']) ?></td>
                        <td class="p-2 border text-center">
                            <?php if($p['has_serial_number']): ?>
                                <span class="bg-purple-100 text-purple-800 px-1 rounded font-bold text-[10px]">YA</span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
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
                                    <a href="?page=cetak_surat_jalan&id=<?= $p['trx_id'] ?>" target="_blank" class="bg-gray-100 px-2 py-1 rounded border hover:bg-gray-200 text-gray-700 text-[10px] inline-flex items-center gap-1" title="Cetak Surat Jalan">
                                        <i class="fas fa-print"></i> SJ
                                    </a>
                                <?php endif; ?>
                                <button onclick="printLabelDirect('<?= h($p['sku']) ?>', '<?= h($p['name']) ?>')" class="bg-yellow-100 px-2 py-1 rounded border border-yellow-300 hover:bg-yellow-200 text-yellow-800 text-[10px] inline-flex items-center gap-1" title="Cetak Label Barcode">
                                    <i class="fas fa-barcode"></i> Lbl
                                </button>
                            </div>
                        </td>
                        <td class="p-2 border text-center no-print flex justify-center gap-1">
                            <button onclick='editProduct(<?= json_encode($p) ?>)' class="text-blue-600"><i class="fas fa-edit"></i></button>
                            <form method="POST" onsubmit="return confirm('Hapus?')" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                <button class="text-red-500"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- IMPORT MODAL -->
<div id="importModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <button onclick="document.getElementById('importModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
            <i class="fas fa-times text-xl"></i>
        </button>
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-green-700"><i class="fas fa-file-excel"></i> Import Data Barang</h3>
        
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 mb-4 text-sm text-yellow-800">
            <p class="font-bold">Panduan Import:</p>
            <ul class="list-disc ml-4 mt-1">
                <li>Gunakan fitur <b>Export Excel</b> untuk mendapatkan template.</li>
                <li>Jika SKU sudah ada, data akan di-<b>UPDATE</b>.</li>
                <li>Jika SKU belum ada, data akan di-<b>INSERT</b> baru.</li>
                <li>Kolom Wajib: SKU, Nama Barang.</li>
            </ul>
        </div>

        <button onclick="document.getElementById('file_excel').click()" class="w-full bg-green-600 text-white py-3 rounded font-bold hover:bg-green-700 shadow flex items-center justify-center gap-2">
            <i class="fas fa-upload"></i> Pilih File Excel (.xlsx)
        </button>
    </div>
</div>

<script>
// DATA UNTUK EXPORT
const productsData = <?= json_encode($export_data_js) ?>;

// --- EXPORT EXCEL ---
function exportToExcel() {
    if(productsData.length === 0) return alert("Tidak ada data untuk diexport.");
    
    // Create Worksheet
    const ws = XLSX.utils.json_to_sheet(productsData);
    
    // Create Workbook
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Data Barang");
    
    // Download
    XLSX.writeFile(wb, "Data_Barang_Export_" + new Date().toISOString().slice(0,10) + ".xlsx");
}

// --- IMPORT EXCEL ---
function processImportFile(input) {
    if(!input.files.length) return;
    
    const file = input.files[0];
    const reader = new FileReader();
    
    // Loading State
    document.getElementById('importModal').classList.add('hidden');
    
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            
            // Ambil Sheet Pertama
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            
            // Convert to JSON
            const jsonData = XLSX.utils.sheet_to_json(firstSheet);
            
            if(jsonData.length > 0) {
                if(confirm("Ditemukan " + jsonData.length + " baris data. Proses Import?")) {
                    document.getElementById('import_json_data').value = JSON.stringify(jsonData);
                    document.getElementById('form_import').submit();
                }
            } else {
                alert("File Excel kosong atau format tidak terbaca.");
            }
        } catch(err) {
            alert("Gagal membaca file: " + err.message);
        }
        // Reset input
        input.value = '';
    };
    
    reader.readAsArrayBuffer(file);
}

// ... (Existing Functions: initAudio, playBeep, formatRupiah, activateUSB, handleFileScan, handleEnter, generateSku, previewBarcode, printSingleLabel, editProduct, resetForm, toggleFormMobile, initCamera, startScan, stopScan) ...
let html5QrCode;
let audioCtx = null;

function initAudio() {
    if (!audioCtx) { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
    if (audioCtx.state === 'suspended') { audioCtx.resume(); }
}

function playBeep() {
    if (!audioCtx) return;
    try {
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);
        oscillator.type = 'square'; 
        oscillator.frequency.setValueAtTime(1500, audioCtx.currentTime); 
        gainNode.gain.setValueAtTime(0.2, audioCtx.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.1);
        oscillator.start();
        oscillator.stop(audioCtx.currentTime + 0.15); 
    } catch (e) { console.error("Audio error:", e); }
}

function formatRupiah(input) {
    let value = input.value.replace(/\D/g, '');
    if(value === '') { input.value = ''; return; }
    input.value = new Intl.NumberFormat('id-ID').format(value);
}

function activateUSB() {
    const input = document.getElementById('inp_sku');
    input.focus();
    input.select();
    input.classList.add('ring-4', 'ring-yellow-400', 'border-yellow-500');
    setTimeout(() => {
        input.classList.remove('ring-4', 'ring-yellow-400', 'border-yellow-500');
    }, 1500);
}

function handleFileScan(input) {
    if (input.files.length === 0) return;
    const file = input.files[0];
    initAudio(); 
    const skuInput = document.getElementById('inp_sku');
    const originalPlaceholder = skuInput.placeholder;
    skuInput.value = "";
    skuInput.placeholder = "Memproses foto...";
    const html5QrCode = new Html5Qrcode("reader"); 
    html5QrCode.scanFile(file, true)
    .then(decodedText => {
        playBeep();
        skuInput.value = decodedText;
        skuInput.placeholder = originalPlaceholder;
        previewBarcode();
        input.value = '';
    })
    .catch(err => {
        alert("Gagal membaca barcode dari foto. Pastikan foto fokus.");
        skuInput.placeholder = originalPlaceholder;
        input.value = '';
    });
}

function handleEnter(e) { if(e.key === 'Enter') e.preventDefault(); }

async function generateSku() {
    const btn = document.getElementById('btn_generate');
    const oldIcon = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    try {
        const res = await fetch('api.php?action=generate_sku');
        const data = await res.json();
        if(data.sku) {
            document.getElementById('inp_sku').value = data.sku;
            previewBarcode();
        }
    } catch(e) { alert("Gagal generate SKU"); } finally {
        btn.innerHTML = oldIcon;
        btn.disabled = false;
    }
}

function previewBarcode() {
    const sku = document.getElementById('inp_sku').value.trim();
    const container = document.getElementById('barcode_container');
    
    if(sku.length > 0) {
        container.classList.remove('hidden');
        try {
            bwipjs.toCanvas('barcode_canvas', {
                bcid:        'code128',          
                text:        sku,                
                scale:       2,                  
                height:      10,                 
                includetext: true,              
                textxalign:  'center',
            });
        } catch(e) {
            console.error(e);
            container.classList.add('hidden');
        }
    } else { 
        container.classList.add('hidden'); 
    }
}

function printSingleLabel() {
    const sku = document.getElementById('inp_sku').value.trim();
    const name = document.getElementById('inp_name').value.trim() || 'NAMA BARANG';
    if(!sku) return alert("SKU harus diisi!");
    
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
    } catch(e) { alert("Gagal render label."); }
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
    } catch(e) { alert("Gagal render label."); }
}

// --- DYNAMIC SN INPUT LOGIC ---
function renderInitialSnInputs() {
    const hasSn = document.getElementById('inp_has_sn').checked;
    const newStock = parseInt(document.getElementById('inp_stock').value) || 0;
    const oldStock = parseInt(document.getElementById('inp_old_stock').value) || 0;
    const oldHasSn = document.getElementById('inp_old_has_sn').value == '1';
    
    const container = document.getElementById('sn_input_area');
    const inputsDiv = document.getElementById('sn_inputs_container');
    const warning = document.getElementById('sn_warning');
    const badge = document.getElementById('sn_count_badge');
    const btnSave = document.getElementById('btn_save');
    
    if (!hasSn) {
        container.classList.add('hidden');
        inputsDiv.innerHTML = '';
        btnSave.disabled = false;
        return;
    }

    // Hitung berapa SN yang butuh diinput
    let needed = 0;
    let allowInput = true;

    if (!oldHasSn && oldStock === 0) {
        // Barang Baru (Non-SN -> SN dengan stok baru)
        needed = newStock;
    } else if (!oldHasSn && oldStock > 0) {
        // Konversi Barang Biasa (Stok Ada) -> Barang SN
        // Butuh SN sejumlah Total Stok Baru (karena yang lama belum punya SN)
        needed = newStock; 
    } else if (oldHasSn) {
        // Barang SN Existing
        if (newStock > oldStock) {
            // Tambah Stok -> Butuh SN sejumlah selisih
            needed = newStock - oldStock;
        } else if (newStock < oldStock) {
            // Kurang Stok -> Tidak boleh edit di sini
            allowInput = false;
        }
        // Jika sama, tidak perlu input
    }

    if (!allowInput) {
        container.classList.remove('hidden');
        inputsDiv.innerHTML = '';
        warning.classList.remove('hidden');
        badge.innerText = "Error";
        btnSave.disabled = true; // Disable save to prevent error
        return;
    }

    btnSave.disabled = false;
    warning.classList.add('hidden');

    if (needed > 0) {
        container.classList.remove('hidden');
        badge.innerText = needed + " SN";
        
        // Generate Inputs
        // Don't regenerate if count matches (to keep user input), unless forcing refresh
        // Simple approach: regenerate always for now or check length
        if (inputsDiv.children.length !== needed) {
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
    
    // SN Checkbox
    document.getElementById('inp_has_sn').checked = (p.has_serial_number == 1);

    // Auto Select Warehouse from previous transaction if available
    // Agar kolom Wilayah tidak kosong saat disimpan
    document.getElementById('inp_warehouse').value = p.current_wh_id || "";
    
    // Set Old Values for Logic
    document.getElementById('inp_old_stock').value = p.stock;
    document.getElementById('inp_old_has_sn').value = p.has_serial_number;

    // Trigger Render SN Logic immediately
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
    document.getElementById('inp_warehouse').value = ''; // Reset Warehouse
    document.getElementById('inp_has_sn').checked = false;
    
    // Reset Logic Fields
    document.getElementById('inp_old_stock').value = 0;
    document.getElementById('inp_old_has_sn').value = 0;
    document.getElementById('sn_input_area').classList.add('hidden');
    document.getElementById('btn_save').disabled = false;

    document.getElementById('barcode_container').classList.add('hidden');
    document.getElementById('scanner_area').classList.add('hidden');
    if(html5QrCode) { try { html5QrCode.stop(); html5QrCode.clear(); } catch(e) {} }
}

function toggleFormMobile() {
    const content = document.getElementById('form_content');
    const icon = document.getElementById('form_toggle_icon');
    content.classList.toggle('hidden');
    icon.classList.toggle('fa-chevron-down');
    icon.classList.toggle('fa-chevron-up');
}

if(window.innerWidth < 1024) { document.getElementById('form_content').classList.add('hidden'); }

async function initCamera() {
    initAudio();
    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        alert("PENTING: Fitur kamera biasanya hanya jalan di HTTPS atau localhost.");
    }
    const scannerArea = document.getElementById('scanner_area');
    scannerArea.classList.remove('hidden');
    try {
        await navigator.mediaDevices.getUserMedia({ video: true });
        const devices = await Html5Qrcode.getCameras();
        const cameraSelect = document.getElementById('camera_select');
        cameraSelect.innerHTML = "";
        if (devices && devices.length) {
            let backCameraId = devices[0].id;
            for (const device of devices) {
                const label = device.label.toLowerCase();
                if (label.includes('back') || label.includes('belakang') || label.includes('rear') || label.includes('environment')) {
                    backCameraId = device.id;
                    break; 
                }
            }
            devices.forEach(device => {
                const option = document.createElement("option");
                option.value = device.id;
                option.text = device.label || `Kamera ${cameraSelect.length + 1}`;
                cameraSelect.appendChild(option);
            });
            cameraSelect.value = backCameraId;
            startScan(backCameraId);
            cameraSelect.onchange = () => { if(html5QrCode) { html5QrCode.stop().then(() => { startScan(cameraSelect.value); }); } };
        } else { alert("Kamera tidak ditemukan pada perangkat ini."); }
    } catch (err) {
        console.error("Camera Error:", err);
        if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError' || err.toString().includes('NotAllowedError')) {
            alert("AKSES KAMERA DITOLAK! Mohon izinkan akses kamera di browser Anda.");
        } else if (err.name === 'NotFoundError') {
            alert("Perangkat kamera tidak ditemukan.");
        } else {
            alert("Gagal mengakses kamera: " + err.message);
        }
        document.getElementById('scanner_area').classList.add('hidden');
    }
}

function startScan(cameraId) {
    if(html5QrCode) { try { html5QrCode.stop(); } catch(e) {} }
    html5QrCode = new Html5Qrcode("reader");
    const config = { 
        fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0, 
        experimentalFeatures: { useBarCodeDetectorIfSupported: true },
        formatsToSupport: [ Html5QrcodeSupportedFormats.QR_CODE, Html5QrcodeSupportedFormats.CODE_128, Html5QrcodeSupportedFormats.EAN_13 ]
    };
    html5QrCode.start(cameraId, config, (decodedText) => {
        playBeep();
        document.getElementById('inp_sku').value = decodedText;
        previewBarcode();
        stopScan();
    }, () => {}).catch(err => console.error(err));
}

function stopScan() {
    if(html5QrCode) {
        html5QrCode.stop().then(() => {
            document.getElementById('scanner_area').classList.add('hidden');
            html5QrCode.clear();
        }).catch(err => { document.getElementById('scanner_area').classList.add('hidden'); });
    }
}
</script>