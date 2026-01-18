<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG']);

// Handler Simpan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'];
    $warehouse_id = $_POST['warehouse_id'];
    $sku = $_POST['sku'];
    $qty = $_POST['quantity'];
    $notes = $_POST['notes'];
    $reference = $_POST['reference']; // Input Surat Jalan / PO

    // Data Produk Baru (Jika ada)
    $new_name = $_POST['new_name'] ?? '';
    $new_category = $_POST['new_category'] ?? 'Umum';
    $new_unit = $_POST['new_unit'] ?? 'pcs';
    $new_buy = $_POST['new_buy_price'] ?? 0;
    $new_sell = $_POST['new_sell_price'] ?? 0;

    $pdo->beginTransaction();
    try {
        // 1. Cek Produk by SKU
        $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->execute([$sku]);
        $prod = $stmt->fetch();

        $product_id = null;

        if ($prod) {
            // Produk Ada
            $product_id = $prod['id'];
        } else {
            // Produk Tidak Ada -> Cek apakah data barang baru diisi
            if (!empty($new_name)) {
                // Insert Produk Baru
                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock) VALUES (?, ?, ?, ?, ?, ?, 0)");
                $stmt->execute([$sku, $new_name, $new_category, $new_unit, $new_buy, $new_sell]);
                $product_id = $pdo->lastInsertId();
            } else {
                throw new Exception("SKU Baru terdeteksi! Mohon isi 'Detail Barang Baru' di bawah form.");
            }
        }

        // 2. Insert Transaksi (Include Reference)
        $stmt = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, notes, reference, user_id) VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$date, $product_id, $warehouse_id, $qty, $notes, $reference, $_SESSION['user_id']]);
        $trx_id = $pdo->lastInsertId(); // Get ID untuk print langsung

        // 3. Update Stock
        $stmt = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
        $stmt->execute([$qty, $product_id]);

        $pdo->commit();
        $_SESSION['flash'] = [
            'type' => 'success', 
            'message' => 'Barang Masuk Berhasil! <a href="?page=cetak_surat_jalan&id='.$trx_id.'" target="_blank" class="underline font-bold ml-2">Cetak Surat Jalan</a>'
        ];
        if (!$prod) {
            $_SESSION['flash']['message'] .= " (Produk Baru Ditambahkan)";
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Gagal: ' . $e->getMessage()];
    }
    echo "<script>window.location='?page=barang_masuk';</script>";
    exit;
}

$warehouses = $pdo->query("SELECT * FROM warehouses")->fetchAll();
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="text-lg font-bold mb-4">Input Barang Masuk</h3>
        
        <!-- Scanner Area -->
        <div id="scanner_area" class="hidden mb-4 bg-gray-900 rounded-lg overflow-hidden relative">
            <div id="reader" style="width: 100%;"></div>
            <button type="button" onclick="stopScan()" class="absolute top-2 right-2 bg-red-600 text-white px-3 py-1 rounded-full text-xs shadow z-10">Tutup Kamera</button>
            <p class="text-center text-white text-xs py-2">Arahkan kamera ke barcode</p>
        </div>

        <button type="button" onclick="startScan()" class="w-full mb-4 bg-indigo-600 text-white py-2 rounded flex items-center justify-center gap-2 hover:bg-indigo-700">
            <i class="fas fa-camera"></i> Scan Barcode Kamera
        </button>

        <form method="POST" id="formMasuk">
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium">Tanggal</label>
                    <input type="date" name="date" id="tgl_transaksi" value="<?= date('Y-m-d') ?>" class="w-full border rounded p-2">
                </div>
                <div>
                    <label class="block text-sm font-medium">Gudang</label>
                    <select name="warehouse_id" class="w-full border rounded p-2">
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-blue-700 font-bold">No. Surat Jalan / Referensi</label>
                <div class="flex gap-2">
                    <input type="text" name="reference" id="reference_input" class="w-full border rounded p-2 border-blue-300" placeholder="Nomor PO atau Surat Jalan Manual" required>
                    <button type="button" onclick="generateReference('IN')" class="bg-blue-600 text-white px-3 rounded hover:bg-blue-700 font-bold text-xs w-24">Auto Generate</button>
                </div>
                <p class="text-xs text-gray-400 mt-1">Format Auto: IN/No/Tgl/Bln/Thn</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium">SKU / Kode Barang</label>
                <div class="relative">
                    <input type="text" name="sku" id="sku_input" class="w-full border rounded p-2 pr-10" placeholder="Scan atau ketik SKU" required autofocus onblur="checkSku()">
                    <span id="sku_status" class="absolute right-3 top-2 text-xl"></span>
                </div>
                <p id="sku_msg" class="text-xs mt-1 text-gray-500">Tekan Tab atau klik luar kotak untuk cek SKU.</p>
            </div>

            <!-- Bagian Auto-Add Product -->
            <div id="new_product_form" class="hidden bg-yellow-50 p-4 rounded border border-yellow-200 mb-4 animate-fade-in">
                <h4 class="font-bold text-yellow-800 text-sm mb-2"><i class="fas fa-plus-circle"></i> Barang Baru Terdeteksi</h4>
                <p class="text-xs text-yellow-700 mb-3">SKU ini belum ada. Isi detail di bawah untuk menyimpannya ke Data Barang.</p>
                
                <div class="mb-2">
                    <label class="block text-xs font-bold text-gray-700">Nama Barang</label>
                    <input type="text" name="new_name" id="new_name" class="w-full border rounded p-1 text-sm">
                </div>
                <div class="grid grid-cols-2 gap-2 mb-2">
                    <div>
                        <label class="block text-xs font-bold text-gray-700">Kategori</label>
                        <input type="text" name="new_category" class="w-full border rounded p-1 text-sm" placeholder="Umum">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700">Satuan</label>
                        <input type="text" name="new_unit" class="w-full border rounded p-1 text-sm" placeholder="Pcs">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-bold text-gray-700">Harga Beli</label>
                        <input type="number" name="new_buy_price" class="w-full border rounded p-1 text-sm" value="0">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700">Harga Jual</label>
                        <input type="number" name="new_sell_price" class="w-full border rounded p-1 text-sm" value="0">
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium">Jumlah Masuk</label>
                <input type="number" name="quantity" class="w-full border rounded p-2" min="1" required>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium">Catatan</label>
                <input type="text" name="notes" class="w-full border rounded p-2" placeholder="Keterangan tambahan">
            </div>

            <button type="submit" class="w-full bg-green-600 text-white py-2 rounded hover:bg-green-700">Simpan Barang Masuk</button>
        </form>
    </div>

    <!-- History -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="text-lg font-bold mb-4">Riwayat Barang Masuk</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b bg-gray-50">
                        <th class="p-2 text-left">Tgl/Ref</th>
                        <th class="p-2 text-left">Barang</th>
                        <th class="p-2 text-right">Qty</th>
                        <th class="p-2 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $logs = $pdo->query("SELECT i.*, p.name FROM inventory_transactions i JOIN products p ON i.product_id = p.id WHERE i.type='IN' ORDER BY i.id DESC LIMIT 10")->fetchAll();
                    foreach($logs as $l): ?>
                    <tr class="border-b">
                        <td class="p-2">
                            <div class="font-bold"><?= $l['date'] ?></div>
                            <div class="text-xs text-gray-500"><?= $l['reference'] ? $l['reference'] : '-' ?></div>
                        </td>
                        <td class="p-2"><?= $l['name'] ?></td>
                        <td class="p-2 text-right font-bold text-green-600">+<?= $l['quantity'] ?></td>
                        <td class="p-2 text-center">
                            <a href="?page=cetak_surat_jalan&id=<?= $l['id'] ?>" target="_blank" class="bg-gray-100 text-gray-600 px-2 py-1 rounded hover:bg-gray-200" title="Cetak Surat Jalan">
                                <i class="fas fa-print"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
async function checkSku() {
    const sku = document.getElementById('sku_input').value;
    const newForm = document.getElementById('new_product_form');
    const status = document.getElementById('sku_status');
    const msg = document.getElementById('sku_msg');
    
    if(!sku) return;

    try {
        status.innerHTML = '<i class="fas fa-spinner fa-spin text-gray-500"></i>';
        const response = await fetch('api.php?action=get_product_by_sku&sku=' + sku);
        const data = await response.json();

        if (data.error) {
            // Produk Tidak Ada
            status.innerHTML = '<i class="fas fa-plus-circle text-orange-500"></i>';
            msg.innerText = "Barang baru! Silakan isi detailnya.";
            msg.className = "text-xs mt-1 text-orange-600 font-bold";
            newForm.classList.remove('hidden');
            document.getElementById('new_name').focus();
            
            // Required fields enable
            document.getElementById('new_name').required = true;
        } else {
            // Produk Ada
            status.innerHTML = '<i class="fas fa-check-circle text-green-500"></i>';
            msg.innerText = data.name + " (" + data.stock + " " + data.unit + ")";
            msg.className = "text-xs mt-1 text-green-600 font-bold";
            newForm.classList.add('hidden');
            
            // Required fields disable
            document.getElementById('new_name').required = false;
        }
    } catch (err) {
        console.error(err);
    }
}

async function generateReference(type) {
    const refInput = document.getElementById('reference_input');
    const tgl = document.getElementById('tgl_transaksi').value;
    const dateObj = new Date(tgl);
    const day = String(dateObj.getDate()).padStart(2, '0');
    const month = String(dateObj.getMonth() + 1).padStart(2, '0');
    const year = dateObj.getFullYear();

    try {
        const res = await fetch('api.php?action=get_next_trx_number&type=' + type);
        const data = await res.json();
        const nextNo = String(data.next_no).padStart(3, '0');
        
        // Format: TIPE/NO/TGL/BLN/THN
        refInput.value = `${type}/${nextNo}/${day}/${month}/${year}`;
    } catch(e) {
        alert("Gagal generate: " + e);
    }
}

let html5QrCode;
function startScan() {
    document.getElementById('scanner_area').classList.remove('hidden');
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };
    html5QrCode = new Html5Qrcode("reader");
    html5QrCode.start({ facingMode: "environment" }, config, 
        (decodedText) => {
            document.getElementById('sku_input').value = decodedText;
            checkSku(); 
            stopScan();
        },
        () => {}
    ).catch(err => { alert("Kamera Error: " + err); stopScan(); });
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