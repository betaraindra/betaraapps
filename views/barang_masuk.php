<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- LOGIKA SIMPAN DATA (PHP) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $date = $_POST['date'];
    $warehouse_id = $_POST['warehouse_id'];
    $reference = $_POST['reference'];
    $sku = trim($_POST['sku']);
    $qty = (float)$_POST['quantity']; // Support desimal
    $notes = $_POST['notes'];
    
    // SN Fields - DEFAULT SELALU WAJIB (TRUE)
    $has_sn = true; 
    $sn_list = $_POST['sn_list'] ?? [];

    // Validasi Input Wajib
    if (empty($sku)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'SKU Barang harus diisi/scan.'];
    } elseif ($qty <= 0) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Jumlah barang harus lebih dari 0.'];
    } elseif (empty($warehouse_id)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gudang Penerima harus dipilih.'];
    } elseif ($has_sn && count(array_filter($sn_list)) < $qty) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Wajib input Serial Number sejumlah Qty barang. Klik "Generate Auto SN" jika tidak ada SN fisik.'];
    } else {
        $pdo->beginTransaction();
        try {
            // 1. Cek Apakah Produk Sudah Ada
            $stmt = $pdo->prepare("SELECT id, name, stock, buy_price, sell_price, category, has_serial_number FROM products WHERE sku = ?");
            $stmt->execute([$sku]);
            $product = $stmt->fetch();
            
            $product_id = 0;
            $current_buy_price = 0;
            $product_category_code = ''; // Untuk Akun Keuangan
            
            // Ambil Input dari Form (Untuk Update atau Insert)
            // KITA IZINKAN UPDATE MASTER DATA (Nama, Kategori, Satuan) DI SINI
            $input_name = trim($_POST['new_name'] ?? '');
            $input_cat = trim($_POST['new_category'] ?? '');
            $input_unit = trim($_POST['new_unit'] ?? 'Pcs');
            
            $input_buy_price = !empty($_POST['buy_price']) ? cleanNumber($_POST['buy_price']) : 0;
            $input_sell_price = !empty($_POST['sell_price']) ? cleanNumber($_POST['sell_price']) : 0;

            if ($product) {
                // Produk Lama -> Update Master Data (Nama, Kategori, Unit, Harga) & Tambah Stok
                $product_id = $product['id'];
                
                // Jika user tidak mengubah input (atau readonly dihapus lewat inspect element), pake data lama
                // TAPI karena di JS kita enable field, asumsi user mungkin ubah.
                // Fallback ke data lama jika input kosong (safety)
                $final_name = !empty($input_name) ? $input_name : $product['name'];
                $final_cat = !empty($input_cat) ? $input_cat : $product['category'];
                $final_unit = !empty($input_unit) ? $input_unit : 'Pcs'; // Default unit logic is messy, assume exists
                
                $product_category_code = $final_cat;
                
                $current_buy_price = ($input_buy_price > 0) ? $input_buy_price : $product['buy_price'];
                $current_sell_price = ($input_sell_price > 0) ? $input_sell_price : $product['sell_price'];
                
                // Force update has_serial_number jadi 1
                if ($product['has_serial_number'] == 0) {
                    $pdo->prepare("UPDATE products SET has_serial_number = 1 WHERE id = ?")->execute([$product_id]);
                }

                // UPDATE QUERY LENGKAP
                $pdo->prepare("UPDATE products SET name=?, category=?, unit=?, stock = stock + ?, buy_price = ?, sell_price = ? WHERE id = ?")
                    ->execute([$final_name, $final_cat, $final_unit, $qty, $current_buy_price, $current_sell_price, $product_id]);
                    
            } else {
                // Produk Baru
                if(empty($input_name)) throw new Exception("Barang SKU $sku belum terdaftar. Harap isi Nama Barang Baru.");
                if(empty($input_cat)) throw new Exception("Kategori (Akun) untuk barang baru harus dipilih.");

                $product_category_code = $input_cat;
                $current_buy_price = $input_buy_price;
                $current_sell_price = $input_sell_price;
                
                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$sku, $input_name, $input_cat, $input_unit, $current_buy_price, $current_sell_price, $qty]);
                $product_id = $pdo->lastInsertId();
            }
            
            // Siapkan Notes dengan SN agar muncul di Inventory Report
            $sn_string = "";
            $valid_sns = array_filter($sn_list, function($v) { return !empty(trim($v)); });
            if (!empty($valid_sns)) {
                $sn_string = " [SN: " . implode(", ", $valid_sns) . "]";
            }
            $final_notes = $notes . $sn_string;

            // 2. Simpan Riwayat Transaksi (IN)
            $stmt = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$date, $product_id, $warehouse_id, $qty, $reference, $final_notes, $_SESSION['user_id']]);
            $trx_id = $pdo->lastInsertId(); 
            
            // 3. AUTO RECORD FINANCIAL TRANSACTION
            if ($current_buy_price > 0 && !empty($product_category_code)) {
                $total_value = $qty * $current_buy_price;
                $stmtAcc = $pdo->prepare("SELECT id, type FROM accounts WHERE code = ?");
                $stmtAcc->execute([$product_category_code]);
                $acc = $stmtAcc->fetch();
                if ($acc) {
                    $stmtWh = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
                    $stmtWh->execute([$warehouse_id]);
                    $wh_name = $stmtWh->fetchColumn();
                    $wilayah_tag = $wh_name ? " [Wilayah: $wh_name]" : "";
                    $desc_fin = "Pembelian/Stok Masuk: $sku - $reference ($qty x " . number_format($current_buy_price,0,',','.') . "){$wilayah_tag}";
                    $fin_type = 'EXPENSE'; 
                    $stmtFin = $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtFin->execute([$date, $fin_type, $acc['id'], $total_value, $desc_fin, $_SESSION['user_id']]);
                }
            }

            // 4. INSERT SERIAL NUMBERS
            if (!empty($valid_sns)) {
                $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                foreach ($valid_sns as $sn) {
                    $chk = $pdo->prepare("SELECT id FROM product_serials WHERE product_id=? AND serial_number=?");
                    $chk->execute([$product_id, $sn]);
                    if($chk->rowCount() > 0) throw new Exception("Serial Number '$sn' sudah ada di database untuk produk ini.");
                    $stmtSn->execute([$product_id, $sn, $warehouse_id, $trx_id]);
                }
            }

            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"Barang Masuk ($sku) berhasil disimpan & tercatat di keuangan."];
            echo "<script>window.location='?page=barang_masuk';</script>";
            exit;
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: '.$e->getMessage()];
        }
    }
}

// ... (Sisa kode view PHP sama seperti sebelumnya: warehouses, products_list, recent_trx, dll)
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$category_accounts = $pdo->query("SELECT * FROM accounts WHERE code IN ('3003', '2005', '2105') ORDER BY code ASC")->fetchAll();
$products_list = $pdo->query("SELECT sku, name, stock FROM products ORDER BY name ASC")->fetchAll();
$recent_trx = $pdo->query("
    SELECT i.*, p.name as prod_name, p.sku, w.name as wh_name 
    FROM inventory_transactions i 
    JOIN products p ON i.product_id = p.id 
    JOIN warehouses w ON i.warehouse_id = w.id
    WHERE i.type = 'IN' 
    ORDER BY i.created_at DESC 
    LIMIT 10
")->fetchAll();
$app_ref_prefix = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<!-- Import bwip-js untuk barcode -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bwip-js/3.4.4/bwip-js-min.js"></script>

<!-- STYLE KHUSUS PRINT LABEL (SAMA) -->
<style>
    #print_area { display: none; }
    @media print {
        body * { visibility: hidden; }
        body { margin: 0; padding: 0; background-color: white; }
        #print_area, #print_area * { visibility: visible; }
        #print_area { display: flex !important; position: absolute; top: 0; left: 0; width: 100%; height: 100%; align-items: center; justify-content: center; background: white; z-index: 9999; }
        @page { size: 50mm 30mm; margin: 0; }
        .label-box { width: 48mm; height: 28mm; border: 1px solid #000; padding: 2px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; font-family: sans-serif; overflow: hidden; }
        .lbl-name { font-size: 8pt; font-weight: bold; margin-top: 2px; line-height: 1; max-height: 2.2em; overflow: hidden; }
        canvas { max-width: 95% !important; max-height: 20mm !important; }
    }
</style>

<div id="print_area">
    <div class="label-box">
        <canvas id="p_canvas"></canvas>
        <div id="p_name" class="lbl-name">NAMA BARANG</div>
    </div>
</div>

<!-- UI HALAMAN -->
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <!-- HEADER BUTTONS -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 border-b pb-4 gap-4">
            <h3 class="font-bold text-xl text-green-700 flex items-center gap-2">
                <i class="fas fa-box-open"></i> Input Barang Masuk
            </h3>
            <div class="flex gap-2 flex-wrap">
                <button type="button" onclick="activateUSB()" class="bg-gray-700 text-white px-3 py-2 rounded shadow hover:bg-gray-800 transition text-sm flex items-center gap-2"><i class="fas fa-keyboard"></i> USB Mode</button>
                <input type="file" id="scan_image_file" accept="image/*" capture="environment" class="hidden" onchange="handleFileScan(this)">
                <button type="button" onclick="document.getElementById('scan_image_file').click()" class="bg-purple-600 text-white px-3 py-2 rounded shadow hover:bg-purple-700 transition text-sm flex items-center gap-2"><i class="fas fa-camera"></i> Foto Scan</button>
                <button type="button" onclick="initCamera()" class="bg-indigo-600 text-white px-3 py-2 rounded shadow hover:bg-indigo-700 transition text-sm flex items-center gap-2"><i class="fas fa-video"></i> Live Scan</button>
            </div>
        </div>
        
        <!-- SCANNER UI (Hidden) -->
        <div id="scanner_area" class="hidden mb-6 bg-black rounded-lg relative overflow-hidden shadow-inner border-4 border-gray-800">
            <div class="absolute top-2 left-2 z-20">
                <select id="camera_select" class="bg-white text-gray-800 text-xs py-1 px-2 rounded shadow border border-gray-300 focus:outline-none"><option value="">Memuat Kamera...</option></select>
            </div>
            <div id="reader" class="w-full h-64 md:h-80 bg-black"></div>
            <div class="absolute top-2 right-2 z-10 flex flex-col gap-2">
                <button type="button" onclick="stopScan()" class="bg-red-600 text-white w-10 h-10 rounded-full shadow hover:bg-red-700 flex items-center justify-center"><i class="fas fa-times"></i></button>
                <button type="button" id="btn_flash" onclick="toggleFlash()" class="hidden bg-yellow-400 text-black w-10 h-10 rounded-full shadow hover:bg-yellow-500 flex items-center justify-center border-2 border-yellow-600"><i class="fas fa-bolt"></i></button>
            </div>
        </div>

        <form method="POST" autocomplete="off" onkeydown="return event.key != 'Enter';">
            <?= csrf_field() ?>
            
            <!-- TANGGAL & GUDANG -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="date" id="trx_date" value="<?= date('Y-m-d') ?>" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Gudang Penerima <span class="text-red-500">*</span></label>
                    <select name="warehouse_id" id="warehouse_id" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:outline-none bg-white font-bold text-gray-800" required>
                        <option value="" selected disabled>-- Pilih Gudang Penerima --</option>
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- REF & MANUAL SEARCH -->
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">No. Surat Jalan / Referensi</label>
                <div class="flex gap-2">
                    <input type="text" name="reference" id="reference_input" placeholder="Klik Auto untuk generate..." class="w-full border border-gray-300 p-2 rounded uppercase font-medium focus:ring-2 focus:ring-green-500 focus:outline-none" required>
                    <button type="button" id="btn_auto_ref" onclick="generateReference()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded hover:bg-gray-300 font-bold whitespace-nowrap border border-gray-300 text-sm"><i class="fas fa-magic text-green-600"></i> Auto</button>
                </div>
            </div>
            <div class="mb-4 bg-blue-50 p-3 rounded border border-blue-200">
                <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang dari Database (Manual)</label>
                <select id="manual_product_select" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-blue-500" onchange="handleManualProductSelect(this)">
                    <option value="">-- Pilih / Cari Nama Barang --</option>
                    <?php foreach($products_list as $prod): ?>
                        <option value="<?= $prod['sku'] ?>"><?= $prod['name'] ?> (Stok: <?= $prod['stock'] ?>) - SKU: <?= $prod['sku'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- SCAN INPUT -->
            <div class="mb-2">
                <label class="block text-sm font-bold text-gray-700 mb-1">Scan Barcode (SKU Produk / Serial Number)</label>
                <div class="flex gap-2">
                    <input type="text" name="sku" id="sku_input" class="w-full border-2 border-green-600 p-3 rounded font-mono font-bold text-lg focus:ring-4 focus:ring-green-200 focus:outline-none transition-all uppercase" placeholder="SCAN BARCODE / SN..." onchange="checkSku()" onkeydown="if(event.key === 'Enter'){ checkSku(); event.preventDefault(); }" required autofocus>
                    <button type="button" id="btn_gen_sku" onclick="generateNewSku()" class="bg-yellow-500 text-white px-3 rounded hover:bg-yellow-600 font-bold whitespace-nowrap text-sm"><i class="fas fa-plus"></i> SKU Baru</button>
                </div>
                <p id="sku_status" class="text-xs mt-1 min-h-[1.25rem] font-medium transition-all"></p>
                <input type="hidden" id="h_scanned_sn" value="">
            </div>

            <!-- DETAIL SECTION (ENABLE EDIT ALL) -->
            <div id="detail_section" class="hidden mb-6 p-5 border bg-green-50 border-green-200 rounded-lg transition-all duration-300">
                
                <div id="new_fields" class="mb-4 border-b pb-4 border-green-200">
                    <div id="info_banner" class="flex items-center gap-2 mb-3 bg-orange-100 text-orange-800 p-2 rounded border border-orange-200">
                        <i class="fas fa-info-circle text-lg"></i>
                        <span class="font-bold text-sm">Informasi Barang. Anda dapat mengubah Nama/Kategori jika perlu.</span>
                    </div>
                    
                    <div class="mb-3">
                        <label class="block text-xs text-gray-600 font-bold mb-1">Nama Barang <span class="text-red-500">*</span></label>
                        <input type="text" name="new_name" id="new_name" placeholder="Masukkan Nama Barang" class="w-full border p-2 rounded focus:ring-1 focus:ring-green-500 bg-white">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-600 font-bold mb-1">Kategori (Akun) <span class="text-red-500">*</span></label>
                            <select name="new_category" id="new_category" class="w-full border p-2 rounded focus:ring-1 focus:ring-green-500 bg-white">
                                <option value="" selected disabled>-- Pilih Kategori --</option>
                                <?php foreach($category_accounts as $acc): ?>
                                    <option value="<?= $acc['code'] ?>"><?= $acc['code'] ?> - <?= $acc['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 font-bold mb-1">Satuan</label>
                            <input type="text" name="new_unit" id="new_unit" placeholder="Pcs/Pack/Kg" class="w-full border p-2 rounded focus:ring-1 focus:ring-green-500 bg-white">
                        </div>
                    </div>
                    
                    <div class="mt-4 flex justify-center">
                        <div class="bg-white p-3 rounded border border-gray-300 text-center shadow-sm">
                            <div class="text-[10px] font-bold text-gray-500 mb-2">PREVIEW BARCODE</div>
                            <canvas id="new_barcode_canvas"></canvas>
                            <div class="mt-3 flex justify-center gap-2">
                                <button type="button" onclick="printNewLabel()" class="bg-blue-600 text-white px-3 py-1 rounded text-xs font-bold hover:bg-blue-700 flex items-center gap-1"><i class="fas fa-print"></i> Cetak Label</button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Harga Beli (HPP) <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <span class="absolute left-2 top-2 text-gray-500 text-xs">Rp</span>
                            <input type="text" name="buy_price" id="buy_price" onkeyup="formatRupiah(this)" class="w-full border p-2 pl-8 rounded focus:ring-1 focus:ring-green-500 text-right bg-white font-bold text-sm" placeholder="0">
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">Mengupdate HPP (Harga Pokok) terakhir.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Harga Jual (Opsional)</label>
                        <div class="relative">
                            <span class="absolute left-2 top-2 text-gray-500 text-xs">Rp</span>
                            <input type="text" name="sell_price" id="sell_price" onkeyup="formatRupiah(this)" class="w-full border p-2 pl-8 rounded focus:ring-1 focus:ring-green-500 text-right bg-white text-sm" placeholder="0">
                        </div>
                    </div>
                </div>
            </div>

            <!-- QTY INPUT -->
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Jumlah Masuk <span class="text-red-500">*</span></label>
                <input type="number" id="quantity_input" name="quantity" step="1" onchange="renderSnInputs()" onkeyup="renderSnInputs()" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:outline-none font-bold text-lg" placeholder="0" required>
            </div>

            <!-- SN INPUT AREA -->
            <div id="sn_container" class="hidden mb-6 bg-purple-50 p-4 rounded border border-purple-200">
                <!-- (Kode SN Input area sama seperti sebelumnya) -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-3 border-b border-purple-200 pb-2 gap-2">
                    <h4 class="font-bold text-purple-800 text-sm flex items-center gap-2"><i class="fas fa-barcode"></i> Input Serial Number (Wajib)</h4>
                    <div class="flex gap-1 flex-wrap">
                         <button type="button" onclick="focusNextEmptySn()" class="bg-gray-700 text-white px-2 py-1 rounded text-xs font-bold hover:bg-gray-800 flex items-center gap-1" title="Mode Cepat (USB)"><i class="fas fa-keyboard"></i> USB</button>
                         <input type="file" id="sn_file_input" accept="image/*" class="hidden" onchange="handleSnFileScan(this)">
                         <button type="button" onclick="document.getElementById('sn_file_input').click()" class="bg-indigo-600 text-white px-2 py-1 rounded text-xs font-bold hover:bg-indigo-700 flex items-center gap-1" title="Foto Scan"><i class="fas fa-camera"></i> Foto</button>
                         <button type="button" onclick="initSnCamera()" class="bg-blue-600 text-white px-2 py-1 rounded text-xs font-bold hover:bg-blue-700 flex items-center gap-1" title="Live Camera"><i class="fas fa-video"></i> Live</button>
                         <button type="button" onclick="autoGenerateSN()" class="bg-purple-600 text-white px-2 py-1 rounded text-xs font-bold hover:bg-purple-700 shadow flex items-center gap-1"><i class="fas fa-magic"></i> Generate Auto SN</button>
                    </div>
                </div>
                <div id="sn_scanner_area" class="hidden mb-3 bg-black rounded p-2 relative border-4 border-gray-800">
                    <div id="sn_reader" class="w-full h-48 bg-black"></div>
                    <button type="button" onclick="stopSnCamera()" class="absolute top-2 right-2 text-white bg-red-600 rounded-full w-8 h-8 flex items-center justify-center font-bold shadow hover:bg-red-700"><i class="fas fa-times"></i></button>
                    <div class="text-white text-center text-xs mt-1">Arahkan kamera ke Serial Number</div>
                </div>
                <div id="sn_inputs" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2"></div>
                <p class="text-xs text-purple-600 mt-2 italic">* Sistem akan mendeteksi apakah Anda menscan SN langsung.</p>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-bold text-gray-700 mb-1">Catatan</label>
                <textarea name="notes" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:outline-none" rows="2" placeholder="Keterangan tambahan..."></textarea>
            </div>

            <button type="submit" class="w-full bg-green-600 text-white py-3 rounded font-bold hover:bg-green-700 shadow transition transform active:scale-95 text-lg"><i class="fas fa-save"></i> Simpan Barang Masuk</button>
        </form>
    </div>

    <!-- LIST RIWAYAT -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-gray-800 mb-4 border-b pb-2">Riwayat Transaksi Masuk Terakhir</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border-b">Tanggal</th>
                        <th class="p-3 border-b">Ref</th>
                        <th class="p-3 border-b">Barang</th>
                        <th class="p-3 border-b text-right">Jumlah</th>
                        <th class="p-3 border-b text-center">Gudang</th>
                        <th class="p-3 border-b text-center">Cetak</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if(empty($recent_trx)): ?><tr><td colspan="6" class="p-4 text-center text-gray-400">Belum ada transaksi barang masuk.</td></tr><?php else: ?>
                        <?php foreach($recent_trx as $trx): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="p-3 whitespace-nowrap text-gray-600"><?= date('d/m/Y', strtotime($trx['date'])) ?></td>
                            <td class="p-3 font-mono text-xs font-bold text-blue-600"><?= h($trx['reference']) ?></td>
                            <td class="p-3"><div class="font-bold text-gray-800"><?= h($trx['prod_name']) ?></div><div class="text-xs text-gray-500"><?= h($trx['sku']) ?></div></td>
                            <td class="p-3 text-right font-bold text-green-700">+<?= number_format($trx['quantity']) ?></td>
                            <td class="p-3 text-center text-xs text-gray-600"><?= h($trx['wh_name']) ?></td>
                            <td class="p-3 text-center"><a href="?page=cetak_surat_jalan&id=<?= $trx['id'] ?>" target="_blank" class="text-blue-500 hover:text-blue-700 p-1 bg-blue-50 rounded border border-blue-200" title="Cetak Surat Jalan"><i class="fas fa-print"></i> SJ</a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// [JS: Audio, Camera, Manual Select, USB, dll sama seperti sebelumnya]
let html5QrCode; let snQrCode; let audioCtx = null; let isFlashOn = false;
const APP_NAME_PREFIX = "<?= $app_ref_prefix ?>";

function initAudio() { if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)(); if (audioCtx.state === 'suspended') audioCtx.resume(); }
function playBeep() { if (!audioCtx) return; try { const o = audioCtx.createOscillator(); const g = audioCtx.createGain(); o.connect(g); g.connect(audioCtx.destination); o.type = 'square'; o.frequency.setValueAtTime(1200, audioCtx.currentTime); g.gain.setValueAtTime(0.1, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.1); o.start(); o.stop(audioCtx.currentTime + 0.15); } catch (e) {} }
function handleManualProductSelect(s) { const sku = s.value; if (sku) { document.getElementById('sku_input').value = sku; checkSku(); s.value = ""; } }
function activateUSB() { const i = document.getElementById('sku_input'); i.focus(); i.select(); i.classList.add('ring-4', 'ring-yellow-400', 'border-yellow-500'); setTimeout(() => { i.classList.remove('ring-4', 'ring-yellow-400', 'border-yellow-500'); }, 1500); }
function formatRupiah(i) { let v = i.value.replace(/\D/g, ''); if(v === '') { i.value = ''; return; } i.value = new Intl.NumberFormat('id-ID').format(v); }

// *** MODIFIED checkSku() to ALLOW EDITING ***
async function checkSku() {
    const sku = document.getElementById('sku_input').value.trim();
    if (sku.length < 3) return;

    const statusEl = document.getElementById('sku_status');
    const detailSec = document.getElementById('detail_section');
    const newFields = document.getElementById('new_fields'); 
    const h_scanned_sn = document.getElementById('h_scanned_sn'); 
    const infoBanner = document.getElementById('info_banner');
    
    // Inputs
    const inpName = document.getElementById('new_name');
    const inpCat = document.getElementById('new_category');
    const inpUnit = document.getElementById('new_unit');
    
    statusEl.innerHTML = '<span class="text-blue-500"><i class="fas fa-spinner fa-spin"></i> Mencari data...</span>';

    try {
        const res = await fetch(`api.php?action=get_product_by_sku&sku=${encodeURIComponent(sku)}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        detailSec.classList.remove('hidden');
        newFields.classList.remove('hidden'); 

        if (data && data.id) {
            // --- PRODUK DITEMUKAN ---
            if (data.scanned_sn) {
                h_scanned_sn.value = data.scanned_sn;
                document.getElementById('quantity_input').value = 1;
                statusEl.innerHTML = `<span class="text-purple-600 font-bold"><i class="fas fa-tag"></i> SN: ${data.scanned_sn} -> Produk: ${data.name}</span>`;
            } else {
                h_scanned_sn.value = '';
                statusEl.innerHTML = `<span class="text-green-600 font-bold"><i class="fas fa-check-circle"></i> Produk: ${data.name} (Stok: ${data.stock})</span>`;
            }
            
            // KEY CHANGE: ALLOW EDITING FIELDS EVEN IF FOUND
            infoBanner.className = "flex items-center gap-2 mb-3 bg-blue-50 text-blue-800 p-2 rounded border border-blue-200";
            infoBanner.innerHTML = `<i class="fas fa-info-circle text-lg"></i><span class="font-bold text-sm">Produk Terdaftar. Anda bisa mengupdate Nama/Kategori/Harga jika diperlukan.</span>`;

            inpName.value = data.name;
            // Remove readonly logic
            inpName.removeAttribute('readonly'); 
            inpName.classList.remove('bg-gray-100', 'cursor-not-allowed');

            inpCat.value = data.category;
            // Remove disabled logic
            inpCat.removeAttribute('disabled');
            inpCat.classList.remove('bg-gray-100', 'cursor-not-allowed');

            inpUnit.value = data.unit;
            // Remove readonly logic
            inpUnit.removeAttribute('readonly');
            inpUnit.classList.remove('bg-gray-100', 'cursor-not-allowed');
            
            if(data.buy_price) document.getElementById('buy_price').value = new Intl.NumberFormat('id-ID').format(data.buy_price);
            if(data.sell_price) document.getElementById('sell_price').value = new Intl.NumberFormat('id-ID').format(data.sell_price);
            
            if(data.last_warehouse_id) document.getElementById('warehouse_id').value = data.last_warehouse_id;

            if (data.scanned_sn) renderSnInputs();
            else { document.querySelector('input[name="quantity"]').focus(); renderSnInputs(); }

        } else {
            // --- PRODUK BARU ---
            h_scanned_sn.value = '';
            statusEl.innerHTML = '<span class="text-orange-600 font-bold"><i class="fas fa-plus-circle"></i> Produk Baru. Silakan lengkapi data.</span>';
            
            infoBanner.className = "flex items-center gap-2 mb-3 bg-orange-100 text-orange-800 p-2 rounded border border-orange-200";
            infoBanner.innerHTML = `<i class="fas fa-plus-circle text-lg"></i><span class="font-bold text-sm">Barang Baru! Silakan lengkapi data master barang.</span>`;

            inpName.value = '';
            inpName.removeAttribute('readonly');
            inpName.classList.remove('bg-gray-100', 'cursor-not-allowed');

            inpCat.value = '';
            inpCat.removeAttribute('disabled');
            inpCat.classList.remove('bg-gray-100', 'cursor-not-allowed');

            inpUnit.value = '';
            inpUnit.removeAttribute('readonly');
            inpUnit.classList.remove('bg-gray-100', 'cursor-not-allowed');

            document.getElementById('new_name').focus();
            renderSnInputs();
            setTimeout(() => { try { bwipjs.toCanvas('new_barcode_canvas', { bcid: 'code128', text: sku, scale: 2, height: 10, includetext: true, textxalign: 'center' }); } catch(e) {} }, 100);
        }
    } catch (e) {
        statusEl.innerHTML = `<span class="text-red-600 font-bold">Error: ${e.message}</span>`;
    }
}

// [Fungsi Lain renderSnInputs, autoGenerateSN, dll tetap sama]
function renderSnInputs() { const qty = parseInt(document.getElementById('quantity_input').value) || 0; const container = document.getElementById('sn_container'); const inputsDiv = document.getElementById('sn_inputs'); const scannedSn = document.getElementById('h_scanned_sn').value; if (qty > 0) { container.classList.remove('hidden'); const currentInputs = inputsDiv.getElementsByTagName('input'); if (currentInputs.length === qty && !scannedSn) return; inputsDiv.innerHTML = ''; for (let i = 1; i <= qty; i++) { let val = (i === 1 && scannedSn) ? scannedSn : ''; const div = document.createElement('div'); div.innerHTML = `<div class="relative"><span class="absolute left-2 top-2 text-xs font-bold text-gray-400">#${i}</span><input type="text" name="sn_list[]" value="${val}" class="w-full border p-2 pl-8 rounded text-sm font-mono uppercase focus:ring-1 focus:ring-purple-500 sn-input-field" placeholder="SN Barang" required></div>`; inputsDiv.appendChild(div); } } else { container.classList.add('hidden'); inputsDiv.innerHTML = ''; } }
function autoGenerateSN() { const inputs = document.getElementsByName('sn_list[]'); if (inputs.length === 0) return alert("Tidak ada kolom SN."); const d = new Date(); const prefix = "SN" + d.getDate() + (d.getMonth()+1) + d.getFullYear().toString().substr(-2); for (let input of inputs) { if (!input.value) { const rand = Math.floor(1000 + Math.random() * 9000); input.value = prefix + "-" + rand; } } }
async function generateNewSku() { const btn = document.getElementById('btn_gen_sku'); btn.disabled = true; try { const res = await fetch('api.php?action=generate_sku'); const data = await res.json(); if (data.sku) { document.getElementById('sku_input').value = data.sku; checkSku(); } } catch(e) { alert(e.message); } finally { btn.disabled = false; } }
function printNewLabel() { const sku = document.getElementById('sku_input').value; const name = document.getElementById('new_name').value || 'NAMA BARANG'; if(!sku) return; document.getElementById('p_name').innerText = name; try { bwipjs.toCanvas('p_canvas', { bcid: 'code128', text: sku, scale: 2, height: 10, includetext: true, textxalign: 'center' }); window.print(); } catch(e) {} }
async function generateReference() { try { const res = await fetch('api.php?action=get_next_trx_number&type=IN'); const data = await res.json(); const d = new Date(); const ref = `${APP_NAME_PREFIX}/IN/${d.getDate()}/${d.getMonth()+1}/${d.getFullYear()}-${data.next_no.toString().padStart(3,'0')}`; document.getElementById('reference_input').value = ref; } catch (error) {} }

// [Scanner handlers (handleFileScan, initCamera, etc) sama seperti sebelumnya]
function handleFileScan(input) { if (input.files.length === 0) return; const file = input.files[0]; initAudio(); const skuInput = document.getElementById('sku_input'); skuInput.value = ""; const h = new Html5Qrcode("reader"); h.scanFile(file, true).then(d => { playBeep(); skuInput.value = d; checkSku(); input.value = ''; }).catch(e => { alert("Gagal."); input.value = ''; }); }
async function initCamera() { initAudio(); if(snQrCode) try { await snQrCode.stop(); } catch(e){} document.getElementById('scanner_area').classList.remove('hidden'); try { await navigator.mediaDevices.getUserMedia({ video: true }); const d = await Html5Qrcode.getCameras(); const s = document.getElementById('camera_select'); s.innerHTML = ""; if (d && d.length) { let id = d[0].id; d.forEach(dev => { if(dev.label.toLowerCase().includes('back')) id = dev.id; const o = document.createElement("option"); o.value = dev.id; o.text = dev.label; s.appendChild(o); }); s.value = id; startScan(id); s.onchange = () => { if(html5QrCode) html5QrCode.stop().then(() => startScan(s.value)); }; } } catch (e) {} }
function startScan(id) { if(html5QrCode) try { html5QrCode.stop(); } catch(e) {} html5QrCode = new Html5Qrcode("reader"); html5QrCode.start(id, { fps: 10, qrbox: { width: 250, height: 250 } }, (d) => { playBeep(); document.getElementById('sku_input').value = d; checkSku(); stopScan(); }, () => {}); }
function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => { document.getElementById('scanner_area').classList.add('hidden'); html5QrCode.clear(); }); }
function toggleFlash() { if(html5QrCode) { isFlashOn = !isFlashOn; html5QrCode.applyVideoConstraints({ advanced: [{ torch: isFlashOn }] }); } }

// [SN Scanner Logic]
function handleSnFileScan(i){if(!i.files.length)return;const f=i.files[0];initAudio();const h=new Html5Qrcode("sn_reader");h.scanFile(f,true).then(d=>{playBeep();processSnInput(d);i.value='';}).catch(e=>{alert("Gagal");i.value='';});}
async function initSnCamera(){initAudio();if(html5QrCode)try{await html5QrCode.stop();document.getElementById('scanner_area').classList.add('hidden');}catch(e){}document.getElementById('sn_scanner_area').classList.remove('hidden');try{await navigator.mediaDevices.getUserMedia({video:true});const d=await Html5Qrcode.getCameras();if(d&&d.length){startSnScan(d[0].id);}}catch(e){alert("Error kamera");}}
function startSnScan(id){if(snQrCode)try{snQrCode.stop()}catch(e){}snQrCode=new Html5Qrcode("sn_reader");snQrCode.start(id,{fps:10,qrbox:{width:200,height:200}},(d)=>{playBeep();processSnInput(d);},()=>{});}
function stopSnCamera(){if(snQrCode)snQrCode.stop().then(()=>{document.getElementById('sn_scanner_area').classList.add('hidden');snQrCode.clear();});}
function processSnInput(t){const inputs=document.querySelectorAll('.sn-input-field');for(let inp of inputs){if(inp.value===t){alert("SN sudah ada!");return;}}for(let inp of inputs){if(!inp.value){inp.value=t;inp.classList.add('bg-green-100');setTimeout(()=>inp.classList.remove('bg-green-100'),500);break;}}}
function focusNextEmptySn(){const inputs=document.querySelectorAll('.sn-input-field');for(let input of inputs){if(!input.value){input.focus();return;}}alert("Semua terisi.");}

document.addEventListener('DOMContentLoaded', generateReference);
</script>