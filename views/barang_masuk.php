<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- LOGIKA SIMPAN DATA (BATCH / CART SYSTEM) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_inbound'])) {
    validate_csrf();
    
    $date = $_POST['date'];
    $warehouse_id = $_POST['warehouse_id'];
    $reference = $_POST['reference'];
    $cart_json = $_POST['cart_json'];
    $cart = json_decode($cart_json, true);

    if (empty($warehouse_id)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gudang Penerima harus dipilih.'];
    } elseif (empty($cart) || !is_array($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Keranjang barang kosong.'];
    } else {
        $pdo->beginTransaction();
        try {
            $count_items = 0;
            
            // Generate Transaction Group Ref ID (Optional, bisa pakai $reference)
            // Untuk Barang Masuk, kita proses per item dalam loop
            
            foreach ($cart as $item) {
                $sku = trim($item['sku']);
                $qty = (float)$item['qty'];
                $sns = $item['sns'] ?? []; // Array SN
                $notes = $item['notes'];
                
                // Ambil info detail dari input (jika barang baru diupdate/dibuat di JS form)
                $input_name = trim($item['name']);
                $input_cat = trim($item['category'] ?? 'Umum');
                $input_unit = trim($item['unit'] ?? 'Pcs');
                $input_buy = cleanNumber($item['buy_price']);
                $input_sell = cleanNumber($item['sell_price']);

                // 1. Cek / Create / Update Product
                $stmt = $pdo->prepare("SELECT id, stock, buy_price, has_serial_number FROM products WHERE sku = ?");
                $stmt->execute([$sku]);
                $product = $stmt->fetch();
                $product_id = 0;

                if ($product) {
                    // Update Harga & Stok
                    $product_id = $product['id'];
                    $pdo->prepare("UPDATE products SET stock = stock + ?, buy_price = ?, sell_price = ? WHERE id = ?")
                        ->execute([$qty, $input_buy, $input_sell, $product_id]);
                } else {
                    // Insert Baru
                    $stmtIns = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, has_serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmtIns->execute([$sku, $input_name, $input_cat, $input_unit, $input_buy, $input_sell, $qty]);
                    $product_id = $pdo->lastInsertId();
                }

                // 2. Insert Transaksi Inventory
                $sn_string = !empty($sns) ? " [SN: " . implode(", ", $sns) . "]" : "";
                $final_notes = $notes . $sn_string;
                
                $stmtTrx = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)");
                $stmtTrx->execute([$date, $product_id, $warehouse_id, $qty, $reference, $final_notes, $_SESSION['user_id']]);
                $trx_id = $pdo->lastInsertId();

                // 3. Insert Serial Numbers
                if (!empty($sns)) {
                    $stmtSn = $pdo->prepare("INSERT INTO product_serials (product_id, serial_number, status, warehouse_id, in_transaction_id) VALUES (?, ?, 'AVAILABLE', ?, ?)");
                    foreach ($sns as $sn) {
                        // Cek Duplicate SN
                        $chk = $pdo->prepare("SELECT id FROM product_serials WHERE product_id=? AND serial_number=?");
                        $chk->execute([$product_id, $sn]);
                        if($chk->rowCount() == 0) {
                            $stmtSn->execute([$product_id, $sn, $warehouse_id, $trx_id]);
                        }
                    }
                }

                // 4. Catat Keuangan (Per Item)
                if ($input_buy > 0) {
                    $total_val = $qty * $input_buy;
                    // Cari akun persediaan (Asset) berdasarkan kategori atau default 3003
                    $acc_code = $input_cat; // Asumsi kategori = kode akun
                    $accId = $pdo->query("SELECT id FROM accounts WHERE code = '$acc_code'")->fetchColumn();
                    if(!$accId) $accId = $pdo->query("SELECT id FROM accounts WHERE code = '3003'")->fetchColumn(); // Default Persediaan

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
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$count_items jenis barang berhasil disimpan."];
            echo "<script>window.location='?page=barang_masuk';</script>";
            exit;
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: '.$e->getMessage()];
        }
    }
}

// Data Master
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$category_accounts = $pdo->query("SELECT * FROM accounts WHERE code IN ('3003', '2005', '2105') OR type='ASSET' ORDER BY code ASC")->fetchAll();
$products_list = $pdo->query("SELECT sku, name, category, unit, buy_price, sell_price FROM products ORDER BY name ASC")->fetchAll();
$app_ref_prefix = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-xl text-green-700 flex items-center gap-2 mb-6 border-b pb-4">
            <i class="fas fa-box-open"></i> Input Barang Masuk (Multi-Item)
        </h3>

        <form method="POST" id="form_masuk" onsubmit="return validateCart()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_inbound" value="1">
            <input type="hidden" name="cart_json" id="cart_json">
            
            <!-- HEADER TRANSAKSI -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6 bg-gray-50 p-4 rounded border border-gray-200">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Gudang Penerima <span class="text-red-500">*</span></label>
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

            <!-- INPUT ITEM BARU -->
            <div class="bg-green-50 p-4 rounded border border-green-200 mb-6">
                <h4 class="font-bold text-green-800 mb-3 text-sm uppercase">Input Barang ke Keranjang</h4>
                
                <!-- 1. Cari Barang -->
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang Database (Atau Ketik SKU Baru)</label>
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

                <!-- 2. Detail Barang & Qty -->
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end mb-4">
                    <div class="md:col-span-3">
                        <label class="block text-xs font-bold text-gray-600 mb-1">SKU / Barcode</label>
                        <input type="text" id="inp_sku" class="w-full border p-2 rounded font-mono font-bold uppercase" placeholder="SCAN/KETIK...">
                    </div>
                    <div class="md:col-span-4">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Nama Barang</label>
                        <input type="text" id="inp_name" class="w-full border p-2 rounded" placeholder="Nama Barang...">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Qty Masuk</label>
                        <input type="number" id="inp_qty" class="w-full border p-2 rounded font-bold text-center" min="1" placeholder="0" onkeyup="renderSnInputs()" onchange="renderSnInputs()">
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Harga Beli (Satuan)</label>
                        <input type="text" id="inp_buy" class="w-full border p-2 rounded text-right" onkeyup="formatRupiah(this)" placeholder="Rp 0">
                    </div>
                </div>

                <!-- Hidden Fields for New Product Master Data -->
                <div id="new_prod_details" class="grid grid-cols-3 gap-4 mb-4 hidden">
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Kategori</label>
                        <select id="inp_cat" class="w-full border p-2 rounded text-sm">
                            <?php foreach($category_accounts as $acc): ?>
                                <option value="<?= $acc['code'] ?>"><?= $acc['code'] ?> - <?= $acc['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Satuan</label>
                        <input type="text" id="inp_unit" class="w-full border p-2 rounded text-sm" value="Pcs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600">Harga Jual (Est)</label>
                        <input type="text" id="inp_sell" class="w-full border p-2 rounded text-sm text-right" onkeyup="formatRupiah(this)">
                    </div>
                </div>

                <!-- 3. SN Input Area (Dynamic) -->
                <div id="sn_wrapper" class="hidden mt-4 bg-white p-3 rounded border border-gray-300">
                    <label class="block text-xs font-bold text-gray-700 mb-2">Input Serial Number (Wajib per Qty)</label>
                    <div id="sn_inputs_container" class="grid grid-cols-2 md:grid-cols-4 gap-2 max-h-40 overflow-y-auto"></div>
                </div>

                <!-- 4. Notes & Add Button -->
                <div class="flex gap-4 mt-4 items-end">
                    <div class="flex-1">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Catatan Item</label>
                        <input type="text" id="inp_notes" class="w-full border p-2 rounded" placeholder="Keterangan...">
                    </div>
                    <button type="button" onclick="addToCart()" class="bg-blue-600 text-white px-6 py-2 rounded font-bold hover:bg-blue-700">
                        <i class="fas fa-plus"></i> Tambah ke Daftar
                    </button>
                </div>
            </div>

            <!-- TABEL KERANJANG -->
            <div class="bg-white border rounded shadow-sm mb-6">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100 text-gray-700">
                        <tr>
                            <th class="p-3">Barang</th>
                            <th class="p-3 text-center">Qty</th>
                            <th class="p-3">Serial Numbers</th>
                            <th class="p-3 text-right">Subtotal Beli</th>
                            <th class="p-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="cart_body">
                        <tr><td colspan="5" class="p-4 text-center text-gray-400">Keranjang kosong.</td></tr>
                    </tbody>
                </table>
            </div>

            <button type="submit" id="btn_submit" class="w-full bg-green-600 text-white py-3 rounded font-bold hover:bg-green-700 shadow text-lg" disabled>
                <i class="fas fa-save"></i> Simpan Transaksi Masuk
            </button>
        </form>
    </div>
</div>

<script>
let cart = [];
const APP_REF = "<?= $app_ref_prefix ?>";

// --- INIT ---
$(document).ready(function() {
    $('#manual_product_select').select2({ placeholder: '-- Cari / Pilih Barang --', allowClear: true, width: '100%' });
    generateReference();
});

// --- SELECT2 HANDLER ---
$('#manual_product_select').on('select2:select', function (e) {
    const data = e.params.data.element.dataset; // Access data attributes
    const sku = e.params.data.id;
    
    // Fill Inputs
    document.getElementById('inp_sku').value = sku;
    document.getElementById('inp_name').value = data.name;
    document.getElementById('inp_buy').value = formatMoney(data.buy);
    
    // Fill Hidden/Optional fields
    document.getElementById('inp_cat').value = data.cat || '3003';
    document.getElementById('inp_unit').value = data.unit || 'Pcs';
    document.getElementById('inp_sell').value = formatMoney(data.sell);
    
    // Show details logic
    document.getElementById('new_prod_details').classList.remove('hidden');
    document.getElementById('inp_qty').focus();
});

// --- SN GENERATOR ---
function renderSnInputs() {
    const qty = parseInt(document.getElementById('inp_qty').value) || 0;
    const container = document.getElementById('sn_inputs_container');
    const wrapper = document.getElementById('sn_wrapper');
    
    if (qty > 0) {
        wrapper.classList.remove('hidden');
        // Check existing inputs to avoid redraw if same qty (optional optimization)
        const currentCount = container.children.length;
        
        if(qty > currentCount) {
            for(let i = currentCount; i < qty; i++) {
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'sn-field border p-2 rounded text-xs uppercase font-mono';
                input.placeholder = `SN #${i+1}`;
                container.appendChild(input);
            }
        } else if (qty < currentCount) {
            while(container.children.length > qty) {
                container.removeChild(container.lastChild);
            }
        }
    } else {
        wrapper.classList.add('hidden');
        container.innerHTML = '';
    }
}

// --- ADD TO CART ---
function addToCart() {
    const sku = document.getElementById('inp_sku').value.trim();
    const name = document.getElementById('inp_name').value.trim();
    const qty = parseInt(document.getElementById('inp_qty').value);
    const buyPrice = document.getElementById('inp_buy').value;
    
    if(!sku || !name || !qty || qty <= 0) {
        alert("Lengkapi SKU, Nama, dan Qty!");
        return;
    }

    // Collect SNs
    let sns = [];
    const snInputs = document.querySelectorAll('.sn-field');
    let allSnFilled = true;
    snInputs.forEach(inp => {
        if(!inp.value.trim()) allSnFilled = false;
        sns.push(inp.value.trim());
    });

    if(!allSnFilled) {
        alert("Harap isi semua kolom Serial Number!");
        return;
    }

    // Item Object
    const item = {
        sku: sku,
        name: name,
        qty: qty,
        buy_price: buyPrice,
        sell_price: document.getElementById('inp_sell').value,
        category: document.getElementById('inp_cat').value,
        unit: document.getElementById('inp_unit').value,
        notes: document.getElementById('inp_notes').value,
        sns: sns
    };

    cart.push(item);
    renderCart();
    
    // Reset Form
    $('#manual_product_select').val(null).trigger('change');
    document.getElementById('inp_sku').value = '';
    document.getElementById('inp_name').value = '';
    document.getElementById('inp_qty').value = '';
    document.getElementById('inp_buy').value = '';
    document.getElementById('sn_inputs_container').innerHTML = '';
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
        const subtotal = item.qty * parseInt(item.buy_price.replace(/\D/g,'') || 0);
        
        tbody.innerHTML += `
            <tr class="border-b hover:bg-gray-50">
                <td class="p-3">
                    <div class="font-bold">${item.name}</div>
                    <div class="text-xs text-gray-500">${item.sku}</div>
                </td>
                <td class="p-3 text-center font-bold">${item.qty}</td>
                <td class="p-3 text-xs font-mono break-all max-w-xs">${item.sns.join(', ')}</td>
                <td class="p-3 text-right">Rp ${new Intl.NumberFormat('id-ID').format(subtotal)}</td>
                <td class="p-3 text-center">
                    <button type="button" onclick="cart.splice(${idx},1);renderCart()" class="text-red-500"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
    });
    
    document.getElementById('cart_json').value = JSON.stringify(cart);
}

function validateCart() {
    if(cart.length === 0) return false;
    return confirm("Simpan transaksi ini?");
}

// Helpers
function formatMoney(val) { return val ? 'Rp ' + new Intl.NumberFormat('id-ID').format(val) : ''; }
function formatRupiah(i) { let v = i.value.replace(/\D/g, ''); if(v==='') { i.value=''; return; } i.value = new Intl.NumberFormat('id-ID').format(v); }
async function generateReference() { try { const res = await fetch('api.php?action=get_next_trx_number&type=IN'); const data = await res.json(); const d = new Date(); const ref = `${APP_REF}/IN/${d.getDate()}/${d.getMonth()+1}/${d.getFullYear()}-${data.next_no}`; document.getElementById('reference_input').value = ref; } catch(e){} }
</script>