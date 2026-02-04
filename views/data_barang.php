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
            
            // Hapus SN jika ada
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
            $stock = (int)$_POST['stock']; 
            
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
                // UPDATE
                $id = $_POST['edit_id'];
                
                $sql_img = "";
                $params = [$sku, $name, $category, $unit, $buy_price, $sell_price, $stock];
                
                if ($image_path) {
                    $sql_img = ", image_url = ?";
                    $params[] = $image_path;
                    
                    // Hapus gambar lama
                    $old_img = $pdo->query("SELECT image_url FROM products WHERE id=$id")->fetchColumn();
                    if ($old_img && file_exists($old_img)) unlink($old_img);
                }
                
                $params[] = $id; // ID untuk WHERE
                
                $stmt = $pdo->prepare("UPDATE products SET sku=?, name=?, category=?, unit=?, buy_price=?, sell_price=?, stock=? $sql_img WHERE id=?");
                $stmt->execute($params);
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data barang diperbarui.'];

            } else {
                // INSERT
                // Cek SKU Unik
                $check = $pdo->prepare("SELECT id FROM products WHERE sku=?");
                $check->execute([$sku]);
                if ($check->rowCount() > 0) throw new Exception("SKU $sku sudah ada!");

                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, image_url, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$sku, $name, $category, $unit, $buy_price, $sell_price, $stock, $image_path]);
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang baru ditambahkan.'];
            }

        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Error: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }
}

// --- FILTER & QUERY DATA ---
$start_date = $_GET['start'] ?? date('Y-m-01', strtotime('-1 year'));
$end_date = $_GET['end'] ?? date('Y-m-d');
$search = $_GET['q'] ?? '';
$cat_filter = $_GET['category'] ?? 'ALL';
$stock_status = $_GET['stock_status'] ?? 'ALL';
$sort_by = $_GET['sort'] ?? 'newest';

// Ambil list kategori untuk filter
$existing_categories = $pdo->query("SELECT DISTINCT category, '' as name FROM products ORDER BY category")->fetchAll(); 

// Query Dasar
$sql = "SELECT p.*, 
        (SELECT date FROM inventory_transactions WHERE product_id = p.id ORDER BY date DESC LIMIT 1) as last_trx_date
        FROM products p 
        WHERE (p.name LIKE ? OR p.sku LIKE ?)";
$params = ["%$search%", "%$search%"];

// Filter Kategori
if ($cat_filter !== 'ALL') {
    $sql .= " AND p.category = ?";
    $params[] = $cat_filter;
}

// Filter Status Stok
if ($stock_status === 'LOW') $sql .= " AND p.stock < 10 AND p.stock > 0";
elseif ($stock_status === 'EMPTY') $sql .= " AND p.stock <= 0";
elseif ($stock_status === 'AVAILABLE') $sql .= " AND p.stock > 0";

// Sorting
if ($sort_by === 'name_asc') $sql .= " ORDER BY p.name ASC";
elseif ($sort_by === 'stock_high') $sql .= " ORDER BY p.stock DESC";
else $sql .= " ORDER BY p.created_at DESC"; // newest

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Jika sedang mode edit, ambil data
$edit_item = null;
if (isset($_GET['edit_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
    $stmt->execute([$_GET['edit_id']]);
    $edit_item = $stmt->fetch();
}
?>

<!-- FILTER BAR -->
<div class="bg-white p-4 rounded-lg shadow mb-6 border-l-4 border-indigo-600 no-print">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
        <input type="hidden" name="page" value="data_barang">
        <div class="lg:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-indigo-500" placeholder="Nama / SKU...">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Kategori</label>
            <select name="category" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                <option value="ALL">-- Semua --</option>
                <?php foreach($existing_categories as $ec): ?>
                    <option value="<?= $ec['category'] ?>" <?= $cat_filter==$ec['category'] ? 'selected' : '' ?>><?= htmlspecialchars($ec['category']) ?></option>
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
        <div>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold hover:bg-indigo-700 w-full shadow text-sm h-[38px]">
                <i class="fas fa-filter"></i> Filter
            </button>
        </div>
        <div>
            <a href="?page=data_barang" class="bg-gray-500 text-white px-4 py-2 rounded font-bold hover:bg-gray-600 w-full shadow text-sm h-[38px] flex items-center justify-center">
                <i class="fas fa-sync-alt"></i> Reset
            </a>
        </div>
    </form>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6 page-content-wrapper">
    
    <!-- LEFT: FORM INPUT -->
    <div class="lg:col-span-1 no-print">
        <div class="bg-white p-6 rounded-lg shadow sticky top-6">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2">
                <i class="fas fa-box"></i> <?= $edit_item ? 'Edit Barang' : 'Tambah Barang' ?>
            </h3>
            
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="save_product" value="1">
                <?php if($edit_item): ?>
                    <input type="hidden" name="edit_id" value="<?= $edit_item['id'] ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">SKU / Kode Barcode</label>
                    <div class="flex gap-1">
                        <input type="text" name="sku" id="form_sku" value="<?= $edit_item['sku']??'' ?>" class="w-full border p-2 rounded text-sm uppercase font-mono" placeholder="Scan/Ketik..." required>
                        <button type="button" onclick="generateSku()" class="bg-yellow-500 text-white px-2 rounded text-xs"><i class="fas fa-bolt"></i></button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Nama Barang</label>
                    <input type="text" name="name" value="<?= $edit_item['name']??'' ?>" class="w-full border p-2 rounded text-sm" placeholder="Contoh: Kabel LAN 5m" required>
                </div>

                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Kategori</label>
                        <input type="text" name="category" list="cat_list" value="<?= $edit_item['category']??'Umum' ?>" class="w-full border p-2 rounded text-sm" placeholder="Pilih/Ketik">
                        <datalist id="cat_list">
                            <?php foreach($existing_categories as $ec): ?>
                                <option value="<?= htmlspecialchars($ec['category']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Satuan</label>
                        <input type="text" name="unit" value="<?= $edit_item['unit']??'Pcs' ?>" class="w-full border p-2 rounded text-sm" placeholder="Pcs/Kg/Pack">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Harga Beli</label>
                        <input type="text" name="buy_price" value="<?= isset($edit_item['buy_price']) ? number_format($edit_item['buy_price'],0,',','.') : '' ?>" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right" placeholder="0">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Harga Jual</label>
                        <input type="text" name="sell_price" value="<?= isset($edit_item['sell_price']) ? number_format($edit_item['sell_price'],0,',','.') : '' ?>" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right" placeholder="0">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Stok Awal / Koreksi</label>
                    <input type="number" name="stock" value="<?= $edit_item['stock']??0 ?>" class="w-full border p-2 rounded text-sm font-bold text-center bg-gray-50">
                    <p class="text-[10px] text-gray-400 mt-1">*Ubah hanya jika inisialisasi atau koreksi stock opname.</p>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Gambar Produk</label>
                    <?php if(!empty($edit_item['image_url'])): ?>
                        <div class="mb-2">
                            <img src="<?= $edit_item['image_url'] ?>" class="h-20 w-auto rounded border">
                        </div>
                    <?php endif; ?>
                    <input type="file" name="image" class="w-full border p-1 rounded text-xs">
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700 text-sm shadow">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                    <?php if($edit_item): ?>
                        <a href="?page=data_barang" class="flex-1 bg-gray-500 text-white py-2 rounded font-bold hover:bg-gray-600 text-center text-sm shadow">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- RIGHT: DATA TABLE -->
    <div class="lg:col-span-3">
        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="font-bold text-lg mb-4 text-gray-800 border-b pb-2 flex justify-between">
                <span>Daftar Barang (<?= count($products) ?> Item)</span>
                <a href="?page=cetak_barcode" class="text-sm bg-gray-800 text-white px-3 py-1 rounded hover:bg-black no-print">
                    <i class="fas fa-barcode"></i> Cetak Label
                </a>
            </h3>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left border-collapse">
                    <thead class="bg-gray-100 text-gray-700">
                        <tr>
                            <th class="p-3 border-b w-16">Img</th>
                            <th class="p-3 border-b">Detail Barang</th>
                            <th class="p-3 border-b text-center">Stok</th>
                            <th class="p-3 border-b text-right">Harga</th>
                            <th class="p-3 border-b text-center w-24 no-print">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($products as $p): ?>
                        <tr class="hover:bg-gray-50 group">
                            <td class="p-3">
                                <?php if(!empty($p['image_url'])): ?>
                                    <img src="<?= $p['image_url'] ?>" class="w-10 h-10 object-cover rounded border bg-white">
                                <?php else: ?>
                                    <div class="w-10 h-10 bg-gray-200 rounded flex items-center justify-center text-gray-400">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="p-3">
                                <div class="font-bold text-gray-800 text-base"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="flex gap-2 text-xs mt-1">
                                    <span class="bg-gray-100 px-2 py-0.5 rounded font-mono text-gray-600"><?= htmlspecialchars($p['sku']) ?></span>
                                    <span class="bg-blue-50 px-2 py-0.5 rounded text-blue-600"><?= htmlspecialchars($p['category']) ?></span>
                                </div>
                            </td>
                            <td class="p-3 text-center">
                                <?php 
                                    $stock_class = 'bg-green-100 text-green-800';
                                    if($p['stock'] <= 0) $stock_class = 'bg-red-100 text-red-800';
                                    elseif($p['stock'] < 10) $stock_class = 'bg-orange-100 text-orange-800';
                                ?>
                                <span class="px-3 py-1 rounded-full text-sm font-bold <?= $stock_class ?>">
                                    <?= number_format($p['stock']) ?>
                                </span>
                                <div class="text-[10px] text-gray-400 mt-1"><?= htmlspecialchars($p['unit']) ?></div>
                            </td>
                            <td class="p-3 text-right">
                                <div class="font-bold text-gray-800"><?= formatRupiah($p['sell_price']) ?></div>
                                <div class="text-xs text-gray-400" title="Harga Beli">B: <?= formatRupiah($p['buy_price']) ?></div>
                            </td>
                            <td class="p-3 text-center no-print">
                                <div class="flex justify-center gap-1 opacity-100 lg:opacity-0 group-hover:opacity-100 transition-opacity">
                                    <a href="?page=data_barang&edit_id=<?= $p['id'] ?>" class="bg-blue-100 text-blue-600 p-2 rounded hover:bg-blue-200" title="Edit">
                                        <i class="fas fa-pencil-alt"></i>
                                    </a>
                                    <form method="POST" onsubmit="return confirm('Hapus barang <?= $p['name'] ?>?')" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                        <button class="bg-red-100 text-red-600 p-2 rounded hover:bg-red-200" title="Hapus">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($products)): ?>
                            <tr><td colspan="5" class="p-8 text-center text-gray-400">Tidak ada data barang.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
async function generateSku() {
    try {
        const res = await fetch('api.php?action=generate_sku');
        const data = await res.json();
        if(data.sku) document.getElementById('form_sku').value = data.sku;
    } catch(e) {
        alert('Gagal generate SKU');
    }
}

function formatRupiah(input) {
    let value = input.value.replace(/\D/g, '');
    if(value === '') { input.value = ''; return; }
    input.value = new Intl.NumberFormat('id-ID').format(value);
}
</script>