<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG']);

// === HANDLER IMPORT CSV ===
if (isset($_POST['import_csv'])) {
    if (is_uploaded_file($_FILES['file_csv']['tmp_name'])) {
        $handle = fopen($_FILES['file_csv']['tmp_name'], "r");
        // Skip header row
        fgetcsv($handle, 1000, ",");
        
        $success = 0;
        $pdo->beginTransaction();
        try {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Asumsi format CSV: SKU, Nama, Kategori, Satuan, Harga Beli, Harga Jual, Stok
                // Index: 0, 1, 2, 3, 4, 5, 6
                if (count($data) >= 6) {
                    $sku = $data[0];
                    $name = $data[1];
                    $cat = $data[2];
                    $unit = $data[3];
                    $buy = (float)$data[4];
                    $sell = (float)$data[5];
                    $stock = isset($data[6]) ? (int)$data[6] : 0;

                    // Cek exist
                    $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
                    $stmt->execute([$sku]);
                    $exist = $stmt->fetch();

                    if ($exist) {
                        // Update
                        $upd = $pdo->prepare("UPDATE products SET name=?, category=?, unit=?, buy_price=?, sell_price=? WHERE id=?");
                        $upd->execute([$name, $cat, $unit, $buy, $sell, $exist['id']]);
                    } else {
                        // Insert
                        $ins = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$sku, $name, $cat, $unit, $buy, $sell, $stock]);
                    }
                    $success++;
                }
            }
            $pdo->commit();
            fclose($handle);
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$success Data berhasil diimpor!"];
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal Import: '.$e->getMessage()];
        }
    }
    echo "<script>window.location='?page=data_barang';</script>";
    exit;
}

// === HANDLER EXPORT CSV ===
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=data_barang_'.date('Ymd').'.csv');
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['SKU', 'Nama Barang', 'Kategori', 'Satuan', 'Harga Beli', 'Harga Jual', 'Stok Saat Ini']);
    
    // Data
    $rows = $pdo->query("SELECT sku, name, category, unit, buy_price, sell_price, stock FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// Delete Logic
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    $cek = $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE product_id = $id")->fetchColumn();
    if ($cek > 0) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal hapus: Barang sudah memiliki riwayat transaksi.'];
    } else {
        $pdo->query("DELETE FROM products WHERE id = $id");
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang dihapus.'];
    }
    echo "<script>window.location='?page=data_barang';</script>";
    exit;
}

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['import_csv'])) {
    $sku = $_POST['sku'];
    $name = $_POST['name'];
    $sell_price = $_POST['sell_price'];
    $buy_price = $_POST['buy_price'];
    $category = $_POST['category'];
    $unit = $_POST['unit'];
    $stock = $_POST['stock'];

    $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
    $stmt->execute([$sku]);
    $exists = $stmt->fetch();

    if ($exists) {
        // Edit Mode: Kita tidak update stok di sini untuk menjaga integritas transaksi, 
        // kecuali user ingin 'reset' stok yang seharusnya lewat opname. 
        // Namun sesuai request "Jika SKU ada maka otomatis mengarahkan edit stok", kita update field lain.
        // Stok biasanya dihandle transaksi, tapi jika dipaksa update master:
        $stmt = $pdo->prepare("UPDATE products SET name=?, category=?, unit=?, buy_price=?, sell_price=? WHERE sku=?");
        $stmt->execute([$name, $category, $unit, $buy_price, $sell_price, $sku]);
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data Barang (Detail) berhasil diperbarui!'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$sku, $name, $category, $unit, $buy_price, $sell_price, $stock]);
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang baru ditambahkan!'];
    }
    echo "<script>window.location='?page=data_barang';</script>";
    exit;
}

$search = $_GET['q'] ?? '';
$products = $pdo->prepare("SELECT * FROM products WHERE name LIKE ? OR sku LIKE ? ORDER BY name ASC");
$products->execute(["%$search%", "%$search%"]);
$products = $products->fetchAll();
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Form Input & Import -->
    <div class="space-y-6">
        <!-- Form Manual -->
        <div class="bg-white p-6 rounded-lg shadow h-fit">
            <h3 class="text-lg font-bold mb-4" id="form_title">Input Barang Baru</h3>
            
             <!-- Scanner Area -->
            <div id="scanner_area" class="hidden mb-4 bg-gray-900 rounded-lg overflow-hidden relative">
                <div id="reader" style="width: 100%;"></div>
                <button type="button" onclick="stopScan()" class="absolute top-2 right-2 bg-red-600 text-white px-3 py-1 rounded-full text-xs shadow z-10">Tutup Kamera</button>
                <p class="text-center text-white text-xs py-2">Arahkan kamera ke barcode</p>
            </div>

            <form method="POST" class="space-y-4" id="formBarang">
                <div class="relative">
                    <label class="block text-sm font-medium">SKU / Barcode</label>
                    <div class="flex gap-2">
                        <input type="text" name="sku" id="sku_input" placeholder="Scan/Ketik..." class="w-full border p-2 rounded" required onblur="checkExistingSku()">
                         <button type="button" onclick="startScan()" class="bg-indigo-600 text-white px-2 rounded hover:bg-indigo-700" title="Scan Kamera">
                            <i class="fas fa-camera"></i>
                        </button>
                        <button type="button" onclick="fillRandomSku()" class="bg-gray-200 px-2 rounded hover:bg-gray-300" title="Generate Random">
                            <i class="fas fa-random"></i>
                        </button>
                    </div>
                    <p id="sku_status" class="text-xs mt-1 text-gray-500"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Nama Barang</label>
                    <input type="text" name="name" id="name_input" class="w-full border p-2 rounded" required>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-sm font-medium">Kategori</label>
                        <input type="text" name="category" id="cat_input" class="w-full border p-2 rounded" placeholder="Umum">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Satuan</label>
                        <input type="text" name="unit" id="unit_input" class="w-full border p-2 rounded" placeholder="Pcs">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-sm font-medium">Harga Beli</label>
                        <input type="number" name="buy_price" id="buy_input" class="w-full border p-2 rounded" placeholder="0">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Harga Jual</label>
                        <input type="number" name="sell_price" id="sell_input" class="w-full border p-2 rounded" placeholder="0" required>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium">Stok Awal / Saat Ini</label>
                    <input type="number" name="stock" id="stock_input" class="w-full border p-2 rounded bg-gray-50" placeholder="0">
                    <p class="text-xs text-blue-500 mt-1" id="stock_hint">*Hanya berlaku untuk barang baru</p>
                </div>
                <button type="submit" id="btn_save" class="w-full bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Simpan Data</button>
                <button type="button" onclick="resetForm()" class="w-full bg-gray-300 text-gray-700 p-2 rounded hover:bg-gray-400 hidden" id="btn_cancel">Batal / Reset</button>
            </form>
        </div>

        <!-- Form Import -->
        <div class="bg-white p-6 rounded-lg shadow">
             <h3 class="text-lg font-bold mb-4">Import Excel (CSV)</h3>
             <form method="POST" enctype="multipart/form-data" class="space-y-3">
                 <input type="hidden" name="import_csv" value="1">
                 <input type="file" name="file_csv" accept=".csv" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" required>
                 <div class="text-xs text-gray-500">Format: SKU, Nama, Kategori, Satuan, HargaBeli, HargaJual, Stok</div>
                 <button type="submit" class="w-full bg-green-600 text-white p-2 rounded hover:bg-green-700">Upload & Import</button>
             </form>
        </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white p-6 rounded-lg shadow lg:col-span-2">
        <div class="flex justify-between items-center mb-4 flex-wrap gap-2">
            <h3 class="text-lg font-bold">Daftar Barang</h3>
            <div class="flex gap-2">
                <a href="?page=data_barang&export=true" target="_blank" class="bg-green-100 text-green-700 px-3 py-1 rounded text-sm hover:bg-green-200">
                    <i class="fas fa-file-excel"></i> Export CSV
                </a>
                <form method="GET" class="flex gap-2">
                    <input type="hidden" name="page" value="data_barang">
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari..." class="border p-1 px-3 rounded text-sm">
                    <button class="bg-gray-200 px-3 rounded text-sm"><i class="fas fa-search"></i></button>
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto max-h-[600px]">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 sticky top-0">
                    <tr>
                        <th class="p-3 border">SKU</th>
                        <th class="p-3 border">Nama</th>
                        <th class="p-3 border">Kat</th>
                        <th class="p-3 border text-right">Beli</th>
                        <th class="p-3 border text-right">Jual</th>
                        <th class="p-3 border text-center">Stok</th>
                        <th class="p-3 border text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($products)): ?>
                        <tr><td colspan="7" class="p-4 text-center">Data tidak ditemukan</td></tr>
                    <?php endif; ?>

                    <?php foreach($products as $p): ?>
                    <tr class="hover:bg-gray-50 group">
                        <td class="p-3 border font-mono text-xs">
                            <?= $p['sku'] ?>
                             <button onclick='printBarcode("<?= $p['sku'] ?>")' class="ml-2 text-gray-400 hover:text-black" title="Cetak Barcode"><i class="fas fa-barcode"></i></button>
                        </td>
                        <td class="p-3 border font-semibold"><?= $p['name'] ?></td>
                        <td class="p-3 border text-xs"><?= $p['category'] ?></td>
                        <td class="p-3 border text-right text-xs text-gray-500"><?= formatRupiah($p['buy_price']) ?></td>
                        <td class="p-3 border text-right"><?= formatRupiah($p['sell_price']) ?></td>
                        <td class="p-3 border text-center font-bold <?= $p['stock'] < 10 ? 'text-red-500' : 'text-green-600' ?>">
                            <?= $p['stock'] ?> <?= $p['unit'] ?>
                        </td>
                        <td class="p-3 border text-center">
                            <button onclick='loadEdit(<?= json_encode($p) ?>)' class="text-blue-500 hover:text-blue-700 mr-2"><i class="fas fa-edit"></i></button>
                            <a href="?page=data_barang&delete_id=<?= $p['id'] ?>" onclick="return confirm('Hapus barang ini?')" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Load data ke form jika tombol edit di tabel diklik
function loadEdit(data) {
    document.getElementById('sku_input').value = data.sku;
    checkExistingSku(); // Trigger logic
}

async function checkExistingSku() {
    const sku = document.getElementById('sku_input').value;
    const status = document.getElementById('sku_status');
    const title = document.getElementById('form_title');
    const btn = document.getElementById('btn_save');
    const hint = document.getElementById('stock_hint');
    const btnCancel = document.getElementById('btn_cancel');

    if(!sku) return;

    status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cek SKU...';
    
    try {
        const res = await fetch('api.php?action=get_product_by_sku&sku=' + sku);
        const data = await res.json();

        if (data.id) {
            // Mode Edit
            status.innerHTML = '<span class="text-blue-600 font-bold"><i class="fas fa-check"></i> Barang Ditemukan (Mode Edit)</span>';
            title.innerText = 'Edit Barang: ' + data.name;
            btn.innerText = 'Update Barang';
            btn.classList.replace('bg-blue-600', 'bg-orange-600');
            btn.classList.replace('hover:bg-blue-700', 'hover:bg-orange-700');
            btnCancel.classList.remove('hidden');

            document.getElementById('name_input').value = data.name;
            document.getElementById('cat_input').value = data.category;
            document.getElementById('unit_input').value = data.unit;
            document.getElementById('buy_input').value = data.buy_price;
            document.getElementById('sell_input').value = data.sell_price;
            document.getElementById('stock_input').value = data.stock;
            
            document.getElementById('stock_input').readOnly = true;
            hint.innerText = "*Stok dikelola via Transaksi. Update disini hanya untuk koreksi master.";
        } else {
            // Mode Baru
            status.innerHTML = '<span class="text-green-600 font-bold"><i class="fas fa-plus"></i> SKU Baru tersedia</span>';
            // Jangan reset form jika baru mengetik
            title.innerText = 'Input Barang Baru';
            btn.innerText = 'Simpan Data';
            btn.classList.replace('bg-orange-600', 'bg-blue-600');
            btn.classList.replace('hover:bg-orange-700', 'hover:bg-blue-700');
            hint.innerText = "*Hanya berlaku untuk barang baru";
            document.getElementById('stock_input').readOnly = false;
        }
    } catch(e) {
        console.error(e);
    }
}

function resetForm() {
    document.getElementById('formBarang').reset();
    document.getElementById('form_title').innerText = 'Input Barang Baru';
    document.getElementById('btn_save').innerText = 'Simpan Data';
    document.getElementById('sku_status').innerHTML = '';
    document.getElementById('btn_cancel').classList.add('hidden');
    const btn = document.getElementById('btn_save');
    btn.classList.replace('bg-orange-600', 'bg-blue-600');
    document.getElementById('stock_input').readOnly = false;
}

function fillRandomSku() {
    document.getElementById('sku_input').value = Math.floor(Math.random() * 90000000) + 10000000;
    checkExistingSku();
}

function printBarcode(sku) {
    const w = window.open('', '_blank');
    w.document.write(`
        <html>
        <head><title>Print Barcode</title></head>
        <body onload="window.print(); window.close();" style="display:flex; justify-content:center; align-items:center; height:100vh;">
            <div style="text-align:center;">
                <svg id="barcode"></svg>
            </div>
            <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"><\/script>
            <script>
                JsBarcode("#barcode", "${sku}", {
                    format: "CODE128",
                    lineColor: "#000",
                    width: 2,
                    height: 100,
                    displayValue: true
                });
            <\/script>
        </body>
        </html>
    `);
    w.document.close();
}

let html5QrCode;
function startScan() {
    document.getElementById('scanner_area').classList.remove('hidden');
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };
    html5QrCode = new Html5Qrcode("reader");
    html5QrCode.start({ facingMode: "environment" }, config, 
        (decodedText) => {
            document.getElementById('sku_input').value = decodedText;
            checkExistingSku();
            stopScan();
        },
        () => {}
    ).catch(err => { alert(err); stopScan(); });
}

function stopScan() {
    if (html5QrCode) {
        html5QrCode.stop().then(() => {
            html5QrCode.clear();
            document.getElementById('scanner_area').classList.add('hidden');
        }).catch(() => document.getElementById('scanner_area').classList.add('hidden'));
    }
}
</script>