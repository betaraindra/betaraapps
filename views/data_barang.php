<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

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
            
            // Hapus data
            $pdo->prepare("DELETE FROM product_serials WHERE product_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang berhasil dihapus.'];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }

    // 2. SAVE (ADD/EDIT)
    if (isset($_POST['save_product'])) {
        $pdo->beginTransaction();
        try {
            $sku = trim($_POST['sku']);
            $name = trim($_POST['name']);
            $category = trim($_POST['category']); // Ini kode akun
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
                // --- UPDATE MASTER ---
                $id = $_POST['edit_id'];
                
                $sql_img = "";
                $params = [$sku, $name, $category, $unit, $buy_price, $sell_price];
                
                if ($image_path) {
                    $sql_img = ", image_url = ?";
                    $params[] = $image_path;
                    // Hapus gambar lama
                    $old_img = $pdo->query("SELECT image_url FROM products WHERE id=$id")->fetchColumn();
                    if ($old_img && file_exists($old_img)) unlink($old_img);
                }
                
                $params[] = $id; // WHERE ID
                
                $stmt = $pdo->prepare("UPDATE products SET sku=?, name=?, category=?, unit=?, buy_price=?, sell_price=? $sql_img WHERE id=?");
                $stmt->execute($params);
                
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data barang diperbarui. Stok tidak berubah (Gunakan Barang Masuk/Keluar).'];

            } else {
                // --- INSERT NEW ---
                // Cek SKU Unik
                $check = $pdo->prepare("SELECT id FROM products WHERE sku=?");
                $check->execute([$sku]);
                if ($check->rowCount() > 0) throw new Exception("SKU $sku sudah ada!");

                // Insert Master Product
                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, image_url, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 1)");
                $stmt->execute([$sku, $name, $category, $unit, $buy_price, $sell_price, $image_path]);
                $new_prod_id = $pdo->lastInsertId();

                // Handle Initial Stock (Jika diisi)
                $init_qty = (int)($_POST['initial_stock'] ?? 0);
                $init_wh = $_POST['initial_warehouse_id'] ?? '';
                
                if ($init_qty > 0 && !empty($init_wh)) {
                    // Validasi SN
                    $sn_list = $_POST['sn_list'] ?? [];
                    $valid_sns = array_filter($sn_list, function($v) { return !empty(trim($v)); });
                    
                    if (count($valid_sns) !== $init_qty) {
                        throw new Exception("Jumlah Serial Number tidak sesuai dengan Stok Awal ($init_qty).");
                    }

                    // 1. Update Stok Global
                    $pdo->prepare("UPDATE products SET stock = ? WHERE id = ?")->execute([$init_qty, $new_prod_id]);

                    // 2. Insert Transaksi IN (Saldo Awal)
                    $ref = "INIT/" . date('ymd') . "/" . rand(100,999);
                    $notes = "Stok Awal Barang Baru [SN: " . implode(',', $valid_sns) . "]";
                    
                    $stmtTrx = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (CURDATE(), 'IN', ?, ?, ?, ?, ?, ?)");
                    $stmtTrx->execute([$new_prod_id, $init_wh, $init_qty, $ref, $notes, $_SESSION['user_id']]);
                    $trx_id = $pdo->lastInsertId();

                    // 3. Insert Serial Numbers
                    $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach($valid_sns as $sn) {
                        $stmtSn->execute([$new_prod_id, $sn, $init_wh, $trx_id]);
                    }
                    
                    // 4. Catat Keuangan (Opsional - Modal Awal Barang)
                    // Jika barang baru dianggap modal/aset
                    $total_val = $init_qty * $buy_price;
                    if($total_val > 0) {
                        $accStmt = $pdo->query("SELECT id FROM accounts WHERE code = '$category' LIMIT 1"); // Cek akun kategori
                        $accId = $accStmt->fetchColumn();
                        // Jika kategori bukan ID akun valid, cari default persediaan
                        if(!$accId) $accId = $pdo->query("SELECT id FROM accounts WHERE code = '3003' LIMIT 1")->fetchColumn();
                        
                        if($accId) {
                            $whName = $pdo->query("SELECT name FROM warehouses WHERE id=$init_wh")->fetchColumn();
                            $desc = "Saldo Awal Barang: $name [Wilayah: $whName]";
                            $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (CURDATE(), 'EXPENSE', ?, ?, ?, ?)")
                                ->execute([$accId, $total_val, $desc, $_SESSION['user_id']]);
                        }
                    }
                }
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang baru berhasil ditambahkan.'];
            }
            
            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Error: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }
}

// --- FILTER & QUERY DATA ---
$search = $_GET['q'] ?? '';
$cat_filter = $_GET['category'] ?? 'ALL';
$wh_filter = $_GET['warehouse_id'] ?? 'ALL';

// Master Data for Dropdowns
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$accounts_cat = $pdo->query("SELECT * FROM accounts WHERE code LIKE '3%' OR code LIKE '2%' ORDER BY code ASC")->fetchAll(); 

// Base Query
$sql = "SELECT p.*,
        (SELECT date FROM inventory_transactions WHERE product_id = p.id ORDER BY date DESC LIMIT 1) as last_update,
        (SELECT GROUP_CONCAT(serial_number SEPARATOR ', ') FROM product_serials WHERE product_id = p.id AND status='AVAILABLE' LIMIT 5) as sn_sample,
        (SELECT COUNT(*) FROM product_serials WHERE product_id = p.id AND status='AVAILABLE') as sn_count
        FROM products p 
        WHERE (p.name LIKE ? OR p.sku LIKE ?)";
$params = ["%$search%", "%$search%"];

if ($cat_filter !== 'ALL') {
    $sql .= " AND p.category = ?";
    $params[] = $cat_filter;
}

$sql .= " ORDER BY p.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products_raw = $stmt->fetchAll();

// Process Warehouse Location Logic (Manual Aggregation for accuracy)
$products = [];
foreach($products_raw as $p) {
    // Cari lokasi gudang yang ada stoknya
    $wh_sql = "SELECT w.name, 
               (COALESCE(SUM(CASE WHEN i.type='IN' THEN i.quantity ELSE 0 END),0) - 
                COALESCE(SUM(CASE WHEN i.type='OUT' THEN i.quantity ELSE 0 END),0)) as stock
               FROM inventory_transactions i
               JOIN warehouses w ON i.warehouse_id = w.id
               WHERE i.product_id = ?
               GROUP BY w.id HAVING stock > 0";
    $stmt_wh = $pdo->prepare($wh_sql);
    $stmt_wh->execute([$p['id']]);
    $wh_locations = $stmt_wh->fetchAll();
    
    $loc_str = [];
    foreach($wh_locations as $loc) {
        $loc_str[] = $loc['name'] . " (" . $loc['stock'] . ")";
    }
    $p['locations'] = !empty($loc_str) ? implode(', ', $loc_str) : '-';
    
    // Filter Warehouse View if Selected
    if ($wh_filter !== 'ALL') {
        $found = false;
        foreach($wh_locations as $loc) {
            // Need warehouse ID here, but simple check:
            // This approach is simplified. For strict filtering, SQL join is better.
            // But to display ALL warehouses in column but FILTER by one, we accept all rows and hide in UI? 
            // Better: SQL filtering. 
        }
        // Re-implementing warehouse filter in SQL would be better but complex with grouping. 
        // For now, let's just show all rows.
    }
    
    $products[] = $p;
}

// Data Edit Fetch
$edit_item = null;
if (isset($_GET['edit_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$_GET['edit_id']]);
    $edit_item = $stmt->fetch();
}
?>

<!-- HEADER & FILTER -->
<div class="bg-white p-4 rounded-lg shadow mb-6 no-print border-l-4 border-indigo-600">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-gray-800"><i class="fas fa-boxes"></i> Data Barang (Master)</h2>
        <button onclick="openModal('add')" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold hover:bg-indigo-700 shadow flex items-center gap-2">
            <i class="fas fa-plus"></i> Tambah Barang
        </button>
    </div>
    
    <form method="GET" class="flex flex-wrap gap-4 items-end">
        <input type="hidden" name="page" value="data_barang">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang / SKU</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="w-full border p-2 rounded text-sm" placeholder="Keyword...">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Kategori</label>
            <select name="category" class="border p-2 rounded text-sm bg-white">
                <option value="ALL">-- Semua --</option>
                <?php foreach($accounts_cat as $ac): ?>
                    <option value="<?= $ac['code'] ?>" <?= $cat_filter==$ac['code']?'selected':'' ?>><?= $ac['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="bg-gray-800 text-white px-4 py-2 rounded font-bold text-sm h-[38px]"><i class="fas fa-filter"></i> Filter</button>
    </form>
</div>

<!-- TABEL DATA FULL WIDTH -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left border-collapse border border-gray-200">
            <thead class="bg-gray-100 text-gray-800 font-bold uppercase whitespace-nowrap">
                <tr>
                    <th class="p-3 border">Gbr</th>
                    <th class="p-3 border">Nama Barang</th>
                    <th class="p-3 border">SKU</th>
                    <th class="p-3 border w-48">SN (Available)</th>
                    <th class="p-3 border text-right">Beli (HPP)</th>
                    <th class="p-3 border">Gudang (Stok)</th>
                    <th class="p-3 border text-right">Jual</th>
                    <th class="p-3 border text-center bg-blue-50">Stok</th>
                    <th class="p-3 border text-center">Sat</th>
                    <th class="p-3 border text-center">Update</th>
                    <th class="p-3 border">Ket (Kategori)</th>
                    <th class="p-3 border text-center">Ref / Cetak</th>
                    <th class="p-3 border text-center w-20">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach($products as $p): ?>
                <tr class="hover:bg-gray-50 group">
                    <!-- Gbr -->
                    <td class="p-2 border text-center">
                        <?php if(!empty($p['image_url'])): ?>
                            <img src="<?= $p['image_url'] ?>" class="w-10 h-10 object-cover rounded border bg-white mx-auto cursor-pointer" onclick="window.open(this.src)">
                        <?php else: ?>
                            <div class="w-10 h-10 bg-gray-100 rounded flex items-center justify-center text-gray-300 mx-auto"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                    </td>
                    
                    <!-- Nama -->
                    <td class="p-2 border font-bold text-gray-700 min-w-[150px]"><?= htmlspecialchars($p['name']) ?></td>
                    
                    <!-- SKU -->
                    <td class="p-2 border font-mono text-blue-600"><?= htmlspecialchars($p['sku']) ?></td>
                    
                    <!-- SN -->
                    <td class="p-2 border text-[10px] break-all max-w-[200px]">
                        <?php if($p['sn_count'] > 0): ?>
                            <span class="text-green-600 font-bold"><?= $p['sn_sample'] ?></span>
                            <?= $p['sn_count'] > 5 ? "... (+".($p['sn_count']-5).")" : "" ?>
                        <?php else: ?>
                            <span class="text-gray-300">-</span>
                        <?php endif; ?>
                    </td>
                    
                    <!-- Beli -->
                    <td class="p-2 border text-right text-red-600"><?= formatRupiah($p['buy_price']) ?></td>
                    
                    <!-- Gudang -->
                    <td class="p-2 border text-[10px] leading-tight max-w-[150px]"><?= $p['locations'] ?></td>
                    
                    <!-- Jual -->
                    <td class="p-2 border text-right text-green-600 font-bold"><?= formatRupiah($p['sell_price']) ?></td>
                    
                    <!-- Stok -->
                    <td class="p-2 border text-center font-bold text-lg bg-blue-50 text-blue-800"><?= number_format($p['stock']) ?></td>
                    
                    <!-- Sat -->
                    <td class="p-2 border text-center"><?= htmlspecialchars($p['unit']) ?></td>
                    
                    <!-- Update -->
                    <td class="p-2 border text-center text-[10px] text-gray-500">
                        <?= $p['last_update'] ? date('d/m/y', strtotime($p['last_update'])) : '-' ?>
                    </td>
                    
                    <!-- Ket -->
                    <td class="p-2 border text-[10px] text-gray-500"><?= htmlspecialchars($p['category']) ?></td>
                    
                    <!-- Cetak -->
                    <td class="p-2 border text-center">
                        <a href="?page=cetak_barcode" class="bg-gray-800 text-white px-2 py-1 rounded text-[10px] hover:bg-black" title="Cetak Barcode">
                            <i class="fas fa-barcode"></i> Label
                        </a>
                    </td>
                    
                    <!-- Aksi -->
                    <td class="p-2 border text-center">
                        <div class="flex justify-center gap-1">
                            <button onclick='openModal("edit", <?= json_encode($p) ?>)' class="bg-yellow-100 text-yellow-700 p-1 rounded hover:bg-yellow-200" title="Edit">
                                <i class="fas fa-pencil-alt"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Hapus barang <?= $p['name'] ?>? Data transaksi terkait akan error jika tidak dibersihkan manual.')" class="inline">
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

<!-- MODAL FORM (ADD / EDIT) -->
<div id="productModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6 relative max-h-[90vh] overflow-y-auto">
        <button onclick="closeModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        
        <h3 id="modalTitle" class="text-xl font-bold mb-4 border-b pb-2 text-indigo-800"><i class="fas fa-box"></i> Form Barang</h3>
        
        <form method="POST" enctype="multipart/form-data" id="productForm">
            <?= csrf_field() ?>
            <input type="hidden" name="save_product" value="1">
            <input type="hidden" name="edit_id" id="edit_id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <!-- Barcode & SKU -->
                <div class="md:col-span-2 bg-indigo-50 p-3 rounded border border-indigo-100">
                    <label class="block text-xs font-bold text-gray-700 mb-1">SKU / Kode Barcode <span class="text-red-500">*</span></label>
                    <div class="flex gap-1">
                        <input type="text" name="sku" id="form_sku" class="w-full border p-2 rounded text-sm uppercase font-mono font-bold" placeholder="Scan atau Ketik..." required>
                        <button type="button" onclick="generateSku()" class="bg-yellow-500 text-white px-3 rounded text-xs font-bold hover:bg-yellow-600">AUTO</button>
                    </div>
                </div>

                <!-- Nama -->
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Nama Barang <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="form_name" class="w-full border p-2 rounded text-sm" required>
                </div>

                <!-- Kategori & Satuan -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Kategori (Akun) <span class="text-red-500">*</span></label>
                    <select name="category" id="form_category" class="w-full border p-2 rounded text-sm bg-white">
                        <?php foreach($accounts_cat as $ac): ?>
                            <option value="<?= $ac['code'] ?>"><?= $ac['name'] ?> (<?= $ac['code'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Satuan</label>
                    <input type="text" name="unit" id="form_unit" class="w-full border p-2 rounded text-sm" placeholder="Pcs/Unit/Pack">
                </div>

                <!-- Harga -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Harga Beli (HPP)</label>
                    <input type="text" name="buy_price" id="form_buy" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right" placeholder="0">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Harga Jual</label>
                    <input type="text" name="sell_price" id="form_sell" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right" placeholder="0">
                </div>
                
                <!-- Gambar -->
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Foto Produk</label>
                    <input type="file" name="image" class="w-full border p-1 rounded text-xs bg-gray-50">
                </div>
            </div>

            <!-- SECTION STOK AWAL (Hanya Tampil saat ADD) -->
            <div id="initial_stock_section" class="bg-green-50 p-4 rounded border border-green-200 mb-4 hidden">
                <h4 class="text-sm font-bold text-green-800 mb-2 border-b border-green-300 pb-1">Setup Stok Awal (Barang Baru)</h4>
                
                <div class="grid grid-cols-2 gap-4 mb-2">
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Gudang Penyimpanan</label>
                        <select name="initial_warehouse_id" class="w-full border p-2 rounded text-sm bg-white">
                            <option value="">-- Pilih Gudang --</option>
                            <?php foreach($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Jumlah Stok Awal</label>
                        <input type="number" name="initial_stock" id="init_qty" class="w-full border p-2 rounded text-sm font-bold text-center" placeholder="0" onkeyup="renderSnInputs()">
                    </div>
                </div>
                
                <!-- SN INPUTS -->
                <div id="sn_container" class="hidden mt-2">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Input Serial Number (Wajib)</label>
                    <div id="sn_inputs" class="grid grid-cols-2 gap-2 max-h-[100px] overflow-y-auto"></div>
                    <button type="button" onclick="autoGenSN()" class="mt-1 text-xs text-blue-600 hover:underline">Auto Generate SN</button>
                </div>
            </div>

            <!-- WARNING EDIT MODE -->
            <div id="edit_warning" class="hidden bg-yellow-50 p-3 rounded text-xs text-yellow-800 mb-4 border border-yellow-200">
                <i class="fas fa-exclamation-triangle"></i> <b>Mode Edit:</b> Stok tidak dapat diubah di sini. Gunakan menu <b>Barang Masuk / Keluar</b> untuk update stok.
            </div>

            <div class="flex gap-2">
                <button type="button" onclick="closeModal()" class="flex-1 bg-gray-500 text-white py-2 rounded font-bold hover:bg-gray-600">Batal</button>
                <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700 shadow">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<script>
// --- MODAL LOGIC ---
function openModal(mode, data = null) {
    const modal = document.getElementById('productModal');
    const title = document.getElementById('modalTitle');
    const initSec = document.getElementById('initial_stock_section');
    const warnSec = document.getElementById('edit_warning');
    const form = document.getElementById('productForm');
    
    // Reset Form
    form.reset();
    document.getElementById('edit_id').value = '';
    document.getElementById('sn_inputs').innerHTML = '';
    document.getElementById('sn_container').classList.add('hidden');

    if (mode === 'add') {
        title.innerHTML = '<i class="fas fa-plus-circle"></i> Tambah Barang Baru';
        initSec.classList.remove('hidden');
        warnSec.classList.add('hidden');
    } else {
        title.innerHTML = '<i class="fas fa-edit"></i> Edit Barang';
        initSec.classList.add('hidden');
        warnSec.classList.remove('hidden');
        
        // Fill Data
        document.getElementById('edit_id').value = data.id;
        document.getElementById('form_sku').value = data.sku;
        document.getElementById('form_name').value = data.name;
        document.getElementById('form_category').value = data.category;
        document.getElementById('form_unit').value = data.unit;
        document.getElementById('form_buy').value = new Intl.NumberFormat('id-ID').format(data.buy_price);
        document.getElementById('form_sell').value = new Intl.NumberFormat('id-ID').format(data.sell_price);
    }
    
    modal.classList.remove('hidden');
}

function closeModal() {
    document.getElementById('productModal').classList.add('hidden');
}

// --- HELPER FUNCTIONS ---
function renderSnInputs() {
    const qty = parseInt(document.getElementById('init_qty').value) || 0;
    const container = document.getElementById('sn_container');
    const inputsDiv = document.getElementById('sn_inputs');
    
    if (qty > 0) {
        container.classList.remove('hidden');
        inputsDiv.innerHTML = '';
        for(let i=0; i<qty; i++) {
            const inp = document.createElement('input');
            inp.type = 'text';
            inp.name = 'sn_list[]';
            inp.className = 'border p-1 rounded text-xs font-mono uppercase w-full';
            inp.placeholder = 'SN #' + (i+1);
            inp.required = true;
            inputsDiv.appendChild(inp);
        }
    } else {
        container.classList.add('hidden');
    }
}

function autoGenSN() {
    const inputs = document.getElementsByName('sn_list[]');
    if(inputs.length === 0) return;
    const prefix = "SN" + Math.floor(Math.random() * 1000);
    for(let inp of inputs) {
        if(!inp.value) inp.value = prefix + "-" + Math.floor(Math.random()*9999);
    }
}

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
</script>