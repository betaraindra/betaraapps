<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_activity'])) {
    validate_csrf();
    $cart = json_decode($_POST['cart_json'], true);
    $wh_id = $_POST['warehouse_id'];
    
    if (empty($wh_id) || empty($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gudang atau Barang belum dipilih.'];
    } else {
        $pdo->beginTransaction();
        try {
            foreach ($cart as $item) {
                $stmt = $pdo->prepare("SELECT id, stock, buy_price FROM products WHERE sku = ?");
                $stmt->execute([$item['sku']]);
                $prod = $stmt->fetch();
                if (!$prod) throw new Exception("SKU {$item['sku']} error.");

                $qty = count($item['sns']);
                if ($qty == 0) continue;

                // Transaksi OUT
                $notes = $_POST['description'] . " (" . $item['notes'] . ") [SN: " . implode(',', $item['sns']) . "]";
                $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)")
                    ->execute([$_POST['date'], $prod['id'], $wh_id, $qty, $_POST['reference'], $notes, $_SESSION['user_id']]);
                $trx_id = $pdo->lastInsertId();

                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$qty, $prod['id']]);

                // Update SN
                $snUpd = $pdo->prepare("UPDATE product_serials SET status='SOLD', out_transaction_id=? WHERE product_id=? AND serial_number=? AND warehouse_id=?");
                foreach($item['sns'] as $sn) $snUpd->execute([$trx_id, $prod['id'], $sn, $wh_id]);
            }
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Aktivitas berhasil disimpan.'];
            echo "<script>window.location='?page=input_aktivitas';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>$e->getMessage()];
        }
    }
}

$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$all_products = $pdo->query("SELECT sku, name FROM products ORDER BY name ASC")->fetchAll();
$app_ref = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-purple-600">
        <h3 class="font-bold text-xl text-purple-700 flex items-center gap-2 mb-4 border-b pb-2">
            <i class="fas fa-tools"></i> Input Aktivitas (Pemakaian)
        </h3>

        <!-- SCANNER -->
        <div class="bg-gray-800 p-3 rounded-lg mb-4 flex gap-3 items-center justify-between text-white">
            <div class="text-sm font-bold"><i class="fas fa-qrcode"></i> Helper:</div>
            <div class="flex gap-2">
                <button onclick="document.getElementById('f_scan').click()" class="bg-purple-600 px-3 py-1 rounded text-xs font-bold"><i class="fas fa-camera"></i> Foto</button>
                <input type="file" id="f_scan" class="hidden" accept="image/*" onchange="handleFileScan(this)">
                <button onclick="initCamera()" class="bg-red-600 px-3 py-1 rounded text-xs font-bold"><i class="fas fa-video"></i> Live</button>
            </div>
        </div>
        <div id="scanner_area" class="hidden mb-4 relative bg-black rounded-lg h-48 overflow-hidden">
            <div id="reader" class="w-full h-full"></div>
            <button onclick="stopScan()" class="absolute top-2 right-2 bg-red-600 text-white p-1 rounded-full"><i class="fas fa-times"></i></button>
        </div>

        <form method="POST" onsubmit="return validateCart()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_activity" value="1">
            <input type="hidden" name="cart_json" id="cart_json">
            <input type="hidden" name="activity_mode" value="OUT">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Gudang</label>
                    <select name="warehouse_id" id="warehouse_id" class="w-full border p-2 rounded bg-white" required onchange="resetCart()">
                        <option value="">-- Pilih Gudang --</option>
                        <?php foreach($warehouses as $w): ?><option value="<?= $w['id'] ?>"><?= $w['name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Keterangan Aktivitas</label>
                    <input type="text" name="description" class="w-full border p-2 rounded" placeholder="Contoh: Instalasi..." required>
                </div>
            </div>
            
            <input type="hidden" name="date" value="<?= date('Y-m-d') ?>">
            <input type="hidden" name="reference" id="reference_input">

            <!-- INPUT ITEM -->
            <div class="bg-purple-50 p-4 rounded border border-purple-200 mb-6">
                <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang</label>
                <select id="product_select" class="w-full p-2 rounded">
                    <option value="">-- Cari Barang --</option>
                    <?php foreach($all_products as $p): ?><option value="<?= $p['sku'] ?>"><?= $p['sku'] ?> - <?= $p['name'] ?></option><?php endforeach; ?>
                </select>

                <!-- DETAIL -->
                <div id="detail_area" class="hidden mt-3 bg-white p-2 rounded border shadow-sm flex justify-between items-center">
                    <div>
                        <div class="font-bold text-gray-800" id="det_name">-</div>
                        <div class="text-xs text-gray-500" id="det_sku">-</div>
                    </div>
                    <div class="font-bold text-blue-600 text-lg" id="det_stock">0</div>
                </div>

                <!-- SN DROPDOWN -->
                <div id="sn_dropdown_area" class="hidden mt-3">
                    <label class="block text-xs font-bold text-purple-700 mb-1">Pilih SN (Wajib)</label>
                    <select id="sn_multi_select" class="w-full border rounded" multiple="multiple" style="width: 100%"></select>
                </div>

                <div class="flex gap-4 mt-4 items-end">
                    <div class="w-20">
                        <label class="text-xs font-bold">Qty</label>
                        <input type="number" id="inp_qty" class="w-full border p-2 rounded text-center font-bold bg-gray-100" readonly value="0">
                    </div>
                    <div class="flex-1">
                        <label class="text-xs font-bold">Catatan Item</label>
                        <input type="text" id="inp_notes" class="w-full border p-2 rounded">
                    </div>
                    <button type="button" onclick="addToCart()" class="bg-purple-600 text-white px-4 py-2 rounded font-bold hover:bg-purple-700">Tambah</button>
                </div>
            </div>

            <!-- CART -->
            <div class="border rounded bg-white mb-6">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100">
                        <tr><th class="p-3">Barang</th><th class="p-3 text-center">Qty</th><th class="p-3">SN</th><th class="p-3 text-center">Hapus</th></tr>
                    </thead>
                    <tbody id="cart_body"><tr><td colspan="4" class="p-4 text-center text-gray-400">Kosong.</td></tr></tbody>
                </table>
            </div>

            <button type="submit" id="btn_submit" class="w-full bg-green-600 text-white py-3 rounded font-bold disabled:bg-gray-400" disabled>Simpan</button>
        </form>
    </div>
</div>

<script>
let cart = [];
let html5QrCode;
const APP_REF = "<?= $app_ref ?>";

$(document).ready(function(){
    $('#product_select').select2({ placeholder: '-- Cari Barang --', width:'100%' });
    $('#sn_multi_select').select2({ placeholder: 'Pilih SN...', width:'100%' });
    genRef();
});

// SEARCH BARANG
$('#product_select').on('select2:select', function(e){
    const sku = e.params.data.id;
    const whId = document.getElementById('warehouse_id').value;
    if(!whId) { alert("Pilih Gudang Dulu!"); $('#product_select').val(null).trigger('change'); return; }

    fetch(`api.php?action=get_product_by_sku&sku=${encodeURIComponent(sku)}`)
        .then(r=>r.json())
        .then(d => {
            if(d.id) {
                document.getElementById('detail_area').classList.remove('hidden');
                document.getElementById('det_name').innerText = d.name;
                document.getElementById('det_sku').innerText = d.sku;
                document.getElementById('det_stock').innerText = d.stock;
                
                // DATASET
                document.getElementById('inp_qty').dataset.sku = d.sku;
                document.getElementById('inp_qty').dataset.name = d.name;
                document.getElementById('inp_qty').dataset.id = d.id;

                // GET SN
                return fetch(`api.php?action=get_available_sns&product_id=${d.id}&warehouse_id=${whId}`);
            }
        })
        .then(r=>r.json())
        .then(sns => {
            const sel = $('#sn_multi_select');
            sel.empty();
            if(sns && sns.length > 0) {
                document.getElementById('sn_dropdown_area').classList.remove('hidden');
                sns.forEach(sn => sel.append(new Option(sn, sn, false, false)));
                sel.trigger('change');
            } else {
                alert("SN Kosong di gudang ini!");
                document.getElementById('sn_dropdown_area').classList.add('hidden');
            }
        });
});

$('#sn_multi_select').on('change', function() {
    const v = $(this).val();
    document.getElementById('inp_qty').value = v ? v.length : 0;
});

function addToCart() {
    const qty = parseInt(document.getElementById('inp_qty').value);
    if(!qty || qty<=0) { alert("Pilih SN!"); return; }
    
    cart.push({
        sku: document.getElementById('inp_qty').dataset.sku,
        name: document.getElementById('inp_qty').dataset.name,
        qty: qty,
        sns: $('#sn_multi_select').val(),
        notes: document.getElementById('inp_notes').value
    });
    renderCart();
    
    $('#product_select').val(null).trigger('change');
    document.getElementById('detail_area').classList.add('hidden');
    document.getElementById('sn_dropdown_area').classList.add('hidden');
    document.getElementById('inp_qty').value = 0;
}

function renderCart() {
    const b = document.getElementById('cart_body');
    b.innerHTML = '';
    if(cart.length===0) { b.innerHTML = '<tr><td colspan="4" class="p-4 text-center">Kosong</td></tr>'; document.getElementById('btn_submit').disabled = true; return; }
    document.getElementById('btn_submit').disabled = false;
    cart.forEach((i,idx) => {
        b.innerHTML += `<tr class="border-b"><td class="p-2 font-bold">${i.name}</td><td class="p-2 text-center">${i.qty}</td><td class="p-2 text-xs font-mono">${i.sns.join(',')}</td><td class="p-2 text-center"><button onclick="cart.splice(${idx},1);renderCart()" class="text-red-500">x</button></td></tr>`;
    });
    document.getElementById('cart_json').value = JSON.stringify(cart);
}

function handleFileScan(input) { if (input.files.length === 0) return; const h = new Html5Qrcode("reader"); h.scanFile(input.files[0], true).then(t => processScan(t)); }
async function initCamera() { document.getElementById('scanner_area').classList.remove('hidden'); html5QrCode = new Html5Qrcode("reader"); html5QrCode.start({ facingMode: "environment" }, { fps: 10 }, (t) => { stopScan(); processScan(t); }, () => {}); }
function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => { document.getElementById('scanner_area').classList.add('hidden'); html5QrCode.clear(); }); }
function processScan(txt) {
    const opt = $(`#product_select option[value='${txt}']`);
    if(opt.length>0) $('#product_select').val(txt).trigger('change');
    else {
        const snOpt = $(`#sn_multi_select option[value='${txt}']`);
        if(snOpt.length>0) {
            let cur = $('#sn_multi_select').val() || [];
            if(!cur.includes(txt)) { cur.push(txt); $('#sn_multi_select').val(cur).trigger('change'); alert("SN Added"); }
        } else alert("Not Found");
    }
}

function resetCart() { if(cart.length>0 && confirm("Reset Cart?")) { cart=[]; renderCart(); } }
function validateCart() { return cart.length>0; }
async function genRef() { try{const r=await fetch('api.php?action=get_next_trx_number&type=OUT');const d=await r.json();const dt=new Date();document.getElementById('reference_input').value=`${APP_REF}/ACT/${dt.getDate()}${dt.getMonth()+1}${dt.getFullYear().toString().substr(-2)}-${d.next_no}`;}catch(e){} }
</script>