<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- LOGIKA SIMPAN TRANSAKSI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_transaction'])) {
    validate_csrf();
    
    $cart_json = $_POST['cart_json'];
    $cart = json_decode($cart_json, true);
    
    $date = $_POST['date'];
    $wh_id = $_POST['warehouse_id']; 
    $dest_wh_id = !empty($_POST['dest_warehouse_id']) ? $_POST['dest_warehouse_id'] : null; 
    $ref = $_POST['reference'];
    $out_type = $_POST['out_type']; // INTERNAL / EXTERNAL
    
    if (empty($wh_id)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gudang Asal harus dipilih.'];
    } elseif (empty($cart) || !is_array($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Keranjang kosong!'];
    } else {
        $pdo->beginTransaction();
        try {
            // Ambil Nama Gudang
            $wh_name = $pdo->query("SELECT name FROM warehouses WHERE id = $wh_id")->fetchColumn();
            $dest_wh_name = $dest_wh_id ? $pdo->query("SELECT name FROM warehouses WHERE id = $dest_wh_id")->fetchColumn() : '';

            foreach ($cart as $item) {
                // 1. Validasi Stok DB
                $stmt = $pdo->prepare("SELECT id, stock, sell_price, buy_price, name FROM products WHERE sku = ?");
                $stmt->execute([$item['sku']]);
                $prod = $stmt->fetch();
                
                if (!$prod) throw new Exception("Produk SKU {$item['sku']} tidak ditemukan.");
                
                // SN wajib ada sesuai Qty
                if (empty($item['sns']) || count($item['sns']) != $item['qty']) {
                    throw new Exception("Jumlah SN tidak sesuai Qty untuk produk {$prod['name']}.");
                }

                if ($prod['stock'] < $item['qty']) {
                    throw new Exception("Stok Global {$prod['name']} tidak cukup!");
                }
                
                // 2. Transaksi OUT
                $notes_out = $item['notes'];
                if($dest_wh_id) $notes_out .= " (Mutasi ke $dest_wh_name)";
                $notes_out .= " [SN: " . implode(', ', $item['sns']) . "]";

                $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)")
                    ->execute([$date, $prod['id'], $wh_id, $item['qty'], $ref, $notes_out, $_SESSION['user_id']]);
                $trx_out_id = $pdo->lastInsertId();

                // 3. Update Stok & Status SN
                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$item['qty'], $prod['id']]);
                
                $snStmt = $pdo->prepare("UPDATE product_serials SET status = 'SOLD', out_transaction_id = ? WHERE product_id = ? AND serial_number = ? AND status = 'AVAILABLE'");
                foreach ($item['sns'] as $sn) {
                    $snStmt->execute([$trx_out_id, $prod['id'], $sn]);
                    if ($snStmt->rowCount() == 0) {
                        throw new Exception("SN $sn tidak tersedia di sistem (mungkin sudah terjual).");
                    }
                }

                // 4. Handle Keuangan / Mutasi
                $total_buy = $item['qty'] * $prod['buy_price'];
                $total_sell = $item['qty'] * $prod['sell_price'];

                if ($out_type === 'INTERNAL' && !empty($dest_wh_id)) {
                    // MUTASI
                    $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([$item['qty'], $prod['id']]);
                    $ref_in = $ref . '-TRF';
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'IN', ?, ?, ?, ?, 'Mutasi Masuk', ?)")
                        ->execute([$date, $prod['id'], $dest_wh_id, $item['qty'], $ref_in, $_SESSION['user_id']]);
                    $trx_in_id = $pdo->lastInsertId();
                    
                    $snMove = $pdo->prepare("UPDATE product_serials SET status = 'AVAILABLE', warehouse_id = ?, in_transaction_id = ? WHERE product_id = ? AND serial_number = ?");
                    foreach ($item['sns'] as $sn) $snMove->execute([$dest_wh_id, $trx_in_id, $prod['id'], $sn]);
                
                } elseif ($out_type === 'EXTERNAL') {
                    // PENJUALAN
                    $acc_sales = $pdo->query("SELECT id FROM accounts WHERE code='4001'")->fetchColumn();
                    if ($acc_sales && $total_sell > 0) {
                        $desc = "Penjualan: {$prod['name']} ($item[qty]) [Wilayah: $wh_name]";
                        $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'INCOME', ?, ?, ?, ?)")
                            ->execute([$date, $acc_sales, $total_sell, $desc, $_SESSION['user_id']]);
                    }
                } elseif ($out_type === 'INTERNAL' && empty($dest_wh_id)) {
                    // PEMAKAIAN SENDIRI
                    $acc_use = $pdo->query("SELECT id FROM accounts WHERE code='2105'")->fetchColumn();
                    if ($acc_use && $total_buy > 0) {
                        $desc = "Pemakaian: {$prod['name']} ($item[qty]) [Wilayah: $wh_name]";
                        $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                            ->execute([$date, $acc_use, $total_buy, $desc, $_SESSION['user_id']]);
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"Transaksi Keluar Berhasil."];
            echo "<script>window.location='?page=barang_keluar';</script>";
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: '.$e->getMessage()];
        }
    }
}

// Data Master
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$products_list = $pdo->query("SELECT sku, name FROM products WHERE stock > 0 ORDER BY name ASC")->fetchAll();
$app_ref_prefix = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-xl text-red-700 flex items-center gap-2 mb-6 border-b pb-4">
            <i class="fas fa-dolly"></i> Input Barang Keluar
        </h3>

        <form method="POST" id="form_out" onsubmit="return validateCart()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_transaction" value="1">
            <input type="hidden" name="cart_json" id="cart_json">

            <!-- HEADER -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Referensi</label>
                    <div class="flex gap-2">
                        <input type="text" name="reference" id="reference_input" class="w-full border p-2 rounded uppercase" required>
                        <button type="button" onclick="generateReference()" class="bg-gray-200 px-3 rounded"><i class="fas fa-magic"></i></button>
                    </div>
                </div>
            </div>

            <!-- TIPE -->
            <div class="mb-4 bg-gray-50 p-3 rounded border">
                <label class="block text-sm font-bold text-gray-700 mb-2">Jenis Transaksi</label>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="out_type" value="EXTERNAL" onchange="toggleDestWarehouse()">
                        <span class="font-bold text-blue-700">Penjualan / External</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="out_type" value="INTERNAL" checked onchange="toggleDestWarehouse()">
                        <span class="font-bold text-orange-700">Internal / Mutasi / Pemakaian</span>
                    </label>
                </div>
            </div>

            <!-- GUDANG -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Dari Gudang (Asal)</label>
                    <select name="warehouse_id" id="warehouse_id" class="w-full border p-2 rounded bg-white" required onchange="resetCart()">
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="dest_wh_container">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Ke Gudang (Tujuan Mutasi)</label>
                    <select name="dest_warehouse_id" class="w-full border p-2 rounded bg-white">
                        <option value="">-- Pilih Jika Mutasi --</option>
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">* Kosongkan jika Pemakaian Sendiri.</p>
                </div>
            </div>

            <!-- INPUT ITEM -->
            <div class="bg-red-50 p-4 rounded border border-red-200 mb-6">
                <h4 class="font-bold text-red-800 mb-3 text-sm">Pilih Barang & SN</h4>
                
                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-500 mb-1">Cari Barang (Ketik Nama/SKU)</label>
                    <select id="manual_product_select" class="w-full p-2 rounded">
                        <option value="">-- Cari Barang --</option>
                        <?php foreach($products_list as $prod): ?>
                            <option value="<?= $prod['sku'] ?>"><?= $prod['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- DETAIL CARD -->
                <div id="product_detail_card" class="hidden mb-4 bg-white p-3 rounded border border-red-100 shadow-sm flex items-center justify-between">
                    <div>
                        <div class="text-[10px] text-gray-500 font-bold uppercase">Barang Terpilih</div>
                        <div class="font-bold text-lg text-gray-800" id="detail_name">-</div>
                        <div class="text-xs text-gray-500 font-mono" id="detail_sku">-</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-500">Stok Tersedia</div>
                        <div class="font-bold text-xl text-blue-600" id="detail_stock">0</div>
                        <div class="text-[10px] text-gray-400" id="detail_unit">Unit</div>
                    </div>
                </div>

                <!-- SN DROPDOWN MULTI SELECT -->
                <div id="sn_dropdown_container" class="hidden mb-4">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Pilih Serial Number (Klik untuk memilih)</label>
                    <select id="sn_multi_select" class="w-full p-2 border rounded" multiple="multiple" style="width: 100%">
                        <!-- Options populated via AJAX -->
                    </select>
                    <p class="text-[10px] text-gray-500 mt-1">Pilih SN yang akan dikeluarkan. Qty otomatis terhitung.</p>
                </div>

                <div class="flex flex-col md:flex-row gap-2 items-end">
                    <div class="w-24">
                        <label class="text-xs font-bold text-gray-600">Qty</label>
                        <input type="number" id="qty_input" class="w-full border p-2 rounded text-center font-bold bg-gray-100" readonly value="0">
                    </div>
                    <div class="flex-1">
                        <label class="text-xs font-bold text-gray-600">Catatan</label>
                        <input type="text" id="notes_input" class="w-full border p-2 rounded" placeholder="Keterangan...">
                    </div>
                </div>

                <button type="button" onclick="addToCart()" class="mt-4 bg-red-600 text-white px-6 py-2 rounded font-bold hover:bg-red-700 shadow w-full md:w-auto">
                    <i class="fas fa-plus"></i> Tambah ke Keranjang
                </button>
            </div>

            <!-- CART -->
            <div class="border rounded overflow-hidden mb-6 bg-white">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-3">Barang</th>
                            <th class="p-3 text-center">Qty</th>
                            <th class="p-3">SN List</th>
                            <th class="p-3 text-center">Hapus</th>
                        </tr>
                    </thead>
                    <tbody id="cart_body">
                        <tr><td colspan="4" class="p-4 text-center text-gray-400">Keranjang kosong.</td></tr>
                    </tbody>
                </table>
            </div>

            <button type="submit" id="btn_submit" class="w-full bg-blue-600 text-white py-3 rounded font-bold hover:bg-blue-700 text-lg shadow" disabled>
                Simpan Transaksi Keluar
            </button>
        </form>
    </div>
</div>

<script>
let cart = [];
const APP_REF = "<?= $app_ref_prefix ?>";

$(document).ready(function() {
    $('#manual_product_select').select2({ placeholder: '-- Cari Barang --', allowClear: true, width: '100%' });
    $('#sn_multi_select').select2({ placeholder: 'Pilih Serial Number...', width: '100%' });
    generateReference();
});

// HANDLER SELECT BARANG
$('#manual_product_select').on('select2:select', function (e) {
    const sku = e.params.data.id;
    if(sku) loadProductData(sku);
});

// HANDLER CHANGE SN -> UPDATE QTY
$('#sn_multi_select').on('change', function() {
    const selected = $(this).val();
    document.getElementById('qty_input').value = selected ? selected.length : 0;
});

async function loadProductData(sku) {
    const whId = document.getElementById('warehouse_id').value;
    
    // 1. Get Product Detail
    const res = await fetch(`api.php?action=get_product_by_sku&sku=${encodeURIComponent(sku)}`);
    const data = await res.json();
    
    if(data.id) {
        document.getElementById('product_detail_card').classList.remove('hidden');
        document.getElementById('detail_name').innerText = data.name;
        document.getElementById('detail_sku').innerText = data.sku;
        document.getElementById('detail_stock').innerText = data.stock; // Global stock, but we care about SN availability
        
        // Save hidden data
        document.getElementById('qty_input').dataset.sku = data.sku;
        document.getElementById('qty_input').dataset.name = data.name;
        document.getElementById('qty_input').dataset.id = data.id;

        // 2. Fetch Available SNs for this Product & Warehouse
        loadAvailableSNs(data.id, whId);
    }
}

async function loadAvailableSNs(prodId, whId) {
    const snContainer = document.getElementById('sn_dropdown_container');
    const snSelect = $('#sn_multi_select');
    
    snSelect.empty();
    document.getElementById('qty_input').value = 0;
    
    const res = await fetch(`api.php?action=get_available_sns&product_id=${prodId}&warehouse_id=${whId}`);
    const sns = await res.json();
    
    if (sns.length > 0) {
        snContainer.classList.remove('hidden');
        sns.forEach(sn => {
            const opt = new Option(sn, sn, false, false);
            snSelect.append(opt);
        });
        snSelect.trigger('change');
    } else {
        snContainer.classList.add('hidden');
        alert("Stok SN habis di gudang ini!");
    }
}

function addToCart() {
    const qty = parseInt(document.getElementById('qty_input').value);
    if(!qty || qty <= 0) { alert("Pilih minimal 1 SN!"); return; }
    
    const sku = document.getElementById('qty_input').dataset.sku;
    const name = document.getElementById('qty_input').dataset.name;
    const notes = document.getElementById('notes_input').value;
    const sns = $('#sn_multi_select').val(); // Array of strings

    cart.push({ sku, name, qty, sns, notes });
    renderCart();
    
    // Reset Form
    $('#manual_product_select').val(null).trigger('change');
    $('#sn_multi_select').val(null).trigger('change');
    document.getElementById('product_detail_card').classList.add('hidden');
    document.getElementById('sn_dropdown_container').classList.add('hidden');
    document.getElementById('qty_input').value = 0;
    document.getElementById('notes_input').value = '';
}

function renderCart() {
    const tbody = document.getElementById('cart_body');
    tbody.innerHTML = '';
    if(cart.length===0) {
        tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-gray-400">Keranjang kosong.</td></tr>';
        document.getElementById('btn_submit').disabled = true;
        return;
    }
    document.getElementById('btn_submit').disabled = false;
    
    cart.forEach((item, idx) => {
        tbody.innerHTML += `
            <tr class="border-b">
                <td class="p-2 font-bold">${item.name}<br><small>${item.sku}</small></td>
                <td class="p-2 text-center">${item.qty}</td>
                <td class="p-2 text-xs font-mono">${item.sns.join(', ')}</td>
                <td class="p-2 text-center"><button type="button" onclick="cart.splice(${idx},1);renderCart()" class="text-red-500"><i class="fas fa-trash"></i></button></td>
            </tr>
        `;
    });
    document.getElementById('cart_json').value = JSON.stringify(cart);
}

function resetCart() {
    if(cart.length > 0 && confirm("Ganti gudang akan menghapus keranjang. Lanjutkan?")) {
        cart = [];
        renderCart();
    }
    $('#manual_product_select').val(null).trigger('change');
    document.getElementById('product_detail_card').classList.add('hidden');
    document.getElementById('sn_dropdown_container').classList.add('hidden');
}

function toggleDestWarehouse() {
    const type = document.querySelector('input[name="out_type"]:checked').value;
    document.getElementById('dest_wh_container').style.display = (type === 'INTERNAL') ? 'block' : 'none';
}

function validateCart() { return cart.length > 0 ? confirm("Simpan transaksi?") : false; }
async function generateReference() { try { const res = await fetch('api.php?action=get_next_trx_number&type=OUT'); const data = await res.json(); const d = new Date(); const ref = `${APP_REF}/OUT/${d.getDate()}/${d.getMonth()+1}/${d.getFullYear()}-${data.next_no}`; document.getElementById('reference_input').value = ref; } catch(e){} }
</script>