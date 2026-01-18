<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG']);

// Handler Simpan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'];
    $warehouse_id = $_POST['warehouse_id'];
    $sku = $_POST['sku'];
    $qty = $_POST['quantity'];
    $notes = $_POST['notes'];
    $reference = $_POST['reference']; // Surat Jalan / Invoice

    $stmt = $pdo->prepare("SELECT id, stock, name FROM products WHERE sku = ?");
    $stmt->execute([$sku]);
    $prod = $stmt->fetch();

    if ($prod) {
        if ($prod['stock'] >= $qty) {
            $pdo->beginTransaction();
            try {
                // Insert Transaction with Reference
                $stmt = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, notes, reference, user_id) VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$date, $prod['id'], $warehouse_id, $qty, $notes, $reference, $_SESSION['user_id']]);
                $trx_id = $pdo->lastInsertId();

                // Update Stock
                $stmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $stmt->execute([$qty, $prod['id']]);

                $pdo->commit();
                $_SESSION['flash'] = [
                    'type' => 'success', 
                    'message' => 'Barang Keluar Berhasil! <a href="?page=cetak_surat_jalan&id='.$trx_id.'" target="_blank" class="underline font-bold ml-2">Cetak Surat Jalan</a>'
                ];
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Gagal: ' . $e->getMessage()];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => "Stok tidak cukup! Stok {$prod['name']} saat ini: {$prod['stock']}"];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'SKU tidak ditemukan.'];
    }
    echo "<script>window.location='?page=barang_keluar';</script>";
    exit;
}
$warehouses = $pdo->query("SELECT * FROM warehouses")->fetchAll();
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white p-6 rounded-lg shadow border-t-4 border-red-500">
        <h3 class="text-lg font-bold mb-4">Input Barang Keluar</h3>
        
        <!-- Scanner Area -->
        <div id="scanner_area" class="hidden mb-4 bg-gray-900 rounded-lg overflow-hidden relative">
            <div id="reader" style="width: 100%;"></div>
            <button type="button" onclick="stopScan()" class="absolute top-2 right-2 bg-red-600 text-white px-3 py-1 rounded-full text-xs shadow z-10">Tutup Kamera</button>
            <p class="text-center text-white text-xs py-2">Arahkan kamera ke barcode</p>
        </div>

        <button type="button" onclick="startScan()" class="w-full mb-4 bg-indigo-600 text-white py-2 rounded flex items-center justify-center gap-2 hover:bg-indigo-700">
            <i class="fas fa-camera"></i> Scan Barcode Kamera
        </button>

        <form method="POST">
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
                <label class="block text-sm font-medium text-red-700 font-bold">No. Surat Jalan / Referensi</label>
                <div class="flex gap-2">
                    <input type="text" name="reference" id="reference_input" class="w-full border rounded p-2 border-red-300" placeholder="Nomor Surat Jalan untuk Penerima" required>
                    <button type="button" onclick="generateReference('OUT')" class="bg-red-600 text-white px-3 rounded hover:bg-red-700 font-bold text-xs w-24">Auto Generate</button>
                </div>
                <p class="text-xs text-gray-400 mt-1">Format Auto: OUT/No/Tgl/Bln/Thn</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium">SKU / Kode Barang</label>
                <input type="text" name="sku" id="sku_input" class="w-full border rounded p-2" placeholder="Scan barcode..." required autofocus onblur="checkProduct()">
                <p class="text-xs text-gray-500 mt-1">Gunakan Scanner USB atau tombol kamera di atas.</p>
            </div>

            <!-- DETEKSI PRODUK UI -->
            <div id="product_info" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded">
                <p class="font-bold text-red-800" id="info_name"></p>
                <p class="text-sm text-red-600" id="info_stock"></p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium">Jumlah Keluar</label>
                <input type="number" name="quantity" class="w-full border rounded p-2" min="1" required>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium">Catatan</label>
                <input type="text" name="notes" class="w-full border rounded p-2" placeholder="Keterangan tambahan">
            </div>

            <button type="submit" class="w-full bg-red-600 text-white py-2 rounded hover:bg-red-700">Keluarkan Barang</button>
        </form>
    </div>

    <!-- Latest OUT Transactions -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="text-lg font-bold mb-4">Riwayat Barang Keluar</h3>
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
                    $logs = $pdo->query("SELECT i.*, p.name FROM inventory_transactions i JOIN products p ON i.product_id = p.id WHERE i.type='OUT' ORDER BY i.id DESC LIMIT 10")->fetchAll();
                    foreach($logs as $l): ?>
                    <tr class="border-b">
                        <td class="p-2">
                             <div class="font-bold"><?= $l['date'] ?></div>
                             <div class="text-xs text-gray-500"><?= $l['reference'] ? $l['reference'] : '-' ?></div>
                        </td>
                        <td class="p-2"><?= $l['name'] ?></td>
                        <td class="p-2 text-right font-bold text-red-600">-<?= $l['quantity'] ?></td>
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
async function checkProduct() {
    const sku = document.getElementById('sku_input').value;
    const infoBox = document.getElementById('product_info');
    
    if(!sku) return;

    try {
        const response = await fetch('api.php?action=get_product_by_sku&sku=' + sku);
        const data = await response.json();

        if (data.id) {
            infoBox.classList.remove('hidden');
            document.getElementById('info_name').innerText = data.name;
            document.getElementById('info_stock').innerText = "Stok Tersedia: " + data.stock + " " + data.unit;
        } else {
            infoBox.classList.remove('hidden');
            document.getElementById('info_name').innerText = "Produk Tidak Ditemukan!";
            document.getElementById('info_name').className = "font-bold text-gray-800";
            document.getElementById('info_stock').innerText = "Pastikan SKU benar.";
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
            checkProduct();
            stopScan();
        },
        () => {}
    ).catch(err => {
        alert("Gagal mengakses kamera: " + err);
        stopScan();
    });
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