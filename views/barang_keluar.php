<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_transaction'])) {
    validate_csrf();
    $cart = json_decode($_POST['cart_json'], true);
    $wh_id = $_POST['warehouse_id']; 
    
    if (empty($wh_id) || empty($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gudang atau Barang belum dipilih.'];
    } else {
        $pdo->beginTransaction();
        try {
            foreach ($cart as $item) {
                // Get Master Product
                $stmt = $pdo->prepare("SELECT id, stock, sell_price, buy_price FROM products WHERE sku = ?");
                $stmt->execute([$item['sku']]);
                $prod = $stmt->fetch();
                if (!$prod) throw new Exception("SKU {$item['sku']} tidak ditemukan.");

                $qty = count($item['sns']);
                if ($qty == 0) throw new Exception("SN untuk {$item['name']} kosong.");

                // 1. Catat Trx Keluar
                $notes = $item['notes'] . " [SN: " . implode(', ', $item['sns']) . "]";
                $stmtTrx = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)");
                $stmtTrx->execute([$_POST['date'], $prod['id'], $wh_id, $qty, $_POST['reference'], $notes, $_SESSION['user_id']]);
                $trx_id = $pdo->lastInsertId();

                // 2. Update Product Master Stock
                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$qty, $prod['id']]);

                // 3. Update Status SN (Menjadi SOLD/OUT)
                $snUpd = $pdo->prepare("UPDATE product_serials SET status = 'SOLD', out_transaction_id = ? WHERE product_id = ? AND serial_number = ? AND warehouse_id = ? AND status = 'AVAILABLE'");
                foreach ($item['sns'] as $sn) {
                    $snUpd->execute([$trx_id, $prod['id'], $sn, $wh_id]);
                    if ($snUpd->rowCount() == 0) throw new Exception("SN $sn tidak tersedia di gudang ini (Mungkin sudah terjual).");
                }

                // 4. Catat Keuangan (Opsional: Penjualan atau Pemakaian)
                // Default: Pemakaian (Expense) jika Internal, Pendapatan jika External.
                // Disederhanakan: Mencatat Expense Pemakaian (Harga Beli)
                $val = $qty * $prod['buy_price'];
                if($val > 0) {
                    $accId = $pdo->query("SELECT id FROM accounts WHERE code='2105'")->fetchColumn(); // Beban Material
                    if($accId) {
                        $whName = $pdo->query("SELECT name FROM warehouses WHERE id=$wh_id")->fetchColumn();
                        $desc = "Pemakaian: {$item['name']} ($qty) [Wilayah: $whName]";
                        $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                            ->execute([$_POST['date'], $accId, $val, $desc, $_SESSION['user_id']]);
                    }
                }
            }
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Transaksi Keluar Berhasil.'];
            echo "<script>window.location='?page=barang_keluar';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>$e->getMessage()];
        }
    }
}

$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$all_products = $pdo->query("SELECT sku, name FROM products ORDER BY name ASC")->fetchAll(); // Untuk search select2
$app_ref = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-xl text-red-700 flex items-center gap-2 mb-6 border-b pb-4">
            <i class="fas fa-dolly"></i> Input Barang Keluar
        </h3>

        <!-- SCANNER UI -->
        <div class="bg-gray-800 p-3 rounded-lg mb-6 flex flex-col md:flex-row gap-3 items-center justify-between text-white">
            <div class="text-sm font-bold flex items-center gap-2"><i class="fas fa-qrcode"></i> Scan Helper:</div>
            <div class="flex gap-2 w-full md:w-auto">
                <button type="button" onclick="document.getElementById('scan_image_file').click()" class="flex-1 bg-purple-600 hover:bg-purple-700 px-3 py-2 rounded text-xs font-bold">
                    <i class="fas fa-camera"></i> Foto
                </button>
                <input type="file" id="scan_image_file" accept="image/*" class="hidden" onchange="handleFileScan(this)">
                <button type="button" onclick="initCamera()" class="flex-1 bg-red-600 hover:bg-red-700 px-3 py-2 rounded text-xs font-bold">
                    <i class="fas fa-video"></i> Live
                </button>
            </div>
        </div>
        <div id="scanner_area" class="hidden mb-6 relative bg-black rounded-lg border-4 border-gray-900 overflow-hidden shadow-2xl h-64">
            <div id="reader" class="w-full h-full"></div>
            <button onclick="stopScan()" class="absolute top-2 right-2 bg-red-600 text-white p-2 rounded-full"><i class="fas fa-times"></i></button>
        </div>

        <form method="POST" onsubmit="return validateCart()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_transaction" value="1">
            <input type="hidden" name="cart_json" id="cart_json">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Gudang Asal (Wajib)</label>
                    <select name="warehouse_id" id="warehouse_id" class="w-full border p-2 rounded bg-white font-bold" required onchange="resetCart()">
                        <option value="">-- Pilih Gudang --</option>
                        <?php foreach($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= $w['name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-700 mb-1">Referensi</label>
                <div class="flex gap-1">
                    <input type="text" name="reference" id="ref_input" class="w-full border p-2 rounded uppercase" required>
                    <button type="button" onclick="genRef()" class="bg-gray-200 px-3 rounded"><i class="fas fa-magic"></i></button>
                </div>
            </div>

            <!-- INPUT ITEM -->
            <div class="bg-red-50 p-4 rounded border border-red-200 mb-6">
                <h4 class="font-bold text-red-800 mb-3 text-sm">Pilih Barang & SN</h4>
                
                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang (Ketik / Scan)</label>
                    <select id="product_select" class="w-full p-2 rounded">
                        <option value="">-- Cari Barang --</option>
                        <?php foreach($all_products as $p): ?>
                            <option value="<?= $p['sku'] ?>"><?= $p['sku'] ?> - <?= $p['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- DETAIL INFO -->
                <div id="prod_detail" class="hidden mb-3 bg-white p-3 rounded border border-red-100 flex justify-between items-center shadow-sm">
                    <div>
                        <div class="font-bold text-gray-800" id="det_name">-</div>
                        <div class="text-xs text-gray-500 font-mono" id="det_sku">-</div>
                    </div>
                    <div class="text-right">
                        <span class="text-xs text-gray-500">Stok (Global):</span>
                        <div class="font-bold text-blue-600 text-lg" id="det_stock">0</div>
                    </div>
                </div>

                <!-- SN SELECTOR (MULTI) -->
                <div id="sn_area" class="hidden mb-3">
                    <label class="block text-xs font-bold text-red-700 mb-1">Pilih Serial Number (Available)</label>
                    <select id="sn_select" class="w-full border rounded" multiple="multiple" style="width: 100%"></select>
                    <p class="text-[10px] text-gray-500 mt-1">Klik untuk memilih SN yang akan dikeluarkan. Qty otomatis terhitung.</p>
                </div>

                <div class="flex gap-4 items-end">
                    <div class="w-20">
                        <label class="block text-xs font-bold text-gray-600">Qty</label>
                        <input type="text" id="inp_qty" class="w-full border p-2 rounded text-center font-bold bg-gray-100" readonly value="0">
                    </div>
                    <div class="flex-1">
                        <label class="block text-xs font-bold text-gray-600">Catatan</label>
                        <input type="text" id="inp_notes" class="w-full border p-2 rounded">
                    </div>
                    <button type="button" onclick="addToCart()" class="bg-red-600 text-white px-6 py-2 rounded font-bold hover:bg-red-700">
                        <i class="fas fa-plus"></i> Add
                    </button>
                </div>
            </div>

            <!-- CART -->
            <div class="bg-white border rounded shadow-sm mb-6">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-3">Barang</th>
                            <th class="p-3 text-center">Qty</th>
                            <th class="p-3">List SN</th>
                            <th class="p-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="cart_body">
                        <tr><td colspan="4" class="p-4 text-center text-gray-400">Keranjang Kosong</td></tr>
                    </tbody>
                </table>
            </div>

            <button type="submit" id="btn_submit" class="w-full bg-blue-600 text-white py-3 rounded font-bold hover:bg-blue-700 shadow disabled:bg-gray-400" disabled>
                Proses Barang Keluar
            </button>
        </form>
    </div>
</div>

<script>
let cart = [];
let html5QrCode;
const APP_REF = "<?= $app_ref ?>";

$(document).ready(function() {
    $('#product_select').select2({ placeholder: 'Cari Barang...', width: '100%' });
    $('#sn_select').select2({ placeholder: 'Pilih SN...', width: '100%' });
    genRef();
});

// TRIGGER SEARCH
$('#product_select').on('select2:select', function (e) {
    const sku = e.params.data.id;
    if(sku) loadProduct(sku);
});

// TRIGGER QTY UPDATE
$('#sn_select').on('change', function() {
    const vals = $(this).val();
    document.getElementById('inp_qty').value = vals ? vals.length : 0;
});

async function loadProduct(sku) {
    const whId = document.getElementById('warehouse_id').value;
    if(!whId) { alert("Pilih Gudang Asal Dulu!"); $('#product_select').val(null).trigger('change'); return; }

    const res = await fetch(`api.php?action=get_product_by_sku&sku=${encodeURIComponent(sku)}`);
    const data = await res.json();
    
    if(data.id) {
        document.getElementById('prod_detail').classList.remove('hidden');
        document.getElementById('det_name').innerText = data.name;
        document.getElementById('det_sku').innerText = data.sku;
        document.getElementById('det_stock').innerText = data.stock;
        
        // Load SNs
        const snRes = await fetch(`api.php?action=get_available_sns&product_id=${data.id}&warehouse_id=${whId}`);
        const sns = await snRes.json();
        
        const snSel = $('#sn_select');
        snSel.empty();
        
        if(sns.length > 0) {
            document.getElementById('sn_area').classList.remove('hidden');
            sns.forEach(sn => snSel.append(new Option(sn, sn, false, false)));
            snSel.trigger('change');
        } else {
            alert("Tidak ada SN tersedia di gudang ini!");
            document.getElementById('sn_area').classList.add('hidden');
        }
        
        // Meta Data
        document.getElementById('inp_qty').dataset.sku = data.sku;
        document.getElementById('inp_qty').dataset.name = data.name;
    }
}

function addToCart() {
    const qty = parseInt(document.getElementById('inp_qty').value);
    if(!qty || qty <= 0) { alert("Pilih minimal 1 SN!"); return; }
    
    cart.push({
        sku: document.getElementById('inp_qty').dataset.sku,
        name: document.getElementById('inp_qty').dataset.name,
        qty: qty,
        sns: $('#sn_select').val(),
        notes: document.getElementById('inp_notes').value
    });
    
    renderCart();
    
    // Reset
    $('#product_select').val(null).trigger('change');
    document.getElementById('prod_detail').classList.add('hidden');
    document.getElementById('sn_area').classList.add('hidden');
    document.getElementById('inp_qty').value = 0;
}

function renderCart() {
    const b = document.getElementById('cart_body');
    b.innerHTML = '';
    if(cart.length === 0) {
        b.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-gray-400">Kosong</td></tr>';
        document.getElementById('btn_submit').disabled = true;
        return;
    }
    document.getElementById('btn_submit').disabled = false;
    cart.forEach((i, idx) => {
        b.innerHTML += `
            <tr class="border-b">
                <td class="p-2 font-bold">${i.name}</td>
                <td class="p-2 text-center">${i.qty}</td>
                <td class="p-2 text-xs font-mono break-all">${i.sns.join(', ')}</td>
                <td class="p-2 text-center"><button type="button" onclick="cart.splice(${idx},1);renderCart()" class="text-red-500"><i class="fas fa-trash"></i></button></td>
            </tr>
        `;
    });
    document.getElementById('cart_json').value = JSON.stringify(cart);
}

// SCANNER FUNCTIONS (Compatible Logic)
function handleFileScan(input) {
    if (input.files.length === 0) return;
    const html5QrCode = new Html5Qrcode("reader");
    html5QrCode.scanFile(input.files[0], true).then(txt => processScan(txt)).catch(e => alert("Gagal Scan Foto"));
}
async function initCamera() {
    document.getElementById('scanner_area').classList.remove('hidden');
    html5QrCode = new Html5Qrcode("reader");
    html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, 
        (txt) => { stopScan(); processScan(txt); }, () => {}
    );
}
function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => { document.getElementById('scanner_area').classList.add('hidden'); html5QrCode.clear(); }); }

function processScan(txt) {
    // 1. Coba Cari Barang by SKU
    const opt = $(`#product_select option[value='${txt}']`);
    if(opt.length > 0) {
        $('#product_select').val(txt).trigger('change');
    } else {
        // 2. Jika tidak ketemu SKU, mungkin itu SN?
        // Cari apakah SN ini ada di dropdown SN yang sedang aktif?
        const snOpt = $(`#sn_select option[value='${txt}']`);
        if(snOpt.length > 0) {
            // Select it
            let current = $('#sn_select').val() || [];
            if(!current.includes(txt)) {
                current.push(txt);
                $('#sn_select').val(current).trigger('change');
                alert("SN " + txt + " ditambahkan.");
            } else {
                alert("SN " + txt + " sudah dipilih.");
            }
        } else {
            alert("Data tidak ditemukan (Bukan SKU Barang / SN tidak tersedia di list).");
        }
    }
}

function resetCart() { if(cart.length > 0 && confirm("Ganti gudang hapus keranjang?")) { cart=[]; renderCart(); } }
function validateCart() { return cart.length > 0; }
async function genRef() { try{const r=await fetch('api.php?action=get_next_trx_number&type=OUT');const d=await r.json();const dt=new Date();document.getElementById('ref_input').value=`${APP_REF}/OUT/${dt.getDate()}${dt.getMonth()+1}${dt.getFullYear().toString().substr(-2)}-${d.next_no}`;}catch(e){} }
</script>