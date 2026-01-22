<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'SVP', 'MANAGER']);

// --- PROSES SIMPAN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'];
    $warehouse_id = $_POST['warehouse_id'];
    $sku = $_POST['sku'];
    $qty = $_POST['quantity'];
    $notes = $_POST['notes'];
    $reference = $_POST['reference']; 
    $buy_price = (float)str_replace('.', '', $_POST['buy_price']); 
    $sell_price = (float)str_replace('.', '', $_POST['sell_price']); 
    $new_name = $_POST['new_name'] ?? '';
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->execute([$sku]);
        $prod = $stmt->fetch();
        
        $product_id = null;
        if ($prod) {
            $product_id = $prod['id'];
            $pdo->prepare("UPDATE products SET stock=stock+?, buy_price=?, sell_price=? WHERE id=?")->execute([$qty, $buy_price, $sell_price, $product_id]);
        } else {
            if(empty($new_name)) throw new Exception("Nama barang wajib diisi untuk barang baru!");
            $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$sku, $new_name, $_POST['new_category'], $_POST['new_unit'], $buy_price, $sell_price, $qty]);
            $product_id = $pdo->lastInsertId();
        }

        $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, notes, reference, user_id) VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)")
            ->execute([$date, $product_id, $warehouse_id, $qty, $notes, $reference, $_SESSION['user_id']]);
        $trx_id = $pdo->lastInsertId();

        if ($buy_price > 0) {
            $accAsset = $pdo->query("SELECT id FROM accounts WHERE code='3003'")->fetch(); 
            if ($accAsset) {
                $desc = "Beli Stok: " . ($prod ? $sku : $new_name) . " ($qty x ".number_format($buy_price).")";
                $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                    ->execute([$date, $accAsset['id'], $qty * $buy_price, $desc, $_SESSION['user_id']]);
            }
        }
        
        logActivity($pdo, 'BARANG_MASUK', "Input Masuk: $sku ($qty)");
        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Berhasil! <a href="?page=cetak_surat_jalan&id='.$trx_id.'" target="_blank" class="underline font-bold">Cetak Surat Jalan</a>'];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }
    echo "<script>window.location='?page=barang_masuk';</script>";
    exit;
}

$warehouses = $pdo->query("SELECT * FROM warehouses")->fetchAll();
$history = $pdo->query("SELECT i.*, p.name as prod_name, p.sku, p.sell_price, u.username 
                        FROM inventory_transactions i 
                        JOIN products p ON i.product_id = p.id 
                        JOIN users u ON i.user_id = u.id 
                        WHERE i.type = 'IN' 
                        ORDER BY i.created_at DESC LIMIT 10")->fetchAll();
?>

<!-- Load JsBarcode -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"></script>

<style>
    @media print {
        body.label-print-mode * { visibility: hidden; }
        body.label-print-mode #label_print_area, body.label-print-mode #label_print_area * { visibility: visible; }
        body.label-print-mode #label_print_area {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
        }
        @page { size: 50mm 30mm; margin: 0; }
        .label-box {
            width: 48mm;
            height: 28mm;
            border: 1px solid #000;
            padding: 2px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-family: sans-serif;
            overflow: hidden;
        }
    }
    #label_print_area { display: none; }
    body.label-print-mode #label_print_area { display: flex; }
</style>

<div id="label_print_area">
    <div class="label-box">
        <div class="font-bold text-[10px] leading-tight mb-1 truncate w-full px-1" id="lbl_name">NAMA BARANG</div>
        <svg id="lbl_barcode" class="w-full h-8"></svg>
        <div class="font-bold text-[12px] mt-1" id="lbl_price">Rp 0</div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex justify-between items-center mb-6 border-b pb-4">
                <h3 class="font-bold text-xl text-green-700"><i class="fas fa-box-open"></i> Input Barang Masuk (Pembelian)</h3>
                <div class="flex gap-2">
                    <button onclick="focusInput()" class="bg-gray-200 text-gray-700 px-3 py-2 rounded shadow hover:bg-gray-300 transition text-sm" title="Klik untuk siap scan dengan USB">
                        <i class="fas fa-barcode"></i> USB Mode
                    </button>
                    <button onclick="startScan()" class="bg-indigo-600 text-white px-3 py-2 rounded shadow hover:bg-indigo-700 transition text-sm">
                        <i class="fas fa-camera"></i> Scan Kamera
                    </button>
                </div>
            </div>
            
            <div id="scanner_area" class="hidden mb-6 bg-black rounded-lg relative overflow-hidden shadow-inner border-4 border-gray-800">
                <div id="reader" class="w-full h-64 md:h-80 bg-black"></div>
                <div class="absolute top-2 right-2 z-10 flex flex-col gap-2">
                    <button onclick="stopScan()" class="bg-red-600 text-white w-10 h-10 rounded-full shadow hover:bg-red-700 flex items-center justify-center"><i class="fas fa-times"></i></button>
                    <button id="btn_flash" onclick="toggleFlash()" class="hidden bg-yellow-400 text-black w-10 h-10 rounded-full shadow hover:bg-yellow-500 flex items-center justify-center"><i class="fas fa-bolt"></i></button>
                </div>
                <div class="absolute bottom-4 left-0 right-0 text-center pointer-events-none">
                    <span class="bg-black bg-opacity-50 text-white px-4 py-1 rounded-full text-xs">Arahkan kamera ke Barcode</span>
                </div>
            </div>

            <form method="POST">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                        <input type="date" name="date" id="trx_date" value="<?= date('Y-m-d') ?>" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Gudang Penerima</label>
                        <select name="warehouse_id" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:outline-none bg-white">
                            <?php foreach($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">No. Surat Jalan / Referensi</label>
                    <div class="flex gap-2">
                        <input type="text" name="reference" id="reference_input" placeholder="Contoh: PO-001 atau INV-SUPPLIER" class="w-full border border-gray-300 p-2 rounded uppercase font-medium focus:ring-2 focus:ring-green-500 focus:outline-none" required>
                        <button type="button" onclick="generateReference()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded hover:bg-gray-300 font-bold whitespace-nowrap border border-gray-300">
                            <i class="fas fa-magic text-green-600"></i> Auto
                        </button>
                    </div>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-1">SKU / Kode Barang (Scan disini)</label>
                    <div class="flex gap-2">
                        <input type="text" name="sku" id="sku_input" class="w-full border-2 border-green-600 p-3 rounded font-mono font-bold text-lg focus:ring-4 focus:ring-green-200 focus:outline-none transition-all" placeholder="Scan Barcode / Ketik SKU..." onblur="checkSku()" onkeydown="handleEnter(event)" required autofocus>
                        
                        <!-- Auto Generate -->
                        <button type="button" onclick="generateNewSku()" class="bg-yellow-500 text-white px-3 rounded hover:bg-yellow-600 font-bold" title="Generate Barcode Baru"><i class="fas fa-magic"></i> Auto</button>
                        
                        <!-- Download Image -->
                        <button type="button" onclick="downloadBarcodeImg()" class="bg-blue-600 text-white px-3 rounded hover:bg-blue-700 font-bold" title="Download Gambar"><i class="fas fa-image"></i></button>
                        
                        <!-- Check -->
                        <button type="button" onclick="checkSku()" class="bg-green-600 text-white px-4 rounded hover:bg-green-700 font-bold"><i class="fas fa-search"></i></button>
                    </div>
                    <p id="sku_status" class="text-xs mt-1 min-h-[1.25rem] font-medium"></p>
                </div>

                <div id="detail_section" class="hidden mb-6 p-5 border bg-green-50 border-green-200 rounded-lg transition-all duration-300">
                    <div id="new_fields" class="hidden mb-4 border-b pb-4 border-green-200">
                        <div class="flex items-center gap-2 mb-3 bg-orange-100 text-orange-800 p-2 rounded border border-orange-200">
                            <i class="fas fa-plus-circle text-lg"></i>
                            <span class="font-bold text-sm">Barang Baru Terdeteksi! Silakan lengkapi data.</span>
                        </div>
                        <div class="mb-3">
                            <label class="block text-xs text-gray-600 font-bold mb-1">Nama Barang Baru <span class="text-red-500">*</span></label>
                            <input type="text" name="new_name" id="new_name" placeholder="Masukkan Nama Barang" class="w-full border p-2 rounded focus:ring-1 focus:ring-green-500">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-600 font-bold mb-1">Kategori</label>
                                <input type="text" name="new_category" placeholder="Umum" class="w-full border p-2 rounded focus:ring-1 focus:ring-green-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 font-bold mb-1">Satuan</label>
                                <input type="text" name="new_unit" placeholder="Pcs/Pack" class="w-full border p-2 rounded focus:ring-1 focus:ring-green-500">
                            </div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Harga Beli (HPP) <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-gray-500 text-sm">Rp</span>
                                <input type="number" name="buy_price" id="buy_price" class="w-full border p-2 pl-10 rounded text-right font-medium focus:ring-1 focus:ring-green-500" placeholder="0">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Harga Jual</label>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-gray-500 text-sm">Rp</span>
                                <input type="number" name="sell_price" id="sell_price" class="w-full border p-2 pl-10 rounded text-right font-medium focus:ring-1 focus:ring-green-500" placeholder="0">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Jumlah Masuk</label>
                        <input type="number" name="quantity" id="qty_input" class="w-full border border-gray-300 p-2 rounded font-bold text-lg focus:ring-2 focus:ring-green-500 focus:outline-none" required>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Keterangan</label>
                        <input type="text" name="notes" class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-green-500 focus:outline-none" placeholder="Keterangan tambahan">
                    </div>
                </div>
                
                <button type="submit" class="w-full bg-green-600 text-white py-3 rounded-lg font-bold text-lg hover:bg-green-700 shadow-lg transition transform active:scale-95">
                    <i class="fas fa-save mr-2"></i> Simpan & Catat Keuangan
                </button>
            </form>
        </div>
    </div>
    
    <div class="xl:col-span-1">
        <div class="bg-white p-6 rounded-lg shadow h-full flex flex-col">
            <h3 class="font-bold text-lg mb-4 text-gray-800 border-b pb-2">Riwayat Barang Masuk</h3>
            <div class="flex-1 overflow-y-auto max-h-[600px] pr-2">
                <?php if(count($history) > 0): ?>
                    <div class="space-y-3">
                        <?php foreach($history as $h): ?>
                        <div class="p-3 border rounded-lg hover:bg-gray-50 transition bg-white shadow-sm relative group">
                            <button type="button" onclick='printLabel("<?= $h['sku'] ?>", "<?= htmlspecialchars($h['prod_name']) ?>", "<?= formatRupiah($h['sell_price']) ?>")' class="absolute top-2 right-2 text-gray-400 hover:text-black bg-white border rounded p-1 shadow-sm" title="Cetak Label Barcode">
                                <i class="fas fa-tag"></i>
                            </button>

                            <div class="flex justify-between items-start mb-1 pr-6">
                                <span class="text-xs font-bold text-gray-500"><?= date('d/m/y H:i', strtotime($h['created_at'])) ?></span>
                                <span class="text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded font-bold">IN</span>
                            </div>
                            <div class="font-bold text-gray-800 text-sm"><?= $h['prod_name'] ?></div>
                            <div class="text-xs text-gray-500 mb-2">Ref: <?= $h['reference'] ?></div>
                            <div class="flex justify-between items-center border-t pt-2 mt-1">
                                <span class="text-sm font-bold text-green-600">+ <?= $h['quantity'] ?> Pcs</span>
                                <a href="?page=cetak_surat_jalan&id=<?= $h['id'] ?>" target="_blank" class="text-xs text-blue-600 hover:underline"><i class="fas fa-print"></i> Cetak SJ</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center text-gray-400 py-10">
                        <i class="fas fa-history text-4xl mb-2"></i>
                        <p>Belum ada riwayat transaksi.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
let html5QrCode;
let isFlashOn = false;

function handleEnter(e) {
    if(e.key === 'Enter') {
        e.preventDefault();
        checkSku();
    }
}

async function generateNewSku() {
    try {
        const res = await fetch('api.php?action=generate_sku');
        const data = await res.json();
        if(data.sku) {
            document.getElementById('sku_input').value = data.sku;
            checkSku();
        }
    } catch(e) { alert("Gagal generate SKU"); }
}

// DOWNLOAD IMAGE FUNCTION
function downloadBarcodeImg() {
    const sku = document.getElementById('sku_input').value;
    const name = document.getElementById('new_name').value || 'barcode';
    if(!sku) return alert("Generate atau Scan SKU terlebih dahulu!");

    const canvas = document.createElement('canvas');
    try {
        JsBarcode(canvas, sku, { format: "CODE128", width: 2, height: 60, displayValue: true });
        const link = document.createElement('a');
        link.download = name.replace(/\s+/g, '_') + '_' + sku + '.png';
        link.href = canvas.toDataURL("image/png");
        link.click();
    } catch(e) { alert("Gagal membuat gambar barcode."); }
}

function printLabel(sku, name, price) {
    document.getElementById('lbl_name').innerText = name;
    document.getElementById('lbl_price').innerText = price;
    try {
        JsBarcode("#lbl_barcode", sku, { format: "CODE128", width: 2, height: 30, displayValue: true, fontSize: 12, margin: 0 });
    } catch(e) { alert("Gagal generate barcode."); return; }
    document.body.classList.add('label-print-mode');
    window.print();
    setTimeout(() => { document.body.classList.remove('label-print-mode'); }, 1000);
}

function focusInput() { document.getElementById('sku_input').focus(); }

async function generateReference() {
    const dateVal = document.getElementById('trx_date').value; 
    if(!dateVal) return;
    try {
        const res = await fetch('api.php?action=get_next_trx_number&type=IN');
        const data = await res.json();
        const nextNo = String(data.next_no).padStart(3, '0');
        const d = new Date(dateVal);
        const refCode = `IN/${nextNo}/${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
        document.getElementById('reference_input').value = refCode;
    } catch (e) { alert("Gagal generate referensi"); }
}

async function checkSku() {
    const sku = document.getElementById('sku_input').value;
    const statusEl = document.getElementById('sku_status');
    const detailSec = document.getElementById('detail_section');
    const newFields = document.getElementById('new_fields');
    if(!sku) return;
    statusEl.innerHTML = '<span class="text-gray-500"><i class="fas fa-spinner fa-spin"></i> Mengecek...</span>';
    try {
        const res = await fetch('api.php?action=get_product_by_sku&sku=' + sku);
        const data = await res.json();
        detailSec.classList.remove('hidden');
        if(data.error) {
            statusEl.innerHTML = '<span class="text-orange-600 font-bold bg-orange-100 px-2 py-1 rounded text-xs"><i class="fas fa-plus-circle"></i> Barang Baru</span>';
            newFields.classList.remove('hidden');
            document.getElementById('new_name').focus();
            document.getElementById('buy_price').value = '';
            document.getElementById('sell_price').value = '';
        } else {
            statusEl.innerHTML = '<span class="text-green-600 font-bold bg-green-100 px-2 py-1 rounded text-xs"><i class="fas fa-check-circle"></i> ' + data.name + ' (Stok: ' + data.stock + ')</span>';
            newFields.classList.add('hidden');
            document.getElementById('buy_price').value = data.buy_price;
            document.getElementById('sell_price').value = data.sell_price;
            document.getElementById('qty_input').focus();
        }
    } catch(e) { statusEl.innerHTML = '<span class="text-red-500">Error koneksi.</span>'; }
}

function startScan() {
    const scannerArea = document.getElementById('scanner_area');
    scannerArea.classList.remove('hidden');
    if(html5QrCode) html5QrCode.clear();
    html5QrCode = new Html5Qrcode("reader");
    html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: { width: 250, height: 250 } }, 
        (decodedText) => {
            document.getElementById('sku_input').value = decodedText;
            checkSku();
            stopScan();
            new Audio('https://actions.google.com/sounds/v1/alarms/beep_short.ogg').play();
        }, () => {}
    ).then(() => {
        const track = html5QrCode.getRunningTrackCameraCapabilities();
        if (track && track.torchFeature().isSupported()) document.getElementById('btn_flash').classList.remove('hidden');
    }).catch(err => { alert("Camera Error: " + err); scannerArea.classList.add('hidden'); });
}
function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => document.getElementById('scanner_area').classList.add('hidden')); else document.getElementById('scanner_area').classList.add('hidden'); }
function toggleFlash() { if(html5QrCode) { isFlashOn = !isFlashOn; html5QrCode.applyVideoConstraints({ advanced: [{ torch: isFlashOn }] }); } }
</script>