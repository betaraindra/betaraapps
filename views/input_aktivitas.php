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
            
            // Reset SN jika perlu
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
            
            // Tentukan Akun Keuangan (Hanya untuk referensi, tidak dicatat)
            $accId = null;
            $finType = '';
            
            if ($mode == 'OUT') {
                // PEMAKAIAN -> EXPENSE (Beban)
                $accStmt = $pdo->query("SELECT id FROM accounts WHERE code = '2105' LIMIT 1");
                $accId = $accStmt->fetchColumn();
                $finType = 'EXPENSE';
            } else {
                // PENGEMBALIAN -> INCOME
                $accStmt = $pdo->query("SELECT id FROM accounts WHERE code = '1004' LIMIT 1");
                $accId = $accStmt->fetchColumn();
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
                    // Cek SN (MANDATORY)
                    if (empty($item['sns']) || count($item['sns']) != $item['qty']) {
                        throw new Exception("Produk {$prod['name']} wajib SN sesuai Qty.");
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
                    if (!empty($item['sns'])) {
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
                    if (!empty($item['sns'])) {
                        // Cek apakah SN ada? Jika ada update, jika tidak insert
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

                $count++;
            }
            
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$count item berhasil diproses ($mode). Stok diperbarui."];
            echo "<script>window.location='?page=input_aktivitas';</script>";
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: '.$e->getMessage()];
        }
    }
}

// --- 3. LOAD DATA ---
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');
$search_q = $_GET['q'] ?? '';

$page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$limit = 20;
$offset = ($page_num - 1) * $limit;

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
                <p class="text-sm text-gray-500 mt-1">Catat pemakaian barang operasional atau pengembalian (Stok Only).</p>
            </div>
            
            <div class="flex gap-2 flex-wrap">
                <button type="button" onclick="activateUSB()" class="bg-gray-700 text-white px-3 py-2 rounded shadow hover:bg-gray-800 transition text-sm flex items-center gap-2" title="Scanner USB"><i class="fas fa-keyboard"></i> USB Mode</button>
                <input type="file" id="scan_image_file" accept="image/*" capture="environment" class="hidden" onchange="handleFileScan(this)">
                <button type="button" onclick="document.getElementById('scan_image_file').click()" class="bg-purple-600 text-white px-3 py-2 rounded shadow hover:bg-purple-700 transition text-sm flex items-center gap-2" title="Upload Foto"><i class="fas fa-camera"></i> Foto Scan</button>
                <button type="button" onclick="initCamera()" class="bg-indigo-600 text-white px-3 py-2 rounded shadow hover:bg-indigo-700 transition text-sm flex items-center gap-2" title="Live Camera"><i class="fas fa-video"></i> Live Scan</button>
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
                        <p class="text-[10px] text-blue-600 mt-1 italic"><i class="fas fa-info-circle"></i> Memilih gudang akan memuat daftar barang yang tersedia.</p>
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
                
                <!-- DROPDOWN MANUAL SELECT -->
                <div class="mb-4 bg-white p-2 rounded border border-purple-100 shadow-sm">
                    <label class="block text-xs font-bold text-gray-500 mb-1">Pilih Manual dari Stok Gudang Ini</label>
                    <select id="manual_select" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-purple-500" disabled onchange="selectManualItem(this)">
                        <option value="">-- Pilih Gudang Terlebih Dahulu --</option>
                    </select>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                    <div class="md:col-span-4">
                        <label class="block text-xs font-bold text-gray-600 mb-1">SKU / SN Barcode</label>
                        <div class="flex gap-1">
                            <input type="text" id="sku_input" class="w-full border-2 border-purple-500 p-2 rounded font-mono font-bold text-lg uppercase transition-colors" placeholder="SCAN..." onchange="checkSku()" onkeydown="if(event.key === 'Enter'){ checkSku(); event.preventDefault(); }">
                            <button type="button" onclick="checkSku()" class="bg-blue-600 text-white px-3 rounded hover:bg-blue-700"><i class="fas fa-search"></i></button>
                        </div>
                        
                        <!-- DETAIL INFO AREA (GANTI SPAN) -->
                        <div id="sku_status" class="mt-2 text-sm font-bold bg-white p-2 rounded border border-gray-300 shadow-sm min-h-[40px] flex items-center text-gray-500 italic">
                            Belum ada barang dipilih.
                        </div>
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
                <div id="sn_out_container" class="hidden mt-4 p-3 bg-white rounded border border-gray-300">
                    <label class="block text-xs font-bold text-gray-700 mb-2">Input Serial Number (Wajib)</label>
                    <div id="sn_inputs" class="grid grid-cols-2 md:grid-cols-4 gap-2"></div>
                </div>
            </div>

            <!-- CART TABLE -->
            <div class="bg-white border rounded-lg overflow-hidden shadow-sm mb-6">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100 text-gray-700 border-b">
                        <tr>
                            <th class="p-3">Barang (SKU)</th>
                            <th class="p-3 text-center">Qty</th>
                            <th class="p-3">SN</th>
                            <th class="p-3">Catatan</th>
                            <th class="p-3 text-center w-16"><i class="fas fa-trash"></i></th>
                        </tr>
                    </thead>
                    <tbody id="cart_body" class="divide-y">
                        <tr><td colspan="5" class="p-6 text-center text-gray-400 italic">Belum ada barang di daftar.</td></tr>
                    </tbody>
                </table>
            </div>

            <button type="submit" class="w-full bg-green-600 text-white py-4 rounded font-bold text-lg shadow hover:bg-green-700 transition transform active:scale-95 flex justify-center items-center gap-2">
                <i class="fas fa-save"></i> Simpan Transaksi
            </button>
        </form>
    </div>

    <!-- LIST RIWAYAT -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-gray-800 mb-4 border-b pb-2">Riwayat Aktivitas Terakhir</h3>
        <form method="GET" class="flex flex-wrap gap-2 mb-4">
            <input type="hidden" name="page" value="input_aktivitas">
            <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" class="border p-2 rounded text-sm" placeholder="Cari...">
            <input type="date" name="start" value="<?= $start_date ?>" class="border p-2 rounded text-sm">
            <input type="date" name="end" value="<?= $end_date ?>" class="border p-2 rounded text-sm">
            <button type="submit" class="bg-blue-600 text-white px-3 py-2 rounded text-sm"><i class="fas fa-filter"></i></button>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border-b">Tanggal</th>
                        <th class="p-3 border-b">Ref</th>
                        <th class="p-3 border-b">Barang</th>
                        <th class="p-3 border-b text-right">Qty</th>
                        <th class="p-3 border-b">Keterangan</th>
                        <th class="p-3 border-b text-center">User</th>
                        <th class="p-3 border-b text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if(empty($activities_data)): ?>
                        <tr><td colspan="7" class="p-4 text-center text-gray-400">Belum ada data aktivitas.</td></tr>
                    <?php else: ?>
                        <?php foreach($activities_data as $row): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="p-3 whitespace-nowrap text-gray-600"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                            <td class="p-3 font-mono text-xs text-blue-600 font-bold"><?= h($row['reference']) ?></td>
                            <td class="p-3">
                                <div class="font-bold text-gray-800"><?= h($row['prod_name']) ?></div>
                                <div class="text-xs text-gray-500"><?= h($row['sku']) ?></div>
                            </td>
                            <td class="p-3 text-right font-bold <?= $row['type']=='OUT'?'text-red-600':'text-green-600' ?>">
                                <?= $row['type']=='OUT' ? '-' : '+' ?><?= number_format($row['quantity']) ?>
                            </td>
                            <td class="p-3 text-gray-600 italic text-xs max-w-xs truncate" title="<?= h($row['notes']) ?>"><?= h($row['notes']) ?></td>
                            <td class="p-3 text-center text-xs text-gray-500"><?= h($row['username']) ?></td>
                            <td class="p-3 text-center">
                                <form method="POST" onsubmit="return confirm('Hapus aktivitas ini? Stok akan dikembalikan.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                    <button class="text-red-500 hover:text-red-700" title="Hapus"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if($total_pages > 1): ?>
        <div class="flex justify-center gap-2 mt-4">
            <?php if($page_num > 1): ?>
                <a href="?page=input_aktivitas&p=<?= $page_num - 1 ?>&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($search_q) ?>" class="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded text-sm">Prev</a>
            <?php endif; ?>
            <span class="px-3 py-1 text-sm font-bold text-gray-600">Halaman <?= $page_num ?> dari <?= $total_pages ?></span>
            <?php if($page_num < $total_pages): ?>
                <a href="?page=input_aktivitas&p=<?= $page_num + 1 ?>&start=<?= $start_date ?>&end=<?= $end_date ?>&q=<?= h($search_q) ?>" class="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded text-sm">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
let cart = [];
let html5QrCode;
let audioCtx = null;
let currentMode = 'OUT';
const APP_NAME_PREFIX = "<?= $app_ref_prefix ?>";

// --- INIT ---
document.addEventListener('DOMContentLoaded', () => {
    generateReference();
    
    // SETUP WAREHOUSE CHANGE LISTENER
    document.getElementById('warehouse_id').addEventListener('change', function() {
        loadProductsByWarehouse(this.value);
    });
    
    // Load initial if value exists (e.g. back browser)
    const initWh = document.getElementById('warehouse_id').value;
    if(initWh) loadProductsByWarehouse(initWh);
    
    // Init Select2 Placeholder
    $('#manual_select').select2({
        placeholder: "-- Pilih Gudang Terlebih Dahulu --",
        width: '100%',
        disabled: true
    });
});

// --- SELECT2 EVENT LISTENER ---
$('#manual_select').on('select2:select', function (e) {
    var sku = e.params.data.id;
    if (sku) {
        document.getElementById('sku_input').value = sku;
        checkSku();
        $('#manual_select').val(null).trigger('change');
    }
});

// --- LOAD MANUAL DROPDOWN ---
async function loadProductsByWarehouse(whId) {
    const select = document.getElementById('manual_select');
    // Disable during load
    $('#manual_select').prop('disabled', true);
    select.innerHTML = '<option value="">Memuat data barang...</option>';
    
    try {
        const res = await fetch(`api.php?action=get_warehouse_stock&warehouse_id=${whId}`);
        const data = await res.json();
        
        select.innerHTML = '<option value="">-- Cari Barang (Ketik Nama) --</option>';
        
        if (data.length > 0) {
            data.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.sku;
                opt.text = `${p.name} (Stok: ${p.current_qty} ${p.unit})`;
                select.appendChild(opt);
            });
            // Re-init Select2 & Enable
            $('#manual_select').prop('disabled', false).select2({
                placeholder: "-- Cari Barang (Ketik Nama) --",
                width: '100%'
            });
        } else {
            select.innerHTML = '<option value="">-- Tidak ada stok di gudang ini --</option>';
            // Re-init Select2 but keep disabled effectively (or enabled to see 'No stock')
             $('#manual_select').prop('disabled', false).select2({
                placeholder: "-- Tidak ada stok --",
                width: '100%'
            });
        }
    } catch(e) {
        select.innerHTML = '<option value="">Gagal memuat data</option>';
    }
}

function selectManualItem(select) {
    const sku = select.value;
    if (sku) {
        document.getElementById('sku_input').value = sku;
        checkSku();
        select.value = ""; 
    }
}

// --- AUDIO ---
function initAudio() { if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)(); if (audioCtx.state === 'suspended') audioCtx.resume(); }
function playBeep() { if (!audioCtx) return; try { const o = audioCtx.createOscillator(); const g = audioCtx.createGain(); o.connect(g); g.connect(audioCtx.destination); o.type = 'square'; o.frequency.setValueAtTime(1200, audioCtx.currentTime); g.gain.setValueAtTime(0.1, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.1); o.start(); o.stop(audioCtx.currentTime + 0.15); } catch (e) {} }

// --- UI TOGGLES ---
function toggleMode(mode) {
    currentMode = mode;
    const container = document.getElementById('item_input_container');
    const skuInput = document.getElementById('sku_input');
    const title = document.getElementById('item_title');
    
    if (mode === 'IN') {
        container.classList.remove('bg-purple-50', 'border-purple-200');
        container.classList.add('bg-teal-50', 'border-teal-200');
        skuInput.classList.remove('border-purple-500');
        skuInput.classList.add('border-teal-500');
        title.innerText = "Input Barang Pengembalian (Retur)";
        title.classList.remove('text-purple-800', 'border-purple-200');
        title.classList.add('text-teal-800', 'border-teal-200');
    } else {
        container.classList.remove('bg-teal-50', 'border-teal-200');
        container.classList.add('bg-purple-50', 'border-purple-200');
        skuInput.classList.remove('border-teal-500');
        skuInput.classList.add('border-purple-500');
        title.innerText = "Input Barang Pemakaian";
        title.classList.remove('text-teal-800', 'border-teal-200');
        title.classList.add('text-purple-800', 'border-purple-200');
    }
}

// --- PRODUCT CHECK & CART ---
async function checkSku() {
    const sku = document.getElementById('sku_input').value.trim();
    if (!sku) return;
    
    const status = document.getElementById('sku_status');
    status.innerText = "Mencari...";
    status.className = "mt-2 text-sm font-bold bg-white p-2 rounded border border-gray-300 shadow-sm min-h-[40px] flex items-center text-blue-600 italic";
    
    try {
        const res = await fetch(`api.php?action=get_product_by_sku&sku=${encodeURIComponent(sku)}`);
        const data = await res.json();
        
        if (data.id) {
            // Found
            document.getElementById('sku_input').setAttribute('data-id', data.id);
            document.getElementById('sku_input').setAttribute('data-name', data.name);
            document.getElementById('sku_input').setAttribute('data-unit', data.unit);
            
            // Tampilan Detail Barang
            if (data.scanned_sn) {
                document.getElementById('qty_input').value = 1;
                document.getElementById('qty_input').disabled = true; // SN unik = 1 item
                renderSnInputs([data.scanned_sn]);
                status.innerHTML = `<span class="text-green-600"><i class="fas fa-check-circle"></i> ${data.name} (SN: ${data.scanned_sn})</span>`;
            } else {
                document.getElementById('qty_input').value = 1;
                document.getElementById('qty_input').disabled = false;
                renderSnInputs();
                document.getElementById('qty_input').focus();
                // Menampilkan Stok dalam Info
                status.innerHTML = `<span class="text-green-600"><i class="fas fa-box"></i> ${data.name} <span class="bg-green-100 text-green-800 px-2 rounded text-xs ml-2">Stok: ${data.stock}</span></span>`;
            }
        } else {
            status.innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> Barang tidak ditemukan!</span>`;
            document.getElementById('sku_input').value = '';
        }
    } catch (e) {
        status.innerText = "Error API";
    }
}

function renderSnInputs(prefill = []) {
    const qty = parseInt(document.getElementById('qty_input').value) || 0;
    const container = document.getElementById('sn_out_container');
    const inputsDiv = document.getElementById('sn_inputs');
    
    if (qty > 0) {
        container.classList.remove('hidden');
        inputsDiv.innerHTML = '';
        for (let i = 0; i < qty; i++) {
            const val = prefill[i] || '';
            const ro = val ? 'readonly bg-gray-100' : '';
            inputsDiv.innerHTML += `<input type="text" class="border p-2 rounded text-sm sn-field ${ro}" placeholder="SN #${i+1}" value="${val}" ${ro}>`;
        }
    } else {
        container.classList.add('hidden');
    }
}

function addToCart() {
    const sku = document.getElementById('sku_input').value;
    const id = document.getElementById('sku_input').getAttribute('data-id');
    const name = document.getElementById('sku_input').getAttribute('data-name');
    const qty = parseInt(document.getElementById('qty_input').value);
    const notes = document.getElementById('notes_input').value;
    
    if (!id || !qty || qty <= 0) {
        alert("Pastikan barang valid dan qty > 0");
        return;
    }

    // Collect SNs
    const snInputs = document.querySelectorAll('.sn-field');
    let sns = [];
    let missingSn = false;
    snInputs.forEach(inp => {
        if (!inp.value.trim()) missingSn = true;
        sns.push(inp.value.trim());
    });

    if (missingSn) {
        alert("Serial Number wajib diisi untuk semua qty!");
        return;
    }

    cart.push({
        sku: sku,
        id: id,
        name: name,
        qty: qty,
        notes: notes,
        sns: sns
    });

    renderCart();
    resetInput();
}

function resetInput() {
    document.getElementById('sku_input').value = '';
    document.getElementById('sku_input').removeAttribute('data-id');
    document.getElementById('qty_input').value = '';
    document.getElementById('qty_input').disabled = false;
    document.getElementById('notes_input').value = '';
    document.getElementById('sn_out_container').classList.add('hidden');
    // Reset Status dengan Style
    const status = document.getElementById('sku_status');
    status.innerText = "Belum ada barang dipilih.";
    status.className = "mt-2 text-sm font-bold bg-white p-2 rounded border border-gray-300 shadow-sm min-h-[40px] flex items-center text-gray-500 italic";
    document.getElementById('sku_input').focus();
}

function renderCart() {
    const tbody = document.getElementById('cart_body');
    tbody.innerHTML = '';
    
    if (cart.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="p-6 text-center text-gray-400 italic">Belum ada barang di daftar.</td></tr>';
        return;
    }

    cart.forEach((item, idx) => {
        tbody.innerHTML += `
            <tr class="hover:bg-gray-50 border-b">
                <td class="p-3">
                    <div class="font-bold">${item.name}</div>
                    <div class="text-xs text-gray-500">${item.sku}</div>
                </td>
                <td class="p-3 text-center font-bold">${item.qty}</td>
                <td class="p-3 text-xs font-mono break-all max-w-xs">${item.sns.join(', ')}</td>
                <td class="p-3 italic text-gray-600">${item.notes}</td>
                <td class="p-3 text-center">
                    <button type="button" onclick="removeFromCart(${idx})" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
    });
    
    document.getElementById('cart_json').value = JSON.stringify(cart);
}

function removeFromCart(idx) {
    cart.splice(idx, 1);
    renderCart();
}

function validateCart() {
    if (cart.length === 0) {
        alert("Keranjang kosong!");
        return false;
    }
    return confirm("Simpan transaksi ini?");
}

async function generateReference() {
    try {
        const res = await fetch('api.php?action=get_next_trx_number&type=OUT'); // Use generic counter
        const data = await res.json();
        const d = new Date();
        const ref = `${APP_NAME_PREFIX}/ACT/${d.getDate()}${d.getMonth()+1}/${d.getFullYear().toString().substr(-2)}-${data.next_no}`;
        document.getElementById('reference_input').value = ref;
    } catch (e) {}
}

// --- SCANNER LOGIC (Sama seperti halaman lain) ---
function activateUSB() { const i = document.getElementById('sku_input'); i.focus(); i.select(); i.classList.add('ring-4', 'ring-purple-400'); setTimeout(() => i.classList.remove('ring-4', 'ring-purple-400'), 1000); }
function handleFileScan(input) { if (input.files.length === 0) return; const file = input.files[0]; initAudio(); const i = document.getElementById('sku_input'); i.value = ""; i.placeholder = "Processing..."; const h = new Html5Qrcode("reader"); h.scanFile(file, true).then(d => { playBeep(); i.value = d; checkSku(); input.value = ''; }).catch(e => { alert("Gagal baca."); i.placeholder = "SCAN..."; input.value = ''; }); }
async function initCamera() { initAudio(); document.getElementById('scanner_area').classList.remove('hidden'); try { await navigator.mediaDevices.getUserMedia({ video: true }); const d = await Html5Qrcode.getCameras(); const s = document.getElementById('camera_select'); s.innerHTML = ""; if (d && d.length) { let id = d[0].id; d.forEach(dev => { if(dev.label.toLowerCase().includes('back')) id = dev.id; const o = document.createElement("option"); o.value = dev.id; o.text = dev.label; s.appendChild(o); }); s.value = id; startScan(id); s.onchange = () => { if(html5QrCode) html5QrCode.stop().then(() => startScan(s.value)); }; } } catch (e) { alert("Error kamera"); document.getElementById('scanner_area').classList.add('hidden'); } }
function startScan(id) { if(html5QrCode) try { html5QrCode.stop(); } catch(e) {} html5QrCode = new Html5Qrcode("reader"); html5QrCode.start(id, { fps: 10, qrbox: { width: 250, height: 250 } }, (d) => { playBeep(); document.getElementById('sku_input').value = d; checkSku(); stopScan(); }, () => {}); }
function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => { document.getElementById('scanner_area').classList.add('hidden'); html5QrCode.clear(); }); }
function toggleFlash() { if(html5QrCode) { isFlashOn = !isFlashOn; html5QrCode.applyVideoConstraints({ advanced: [{ torch: isFlashOn }] }); } }
</script>