<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_inbound'])) {
    validate_csrf();
    
    $date = $_POST['date'];
    $warehouse_id = $_POST['warehouse_id'];
    $reference = $_POST['reference'];
    $cart_json = $_POST['cart_json'];
    $cart = json_decode($cart_json, true);

    if (empty($warehouse_id) || empty($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Data tidak lengkap.'];
    } else {
        $pdo->beginTransaction();
        try {
            $count_items = 0;
            foreach ($cart as $item) {
                $sku = trim($item['sku']);
                $qty = (float)$item['qty'];
                $sns = $item['sns'] ?? []; 
                $notes = $item['notes'];
                
                // Input Data
                $input_name = trim($item['name']);
                $input_cat = trim($item['category'] ?? 'Umum');
                $input_unit = trim($item['unit'] ?? 'Pcs');
                $input_buy = cleanNumber($item['buy_price']);
                $input_sell = cleanNumber($item['sell_price']);

                // Create/Update Product
                $stmt = $pdo->prepare("SELECT id, stock, buy_price FROM products WHERE sku = ?");
                $stmt->execute([$sku]);
                $product = $stmt->fetch();
                $product_id = 0;

                if ($product) {
                    $product_id = $product['id'];
                    $pdo->prepare("UPDATE products SET stock = stock + ?, buy_price = ?, sell_price = ? WHERE id = ?")
                        ->execute([$qty, $input_buy, $input_sell, $product_id]);
                } else {
                    $stmtIns = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmtIns->execute([$sku, $input_name, $input_cat, $input_unit, $input_buy, $input_sell, $qty]);
                    $product_id = $pdo->lastInsertId();
                }

                // Inventory Trx
                $sn_string = !empty($sns) ? " [SN: " . implode(", ", $sns) . "]" : "";
                $final_notes = $notes . $sn_string;
                
                $stmtTrx = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)");
                $stmtTrx->execute([$date, $product_id, $warehouse_id, $qty, $reference, $final_notes, $_SESSION['user_id']]);
                $trx_id = $pdo->lastInsertId();

                // Insert SNs
                if (!empty($sns)) {
                    $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach ($sns as $sn) {
                        $chk = $pdo->prepare("SELECT id FROM product_serials WHERE product_id=? AND serial_number=?");
                        $chk->execute([$product_id, $sn]);
                        if($chk->rowCount() == 0) {
                            $stmtSn->execute([$product_id, $sn, $warehouse_id, $trx_id]);
                        }
                    }
                }

                // Finance (Expense for Stock)
                if ($input_buy > 0) {
                    $total_val = $qty * $input_buy;
                    $accId = $pdo->query("SELECT id FROM accounts WHERE code = '3003'")->fetchColumn(); 
                    if ($accId) {
                        $wh_name = $pdo->query("SELECT name FROM warehouses WHERE id = $warehouse_id")->fetchColumn();
                        $desc_fin = "Stok Masuk: $input_name ($qty) - Ref: $reference [Wilayah: $wh_name]";
                        $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                            ->execute([$date, $accId, $total_val, $desc_fin, $_SESSION['user_id']]);
                    }
                }
                $count_items++;
            }
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"Berhasil input $count_items item."];
            echo "<script>window.location='?page=barang_masuk';</script>";
            exit;
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: '.$e->getMessage()];
        }
    }
}

$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$category_accounts = $pdo->query("SELECT * FROM accounts WHERE code IN ('3003', '2005', '2105') OR type='ASSET' ORDER BY code ASC")->fetchAll();
$products_list = $pdo->query("SELECT sku, name, category, unit, buy_price, sell_price FROM products ORDER BY name ASC")->fetchAll();
$app_ref_prefix = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-xl text-green-700 flex items-center gap-2 mb-6 border-b pb-4">
            <i class="fas fa-box-open"></i> Input Barang Masuk
        </h3>

        <form method="POST" id="form_masuk" onsubmit="return validateCart()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_inbound" value="1">
            <input type="hidden" name="cart_json" id="cart_json">
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 bg-gray-50 p-4 rounded border border-gray-200">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Gudang Penerima</label>
                    <select name="warehouse_id" id="warehouse_id" class="w-full border p-2 rounded bg-white" required>
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">No. Referensi / SJ</label>
                    <div class="flex gap-2">
                        <input type="text" name="reference" id="reference_input" class="w-full border p-2 rounded uppercase" required>
                        <button type="button" onclick="generateReference()" class="bg-gray-200 px-3 rounded"><i class="fas fa-magic"></i></button>
                    </div>
                </div>
            </div>

            <div class="bg-green-50 p-4 rounded border border-green-200 mb-6">
                <h4 class="font-bold text-green-800 mb-3 text-sm">Input Detail Barang</h4>
                
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Cari Database / Ketik Baru</label>
                    <select id="manual_product_select" class="w-full p-2 rounded text-sm">
                        <option value="">-- Cari Nama Barang --</option>
                        <?php foreach($products_list as $prod): ?>
                            <option value="<?= $prod['sku'] ?>" 
                                data-name="<?= htmlspecialchars($prod['name']) ?>"
                                data-cat="<?= htmlspecialchars($prod['category']) ?>"
                                data-unit="<?= htmlspecialchars($prod['unit']) ?>"
                                data-buy="<?= $prod['buy_price'] ?>"
                                data-sell="<?= $prod['sell_price'] ?>">
                                <?= $prod['sku'] ?> - <?= $prod['name'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- FORM INPUT -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600">SKU / Barcode</label>
                        <input type="text" id="inp_sku" class="w-full border p-2 rounded font-mono font-bold uppercase" placeholder="SCAN...">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Nama Barang</label>
                        <input type="text" id="inp_name" class="w-full border p-2 rounded">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Qty Masuk</label>
                        <input type="number" id="inp_qty" class="w-full border p-2 rounded font-bold text-center" min="1" placeholder="0" onkeyup="renderSnInputs()" onchange="renderSnInputs()">
                    </div>
                </div>
                
                <div id="extra_fields" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4 hidden">
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Harga Beli</label>
                        <input type="text" id="inp_buy" class="w-full border p-2 rounded text-right" onkeyup="formatRupiah(this)">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Harga Jual</label>
                        <input type="text" id="inp_sell" class="w-full border p-2 rounded text-right" onkeyup="formatRupiah(this)">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Satuan</label>
                        <input type="text" id="inp_unit" class="w-full border p-2 rounded" value="Pcs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Kategori</label>
                        <select id="inp_cat" class="w-full border p-2 rounded text-sm">
                            <?php foreach($category_accounts as $acc): ?>
                                <option value="<?= $acc['code'] ?>"><?= $acc['code'] ?> - <?= $acc['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- SN INPUT (Manual Text for New Items) -->
                <div id="sn_wrapper" class="hidden mt-4 bg-white p-3 rounded border border-gray-300">
                    <label class="block text-xs font-bold text-gray-700 mb-2">Input Serial Number Baru (Wajib per Qty)</label>
                    <div id="sn_inputs_container" class="grid grid-cols-2 md:grid-cols-4 gap-2 max-h-40 overflow-y-auto"></div>
                </div>

                <div class="flex gap-4 mt-4">
                    <input type="text" id="inp_notes" class="flex-1 border p-2 rounded" placeholder="Catatan Item...">
                    <button type="button" onclick="addToCart()" class="bg-blue-600 text-white px-6 py-2 rounded font-bold">Tambah</button>
                </div>
            </div>

            <!-- CART -->
            <div class="bg-white border rounded shadow-sm mb-6">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100 text-gray-700">
                        <tr>
                            <th class="p-3">Barang</th>
                            <th class="p-3 text-center">Qty</th>
                            <th class="p-3">SN</th>
                            <th class="p-3 text-right">Subtotal Beli</th>
                            <th class="p-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="cart_body">
                        <tr><td colspan="5" class="p-4 text-center text-gray-400">Keranjang kosong.</td></tr>
                    </tbody>
                </table>
            </div>

            <button type="submit" id="btn_submit" class="w-full bg-green-600 text-white py-3 rounded font-bold shadow disabled:bg-gray-400" disabled>Simpan</button>
        </form>
    </div>
</div>

<script>
let cart = [];
const APP_REF = "<?= $app_ref_prefix ?>";

$(document).ready(function() {
    $('#manual_product_select').select2({ placeholder: '-- Cari / Pilih Barang --', allowClear: true, width: '100%' });
    generateReference();
});

$('#manual_product_select').on('select2:select', function (e) {
    const data = e.params.data.element.dataset; 
    const sku = e.params.data.id;
    
    document.getElementById('inp_sku').value = sku;
    document.getElementById('inp_name').value = data.name;
    document.getElementById('inp_buy').value = formatMoney(data.buy);
    document.getElementById('inp_sell').value = formatMoney(data.sell);
    document.getElementById('inp_unit').value = data.unit;
    document.getElementById('inp_cat').value = data.cat;
    
    document.getElementById('extra_fields').classList.remove('hidden');
    document.getElementById('inp_qty').focus();
});

function renderSnInputs() {
    const qty = parseInt(document.getElementById('inp_qty').value) || 0;
    const container = document.getElementById('sn_inputs_container');
    const wrapper = document.getElementById('sn_wrapper');
    document.getElementById('extra_fields').classList.remove('hidden');
    
    if (qty > 0) {
        wrapper.classList.remove('hidden');
        // Simple logic: regenerate if needed or just append
        // For simplicity: clear and regenerate to ensure correct count
        container.innerHTML = ''; 
        for(let i = 0; i < qty; i++) {
            const input = document.createElement('input');
            input.className = 'sn-field border p-2 rounded text-xs uppercase font-mono';
            input.placeholder = `SN #${i+1}`;
            container.appendChild(input);
        }
    } else {
        wrapper.classList.add('hidden');
    }
}

function addToCart() {
    const sku = document.getElementById('inp_sku').value.trim();
    const name = document.getElementById('inp_name').value.trim();
    const qty = parseInt(document.getElementById('inp_qty').value);
    
    if(!sku || !name || !qty || qty <= 0) { alert("Lengkapi data!"); return; }

    let sns = [];
    let validSn = true;
    document.querySelectorAll('.sn-field').forEach(inp => {
        if(!inp.value.trim()) validSn = false;
        sns.push(inp.value.trim());
    });

    if(!validSn) { alert("Lengkapi semua SN!"); return; }

    const item = {
        sku, name, qty, sns,
        buy_price: document.getElementById('inp_buy').value,
        sell_price: document.getElementById('inp_sell').value,
        category: document.getElementById('inp_cat').value,
        unit: document.getElementById('inp_unit').value,
        notes: document.getElementById('inp_notes').value
    };

    cart.push(item);
    renderCart();
    
    // Reset
    $('#manual_product_select').val(null).trigger('change');
    document.getElementById('inp_sku').value = '';
    document.getElementById('inp_name').value = '';
    document.getElementById('inp_qty').value = '';
    document.getElementById('sn_wrapper').classList.add('hidden');
}

function renderCart() {
    const tbody = document.getElementById('cart_body');
    tbody.innerHTML = '';
    if(cart.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-gray-400">Keranjang kosong.</td></tr>';
        document.getElementById('btn_submit').disabled = true;
        return;
    }
    document.getElementById('btn_submit').disabled = false;

    cart.forEach((item, idx) => {
        const sub = item.qty * parseInt(item.buy_price.replace(/\D/g,'')||0);
        tbody.innerHTML += `
            <tr class="border-b">
                <td class="p-3"><b>${item.name}</b><br><small>${item.sku}</small></td>
                <td class="p-3 text-center">${item.qty}</td>
                <td class="p-3 text-xs font-mono break-all">${item.sns.join(', ')}</td>
                <td class="p-3 text-right">Rp ${new Intl.NumberFormat('id-ID').format(sub)}</td>
                <td class="p-3 text-center"><button type="button" onclick="cart.splice(${idx},1);renderCart()" class="text-red-500">Hapus</button></td>
            </tr>
        `;
    });
    document.getElementById('cart_json').value = JSON.stringify(cart);
}

function validateCart() { return cart.length > 0; }
function formatMoney(val) { return val ? 'Rp ' + new Intl.NumberFormat('id-ID').format(val) : ''; }
function formatRupiah(i) { let v = i.value.replace(/\D/g, ''); if(v==='') { i.value=''; return; } i.value = new Intl.NumberFormat('id-ID').format(v); }
async function generateReference() { try { const res = await fetch('api.php?action=get_next_trx_number&type=IN'); const data = await res.json(); const d = new Date(); const ref = `${APP_REF}/IN/${d.getDate()}/${d.getMonth()+1}/${d.getFullYear()}-${data.next_no}`; document.getElementById('reference_input').value = ref; } catch(e){} }
</script>