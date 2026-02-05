<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_activity'])) {
    validate_csrf();
    $cart_json = $_POST['cart_json'];
    $cart = json_decode($cart_json, true);
    $date = $_POST['date'];
    $wh_id = $_POST['warehouse_id']; 
    $ref = $_POST['reference'];
    $desc_main = trim($_POST['description']);
    $mode = $_POST['activity_mode']; 

    if (empty($wh_id) || empty($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Data tidak lengkap.'];
    } else {
        $pdo->beginTransaction();
        try {
            foreach ($cart as $item) {
                $stmt = $pdo->prepare("SELECT id, stock, buy_price FROM products WHERE sku = ?");
                $stmt->execute([$item['sku']]);
                $prod = $stmt->fetch();
                if (!$prod) continue;

                $qty = $item['qty'];
                $sns = $item['sns']; 
                
                if ($mode == 'OUT') {
                    if ($prod['stock'] < $qty) throw new Exception("Stok {$item['name']} kurang.");
                    $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$qty, $prod['id']]);
                    $type = 'OUT';
                } else {
                    $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([$qty, $prod['id']]);
                    $type = 'IN';
                }

                $notes = $desc_main . " (" . $item['notes'] . ") [SN: " . implode(',', $sns) . "]";
                
                $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$date, $type, $prod['id'], $wh_id, $qty, $ref, $notes, $_SESSION['user_id']]);
                $trx_id = $pdo->lastInsertId();

                if($mode == 'OUT') {
                    $snUpd = $pdo->prepare("UPDATE product_serials SET status='SOLD', out_transaction_id=? WHERE product_id=? AND serial_number=?");
                    foreach($sns as $sn) $snUpd->execute([$trx_id, $prod['id'], $sn]);
                } else {
                    // RETURN (IN) logic remains simplistic here for brevity
                    $snIns = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach($sns as $sn) {
                        $chk = $pdo->query("SELECT id FROM product_serials WHERE product_id={$prod['id']} AND serial_number='$sn'")->fetch();
                        if(!$chk) $snIns->execute([$prod['id'], $sn, $wh_id, $trx_id]);
                    }
                }
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
$app_ref_prefix = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-purple-600">
        <h3 class="font-bold text-xl text-purple-700 flex items-center gap-2 mb-4 border-b pb-2">
            <i class="fas fa-tools"></i> Input Aktivitas (Pemakaian)
        </h3>

        <form method="POST" id="form_activity" onsubmit="return validateCart()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_activity" value="1">
            <input type="hidden" name="cart_json" id="cart_json">

            <!-- MODE (Hanya OUT yang fully supported dengan SN Picker) -->
            <div class="mb-4">
                <div class="flex rounded-md shadow-sm" role="group">
                    <label class="flex-1 cursor-pointer">
                        <input type="radio" name="activity_mode" value="OUT" class="peer sr-only" checked onchange="toggleMode('OUT')">
                        <div class="px-4 py-2 bg-white border border-gray-200 rounded-lg hover:bg-gray-100 peer-checked:bg-purple-600 peer-checked:text-white peer-checked:font-bold text-center transition">
                            <i class="fas fa-minus-circle mr-2"></i> Pemakaian (Keluar)
                        </div>
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Gudang</label>
                    <select name="warehouse_id" id="warehouse_id" class="w-full border p-2 rounded bg-white" required onchange="resetCart()">
                        <option value="">-- Pilih Gudang --</option>
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Keterangan</label>
                    <input type="text" name="description" class="w-full border p-2 rounded" placeholder="Contoh: Instalasi..." required>
                </div>
            </div>
            
            <input type="hidden" name="date" value="<?= date('Y-m-d') ?>">
            <input type="hidden" name="reference" id="reference_input">

            <!-- INPUT ITEM -->
            <div id="item_input_container" class="bg-purple-50 p-4 rounded border border-purple-200 mb-6">
                <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang di Gudang Ini</label>
                <select id="manual_select" class="w-full p-2 rounded" disabled>
                    <option value="">-- Pilih Gudang Dulu --</option>
                </select>

                <!-- DETAIL -->
                <div id="detail_area" class="hidden mt-3 bg-white p-2 rounded border shadow-sm flex justify-between items-center">
                    <div>
                        <div class="font-bold text-gray-800" id="det_name">-</div>
                        <div class="text-xs text-gray-500" id="det_sku">-</div>
                    </div>
                    <div class="text-right">
                        <span class="text-xs text-gray-500">Stok: </span>
                        <span class="font-bold text-blue-600" id="det_stock">0</span>
                    </div>
                </div>

                <!-- SN DROPDOWN -->
                <div id="sn_dropdown_area" class="hidden mt-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Pilih Serial Number (Wajib)</label>
                    <select id="sn_multi_select" class="w-full border rounded" multiple="multiple" style="width: 100%"></select>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-2 mt-4 items-end">
                    <div>
                        <label class="text-xs font-bold">Qty (Auto)</label>
                        <input type="number" id="inp_qty" class="w-full border p-2 rounded text-center font-bold bg-gray-100" readonly value="0">
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs font-bold">Catatan Item</label>
                        <input type="text" id="inp_notes" class="w-full border p-2 rounded">
                    </div>
                    <button type="button" onclick="addToCart()" class="bg-blue-600 text-white p-2 rounded font-bold">Tambah</button>
                </div>
            </div>

            <!-- CART -->
            <div class="border rounded bg-white mb-6">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-3">Barang</th>
                            <th class="p-3 text-center">Qty</th>
                            <th class="p-3">SN</th>
                            <th class="p-3 text-center">Hapus</th>
                        </tr>
                    </thead>
                    <tbody id="cart_body">
                        <tr><td colspan="4" class="p-4 text-center text-gray-400">Kosong.</td></tr>
                    </tbody>
                </table>
            </div>

            <button type="submit" id="btn_submit" class="w-full bg-green-600 text-white py-3 rounded font-bold disabled:bg-gray-400" disabled>Simpan</button>
        </form>
    </div>
</div>

<script>
let cart = [];
const APP_REF = "<?= $app_ref_prefix ?>";

$(document).ready(function(){
    $('#manual_select').select2({ placeholder: '-- Cari Barang --', width:'100%' });
    $('#sn_multi_select').select2({ placeholder: 'Pilih SN...', width:'100%' });
    generateReference();
});

// Load Products when Warehouse Changes
function loadProducts(whId) {
    if(!whId) return;
    $('#manual_select').prop('disabled', true).html('<option>Loading...</option>');
    
    fetch(`api.php?action=get_warehouse_stock&warehouse_id=${whId}`)
        .then(r => r.json())
        .then(data => {
            $('#manual_select').empty().append('<option value="">-- Pilih Barang --</option>');
            data.forEach(p => {
                const opt = new Option(`${p.name} (Stok: ${p.current_qty})`, p.sku);
                opt.dataset.name = p.name;
                opt.dataset.stock = p.current_qty;
                opt.dataset.id = p.id;
                $('#manual_select').append(opt);
            });
            $('#manual_select').prop('disabled', false).trigger('change');
        });
}

// On Product Select
$('#manual_select').on('select2:select', function(e){
    const data = e.params.data.element.dataset;
    const sku = e.params.data.id;
    const whId = document.getElementById('warehouse_id').value;
    
    document.getElementById('detail_area').classList.remove('hidden');
    document.getElementById('det_name').innerText = data.name;
    document.getElementById('det_sku').innerText = sku;
    document.getElementById('det_stock').innerText = data.stock;
    
    // Save Meta
    document.getElementById('inp_qty').dataset.sku = sku;
    document.getElementById('inp_qty').dataset.name = data.name;
    document.getElementById('inp_qty').dataset.id = data.id;
    
    // Fetch Available SNs
    fetch(`api.php?action=get_available_sns&product_id=${data.id}&warehouse_id=${whId}`)
        .then(r => r.json())
        .then(sns => {
            const snSel = $('#sn_multi_select');
            snSel.empty();
            if(sns.length > 0) {
                document.getElementById('sn_dropdown_area').classList.remove('hidden');
                sns.forEach(sn => snSel.append(new Option(sn, sn, false, false)));
                snSel.trigger('change');
            } else {
                alert("Stok SN Habis!");
                document.getElementById('sn_dropdown_area').classList.add('hidden');
            }
        });
});

// Auto Update Qty based on SN Select
$('#sn_multi_select').on('change', function() {
    const selected = $(this).val();
    document.getElementById('inp_qty').value = selected ? selected.length : 0;
});

function addToCart() {
    const qty = parseInt(document.getElementById('inp_qty').value);
    if(!qty || qty<=0) { alert("Pilih minimal 1 SN"); return; }

    const sku = document.getElementById('inp_qty').dataset.sku;
    const name = document.getElementById('inp_qty').dataset.name;
    const id = document.getElementById('inp_qty').dataset.id;
    const sns = $('#sn_multi_select').val();
    
    cart.push({ sku, id, name, qty, sns, notes: document.getElementById('inp_notes').value });
    renderCart();
    
    // Reset
    $('#manual_select').val(null).trigger('change');
    document.getElementById('detail_area').classList.add('hidden');
    document.getElementById('sn_dropdown_area').classList.add('hidden');
    document.getElementById('inp_qty').value = 0;
}

function renderCart() {
    const b = document.getElementById('cart_body');
    b.innerHTML = '';
    if(cart.length === 0) {
        b.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-gray-400">Kosong.</td></tr>';
        document.getElementById('btn_submit').disabled = true;
        return;
    }
    document.getElementById('btn_submit').disabled = false;
    cart.forEach((i, idx) => {
        b.innerHTML += `
            <tr class="border-b">
                <td class="p-2 font-bold">${i.name}</td>
                <td class="p-2 text-center">${i.qty}</td>
                <td class="p-2 text-xs font-mono">${i.sns.join(', ')}</td>
                <td class="p-2 text-center"><button type="button" onclick="cart.splice(${idx},1);renderCart()" class="text-red-500">Del</button></td>
            </tr>
        `;
    });
    document.getElementById('cart_json').value = JSON.stringify(cart);
}

function resetCart() {
    if(cart.length > 0) {
        if(confirm("Ganti gudang hapus keranjang?")) {
            cart = [];
            renderCart();
        } else {
            return; // Batalkan ganti gudang jika user cancel (Logic butuh perbaikan flow di real app, disini simplifikasi)
        }
    }
}

function validateCart() { return cart.length > 0; }
async function generateReference() { try { const res = await fetch('api.php?action=get_next_trx_number&type=OUT'); const data = await res.json(); const d = new Date(); const ref = `${APP_REF}/ACT/${d.getDate()}/${d.getMonth()+1}/${d.getFullYear()}-${data.next_no}`; document.getElementById('reference_input').value = ref; } catch(e){} }
function toggleMode(mode) { /* Placeholder since only OUT active */ }
</script>