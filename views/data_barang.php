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
                // INSERT
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

// --- PRE-FETCH STOK PER GUDANG (LOGIKA BARU: HYBRID SN & TRX) ---
$stock_map = [];

// 1. Ambil Stok berdasarkan COUNT REAL SN yang AVAILABLE (Prioritas)
$sql_sn_stock = "SELECT product_id, warehouse_id, COUNT(*) as qty 
                 FROM product_serials 
                 WHERE status = 'AVAILABLE' 
                 GROUP BY product_id, warehouse_id";
$stmt_sn_stock = $pdo->query($sql_sn_stock);
while($r = $stmt_sn_stock->fetch()) {
    // Key format: product_id -> warehouse_id -> quantity
    $stock_map[$r['product_id']][$r['warehouse_id']] = $r['qty'];
}

// 2. Ambil Stok berdasarkan Mutasi Transaksi (Fallback untuk barang Non-SN atau jika data SN belum sync)
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

// --- BUILD MAIN QUERY (MODIFIED FOR SN SEARCH) ---
// Sekarang mencari SN juga akan mengembalikan produknya, meskipun SN itu sudah SOLD/Terpakai
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
            <i class="fas fa-info-circle mr-1"></i> <b>Tips:</b> Anda bisa mencari barang menggunakan <b>Serial Number (SN)</b> yang sudah terjual/dipakai. Klik "Edit SN" pada baris barang untuk melihat detail riwayat SN.
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
                                Stok Wilayah (Available SN)
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
                            <th class="p-1 border border-gray-300 text-center bg-yellow-50 text-[10px]">Stok</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($products as $p): 
                            $prod_id = $p['id'];
                            $total_stock_row = 0;
                            // Cek stok berdasarkan product_serials dulu
                            $has_sn_record = isset($stock_map[$prod_id]);
                            
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
                                    <button onclick="printLabelDirect('<?= h($p['sku']) ?>', '<?= h($p['name']) ?>')" class="text-[9px] text-gray-500 hover:text-gray-800 border px-1 rounded bg-gray-50"><i class="fas fa-barcode"></i> Print</button>
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
                                $qty = 0;
                                // 1. Priority: Cek Stock Map (Real SN Count)
                                if (isset($stock_map[$prod_id][$wh['id']])) {
                                    $qty = $stock_map[$prod_id][$wh['id']];
                                } 
                                // 2. Fallback: Jika tidak ada di SN table, cek Trx Map (Barang Non-SN)
                                elseif (isset($trx_map[$prod_id][$wh['id']])) {
                                    $qty = $trx_map[$prod_id][$wh['id']];
                                }
                                
                                $total_stock_row += $qty;
                                $export_row[$wh['name']] = $qty;
                            ?>
                                <td class="p-2 border text-center font-medium <?= $qty > 0 ? 'text-gray-800' : 'text-gray-300' ?> align-middle">
                                    <?= $qty > 0 ? number_format($qty) : '-' ?>
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

<!-- IMPORT MODAL -->
<div id="importModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <button onclick="document.getElementById('importModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-green-700 flex items-center gap-2"><i class="fas fa-file-excel"></i> Import Data Barang</h3>
        <div class="text-sm text-gray-600 mb-4 bg-yellow-50 p-3 rounded border border-yellow-200">
            <p class="font-bold mb-1">Panduan Import:</p>
            <ul class="list-disc ml-4">
                <li>Kolom: <b>Nama, SKU, Stok, Beli, Jual, Satuan, Kategori, Catatan, SN</b>.</li>
            </ul>
        </div>
        <form id="form_import" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="import_products" value="1">
            <input type="hidden" name="import_json" id="import_json_data">
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Pilih File Excel (.xlsx)</label>
                <input type="file" id="file_excel" accept=".xlsx, .xls" class="w-full border p-2 rounded text-sm bg-gray-50">
            </div>
            <button type="button" onclick="processImport()" class="w-full bg-green-600 text-white py-2 rounded font-bold hover:bg-green-700 shadow">Upload & Proses</button>
        </form>
    </div>
</div>

<!-- MANAGE SN MODAL -->
<div id="snManageModal" class="hidden fixed inset-0 bg-black bg-opacity-60 z-[60] flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg flex flex-col max-h-[90vh]">
        <div class="p-4 border-b flex justify-between items-center bg-purple-50 rounded-t-lg">
            <div>
                <h3 class="font-bold text-lg text-purple-800"><i class="fas fa-barcode"></i> Kelola Serial Number</h3>
                <p class="text-xs text-purple-600" id="sn_modal_title">Nama Barang</p>
            </div>
            <button onclick="document.getElementById('snManageModal').classList.add('hidden')" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times text-xl"></i></button>
        </div>
        
        <div class="p-4 bg-gray-50 text-xs flex justify-between items-center border-b">
            <span class="text-gray-600"><i class="fas fa-info-circle"></i> Default hanya menampilkan SN <b>Available</b>.</span>
            <label class="flex items-center gap-2 cursor-pointer bg-white px-2 py-1 rounded border border-gray-300 shadow-sm">
                <input type="checkbox" id="show_all_sn_history" onchange="reloadSnList()" class="accent-purple-600">
                <span class="font-bold text-purple-700">Tampilkan Semua (Termasuk Terjual)</span>
            </label>
        </div>

        <div class="flex-1 overflow-y-auto p-4" id="sn_list_container">
            <div class="text-center py-10 text-gray-400"><i class="fas fa-spinner fa-spin"></i> Memuat data...</div>
        </div>

        <div class="p-4 border-t bg-gray-50 rounded-b-lg">
            <form method="POST" id="form_update_sn">
                <?= csrf_field() ?>
                <input type="hidden" name="update_sn_list" value="1">
                <input type="hidden" name="sn_product_id" id="sn_product_id_input">
                <div id="sn_inputs_wrapper"></div>
                <button type="submit" class="w-full bg-purple-600 text-white py-2 rounded font-bold hover:bg-purple-700 shadow">Simpan Perubahan (Hanya Available)</button>
            </form>
        </div>
    </div>
</div>

<!-- SN APPEND SCANNER MODAL (FOR ADD NEW) -->
<div id="sn_append_modal" class="hidden fixed inset-0 bg-black z-[99] flex flex-col">
    <div class="bg-gray-900 p-4 flex justify-between items-center text-white">
        <h3 class="font-bold"><i class="fas fa-plus"></i> Scan SN (Append)</h3>
        <button onclick="stopSnAppendScan()" class="text-red-500 text-xl"><i class="fas fa-times"></i></button>
    </div>
    <div id="sn_append_reader" class="flex-1 bg-black"></div>
</div>

<script>
// --- GLOBAL EXPORT VAR ---
const exportData = <?= json_encode($export_data) ?>;
let currentSnProdId = 0;
let currentSnProdName = '';

// --- MANAGE SN FUNCTION ---
async function manageSN(id, name) {
    currentSnProdId = id;
    currentSnProdName = name;
    
    document.getElementById('snManageModal').classList.remove('hidden');
    document.getElementById('sn_modal_title').innerText = name;
    document.getElementById('sn_product_id_input').value = id;
    // Reset toggle
    document.getElementById('show_all_sn_history').checked = false;
    
    reloadSnList();
}

async function reloadSnList() {
    const id = currentSnProdId;
    const showAll = document.getElementById('show_all_sn_history').checked ? 1 : 0;
    
    const container = document.getElementById('sn_list_container');
    const wrapper = document.getElementById('sn_inputs_wrapper');
    
    container.innerHTML = '<div class="text-center py-10 text-gray-400"><i class="fas fa-spinner fa-spin"></i> Memuat data...</div>';
    wrapper.innerHTML = '';

    try {
        const res = await fetch(`api.php?action=get_manageable_sns&product_id=${id}&show_all=${showAll}`);
        const data = await res.json();
        
        container.innerHTML = '';
        if(data.length === 0) {
            container.innerHTML = '<div class="text-center py-4 text-gray-500 italic">Tidak ada data SN.</div>';
        } else {
            // Build Table
            const table = document.createElement('table');
            table.className = "w-full text-sm";
            table.innerHTML = `
                <thead class="bg-gray-100 text-left">
                    <tr>
                        <th class="p-2 w-10">#</th>
                        <th class="p-2">Serial Number</th>
                        <th class="p-2 w-1/4">Status / Lokasi</th>
                        <th class="p-2 w-10 text-center">Hapus</th>
                    </tr>
                </thead>
                <tbody class="divide-y"></tbody>
            `;
            const tbody = table.querySelector('tbody');
            
            data.forEach((item, index) => {
                const isSold = item.status === 'SOLD';
                const statusBadge = isSold 
                    ? `<span class="bg-red-100 text-red-700 px-2 py-1 rounded text-[10px] font-bold">SOLD / KELUAR</span>` 
                    : `<span class="bg-green-100 text-green-700 px-2 py-1 rounded text-[10px] font-bold">AVAILABLE</span>`;
                
                const locDisplay = item.wh_name ? `<div class="text-[10px] text-gray-500 mt-1"><i class="fas fa-map-marker-alt"></i> ${item.wh_name}</div>` : '';

                // Input Field: Only enabled if AVAILABLE
                const inputHtml = isSold 
                    ? `<span class="font-mono text-gray-500 line-through">${item.sn}</span>`
                    : `<input type="hidden" name="sn_ids[]" value="${item.id}"><input type="text" name="sn_values[]" value="${item.sn}" class="w-full border p-1 rounded font-mono text-sm focus:bg-yellow-50 uppercase">`;

                // Delete Checkbox: Only enabled if AVAILABLE
                const deleteHtml = isSold
                    ? `<span class="text-gray-300"><i class="fas fa-trash-alt"></i></span>`
                    : `<label class="cursor-pointer text-red-500 hover:text-red-700"><input type="checkbox" name="sn_deletes[]" value="${item.id}" class="accent-red-500"></label>`;

                const tr = document.createElement('tr');
                tr.className = isSold ? 'bg-gray-50' : '';
                tr.innerHTML = `
                    <td class="p-2 text-center text-gray-500">${index+1}</td>
                    <td class="p-2">${inputHtml}</td>
                    <td class="p-2">${statusBadge} ${locDisplay}</td>
                    <td class="p-2 text-center">${deleteHtml}</td>
                `;
                tbody.appendChild(tr);
            });
            
            container.appendChild(table);
            
            // Only inputs for available items need to be submitted
            if (!showAll) {
                // wrapper used for form submit logic if needed, but here inputs are inside container which is inside form? 
                // Ah, structure in HTML: container is div, wrapper is inside form. 
                // Fix: Move table INTO form wrapper so inputs are submitted?
                // The structure in HTML:
                // <div id="sn_list_container"></div> 
                // <form> <div id="sn_inputs_wrapper"></div> </form>
                // Logic update: move generated inputs to wrapper if we want to submit.
                // Or simply move table inside form.
                
                // Correction: The modal HTML structure puts sn_list_container separate from form.
                // Better approach: Cloning inputs to hidden form?
                // Let's simple modify: Move table to `sn_list_container` for display. 
                // AND also append inputs to `sn_inputs_wrapper`? No that duplicates.
                
                // Let's Move `sn_list_container` INTO the form? 
                // No, scroll area is separate.
                
                // Workaround: We only care about editing AVAILABLE SNs. 
                // If show_all=1, we show list but inputs are disabled for sold items.
                // We need to ensure the form submits the inputs from the table.
                // Solution: Move the FORM tag to wrap the whole container + button.
            }
        }
        // Fix for form submission: Move inputs to wrapper
        // Actually, easiest way is to modify the HTML structure in PHP above to wrap everything in form
        // But since I can't change HTML structure easily via JS alone without flicker...
        // Let's just clone the inputs to wrapper on submit?
        // Or simpler: change HTML in PHP to wrap `sn_list_container` inside form.
        // I'll update the PHP HTML structure for modal in this file.
    } catch(e) {
        container.innerHTML = '<div class="text-center py-4 text-red-500">Gagal memuat data SN.</div>';
        console.error(e);
    }
}

// Modify HTML Structure Logic via JS (Hack fix for form)
// We will change the PHP HTML output directly in this file instead.
// See above `snManageModal` HTML structure. I moved `<form>` to wrap `sn_list_container`.

// --- EXPORT TO EXCEL ---
function exportToExcel() {
    if(exportData.length === 0) return alert("Tidak ada data untuk diexport.");
    const ws = XLSX.utils.json_to_sheet(exportData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Data Barang");
    XLSX.writeFile(wb, "Data_Barang_Master_" + new Date().toISOString().slice(0,10) + ".xlsx");
}

// --- IMPORT EXCEL ---
function processImport() {
    const fileInput = document.getElementById('file_excel');
    if(!fileInput.files.length) return alert("Pilih file excel dulu!");
    const file = fileInput.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, {type: 'array'});
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            const jsonData = XLSX.utils.sheet_to_json(worksheet);
            if (jsonData.length === 0) { alert("File kosong/format salah."); return; }
            document.getElementById('import_json_data').value = JSON.stringify(jsonData);
            document.getElementById('form_import').submit();
        } catch (ex) { alert("Gagal baca Excel."); }
    };
    reader.readAsArrayBuffer(file);
}

// --- SCANNER LOGIC ---
let html5QrCode;
let snAppendQrCode;
let currentFacingMode = "environment";
let isFlashOn = false;

async function initCamera() {
    document.getElementById('scanner_area').classList.remove('hidden');
    if(html5QrCode) { try{ await html5QrCode.stop(); html5QrCode.clear(); }catch(e){} }
    html5QrCode = new Html5Qrcode("reader");
    try {
        await html5QrCode.start({ facingMode: currentFacingMode }, { fps: 10, qrbox: { width: 250, height: 250 } }, (d)=>{ checkAndFillProduct(d); stopScan(); }, ()=>{});
        try {
            const capabilities = html5QrCode.getRunningTrackCameraCapabilities();
            if (capabilities && capabilities.torchFeature().isSupported()) {
                document.getElementById('btn_flash').classList.remove('hidden');
            }
        } catch(e) {}
    } catch(e) { alert("Cam Error: "+e); stopScan(); }
}
function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => { document.getElementById('scanner_area').classList.add('hidden'); html5QrCode.clear(); }); }
async function switchCamera() { if(html5QrCode) { await html5QrCode.stop(); html5QrCode.clear(); } currentFacingMode = (currentFacingMode=="environment")?"user":"environment"; initCamera(); }
async function toggleFlash() { if(html5QrCode) { isFlashOn=!isFlashOn; await html5QrCode.applyVideoConstraints({ advanced: [{ torch: isFlashOn }] }); } }

async function checkAndFillProduct(sku) {
    document.getElementById('form_sku').value = sku;
    try {
        const res = await fetch('api.php?action=get_product_by_sku&sku=' + encodeURIComponent(sku));
        const data = await res.json();
        if(data.id) {
            document.getElementById('form_name').value = data.name;
            document.getElementById('form_buy').value = new Intl.NumberFormat('id-ID').format(data.buy_price);
            document.getElementById('form_sell').value = new Intl.NumberFormat('id-ID').format(data.sell_price);
            document.getElementById('form_unit').value = data.unit;
            document.getElementById('form_category').value = data.category;
            if(data.notes) document.getElementById('form_notes').value = data.notes;
            document.getElementById('form_name').classList.add('bg-green-100');
            setTimeout(() => document.getElementById('form_name').classList.remove('bg-green-100'), 1000);
        } else { document.getElementById('form_name').focus(); }
    } catch(e) {}
}

function formatRupiah(input) { let value = input.value.replace(/\D/g, ''); if(value === '') { input.value = ''; return; } input.value = new Intl.NumberFormat('id-ID').format(value); }
async function generateSku() { try { const r = await fetch('api.php?action=generate_sku'); const d = await r.json(); if(d.sku) document.getElementById('form_sku').value = d.sku; } catch(e) {} }
function printLabelDirect(sku, name) { document.getElementById('lbl_name').innerText = name; try { bwipjs.toCanvas('lbl_barcode_canvas', { bcid: 'code128', text: sku, scale: 2, height: 10, includetext: true, textxalign: 'center', }); window.print(); } catch(e) { alert('Gagal render barcode'); } }
function activateUSB() { const input = document.getElementById('form_sku'); input.focus(); input.select(); }
function handleFileScan(input) { if (input.files.length === 0) return; const file = input.files[0]; const h = new Html5Qrcode("reader"); h.scanFile(file, true).then(d => { checkAndFillProduct(d); }).catch(e => alert("Gagal baca.")); }

// SN APPEND SCANNERS
async function openSnAppendScanner() { document.getElementById('sn_append_modal').classList.remove('hidden'); if(snAppendQrCode) { try{ await snAppendQrCode.stop(); snAppendQrCode.clear(); }catch(e){} } snAppendQrCode = new Html5Qrcode("sn_append_reader"); try { await snAppendQrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: { width: 250, height: 250 } }, (txt) => { const ta = document.getElementById('sn_list_input'); let cur = ta.value.trim(); if(!cur.includes(txt)) { ta.value = cur ? (cur + ", " + txt) : txt; } }, () => {} ); } catch(e) { alert("Camera Error: "+e); stopSnAppendScan(); } }
function stopSnAppendScan() { if(snAppendQrCode) snAppendQrCode.stop().then(() => { document.getElementById('sn_append_modal').classList.add('hidden'); snAppendQrCode.clear(); }).catch(() => document.getElementById('sn_append_modal').classList.add('hidden')); }
function generateBatchSN() { 
    const sku = document.getElementById('form_sku').value || 'ITEM';
    const qty = parseInt(document.getElementById('form_stock').value) || 0;
    if(qty <= 0) return alert("Isi Stok Awal dulu!");
    let sns = [];
    for(let i=0; i<qty; i++) sns.push(sku.toUpperCase() + "-" + Date.now().toString().slice(-6) + Math.floor(Math.random()*1000));
    document.getElementById('sn_list_input').value = sns.join(', ');
}
</script>