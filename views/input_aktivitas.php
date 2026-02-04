<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- LOGIKA SIMPAN AKTIVITAS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_activity'])) {
    validate_csrf();
    
    $cart_json = $_POST['cart_json'];
    $cart = json_decode($cart_json, true);
    
    $date = $_POST['date'];
    $wh_id = $_POST['warehouse_id']; // Gudang Asal (Wajib)
    $ref = $_POST['reference']; // ID Pelanggan / No Tiket / Ref Project
    $desc_main = trim($_POST['description']); // Keterangan Aktivitas
    
    // Validasi
    if (empty($wh_id)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gudang harus dipilih.'];
    } elseif (empty($desc_main)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Keterangan Aktivitas wajib diisi.'];
    } elseif (empty($cart) || !is_array($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Keranjang barang kosong!'];
    } else {
        $pdo->beginTransaction();
        try {
            // Ambil Nama Gudang
            $stmtWh = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
            $stmtWh->execute([$wh_id]);
            $wh_name = $stmtWh->fetchColumn();
            
            // Ambil Akun Biaya (Prioritas: 2009 Pengeluaran Wilayah, Fallback: 2105 Material)
            $accStmt = $pdo->query("SELECT id FROM accounts WHERE code = '2009' LIMIT 1");
            $accId = $accStmt->fetchColumn();
            if (!$accId) {
                $accId = $pdo->query("SELECT id FROM accounts WHERE code = '2105' LIMIT 1")->fetchColumn();
            }

            $count = 0;
            foreach ($cart as $item) {
                // 1. Cek Stok & Data Produk
                $stmt = $pdo->prepare("SELECT id, stock, buy_price, name, has_serial_number FROM products WHERE sku = ?");
                $stmt->execute([$item['sku']]);
                $prod = $stmt->fetch();
                
                if (!$prod) throw new Exception("Produk dengan SKU {$item['sku']} tidak ditemukan.");
                
                // Validasi SN
                if ($prod['has_serial_number'] == 1) {
                    if (empty($item['sns']) || count($item['sns']) != $item['qty']) {
                        throw new Exception("Produk {$prod['name']} wajib Serial Number sejumlah Qty.");
                    }
                }

                if ($prod['stock'] < $item['qty']) {
                    throw new Exception("Stok {$item['name']} tidak mencukupi! (Global Sisa: {$prod['stock']})");
                }
                
                // 2. Kurangi Stok Global
                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$item['qty'], $prod['id']]);
                
                // 3. Catat Transaksi INVENTORI (OUT)
                $notes_out = "Aktivitas: " . $desc_main;
                if(!empty($item['notes'])) $notes_out .= " (" . $item['notes'] . ")";
                if(!empty($item['sns'])) $notes_out .= " [SN: " . implode(', ', $item['sns']) . "]";

                $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)")
                    ->execute([$date, $prod['id'], $wh_id, $item['qty'], $ref, $notes_out, $_SESSION['user_id']]);
                $trx_out_id = $pdo->lastInsertId();

                // 4. Update Status SN (Jika Ada)
                if ($prod['has_serial_number'] == 1 && !empty($item['sns'])) {
                    $snStmt = $pdo->prepare("UPDATE product_serials SET status = 'SOLD', out_transaction_id = ? WHERE product_id = ? AND serial_number = ? AND status = 'AVAILABLE'");
                    foreach ($item['sns'] as $sn) {
                        $snStmt->execute([$trx_out_id, $prod['id'], $sn]);
                        if ($snStmt->rowCount() == 0) {
                            $chk = $pdo->query("SELECT status FROM product_serials WHERE serial_number='$sn'")->fetchColumn();
                            throw new Exception("SN $sn tidak valid/tersedia (Status: $chk).");
                        }
                    }
                }
                
                // 5. Catat Transaksi KEUANGAN (EXPENSE)
                // Menggunakan Harga Beli (HPP) sebagai nilai beban
                $total_value = $item['qty'] * $prod['buy_price'];
                
                if ($accId && $total_value > 0) {
                    $desc_fin = "[AKTIVITAS] {$prod['name']} ({$item['qty']}) - $desc_main [Wilayah: $wh_name]";
                    $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                        ->execute([$date, $accId, $total_value, $desc_fin, $_SESSION['user_id']]);
                }

                $count++;
            }
            
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$count item aktivitas berhasil diproses. Stok & Biaya tercatat."];
            echo "<script>window.location='?page=input_aktivitas';</script>";
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: '.$e->getMessage()];
        }
    }
}

$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$app_ref_prefix = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<!-- UI HALAMAN -->
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-purple-600">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 border-b pb-4 gap-4">
            <div>
                <h3 class="font-bold text-xl text-purple-700 flex items-center gap-2">
                    <i class="fas fa-tools"></i> Input Aktivitas / Pemakaian
                </h3>
                <p class="text-sm text-gray-500 mt-1">Gunakan fitur ini untuk mencatat barang yang digunakan untuk operasional (Bukan dijual).</p>
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

            <!-- HEADER INPUT -->
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Tanggal</label>
                        <input type="date" name="date" id="trx_date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Lokasi Gudang / Wilayah <span class="text-red-500">*</span></label>
                        <select name="warehouse_id" id="warehouse_id" class="w-full border p-2 rounded text-sm bg-white font-bold text-purple-700" required>
                            <option value="" selected disabled>-- Pilih Gudang Asal Barang --</option>
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
                        <label class="block text-xs font-bold text-gray-600 mb-1">Keterangan Aktivitas <span class="text-red-500">*</span></label>
                        <input type="text" name="description" class="w-full border p-2 rounded text-sm" placeholder="Contoh: Instalasi Modem di Rumah Bpk. Budi" required>
                    </div>
                </div>
            </div>

            <!-- ITEM INPUT -->
            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 mb-6">
                <h4 class="font-bold text-blue-800 mb-3 text-sm border-b border-blue-200 pb-1">Input Barang</h4>
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                    <div class="md:col-span-4">
                        <label class="block text-xs font-bold text-gray-600 mb-1">SKU / SN Barcode</label>
                        <div class="flex gap-1">
                            <input type="text" id="sku_input" class="w-full border-2 border-blue-500 p-2 rounded font-mono font-bold text-lg uppercase" placeholder="SCAN..." onchange="checkSku()" onkeydown="if(event.key === 'Enter'){ checkSku(); event.preventDefault(); }">
                            <button type="button" onclick="checkSku()" class="bg-blue-600 text-white px-3 rounded hover:bg-blue-700"><i class="fas fa-search"></i></button>
                        </div>
                        <span id="sku_status" class="text-[10px] block mt-1 font-bold"></span>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Qty</label>
                        <input type="number" id="qty_input" class="w-full border p-2 rounded font-bold text-center" placeholder="0" onchange="renderSnInputs()" onkeydown="if(event.key === 'Enter'){ addToCart(); event.preventDefault(); }">
                    </div>

                    <div class="md:col-span-4">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Catatan Item (Opsional)</label>
                        <input type="text" id="notes_input" class="w-full border p-2 rounded" placeholder="Keterangan per barang..." onkeydown="if(event.key === 'Enter'){ addToCart(); event.preventDefault(); }">
                    </div>

                    <div class="md:col-span-2">
                        <button type="button" onclick="addToCart()" class="w-full bg-orange-500 text-white py-2 rounded font-bold hover:bg-orange-600 shadow flex justify-center items-center gap-2">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                    </div>
                </div>
                
                <!-- SN INPUT AREA -->
                <div id="sn_out_container" class="hidden mt-4 bg-purple-100 p-3 rounded border border-purple-200">
                    <h5 class="text-xs font-bold text-purple-800 mb-2">Scan Serial Number (Wajib)</h5>
                    <div id="sn_out_inputs" class="grid grid-cols-2 md:grid-cols-4 gap-2"></div>
                </div>

                <input type="hidden" id="h_name"><input type="hidden" id="h_stock">
                <input type="hidden" id="h_has_sn" value="0">
                <input type="hidden" id="h_scanned_sn" value="">
            </div>

            <!-- CART TABLE -->
            <div class="mb-6">
                <h4 class="font-bold text-gray-800 mb-2">Keranjang Pemakaian <span id="cart_count" class="bg-purple-600 text-white text-xs px-2 rounded-full">0</span></h4>
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
            
            <button type="submit" id="btn_process" class="w-full bg-purple-600 text-white py-3 rounded-lg font-bold text-lg hover:bg-purple-700 shadow-lg disabled:bg-gray-400 flex justify-center items-center gap-2">
                <i class="fas fa-save"></i> Simpan Aktivitas
            </button>
        </form>
    </div>
</div>

<script>
let html5QrCode;
let audioCtx = null;
let isFlashOn = false;
let cart = [];
const APP_NAME_PREFIX = "<?= $app_ref_prefix ?>";

// --- GENERATE REFERENCE ---
async function generateReference() {
    const d = new Date();
    const dateStr = d.getDate().toString().padStart(2, '0') + (d.getMonth()+1).toString().padStart(2, '0');
    const rand = Math.floor(100 + Math.random() * 900);
    document.getElementById('reference_input').value = `ACT/${dateStr}/${rand}`;
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
    if(q>st) return alert("Stok kurang!"); 
    
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
        if((cart[exist].qty + q) > st) return alert("Total stok kurang!"); 
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
    return confirm("Simpan aktivitas? Stok dan Biaya akan tercatat."); 
}

document.addEventListener('DOMContentLoaded', generateReference);
</script>