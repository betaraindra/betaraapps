<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_inbound'])) {
    validate_csrf();
    $date = $_POST['date'];
    $warehouse_id = $_POST['warehouse_id'];
    $reference = $_POST['reference'];
    $cart = json_decode($_POST['cart_json'], true);

    if (empty($warehouse_id) || empty($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Data tidak lengkap.'];
    } else {
        $pdo->beginTransaction();
        try {
            $count = 0;
            foreach ($cart as $item) {
                $sku = trim($item['sku']);
                $qty = (float)$item['qty'];
                $sns = $item['sns'] ?? []; 
                $notes = $item['notes'];
                
                // Cek / Buat Produk
                $stmt = $pdo->prepare("SELECT id, stock, buy_price FROM products WHERE sku = ?");
                $stmt->execute([$sku]);
                $product = $stmt->fetch();
                $prod_id = 0;

                if ($product) {
                    $prod_id = $product['id'];
                    // Update harga jika ada perubahan input
                    $pdo->prepare("UPDATE products SET stock = stock + ?, buy_price = ?, sell_price = ? WHERE id = ?")
                        ->execute([$qty, cleanNumber($item['buy_price']), cleanNumber($item['sell_price']), $prod_id]);
                } else {
                    $stmtIns = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmtIns->execute([$sku, $item['name'], $item['category'], $item['unit'], cleanNumber($item['buy_price']), cleanNumber($item['sell_price']), $qty]);
                    $prod_id = $pdo->lastInsertId();
                }

                // Log Transaksi
                $full_notes = $notes . (!empty($sns) ? " [SN: " . implode(", ", $sns) . "]" : "");
                $stmtTrx = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)");
                $stmtTrx->execute([$date, $prod_id, $warehouse_id, $qty, $reference, $full_notes, $_SESSION['user_id']]);
                $trx_id = $pdo->lastInsertId();

                // Simpan SN Baru
                if (!empty($sns)) {
                    $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach ($sns as $sn) {
                        // Cek duplikat global (opsional, strict mode)
                        $chk = $pdo->prepare("SELECT id FROM product_serials WHERE product_id=? AND serial_number=?");
                        $chk->execute([$prod_id, $sn]);
                        if($chk->rowCount() == 0) {
                            $stmtSn->execute([$prod_id, $sn, $warehouse_id, $trx_id]);
                        }
                    }
                }

                // Keuangan (Pengeluaran Pembelian Aset)
                $buy_val = cleanNumber($item['buy_price']);
                if ($buy_val > 0) {
                    $total = $qty * $buy_val;
                    $accId = $pdo->query("SELECT id FROM accounts WHERE code = '3003'")->fetchColumn(); 
                    if ($accId) {
                        $whName = $pdo->query("SELECT name FROM warehouses WHERE id=$warehouse_id")->fetchColumn();
                        $desc = "Stok Masuk: {$item['name']} ($qty) [Wilayah: $whName]";
                        $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                            ->execute([$date, $accId, $total, $desc, $_SESSION['user_id']]);
                    }
                }
                $count++;
            }
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$count item berhasil disimpan."];
            echo "<script>window.location='?page=barang_masuk';</script>";
            exit;
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>$e->getMessage()];
        }
    }
}

// Data
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$products = $pdo->query("SELECT sku, name, category, unit, buy_price, sell_price FROM products ORDER BY name ASC")->fetchAll();
$cats = $pdo->query("SELECT * FROM accounts WHERE code IN ('3003','2005','2105') OR type='ASSET'")->fetchAll();
$app_ref = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-xl text-green-700 flex items-center gap-2 mb-6 border-b pb-4">
            <i class="fas fa-box-open"></i> Input Barang Masuk
        </h3>

        <!-- SCANNER UI SECTION -->
        <div class="bg-gray-800 p-3 rounded-lg mb-6 flex flex-col md:flex-row gap-3 items-center justify-between text-white">
            <div class="text-sm font-bold flex items-center gap-2"><i class="fas fa-qrcode"></i> Mode Scan:</div>
            <div class="flex gap-2 w-full md:w-auto">
                <button type="button" onclick="activateUSB()" class="flex-1 bg-blue-600 hover:bg-blue-700 px-3 py-2 rounded text-xs font-bold transition">
                    <i class="fas fa-keyboard"></i> USB / Manual
                </button>
                <input type="file" id="scan_image_file" accept="image/*" class="hidden" onchange="handleFileScan(this)">
                <button type="button" onclick="document.getElementById('scan_image_file').click()" class="flex-1 bg-purple-600 hover:bg-purple-700 px-3 py-2 rounded text-xs font-bold transition">
                    <i class="fas fa-camera"></i> Upload Foto
                </button>
                <button type="button" onclick="initCamera()" class="flex-1 bg-red-600 hover:bg-red-700 px-3 py-2 rounded text-xs font-bold transition">
                    <i class="fas fa-video"></i> Live Kamera
                </button>
            </div>
        </div>

        <!-- CAMERA VIEWPORT -->
        <div id="scanner_area" class="hidden mb-6 relative bg-black rounded-lg border-4 border-gray-900 overflow-hidden shadow-2xl">
            <div id="reader" class="w-full h-64"></div>
            <button onclick="stopScan()" class="absolute top-2 right-2 bg-red-600 text-white p-2 rounded-full shadow hover:bg-red-700 z-50">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" id="form_in" onsubmit="return validateCart()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_inbound" value="1">
            <input type="hidden" name="cart_json" id="cart_json">

            <!-- HEADER TRANSAKSI -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 bg-gray-50 p-4 rounded border border-gray-200">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Gudang Penerima</label>
                    <select name="warehouse_id" class="w-full border p-2 rounded bg-white" required>
                        <?php foreach($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= $w['name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">No. Ref</label>
                    <div class="flex gap-1">
                        <input type="text" name="reference" id="ref_input" class="w-full border p-2 rounded uppercase" required>
                        <button type="button" onclick="genRef()" class="bg-gray-200 px-3 rounded hover:bg-gray-300"><i class="fas fa-magic"></i></button>
                    </div>
                </div>
            </div>

            <!-- INPUT AREA -->
            <div class="bg-green-50 p-4 rounded border border-green-200 mb-6">
                <h4 class="font-bold text-green-800 mb-3 text-sm border-b border-green-300 pb-1">Detail Barang</h4>
                
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang (Database) atau Scan</label>
                    <select id="product_select" class="w-full p-2 rounded">
                        <option value="">-- Cari Nama / SKU --</option>
                        <?php foreach($products as $p): ?>
                            <option value="<?= $p['sku'] ?>" data-json='<?= json_encode($p) ?>'>
                                <?= $p['sku'] ?> - <?= $p['name'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600">SKU / Barcode</label>
                        <input type="text" id="inp_sku" class="w-full border p-2 rounded font-mono font-bold text-blue-700 uppercase" placeholder="Scan disini...">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Nama Barang</label>
                        <input type="text" id="inp_name" class="w-full border p-2 rounded">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Qty Masuk</label>
                        <input type="number" id="inp_qty" class="w-full border p-2 rounded font-bold text-center" min="1" placeholder="0" onkeyup="renderSnInputs()">
                    </div>
                </div>

                <div id="extra_details" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4 hidden">
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Harga Beli</label>
                        <input type="text" id="inp_buy" class="w-full border p-2 rounded text-right" onkeyup="fmtRupiah(this)">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Harga Jual</label>
                        <input type="text" id="inp_sell" class="w-full border p-2 rounded text-right" onkeyup="fmtRupiah(this)">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Satuan</label>
                        <input type="text" id="inp_unit" class="w-full border p-2 rounded" value="Pcs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Kategori</label>
                        <select id="inp_cat" class="w-full border p-2 rounded text-sm bg-white">
                            <?php foreach($cats as $c): ?><option value="<?= $c['code'] ?>"><?= $c['name'] ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- SN AREA (Manual untuk Barang Masuk) -->
                <div id="sn_wrapper" class="hidden mt-4 bg-white p-3 rounded border border-gray-300">
                    <label class="block text-xs font-bold text-green-700 mb-2">Input Serial Number Baru (Wajib)</label>
                    <div id="sn_container" class="grid grid-cols-2 md:grid-cols-4 gap-2 max-h-40 overflow-y-auto"></div>
                </div>

                <div class="flex gap-4 mt-4 items-end">
                    <div class="flex-1">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Catatan</label>
                        <input type="text" id="inp_notes" class="w-full border p-2 rounded" placeholder="Opsional...">
                    </div>
                    <button type="button" onclick="addToCart()" class="bg-blue-600 text-white px-6 py-2 rounded font-bold hover:bg-blue-700">
                        <i class="fas fa-plus"></i> Tambah
                    </button>
                </div>
            </div>

            <!-- TABLE -->
            <div class="bg-white border rounded shadow-sm mb-6">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-3">Barang</th>
                            <th class="p-3 text-center">Qty</th>
                            <th class="p-3">SN</th>
                            <th class="p-3 text-right">Subtotal (Beli)</th>
                            <th class="p-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="cart_body">
                        <tr><td colspan="5" class="p-4 text-center text-gray-400">Keranjang Kosong</td></tr>
                    </tbody>
                </table>
            </div>

            <button type="submit" id="btn_submit" class="w-full bg-green-600 text-white py-3 rounded font-bold hover:bg-green-700 shadow disabled:bg-gray-400" disabled>
                Simpan Transaksi
            </button>
        </form>
    </div>
</div>

<!-- LIBRARIES -->
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<script>
let cart = [];
let html5QrCode;
const APP_REF = "<?= $app_ref ?>";

$(document).ready(function() {
    $('#product_select').select2({ placeholder: 'Cari Barang...', allowClear: true, width: '100%' });
    genRef();
});

// --- HANDLE SELECT2 CHANGE ---
$('#product_select').on('select2:select', function (e) {
    const data = $(this).find(':selected').data('json');
    if(data) fillForm(data);
});

function fillForm(data) {
    document.getElementById('inp_sku').value = data.sku;
    document.getElementById('inp_name').value = data.name;
    document.getElementById('inp_buy').value = fmtMoney(data.buy_price);
    document.getElementById('inp_sell').value = fmtMoney(data.sell_price);
    document.getElementById('inp_unit').value = data.unit;
    $('#inp_cat').val(data.category);
    
    document.getElementById('extra_details').classList.remove('hidden');
    document.getElementById('inp_qty').focus();
}

// --- SCANNER LOGIC ---
function activateUSB() {
    const el = document.getElementById('inp_sku');
    el.focus(); el.value = '';
    el.classList.add('ring-4', 'ring-blue-400');
    setTimeout(() => el.classList.remove('ring-4', 'ring-blue-400'), 1000);
}

// Event Listener untuk USB Scanner (Enter di kolom SKU)
document.getElementById('inp_sku').addEventListener('keypress', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const sku = this.value.trim();
        // Coba cari di select2
        const opt = $(`#product_select option[value='${sku}']`);
        if(opt.length > 0) {
            $('#product_select').val(sku).trigger('change');
            $('#product_select').trigger({type: 'select2:select'}); // Force trigger
        } else {
            // Barang Baru / Tidak ketemu -> Buka form detail
            document.getElementById('extra_details').classList.remove('hidden');
            document.getElementById('inp_name').focus();
        }
    }
});

async function initCamera() {
    document.getElementById('scanner_area').classList.remove('hidden');
    html5QrCode = new Html5Qrcode("reader");
    html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, 
        (decodedText) => {
            stopScan();
            document.getElementById('inp_sku').value = decodedText;
            // Trigger logic yang sama dengan USB
            const event = new KeyboardEvent('keypress', { key: 'Enter' });
            document.getElementById('inp_sku').dispatchEvent(event);
        },
        (errorMessage) => {}
    ).catch(err => alert("Kamera error: " + err));
}

function stopScan() {
    if(html5QrCode) html5QrCode.stop().then(() => {
        document.getElementById('scanner_area').classList.add('hidden');
        html5QrCode.clear();
    });
}

function handleFileScan(input) {
    if (input.files.length === 0) return;
    const html5QrCode = new Html5Qrcode("reader");
    html5QrCode.scanFile(input.files[0], true)
        .then(decodedText => {
            document.getElementById('inp_sku').value = decodedText;
            const event = new KeyboardEvent('keypress', { key: 'Enter' });
            document.getElementById('inp_sku').dispatchEvent(event);
        })
        .catch(err => alert("Gagal scan foto."));
}

// --- SN & CART LOGIC ---
function renderSnInputs() {
    const qty = parseInt(document.getElementById('inp_qty').value) || 0;
    const cont = document.getElementById('sn_container');
    const wrap = document.getElementById('sn_wrapper');
    document.getElementById('extra_details').classList.remove('hidden');

    if (qty > 0) {
        wrap.classList.remove('hidden');
        cont.innerHTML = '';
        for(let i=0; i<qty; i++) {
            const inp = document.createElement('input');
            inp.className = 'sn-field border p-2 rounded text-xs uppercase font-mono';
            inp.placeholder = `SN Barang #${i+1}`;
            cont.appendChild(inp);
        }
    } else {
        wrap.classList.add('hidden');
    }
}

function addToCart() {
    const sku = document.getElementById('inp_sku').value;
    const name = document.getElementById('inp_name').value;
    const qty = parseInt(document.getElementById('inp_qty').value);
    
    if(!sku || !name || !qty || qty <= 0) { alert("Lengkapi Data!"); return; }

    // Collect SN
    let sns = [];
    let validSn = true;
    document.querySelectorAll('.sn-field').forEach(i => {
        if(!i.value.trim()) validSn = false;
        sns.push(i.value.trim());
    });
    if(!validSn) { alert("Lengkapi semua SN!"); return; }

    cart.push({
        sku, name, qty, sns,
        buy_price: document.getElementById('inp_buy').value,
        sell_price: document.getElementById('inp_sell').value,
        unit: document.getElementById('inp_unit').value,
        category: document.getElementById('inp_cat').value,
        notes: document.getElementById('inp_notes').value
    });
    
    renderCart();
    
    // Reset Form
    $('#product_select').val(null).trigger('change');
    document.getElementById('inp_sku').value = '';
    document.getElementById('inp_name').value = '';
    document.getElementById('inp_qty').value = '';
    document.getElementById('sn_wrapper').classList.add('hidden');
}

function renderCart() {
    const tb = document.getElementById('cart_body');
    tb.innerHTML = '';
    if(cart.length === 0) {
        tb.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-gray-400">Kosong</td></tr>';
        document.getElementById('btn_submit').disabled = true;
        return;
    }
    document.getElementById('btn_submit').disabled = false;
    
    cart.forEach((item, idx) => {
        const sub = item.qty * parseInt(item.buy_price.replace(/\D/g,'')||0);
        tb.innerHTML += `
            <tr class="border-b">
                <td class="p-2 font-bold">${item.name}<br><small>${item.sku}</small></td>
                <td class="p-2 text-center">${item.qty}</td>
                <td class="p-2 text-xs font-mono max-w-xs break-all">${item.sns.join(', ')}</td>
                <td class="p-2 text-right">Rp ${new Intl.NumberFormat('id-ID').format(sub)}</td>
                <td class="p-2 text-center"><button type="button" onclick="cart.splice(${idx},1);renderCart()" class="text-red-500"><i class="fas fa-trash"></i></button></td>
            </tr>
        `;
    });
    document.getElementById('cart_json').value = JSON.stringify(cart);
}

function validateCart() { return cart.length > 0; }
function fmtRupiah(i) { let v = i.value.replace(/\D/g, ''); i.value = v ? new Intl.NumberFormat('id-ID').format(v) : ''; }
function fmtMoney(v) { return v ? new Intl.NumberFormat('id-ID').format(v) : ''; }
async function genRef() { try{const r=await fetch('api.php?action=get_next_trx_number&type=IN');const d=await r.json();const dt=new Date();document.getElementById('ref_input').value=`${APP_REF}/IN/${dt.getDate()}${dt.getMonth()+1}${dt.getFullYear().toString().substr(-2)}-${d.next_no}`;}catch(e){} }
</script>