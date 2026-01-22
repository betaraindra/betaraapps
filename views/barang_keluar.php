<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER']);

// --- PROSES SIMPAN TRANSAKSI (BATCH / MULTI ITEM) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_transaction'])) {
    
    $date = $_POST['date'];
    $warehouse_id = $_POST['warehouse_id'];
    $reference = $_POST['reference'];
    $out_type = $_POST['out_type'];
    
    // Data Cart dikirim dalam format JSON string
    $cart_items = json_decode($_POST['cart_json'], true);

    if (empty($cart_items)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Daftar barang masih kosong!'];
    } else {
        $pdo->beginTransaction();
        try {
            $total_success = 0;
            $first_trx_id = 0;

            foreach ($cart_items as $item) {
                $sku = $item['sku'];
                $qty = (int)$item['qty'];
                $notes = $item['notes'];

                // 1. Validasi Stok Terkini di Database (Penting untuk mencegah race condition)
                $stmt = $pdo->prepare("SELECT id, stock, buy_price, sell_price, name FROM products WHERE sku = ? FOR UPDATE");
                $stmt->execute([$sku]);
                $p = $stmt->fetch();

                if (!$p) throw new Exception("Barang SKU $sku tidak ditemukan.");
                if ($p['stock'] < $qty) throw new Exception("Stok barang {$p['name']} tidak cukup! Sisa: {$p['stock']}, Minta: $qty");

                // 2. Simpan Inventory Transaction
                $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, notes, reference, user_id) VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)")
                    ->execute([$date, $p['id'], $warehouse_id, $qty, $notes, $reference, $_SESSION['user_id']]);
                
                if($first_trx_id === 0) $first_trx_id = $pdo->lastInsertId();

                // 3. Update Stok Produk
                $pdo->prepare("UPDATE products SET stock=stock-? WHERE id=?")->execute([$qty, $p['id']]);

                // 4. Catat Keuangan (Jika Eksternal)
                if ($out_type == 'EXTERNAL') {
                    $accSales = $pdo->query("SELECT id FROM accounts WHERE code='1001'")->fetch();
                    $accHpp = $pdo->query("SELECT id FROM accounts WHERE code='2001'")->fetch();
                    
                    // Catat Penjualan (INCOME)
                    if ($accSales) $pdo->prepare("INSERT INTO finance_transactions (date,type,account_id,amount,description,user_id) VALUES (?, 'INCOME', ?, ?, ?, ?)")
                        ->execute([$date, $accSales['id'], $qty * $p['sell_price'], "Penjualan: $sku ($qty x ".number_format($p['sell_price']).") Ref: $reference", $_SESSION['user_id']]);
                    
                    // Catat HPP (EXPENSE)
                    if ($accHpp) $pdo->prepare("INSERT INTO finance_transactions (date,type,account_id,amount,description,user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                        ->execute([$date, $accHpp['id'], $qty * $p['buy_price'], "HPP: $sku ($qty x ".number_format($p['buy_price']).") Ref: $reference", $_SESSION['user_id']]);
                }
                
                logActivity($pdo, 'BARANG_KELUAR', "Keluar: $sku ($qty) - " . $p['name']);
                $total_success++;
            }

            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$total_success Barang Berhasil Dikeluarkan! <a href='?page=cetak_surat_jalan&id=$first_trx_id' target='_blank' class='underline font-bold'>Cetak Surat Jalan</a>"];
        } catch(Exception $e) { 
            $pdo->rollBack(); 
            $_SESSION['flash'] = ['type'=>'error', 'message'=>$e->getMessage()]; 
        }
    }
    echo "<script>window.location='?page=barang_keluar';</script>";
    exit;
}

// GET DATA GUDANG
$warehouses = $pdo->query("SELECT * FROM warehouses")->fetchAll();

// GET RIWAYAT BARANG KELUAR (10 Terakhir)
$history = $pdo->query("SELECT i.*, p.name as prod_name, p.sku, u.username 
                        FROM inventory_transactions i 
                        JOIN products p ON i.product_id = p.id 
                        JOIN users u ON i.user_id = u.id 
                        WHERE i.type = 'OUT' 
                        ORDER BY i.created_at DESC LIMIT 10")->fetchAll();
?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <!-- KOLOM KIRI: FORM INPUT -->
    <div class="xl:col-span-2">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex justify-between items-center mb-6 border-b pb-4">
                <h3 class="font-bold text-xl text-red-700"><i class="fas fa-sign-out-alt"></i> Input Barang Keluar</h3>
                <div class="flex gap-2">
                     <!-- Tombol Manual Focus untuk USB Scanner -->
                     <button onclick="document.getElementById('sku_input').focus()" class="bg-gray-200 text-gray-700 px-3 py-2 rounded shadow hover:bg-gray-300 transition text-sm">
                        <i class="fas fa-barcode"></i> USB Mode
                    </button>
                    <!-- Tombol Kamera -->
                    <button onclick="startScan()" class="bg-indigo-600 text-white px-4 py-2 rounded shadow hover:bg-indigo-700 transition text-sm">
                        <i class="fas fa-camera"></i> Scan Kamera
                    </button>
                </div>
            </div>
            
            <!-- Scanner Area (Kamera) -->
            <div id="scanner_area" class="hidden mb-6 bg-black rounded-lg relative overflow-hidden shadow-inner border-4 border-gray-800">
                <div id="reader" class="w-full h-64 md:h-80 bg-black"></div>
                
                <!-- Controls Overlay -->
                <div class="absolute top-2 right-2 z-10 flex flex-col gap-2">
                    <button onclick="stopScan()" class="bg-red-600 text-white w-10 h-10 rounded-full shadow hover:bg-red-700 flex items-center justify-center">
                        <i class="fas fa-times"></i>
                    </button>
                    <!-- Tombol Flash -->
                    <button id="btn_flash" onclick="toggleFlash()" class="hidden bg-yellow-400 text-black w-10 h-10 rounded-full shadow hover:bg-yellow-500 flex items-center justify-center">
                        <i class="fas fa-bolt"></i>
                    </button>
                </div>
                
                <div class="absolute bottom-4 left-0 right-0 text-center pointer-events-none">
                    <span class="bg-black bg-opacity-50 text-white px-4 py-1 rounded-full text-xs">Arahkan kamera ke Barcode</span>
                </div>
            </div>

            <!-- FORM UTAMA -->
            <form method="POST" id="form_transaction" onsubmit="return validateCart()">
                <input type="hidden" name="save_transaction" value="1">
                <input type="hidden" name="cart_json" id="cart_json">

                <!-- 1. HEADER INFORMASI (Berlaku untuk semua barang) -->
                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-6">
                    <h4 class="font-bold text-gray-700 mb-3 text-sm uppercase tracking-wide border-b pb-1">Informasi Umum</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Tanggal</label>
                            <input type="date" name="date" id="trx_date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded text-sm focus:ring-1 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Asal Gudang</label>
                            <select name="warehouse_id" class="w-full border p-2 rounded text-sm bg-white focus:ring-1 focus:ring-red-500">
                                <?php foreach($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">No. Referensi / Surat Jalan</label>
                            <div class="flex gap-1">
                                <input type="text" name="reference" id="reference_input" class="w-full border p-2 rounded text-sm uppercase font-bold" placeholder="Auto Generate..." required>
                                <button type="button" onclick="generateReference()" class="bg-gray-200 px-3 rounded text-gray-600 hover:bg-gray-300"><i class="fas fa-magic"></i></button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Jenis Pengeluaran</label>
                            <select name="out_type" class="w-full border p-2 rounded text-sm bg-white">
                                <option value="INTERNAL">Mutasi / Internal</option>
                                <option value="EXTERNAL">Penjualan / Eksternal</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 2. INPUT BARANG (Cart Adder) -->
                <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 mb-6 relative">
                    <h4 class="font-bold text-blue-800 mb-3 text-sm uppercase tracking-wide border-b border-blue-200 pb-1">Input Barang</h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                        <div class="md:col-span-4">
                            <label class="block text-xs font-bold text-gray-600 mb-1">Scan / Input SKU (USB Ready)</label>
                            <div class="flex gap-1">
                                <!-- Autofocus & Enter listener for USB Scanner -->
                                <input type="text" id="sku_input" class="w-full border-2 border-blue-500 p-2 rounded font-mono font-bold text-lg" placeholder="Kode Barang" onblur="checkSku()" onkeydown="handleEnter(event)" autofocus>
                                <button type="button" onclick="checkSku()" class="bg-blue-600 text-white px-3 rounded hover:bg-blue-700"><i class="fas fa-search"></i></button>
                            </div>
                            <span id="sku_status" class="text-[10px] block mt-1 min-h-[15px]"></span>
                        </div>
                        
                        <div class="md:col-span-2">
                             <label class="block text-xs font-bold text-gray-600 mb-1">Qty</label>
                             <input type="number" id="qty_input" class="w-full border p-2 rounded font-bold text-center" placeholder="0" onkeydown="if(event.key === 'Enter'){ addToCart(); }">
                        </div>

                        <div class="md:col-span-4">
                             <label class="block text-xs font-bold text-gray-600 mb-1">Catatan Item</label>
                             <input type="text" id="notes_input" class="w-full border p-2 rounded" placeholder="Keterangan per item" onkeydown="if(event.key === 'Enter'){ addToCart(); }">
                        </div>

                        <div class="md:col-span-2">
                            <button type="button" onclick="addToCart()" class="w-full bg-orange-500 text-white py-2 rounded font-bold hover:bg-orange-600 shadow transition transform active:scale-95">
                                <i class="fas fa-plus"></i> Tambah
                            </button>
                        </div>
                    </div>

                    <!-- Hidden Info Data Holder -->
                    <input type="hidden" id="h_name">
                    <input type="hidden" id="h_stock">
                </div>

                <!-- 3. TABEL DAFTAR BARANG (CART) -->
                <div class="mb-6">
                    <h4 class="font-bold text-gray-800 mb-2">Daftar Barang Keluar</h4>
                    <div class="border rounded-lg overflow-hidden bg-white shadow-sm">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-100 text-gray-700 border-b">
                                <tr>
                                    <th class="p-3">SKU</th>
                                    <th class="p-3">Nama Barang</th>
                                    <th class="p-3 text-center">Qty</th>
                                    <th class="p-3">Catatan</th>
                                    <th class="p-3 text-center w-16"><i class="fas fa-trash"></i></th>
                                </tr>
                            </thead>
                            <tbody id="cart_table_body" class="divide-y">
                                <!-- Cart Items Rendered Here -->
                                <tr><td colspan="5" class="p-4 text-center text-gray-400 italic">Belum ada barang di daftar.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- TOMBOL EKSEKUSI -->
                <button type="submit" id="btn_process" class="w-full bg-red-600 text-white py-3 rounded-lg font-bold text-lg hover:bg-red-700 shadow-lg transition transform active:scale-95 disabled:bg-gray-400 disabled:cursor-not-allowed">
                    <i class="fas fa-paper-plane mr-2"></i> Proses Semua Barang
                </button>
            </form>
        </div>
    </div>

    <!-- KOLOM KANAN: RIWAYAT -->
    <div class="xl:col-span-1">
        <div class="bg-white p-6 rounded-lg shadow h-full flex flex-col">
            <h3 class="font-bold text-lg mb-4 text-gray-800 border-b pb-2">Riwayat Terakhir</h3>
            <div class="flex-1 overflow-y-auto max-h-[600px] pr-2">
                <?php if(count($history) > 0): ?>
                    <div class="space-y-3">
                        <?php foreach($history as $h): ?>
                        <div class="p-3 border rounded-lg hover:bg-gray-50 transition bg-white shadow-sm">
                            <div class="flex justify-between items-start mb-1">
                                <span class="text-xs font-bold text-gray-500"><?= date('d/m/y H:i', strtotime($h['created_at'])) ?></span>
                                <span class="text-xs bg-red-100 text-red-800 px-2 py-0.5 rounded font-bold">OUT</span>
                            </div>
                            <div class="font-bold text-gray-800 text-sm"><?= $h['prod_name'] ?></div>
                            <div class="text-xs text-gray-500 mb-2">Ref: <?= $h['reference'] ?></div>
                            <div class="flex justify-between items-center border-t pt-2 mt-1">
                                <span class="text-sm font-bold text-red-600">- <?= $h['quantity'] ?> Pcs</span>
                                <a href="?page=cetak_surat_jalan&id=<?= $h['id'] ?>" target="_blank" class="text-xs text-blue-600 hover:underline"><i class="fas fa-print"></i> Cetak</a>
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
let cart = [];
let isFlashOn = false;

document.addEventListener('DOMContentLoaded', generateReference);

// Fungsi USB Scanner Handler
function handleEnter(e) {
    if(e.key === 'Enter') {
        e.preventDefault();
        checkSku();
    }
}

async function generateReference() {
    const dateVal = document.getElementById('trx_date').value; 
    if(!dateVal) return;
    try {
        const res = await fetch('api.php?action=get_next_trx_number&type=OUT');
        const data = await res.json();
        const nextNo = String(data.next_no).padStart(3, '0');
        const d = new Date(dateVal);
        const refCode = `OUT/${nextNo}/${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
        document.getElementById('reference_input').value = refCode;
    } catch (e) {}
}

async function checkSku() {
    const sku = document.getElementById('sku_input').value;
    const statusEl = document.getElementById('sku_status');
    
    if(!sku) return;
    statusEl.innerHTML = '<span class="text-gray-500"><i class="fas fa-spinner fa-spin"></i> Cek...</span>';

    try {
        const res = await fetch('api.php?action=get_product_by_sku&sku=' + sku);
        const data = await res.json();
        
        if(data.error) {
            statusEl.innerHTML = '<span class="text-red-600 font-bold">Barang tidak ditemukan!</span>';
            document.getElementById('h_name').value = '';
            document.getElementById('h_stock').value = 0;
            // Clear input so user can try again easily
            document.getElementById('sku_input').select();
        } else {
            statusEl.innerHTML = `<span class="text-green-600 font-bold">${data.name} (Stok: ${data.stock})</span>`;
            document.getElementById('h_name').value = data.name;
            document.getElementById('h_stock').value = data.stock;
            document.getElementById('qty_input').focus();
        }
    } catch(e) { statusEl.innerHTML = 'Error koneksi.'; }
}

function addToCart() {
    const sku = document.getElementById('sku_input').value;
    const name = document.getElementById('h_name').value;
    const stock = parseInt(document.getElementById('h_stock').value || 0);
    const qty = parseInt(document.getElementById('qty_input').value || 0);
    const notes = document.getElementById('notes_input').value;

    if (!sku || !name) { alert("SKU Barang belum valid/dipilih."); return; }
    if (qty <= 0) { alert("Jumlah harus lebih dari 0."); return; }
    if (qty > stock) { alert(`Stok tidak cukup! Tersedia: ${stock}`); return; }

    const existingIndex = cart.findIndex(item => item.sku === sku);
    
    if (existingIndex > -1) {
        if ((cart[existingIndex].qty + qty) > stock) {
            alert(`Total Qty melebihi stok! Tersedia: ${stock}, Di Cart: ${cart[existingIndex].qty}`);
            return;
        }
        cart[existingIndex].qty += qty;
    } else {
        cart.push({ sku, name, qty, notes, stock });
    }

    renderCart();
    
    document.getElementById('sku_input').value = '';
    document.getElementById('qty_input').value = '';
    document.getElementById('notes_input').value = '';
    document.getElementById('h_name').value = '';
    document.getElementById('h_stock').value = 0;
    document.getElementById('sku_status').innerHTML = '';
    document.getElementById('sku_input').focus(); // Ready for next scan
}

function removeFromCart(index) {
    cart.splice(index, 1);
    renderCart();
}

function renderCart() {
    const tbody = document.getElementById('cart_table_body');
    tbody.innerHTML = '';

    if (cart.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-gray-400 italic">Belum ada barang di daftar.</td></tr>';
        return;
    }

    cart.forEach((item, index) => {
        const tr = document.createElement('tr');
        tr.className = 'border-b hover:bg-gray-50';
        tr.innerHTML = `
            <td class="p-3 font-mono font-bold">${item.sku}</td>
            <td class="p-3">${item.name}</td>
            <td class="p-3 text-center font-bold">${item.qty}</td>
            <td class="p-3 text-gray-500 italic">${item.notes}</td>
            <td class="p-3 text-center">
                <button type="button" onclick="removeFromCart(${index})" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function validateCart() {
    if (cart.length === 0) {
        alert("Mohon tambahkan minimal satu barang ke daftar!");
        return false;
    }
    document.getElementById('cart_json').value = JSON.stringify(cart);
    return confirm(`Akan memproses ${cart.length} jenis barang. Lanjutkan?`);
}

// --- CAMERA & FLASH LOGIC ---
function startScan() {
    const scannerArea = document.getElementById('scanner_area');
    scannerArea.classList.remove('hidden');
    if(html5QrCode) html5QrCode.clear();
    
    html5QrCode = new Html5Qrcode("reader");
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };

    html5QrCode.start({ facingMode: "environment" }, config, 
        (decodedText) => {
            document.getElementById('sku_input').value = decodedText;
            checkSku();
            stopScan();
            new Audio('https://actions.google.com/sounds/v1/alarms/beep_short.ogg').play();
        },
        () => {}
    )
    .then(() => {
        // Cek dukungan Flash
        const track = html5QrCode.getRunningTrackCameraCapabilities();
        const flashBtn = document.getElementById('btn_flash');
        if (track && track.torchFeature().isSupported()) {
             flashBtn.classList.remove('hidden');
        } else {
             flashBtn.classList.add('hidden');
        }
    })
    .catch(err => { 
        alert("Kamera error/tidak diizinkan: " + err); 
        scannerArea.classList.add('hidden'); 
    });
}

function stopScan() {
    if(html5QrCode) {
        if(isFlashOn) toggleFlash();
        html5QrCode.stop().then(() => document.getElementById('scanner_area').classList.add('hidden'));
    } else {
        document.getElementById('scanner_area').classList.add('hidden');
    }
}

function toggleFlash() {
    if(html5QrCode) {
        isFlashOn = !isFlashOn;
        html5QrCode.applyVideoConstraints({
            advanced: [{ torch: isFlashOn }]
        })
        .then(() => {
            const btn = document.getElementById('btn_flash');
            if(isFlashOn) {
                btn.classList.add('bg-yellow-500', 'text-white');
                btn.classList.remove('bg-yellow-400', 'text-black');
            } else {
                btn.classList.remove('bg-yellow-500', 'text-white');
                btn.classList.add('bg-yellow-400', 'text-black');
            }
        })
        .catch(err => console.error("Torch error", err));
    }
}
</script>