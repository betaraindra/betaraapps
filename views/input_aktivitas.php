<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- 1. HANDLE HAPUS RIWAYAT (ROLLBACK) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    validate_csrf();
    $del_id = $_POST['delete_id'];
    
    $pdo->beginTransaction();
    try {
        // Ambil data transaksi lama
        $stmt = $pdo->prepare("SELECT type, product_id, quantity, reference FROM inventory_transactions WHERE id=?");
        $stmt->execute([$del_id]);
        $trx = $stmt->fetch();
        
        if ($trx) {
            // Rollback Stok
            $operator = ($trx['type'] == 'OUT') ? '+' : '-'; // Jika dulunya OUT, sekarang tambah stok (balikin)
            $pdo->prepare("UPDATE products SET stock = stock $operator ? WHERE id=?")->execute([$trx['quantity'], $trx['product_id']]);
            
            // Hapus Transaksi Inventori
            $pdo->prepare("DELETE FROM inventory_transactions WHERE id=?")->execute([$del_id]);
            
            // Reset SN jika perlu (Simple logic: jika hapus transaksi OUT, SN jadi Available. Jika hapus IN, SN jadi Sold/Deleted?)
            // Untuk keamanan, fitur hapus di sini hanya mengembalikan stok global. SN perlu penyesuaian manual jika kompleks.
            if ($trx['type'] == 'OUT') {
                $pdo->prepare("UPDATE product_serials SET status='AVAILABLE', out_transaction_id=NULL WHERE out_transaction_id=?")->execute([$del_id]);
            }
            
            logActivity($pdo, 'DELETE_ACTIVITY', "Hapus Aktivitas {$trx['reference']}");
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data aktivitas dihapus & stok dikembalikan.'];
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal hapus: '.$e->getMessage()];
    }
    echo "<script>window.location='?page=input_aktivitas';</script>";
    exit;
}

// --- 2. HANDLE SIMPAN AKTIVITAS (PEMAKAIAN / PENGEMBALIAN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_activity'])) {
    validate_csrf();
    
    $cart_json = $_POST['cart_json'];
    $cart = json_decode($cart_json, true);
    
    $mode = $_POST['activity_mode']; // 'OUT' (Pemakaian) atau 'IN' (Pengembalian)
    $date = $_POST['date'];
    $wh_id = $_POST['warehouse_id']; 
    $ref = $_POST['reference'];
    $desc_main = trim($_POST['description']);
    
    // Validasi
    if (empty($wh_id)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gudang harus dipilih.'];
    } elseif (empty($desc_main)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Keterangan wajib diisi.'];
    } elseif (empty($cart) || !is_array($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Keranjang barang kosong!'];
    } else {
        $pdo->beginTransaction();
        try {
            // Ambil Nama Gudang
            $stmtWh = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
            $stmtWh->execute([$wh_id]);
            $wh_name = $stmtWh->fetchColumn();
            
            // Tentukan Akun Keuangan
            $accId = null;
            $finType = '';
            
            if ($mode == 'OUT') {
                // PEMAKAIAN -> EXPENSE (Beban)
                $accStmt = $pdo->query("SELECT id FROM accounts WHERE code = '2009' LIMIT 1"); // Pengeluaran Wilayah
                $accId = $accStmt->fetchColumn();
                if (!$accId) $accId = $pdo->query("SELECT id FROM accounts WHERE code = '2105' LIMIT 1")->fetchColumn(); // Material
                $finType = 'EXPENSE';
            } else {
                // PENGEMBALIAN -> INCOME (Recovery Asset/Pemasukan Wilayah)
                // Kita gunakan akun 1004 (Pemasukan Wilayah) atau 1003 (Lain-lain) untuk menyeimbangkan
                $accStmt = $pdo->query("SELECT id FROM accounts WHERE code = '1004' LIMIT 1");
                $accId = $accStmt->fetchColumn();
                if (!$accId) $accId = $pdo->query("SELECT id FROM accounts WHERE code = '1003' LIMIT 1")->fetchColumn();
                $finType = 'INCOME';
            }

            $count = 0;
            foreach ($cart as $item) {
                // Cek Produk
                $stmt = $pdo->prepare("SELECT id, stock, buy_price, name, has_serial_number FROM products WHERE sku = ?");
                $stmt->execute([$item['sku']]);
                $prod = $stmt->fetch();
                
                if (!$prod) throw new Exception("Produk SKU {$item['sku']} tidak ditemukan.");
                
                // --- LOGIKA PEMAKAIAN (OUT) ---
                if ($mode == 'OUT') {
                    // Cek SN & Stok
                    if ($prod['has_serial_number'] == 1) {
                        if (empty($item['sns']) || count($item['sns']) != $item['qty']) {
                            throw new Exception("Produk {$prod['name']} wajib SN sesuai Qty.");
                        }
                    }
                    if ($prod['stock'] < $item['qty']) {
                        throw new Exception("Stok {$item['name']} tidak cukup! Sisa: {$prod['stock']}");
                    }

                    // Kurangi Stok
                    $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$item['qty'], $prod['id']]);
                    
                    // Catat Transaksi OUT
                    $notes = "Aktivitas: " . $desc_main . ($item['notes'] ? " ({$item['notes']})" : "");
                    if(!empty($item['sns'])) $notes .= " [SN: " . implode(', ', $item['sns']) . "]";
                    
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)")
                        ->execute([$date, $prod['id'], $wh_id, $item['qty'], $ref, $notes, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();

                    // Update SN jadi SOLD
                    if ($prod['has_serial_number'] == 1 && !empty($item['sns'])) {
                        $snStmt = $pdo->prepare("UPDATE product_serials SET status = 'SOLD', out_transaction_id = ? WHERE product_id = ? AND serial_number = ? AND status = 'AVAILABLE'");
                        foreach ($item['sns'] as $sn) {
                            $snStmt->execute([$trx_id, $prod['id'], $sn]);
                            if ($snStmt->rowCount() == 0) throw new Exception("SN $sn tidak tersedia/salah.");
                        }
                    }
                } 
                
                // --- LOGIKA PENGEMBALIAN (IN) ---
                else {
                    // Tambah Stok
                    $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([$item['qty'], $prod['id']]);
                    
                    // Catat Transaksi IN
                    $notes = "Pengembalian: " . $desc_main . ($item['notes'] ? " ({$item['notes']})" : "");
                    if(!empty($item['sns'])) $notes .= " [SN: " . implode(', ', $item['sns']) . "]";

                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)")
                        ->execute([$date, $prod['id'], $wh_id, $item['qty'], $ref, $notes, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();

                    // Update SN jadi AVAILABLE
                    if ($prod['has_serial_number'] == 1 && !empty($item['sns'])) {
                        // Cek apakah SN ada? Jika ada update, jika tidak insert (kalau barang lama banget)
                        $checkSn = $pdo->prepare("SELECT id FROM product_serials WHERE product_id=? AND serial_number=?");
                        $stmtUpdate = $pdo->prepare("UPDATE product_serials SET status = 'AVAILABLE', warehouse_id = ?, in_transaction_id = ? WHERE id = ?");
                        $stmtInsert = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");

                        foreach ($item['sns'] as $sn) {
                            $checkSn->execute([$prod['id'], $sn]);
                            $existingSn = $checkSn->fetch();
                            
                            if ($existingSn) {
                                $stmtUpdate->execute([$wh_id, $trx_id, $existingSn['id']]);
                            } else {
                                $stmtInsert->execute([$prod['id'], $sn, $wh_id, $trx_id]);
                            }
                        }
                    }
                }

                // --- CATAT KEUANGAN (AUTO) ---
                $total_value = $item['qty'] * $prod['buy_price'];
                if ($accId && $total_value > 0) {
                    $prefix = ($mode == 'OUT') ? "[PEMAKAIAN]" : "[PENGEMBALIAN]";
                    $fin_desc = "$prefix {$prod['name']} ({$item['qty']}) - $desc_main [Wilayah: $wh_name]";
                    
                    $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$date, $finType, $accId, $total_value, $fin_desc, $_SESSION['user_id']]);
                }

                $count++;
            }
            
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$count item berhasil diproses ($mode)."];
            echo "<script>window.location='?page=input_aktivitas';</script>";
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: '.$e->getMessage()];
        }
    }
}

// --- 3. LOAD DATA UNTUK TABEL LIST ---
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');
$search_q = $_GET['q'] ?? '';

// Pagination
$page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$limit = 20;
$offset = ($page_num - 1) * $limit;

// Query List Aktivitas (Notes contains 'Aktivitas:' or 'Pengembalian:')
$sql_list = "SELECT i.*, p.name as prod_name, p.sku, w.name as wh_name, u.username 
             FROM inventory_transactions i 
             JOIN products p ON i.product_id = p.id 
             JOIN warehouses w ON i.warehouse_id = w.id
             JOIN users u ON i.user_id = u.id
             WHERE (i.notes LIKE 'Aktivitas:%' OR i.notes LIKE 'Pengembalian:%')
             AND i.date BETWEEN ? AND ?
             AND (p.name LIKE ? OR i.reference LIKE ? OR i.notes LIKE ?)
             ORDER BY i.date DESC, i.created_at DESC
             LIMIT $limit OFFSET $offset";

$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute([$start_date, $end_date, "%$search_q%", "%$search_q%", "%$search_q%"]);
$activities_data = $stmt_list->fetchAll();

// Count for Pagination
$sql_count = "SELECT COUNT(*) FROM inventory_transactions i 
              JOIN products p ON i.product_id = p.id 
              WHERE (i.notes LIKE 'Aktivitas:%' OR i.notes LIKE 'Pengembalian:%')
              AND i.date BETWEEN ? AND ?
              AND (p.name LIKE ? OR i.reference LIKE ? OR i.notes LIKE ?)";
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute([$start_date, $end_date, "%$search_q%", "%$search_q%", "%$search_q%"]);
$total_rows = $stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$app_ref_prefix = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<!-- UI HALAMAN -->
<div class="space-y-6">
    
    <!-- FORM INPUT SECTION -->
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-purple-600">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 border-b pb-4 gap-4">
            <div>
                <h3 class="font-bold text-xl text-purple-700 flex items-center gap-2">
                    <i class="fas fa-tools"></i> Input Aktivitas & Pengembalian
                </h3>
                <p class="text-sm text-gray-500 mt-1">Catat pemakaian barang operasional atau pengembalian (berhenti berlangganan).</p>
            </div>
            
            <div class="flex gap-2 flex-wrap">
                <button type="button" onclick="activateUSB()" class="bg-gray-700 text-white px-3 py-2 rounded shadow hover:bg-gray-800 transition text-sm flex items-center gap-2" title="Scanner USB">
                    <i class="fas fa-keyboard"></i> USB Mode
                </button>
                <input type="file" id="scan_image_file" accept="image/*" capture="environment" class="hidden" onchange="handleFileScan(this)">
                <button type="button" onclick="document.getElementById('scan_image_file').click()" class="bg-purple-600 text-white px-3 py-2 rounded shadow hover:bg-purple-700 transition text-sm flex items-center gap-2" title="Upload Foto">
                    <i class="fas fa-camera"></i> Foto Scan
                </button>
                <button type="button" onclick="initCamera()" class="bg-indigo-600 text-white px-3 py-2 rounded shadow hover:bg-indigo-700 transition text-sm flex items-center gap-2" title="Live Camera">
                    <i class="fas fa-video"></i> Live Scan
                </button>
            </div>
        </div>

        <!-- Area Scanner Live -->
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
        
        <form method="POST" id="form_activity" onsubmit="return validateCart()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_activity" value="1">
            <input type="hidden" name="cart_json" id="cart_json">

            <!-- MODE SELECTOR -->
            <div class="mb-4">
                <div class="flex rounded-md shadow-sm" role="group">
                    <label class="flex-1 cursor-pointer">
                        <input type="radio" name="activity_mode" value="OUT" class="peer sr-only" checked onchange="toggleMode('OUT')">
                        <div class="px-4 py-2 bg-white border border-gray-200 rounded-l-lg hover:bg-gray-100 peer-checked:bg-purple-600 peer-checked:text-white peer-checked:font-bold text-center transition">
                            <i class="fas fa-minus-circle mr-2"></i> Pemakaian (Keluar)
                        </div>
                    </label>
                    <label class="flex-1 cursor-pointer">
                        <input type="radio" name="activity_mode" value="IN" class="peer sr-only" onchange="toggleMode('IN')">
                        <div class="px-4 py-2 bg-white border border-gray-200 rounded-r-lg hover:bg-gray-100 peer-checked:bg-teal-600 peer-checked:text-white peer-checked:font-bold text-center transition">
                            <i class="fas fa-undo mr-2"></i> Pengembalian (Masuk)
                        </div>
                    </label>
                </div>
            </div>

            <!-- HEADER INPUT -->
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Tanggal</label>
                        <input type="date" name="date" id="trx_date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Lokasi Gudang / Wilayah <span class="text-red-500">*</span></label>
                        <select name="warehouse_id" id="warehouse_id" class="w-full border p-2 rounded text-sm bg-white font-bold text-gray-800" required>
                            <option value="" selected disabled>-- Pilih Gudang --</option>
                            <?php foreach($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">ID Pelanggan / No. Tiket / Ref</label>
                        <div class="flex gap-1">
                            <input type="text" name="reference" id="reference_input" class="w-full border p-2 rounded text-sm uppercase font-bold" placeholder="Contoh: PLG-001">
                            <button type="button" onclick="generateReference()" class="bg-gray-200 px-3 rounded text-gray-600 hover:bg-gray-300"><i class="fas fa-magic"></i></button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Keterangan <span class="text-red-500">*</span></label>
                        <input type="text" name="description" id="desc_input" class="w-full border p-2 rounded text-sm" placeholder="Contoh: Instalasi Modem" required>
                    </div>
                </div>
            </div>

            <!-- ITEM INPUT -->
            <div id="item_input_container" class="bg-purple-50 p-4 rounded-lg border border-purple-200 mb-6 transition-colors duration-300">
                <h4 class="font-bold text-purple-800 mb-3 text-sm border-b border-purple-200 pb-1" id="item_title">Input Barang Pemakaian</h4>
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                    <div class="md:col-span-4">
                        <label class="block text-xs font-bold text-gray-600 mb-1">SKU / SN Barcode</label>
                        <div class="flex gap-1">
                            <input type="text" id="sku_input" class="w-full border-2 border-purple-500 p-2 rounded font-mono font-bold text-lg uppercase transition-colors" placeholder="SCAN..." onchange="checkSku()" onkeydown="if(event.key === 'Enter'){ checkSku(); event.preventDefault(); }">
                            <button type="button" onclick="checkSku()" class="bg-blue-600 text-white px-3 rounded hover:bg-blue-700"><i class="fas fa-search"></i></button>
                        </div>
                        <span id="sku_status" class="text-[10px] block mt-1 font-bold"></span>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Qty</label>
                        <input type="number" id="qty_input" class="w-full border p-2 rounded font-bold text-center" placeholder="0" onchange="renderSnInputs()" onkeydown="if(event.key === 'Enter'){ addToCart(); event.preventDefault(); }">
                    </div>

                    <div class="md:col-span-4">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Catatan Item</label>
                        <input type="text" id="notes_input" class="w-full border p-2 rounded" placeholder="Keterangan per barang..." onkeydown="if(event.key === 'Enter'){ addToCart(); event.preventDefault(); }">
                    </div>

                    <div class="md:col-span-2">
                        <button type="button" onclick="addToCart()" id="btn_add" class="w-full bg-orange-500 text-white py-2 rounded font-bold hover:bg-orange-600 shadow flex justify-center items-center gap-2">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                    </div>
                </div>
                
                <!-- SN INPUT AREA -->
                <div id="sn_out_container" class="hidden mt-4 bg-white bg-opacity-60 p-3 rounded border border-gray-300">
                    <h5 class="text-xs font-bold text-gray-800 mb-2">Scan Serial Number (Wajib)</h5>
                    <div id="sn_out_inputs" class="grid grid-cols-2 md:grid-cols-4 gap-2"></div>
                </div>

                <input type="hidden" id="h_name"><input type="hidden" id="h_stock">
                <input type="hidden" id="h_has_sn" value="0">
                <input type="hidden" id="h_scanned_sn" value="">
            </div>

            <!-- CART TABLE -->
            <div class="mb-6">
                <h4 class="font-bold text-gray-800 mb-2">Keranjang <span id="cart_count" class="bg-gray-600 text-white text-xs px-2 rounded-full">0</span></h4>
                <div class="border rounded-lg overflow-hidden bg-white shadow-sm">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-100 text-gray-700 border-b">
                            <tr>
                                <th class="p-3">SKU</th>
                                <th class="p-3">Barang</th>
                                <th class="p-3 text-center">Qty</th>
                                <th class="p-3">SN</th>
                                <th class="p-3">Ket</th>
                                <th class="p-3 text-center"><i class="fas fa-trash"></i></th>
                            </tr>
                        </thead>
                        <tbody id="cart_table_body">
                            <tr><td colspan="6" class="p-4 text-center text-gray-400 italic">Belum ada barang.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <button type="submit" id="btn_process" class="w-full bg-purple-600 text-white py-3 rounded-lg font-bold text-lg hover:bg-purple-700 shadow-lg disabled:bg-gray-400 flex justify-center items-center gap-2 transition-colors duration-300">
                <i class="fas fa-save"></i> Simpan Aktivitas (Keluar)
            </button>
        </form>
    </div>

    <!-- TABEL DATA AKTIVITAS (HISTORI) -->
    <div class="bg-white p-6 rounded-lg shadow mt-8">
        <div class="flex flex-col md:flex-row justify-between items-center mb-4 border-b pb-4 gap-4">
            <h3 class="font-bold text-lg text-gray-800"><i class="fas fa-history text-blue-600"></i> Riwayat Data Aktivitas</h3>
            
            <!-- FILTER TABEL -->
            <form method="GET" class="flex flex-wrap gap-2 items-center">
                <input type="hidden" name="page" value="input_aktivitas">
                <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="Cari..." class="border p-1 rounded text-sm w-32">
                <input type="date" name="start" value="<?= $start_date ?>" class="border p-1 rounded text-sm">
                <input type="date" name="end" value="<?= $end_date ?>" class="border p-1 rounded text-sm">
                <button class="bg-blue-600 text-white px-3 py-1 rounded text-sm"><i class="fas fa-search"></i></button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border-b">Tanggal</th>
                        <th class="p-3 border-b">Tipe</th>
                        <th class="p-3 border-b">Ref / Ket</th>
                        <th class="p-3 border-b">Barang</th>
                        <th class="p-3 border-b text-right">Qty</th>
                        <th class="p-3 border-b">Gudang</th>
                        <th class="p-3 border-b text-center">User</th>
                        <th class="p-3 border-b text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if(empty($activities_data)): ?>
                        <tr><td colspan="8" class="p-4 text-center text-gray-400">Belum ada data aktivitas sesuai filter.</td></tr>
                    <?php else: ?>
                        <?php foreach($activities_data as $row): 
                            $is_return = strpos($row['notes'], 'Pengembalian:') !== false || $row['type'] == 'IN';
                            $row_class = $is_return ? 'bg-teal-50' : 'hover:bg-gray-50';
                            $badge = $is_return ? '<span class="bg-teal-100 text-teal-800 px-2 py-1 rounded text-xs font-bold">RETUR</span>' : '<span class="bg-purple-100 text-purple-800 px-2 py-1 rounded text-xs font-bold">PAKAI</span>';
                        ?>
                        <tr class="<?= $row_class ?>">
                            <td class="p-3 whitespace-nowrap"><?= date('d/m/y', strtotime($row['date'])) ?></td>
                            <td class="p-3 text-center"><?= $badge ?></td>
                            <td class="p-3">
                                <div class="font-bold text-xs text-blue-700"><?= h($row['reference']) ?></div>
                                <div class="text-xs text-gray-500 italic truncate max-w-xs"><?= h($row['notes']) ?></div>
                            </td>
                            <td class="p-3">
                                <div class="font-bold"><?= h($row['prod_name']) ?></div>
                                <div class="text-xs font-mono"><?= h($row['sku']) ?></div>
                            </td>
                            <td class="p-3 text-right font-bold <?= $is_return?'text-green-600':'text-red-600' ?>">
                                <?= $is_return ? '+' : '-' ?><?= number_format($row['quantity']) ?>
                            </td>
                            <td class="p-3 text-xs text-gray-600"><?= h($row['wh_name']) ?></td>
                            <td class="p-3 text-center text-xs"><?= h($row['username']) ?></td>
                            <td class="p-3 text-center">
                                <form method="POST" onsubmit="return confirm('Hapus aktivitas ini? Stok akan dikembalikan.')" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                    <button class="text-red-400 hover:text-red-600 p-1" title="Hapus / Rollback"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if($total_pages > 1): ?>
        <div class="flex justify-between items-center mt-4 pt-4 border-t">
            <span class="text-xs text-gray-500">Halaman <?= $page_num ?> dari <?= $total_pages ?></span>
            <div class="flex gap-1">
                <?php if($page_num > 1): ?>
                    <a href="?page=input_aktivitas&p=<?= $page_num-1 ?>&q=<?=h($search_q)?>&start=<?=$start_date?>&end=<?=$end_date?>" class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 text-xs">Prev</a>
                <?php endif; ?>
                <?php if($page_num < $total_pages): ?>
                    <a href="?page=input_aktivitas&p=<?= $page_num+1 ?>&q=<?=h($search_q)?>&start=<?=$start_date?>&end=<?=$end_date?>" class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 text-xs">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
let html5QrCode;
let audioCtx = null;
let isFlashOn = false;
let cart = [];
let currentMode = 'OUT'; // Default
const APP_NAME_PREFIX = "<?= $app_ref_prefix ?>";

// --- MODE TOGGLE ---
function toggleMode(mode) {
    currentMode = mode;
    const container = document.getElementById('item_input_container');
    const title = document.getElementById('item_title');
    const skuInput = document.getElementById('sku_input');
    const btn = document.getElementById('btn_process');
    const descInput = document.getElementById('desc_input');
    const whSelect = document.getElementById('warehouse_id');

    if (mode === 'IN') {
        // STYLE RETURN (TEAL/GREEN)
        container.classList.remove('bg-purple-50', 'border-purple-200');
        container.classList.add('bg-teal-50', 'border-teal-200');
        title.innerText = "Input Barang Pengembalian (Return)";
        title.classList.remove('text-purple-800', 'border-purple-200');
        title.classList.add('text-teal-800', 'border-teal-200');
        
        skuInput.classList.remove('border-purple-500');
        skuInput.classList.add('border-teal-500');
        
        btn.classList.remove('bg-purple-600', 'hover:bg-purple-700');
        btn.classList.add('bg-teal-600', 'hover:bg-teal-700');
        btn.innerHTML = '<i class="fas fa-undo"></i> Simpan Pengembalian (Masuk)';
        
        descInput.placeholder = "Contoh: Berhenti Berlangganan ID 123";
    } else {
        // STYLE OUT (PURPLE)
        container.classList.remove('bg-teal-50', 'border-teal-200');
        container.classList.add('bg-purple-50', 'border-purple-200');
        title.innerText = "Input Barang Pemakaian";
        title.classList.remove('text-teal-800', 'border-teal-200');
        title.classList.add('text-purple-800', 'border-purple-200');
        
        skuInput.classList.remove('border-teal-500');
        skuInput.classList.add('border-purple-500');
        
        btn.classList.remove('bg-teal-600', 'hover:bg-teal-700');
        btn.classList.add('bg-purple-600', 'hover:bg-purple-700');
        btn.innerHTML = '<i class="fas fa-save"></i> Simpan Aktivitas (Keluar)';
        
        descInput.placeholder = "Contoh: Instalasi Modem di Rumah Bpk. Budi";
    }
    
    // Clear cart when switching mode to avoid confusion
    if(cart.length > 0) {
        if(confirm("Ganti mode akan mengosongkan keranjang saat ini. Lanjutkan?")) {
            cart = [];
            renderCart();
        } else {
            // Revert radio button
            document.querySelector(`input[name="activity_mode"][value="${mode === 'OUT' ? 'IN' : 'OUT'}"]`).checked = true;
            toggleMode(mode === 'OUT' ? 'IN' : 'OUT'); // Switch back logic style
        }
    }
}

// --- GENERATE REFERENCE ---
async function generateReference() {
    const d = new Date();
    const dateStr = d.getDate().toString().padStart(2, '0') + (d.getMonth()+1).toString().padStart(2, '0');
    const rand = Math.floor(100 + Math.random() * 900);
    // Prefix beda dikit biar keren
    const prefix = currentMode === 'IN' ? 'RET' : 'ACT';
    document.getElementById('reference_input').value = `${prefix}/${dateStr}/${rand}`;
}

// --- SCANNER & CART LOGIC (REUSED FROM BARANG_KELUAR) ---
function initAudio() { if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)(); if (audioCtx.state === 'suspended') audioCtx.resume(); }
function playBeep() { if (!audioCtx) return; try { const o = audioCtx.createOscillator(); const g = audioCtx.createGain(); o.connect(g); g.connect(audioCtx.destination); o.type = 'square'; o.frequency.setValueAtTime(1200, audioCtx.currentTime); g.gain.setValueAtTime(0.1, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.1); o.start(); o.stop(audioCtx.currentTime + 0.15); } catch (e) {} }
function activateUSB() { const i = document.getElementById('sku_input'); i.focus(); i.select(); i.classList.add('ring-4', 'ring-yellow-400'); setTimeout(() => i.classList.remove('ring-4', 'ring-yellow-400'), 1500); }
function handleFileScan(input) { if (!input.files.length) return; const f = input.files[0]; initAudio(); const i = document.getElementById('sku_input'); const p = i.placeholder; i.value = ""; i.placeholder = "Processing..."; const h = new Html5Qrcode("reader"); h.scanFile(f, true).then(t => { playBeep(); i.value = t; i.placeholder = p; checkSku(); input.value = ''; }).catch(e => { alert("Gagal baca."); i.placeholder = p; input.value = ''; }); }
async function initCamera() { initAudio(); document.getElementById('scanner_area').classList.remove('hidden'); try { await navigator.mediaDevices.getUserMedia({ video: true }); const d = await Html5Qrcode.getCameras(); const s = document.getElementById('camera_select'); s.innerHTML = ""; if (d && d.length) { let id = d[0].id; d.forEach(dev => { if(dev.label.toLowerCase().includes('back')) id = dev.id; const o = document.createElement("option"); o.value = dev.id; o.text = dev.label; s.appendChild(o); }); s.value = id; startScan(id); s.onchange = () => { if(html5QrCode) html5QrCode.stop().then(() => startScan(s.value)); }; } else alert("No camera"); } catch (e) { alert("Camera error"); document.getElementById('scanner_area').classList.add('hidden'); } }
function startScan(id) { if(html5QrCode) try{html5QrCode.stop()}catch(e){} html5QrCode = new Html5Qrcode("reader"); html5QrCode.start(id, { fps: 10, qrbox: { width: 250, height: 250 } }, (t) => { document.getElementById('sku_input').value = t; playBeep(); checkSku(); stopScan(); }, () => {}).then(() => { const t = html5QrCode.getRunningTrackCameraCapabilities(); if(t && t.torchFeature().isSupported()) document.getElementById('btn_flash').classList.remove('hidden'); }); }
function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => { document.getElementById('scanner_area').classList.add('hidden'); html5QrCode.clear(); }).catch(() => document.getElementById('scanner_area').classList.add('hidden')); }
function toggleFlash() { if(html5QrCode) { isFlashOn = !isFlashOn; html5QrCode.applyVideoConstraints({ advanced: [{ torch: isFlashOn }] }); } }

async function checkSku() { 
    const s = document.getElementById('sku_input').value.trim(); 
    if(s.length < 3) return; 
    const stat = document.getElementById('sku_status'); 
    const n = document.getElementById('h_name'); 
    const st = document.getElementById('h_stock'); 
    const h_has_sn = document.getElementById('h_has_sn');
    const h_scanned_sn = document.getElementById('h_scanned_sn');
    
    stat.innerHTML = 'Searching...'; 
    try { 
        const r = await fetch(`api.php?action=get_product_by_sku&sku=${encodeURIComponent(s)}`); 
        const d = await r.json(); 
        if(d && d.id) { 
            stat.innerHTML = `<span class="text-green-600">Found: ${d.name} (${d.stock})</span>`; 
            n.value = d.name; st.value = d.stock; h_has_sn.value = d.has_serial_number;
            
            if (d.scanned_sn) {
                h_scanned_sn.value = d.scanned_sn;
                document.getElementById('qty_input').value = 1;
                renderSnInputs();
            } else {
                h_scanned_sn.value = '';
                document.getElementById('qty_input').focus(); 
            }

            // Auto Warehouse Selection logic from prev scan
            if(d.last_warehouse_id) {
                const whSelect = document.getElementById('warehouse_id');
                if(!whSelect.value) { // Only change if not selected
                    whSelect.value = d.last_warehouse_id;
                }
            }
            
            if (d.scanned_sn) setTimeout(() => addToCart(), 200);

        } else { 
            stat.innerHTML = '<span class="text-red-600">Not Found!</span>'; 
            n.value=''; st.value=0; h_has_sn.value=0; h_scanned_sn.value='';
        } 
    } catch(e){ stat.innerText = "Error API"; } 
}

function renderSnInputs() {
    const qty = parseInt(document.getElementById('qty_input').value) || 0;
    const hasSn = document.getElementById('h_has_sn').value == '1';
    const container = document.getElementById('sn_out_container');
    const inputsDiv = document.getElementById('sn_out_inputs');
    const scannedSn = document.getElementById('h_scanned_sn').value;
    
    if (hasSn && qty > 0) {
        container.classList.remove('hidden');
        inputsDiv.innerHTML = '';
        for (let i = 1; i <= qty; i++) {
            let val = (i === 1 && scannedSn) ? scannedSn : '';
            const div = document.createElement('div');
            div.innerHTML = `<input type="text" name="sn_out[]" value="${val}" class="w-full border p-1 rounded text-sm font-mono uppercase bg-white border-purple-400" placeholder="SN #${i}" required>`;
            inputsDiv.appendChild(div);
        }
    } else {
        container.classList.add('hidden');
        inputsDiv.innerHTML = '';
    }
}

function addToCart() { 
    const s = document.getElementById('sku_input').value.trim(); 
    const n = document.getElementById('h_name').value; 
    const st = parseFloat(document.getElementById('h_stock').value||0); 
    const q = parseFloat(document.getElementById('qty_input').value); 
    const nt = document.getElementById('notes_input').value; 
    const hasSn = document.getElementById('h_has_sn').value == '1';
    
    if(!n) return alert("Scan item first"); 
    if(!q || q<=0) return alert("Qty invalid"); 
    
    // Only check stock shortage if mode is OUT
    if(currentMode === 'OUT' && q > st) return alert("Stok kurang!"); 
    
    let sns = [];
    if(hasSn) {
        const inputs = document.getElementsByName('sn_out[]');
        if(inputs.length != q) return alert("Jumlah input SN harus sama dengan Qty");
        for(let input of inputs) {
            const val = input.value.trim();
            if(!val) return alert("Semua Serial Number harus diisi!");
            if(sns.includes(val)) return alert("SN duplikat terdeteksi: " + val);
            sns.push(val);
        }
    }

    const exist = cart.findIndex(x => x.sku === s); 
    if(exist > -1) { 
        if(currentMode === 'OUT' && (cart[exist].qty + q) > st) return alert("Total stok kurang!"); 
        cart[exist].qty += q;
        if(hasSn) cart[exist].sns = cart[exist].sns.concat(sns);
    } else { 
        cart.push({sku:s, name:n, qty:q, notes:nt, sns: sns, has_sn: hasSn}); 
    } 
    renderCart(); 
    
    document.getElementById('sku_input').value=''; 
    document.getElementById('h_name').value=''; 
    document.getElementById('h_stock').value=''; 
    document.getElementById('h_has_sn').value='0';
    document.getElementById('h_scanned_sn').value='';
    document.getElementById('qty_input').value=''; 
    document.getElementById('notes_input').value=''; 
    document.getElementById('sku_status').innerText=''; 
    document.getElementById('sn_out_container').classList.add('hidden');
    document.getElementById('sn_out_inputs').innerHTML = '';
    document.getElementById('sku_input').focus(); 
}

function renderCart() { 
    const b = document.getElementById('cart_table_body'); 
    b.innerHTML = ''; 
    if(cart.length===0) { 
        b.innerHTML = '<tr><td colspan="6" class="p-4 text-center text-gray-400">Empty</td></tr>'; 
        document.getElementById('btn_process').disabled = true; 
    } else { 
        document.getElementById('btn_process').disabled = false; 
        cart.forEach((i,x) => { 
            let snHtml = '-';
            if(i.has_sn && i.sns.length > 0) snHtml = i.sns.map(s => `<span class="bg-purple-100 text-purple-800 text-[10px] px-1 rounded mr-1">${s}</span>`).join(' ');
            const r = document.createElement('tr'); 
            r.innerHTML = `
                <td class="p-3 font-mono">${i.sku}</td>
                <td class="p-3">${i.name}</td>
                <td class="p-3 text-center font-bold">${i.qty}</td>
                <td class="p-3">${snHtml}</td>
                <td class="p-3 italic">${i.notes}</td>
                <td class="p-3 text-center"><button type="button" onclick="removeFromCart(${x})" class="text-red-500"><i class="fas fa-trash"></i></button></td>
            `; 
            b.appendChild(r); 
        }); 
    } 
    document.getElementById('cart_json').value = JSON.stringify(cart); 
    document.getElementById('cart_count').innerText = cart.length; 
}

function removeFromCart(i) { cart.splice(i,1); renderCart(); }
function validateCart() { 
    const wh = document.getElementById('warehouse_id').value;
    if(cart.length===0) { alert('Keranjang kosong'); return false; }
    if(!wh) { alert('Pilih Lokasi Gudang!'); return false; }
    
    const msg = currentMode === 'OUT' 
        ? "Simpan aktivitas? Stok berkurang & Biaya tercatat." 
        : "Simpan pengembalian? Stok bertambah & Aset pulih.";
    
    return confirm(msg); 
}

document.addEventListener('DOMContentLoaded', generateReference);
</script>