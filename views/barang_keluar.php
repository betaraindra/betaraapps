<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- LOGIKA BATAL / RETUR TRANSAKSI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    validate_csrf();
    $cancel_id = $_POST['cancel_id'];

    $pdo->beginTransaction();
    try {
        // 1. Ambil Data Transaksi Lama
        $stmt = $pdo->prepare("SELECT product_id, quantity, reference, type FROM inventory_transactions WHERE id = ?");
        $stmt->execute([$cancel_id]);
        $trx = $stmt->fetch();

        if ($trx && $trx['type'] == 'OUT') {
            // 2. Kembalikan Stok (Rollback: Tambah Stok)
            $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")
                ->execute([$trx['quantity'], $trx['product_id']]);

            // 3. Kembalikan Status SN menjadi AVAILABLE
            $pdo->prepare("UPDATE product_serials SET status = 'AVAILABLE', out_transaction_id = NULL WHERE out_transaction_id = ?")->execute([$cancel_id]);

            // 4. Hapus Transaksi Inventori
            $pdo->prepare("DELETE FROM inventory_transactions WHERE id = ?")->execute([$cancel_id]);

            // 5. Catat Log
            logActivity($pdo, 'RETUR_BARANG', "Batal/Retur Barang Keluar: " . $trx['reference'] . " (Stok kembali +{$trx['quantity']})");

            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Transaksi berhasil dibatalkan. Stok telah dikembalikan ke gudang.'];
        } else {
            throw new Exception("Data transaksi tidak valid atau bukan barang keluar.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal membatalkan: ' . $e->getMessage()];
    }
    echo "<script>window.location='?page=barang_keluar';</script>";
    exit;
}

// --- LOGIKA SIMPAN TRANSAKSI (PHP) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_transaction'])) {
    validate_csrf();
    
    $cart_json = $_POST['cart_json'];
    $cart = json_decode($cart_json, true);
    
    $date = $_POST['date'];
    $wh_id = $_POST['warehouse_id']; 
    $dest_wh_id = !empty($_POST['dest_warehouse_id']) ? $_POST['dest_warehouse_id'] : null; 
    
    $ref = $_POST['reference'];
    $out_type = $_POST['out_type']; // INTERNAL / EXTERNAL
    
    // Validasi Server Side
    if (empty($wh_id)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gudang Asal harus dipilih.'];
    } elseif (empty($out_type)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Jenis Transaksi harus dipilih.'];
    } elseif (empty($cart) || !is_array($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Keranjang transaksi kosong!'];
    } else {
        $pdo->beginTransaction();
        try {
            // Ambil Nama Gudang Asal
            $stmtWh = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
            $stmtWh->execute([$wh_id]);
            $wh_name = $stmtWh->fetchColumn();
            
            // Ambil Nama Gudang Tujuan (Jika ada)
            $dest_wh_name = '';
            if ($dest_wh_id) {
                $stmtDest = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
                $stmtDest->execute([$dest_wh_id]);
                $dest_wh_name = $stmtDest->fetchColumn();
            }

            // Tag Wilayah untuk Finance
            $wilayah_tag_origin = $wh_name ? " [Wilayah: $wh_name]" : "";
            $wilayah_tag_dest = $dest_wh_name ? " [Wilayah: $dest_wh_name]" : "";

            // Validasi Transfer
            if ($out_type === 'INTERNAL' && $dest_wh_id && $dest_wh_id == $wh_id) {
                throw new Exception("Gudang Tujuan tidak boleh sama dengan Gudang Asal.");
            }

            $count = 0;
            foreach ($cart as $item) {
                // 1. Cek Stok & Ambil Info Harga
                $stmt = $pdo->prepare("SELECT id, stock, sell_price, buy_price, name, has_serial_number FROM products WHERE sku = ?");
                $stmt->execute([$item['sku']]);
                $prod = $stmt->fetch();
                
                if (!$prod) throw new Exception("Produk dengan SKU {$item['sku']} tidak ditemukan.");
                
                // 2. Validate SN (ALWAYS MANDATORY)
                if (empty($item['sns']) || count($item['sns']) === 0) {
                    throw new Exception("Produk {$prod['name']} wajib memiliki Serial Number.");
                }

                if ($prod['stock'] < $item['qty']) {
                    throw new Exception("Stok {$item['name']} tidak mencukupi! (Sisa: {$prod['stock']})");
                }
                
                // 3. Kurangi Stok Global (Master Produk)
                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$item['qty'], $prod['id']]);
                
                // 4. Catat Transaksi OUT (Inventori Asal)
                $notes_out = $item['notes'];
                if($dest_wh_id) $notes_out .= " (Mutasi ke $dest_wh_name)";
                
                if(!empty($item['sns'])) {
                    $notes_out .= " [SN: " . implode(', ', $item['sns']) . "]";
                }

                $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)")
                    ->execute([$date, $prod['id'], $wh_id, $item['qty'], $ref, $notes_out, $_SESSION['user_id']]);
                $trx_out_id = $pdo->lastInsertId();

                // 5. UPDATE SN STATUS (ALWAYS)
                if (!empty($item['sns'])) {
                    $snStmt = $pdo->prepare("UPDATE product_serials SET status = 'SOLD', out_transaction_id = ? WHERE product_id = ? AND serial_number = ? AND status = 'AVAILABLE'");
                    foreach ($item['sns'] as $sn) {
                        $snStmt->execute([$trx_out_id, $prod['id'], $sn]);
                        if ($snStmt->rowCount() == 0) {
                            $chk = $pdo->query("SELECT status FROM product_serials WHERE serial_number='$sn'")->fetchColumn();
                            throw new Exception("SN $sn tidak tersedia (Status: $chk) atau salah produk.");
                        }
                    }
                }
                
                // Hitung Nilai Transaksi
                $total_buy_val = $item['qty'] * $prod['buy_price'];  // Nilai Modal/Aset
                $total_sell_val = $item['qty'] * $prod['sell_price']; // Nilai Jual

                // 6. LOGIKA MUTASI ANTAR GUDANG (Internal Transfer)
                if ($out_type === 'INTERNAL' && !empty($dest_wh_id)) {
                    // A. Update Stok Global (Kembalikan stok, karena hanya pindah lokasi)
                    $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([$item['qty'], $prod['id']]);
                    
                    // B. Catat Transaksi IN (Inventori Tujuan)
                    $ref_in = $ref . '-TRF';
                    $notes_in = "Mutasi Masuk dari " . $wh_name;
                    $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'IN', ?, ?, ?, ?, ?, ?)")
                        ->execute([$date, $prod['id'], $dest_wh_id, $item['qty'], $ref_in, $notes_in, $_SESSION['user_id']]);
                    $trx_in_id = $pdo->lastInsertId();

                    // C. Update Location SN (Set AVAILABLE again but in new warehouse)
                    if (!empty($item['sns'])) {
                        $snUpdate = $pdo->prepare("UPDATE product_serials SET status = 'AVAILABLE', warehouse_id = ?, in_transaction_id = ? WHERE product_id = ? AND serial_number = ?");
                        foreach ($item['sns'] as $sn) {
                            $snUpdate->execute([$dest_wh_id, $trx_in_id, $prod['id'], $sn]);
                        }
                    }
                }

                // 7. LOGIKA PENJUALAN (EXTERNAL) - INCOME
                elseif ($out_type === 'EXTERNAL') {
                    // Cari Akun Penjualan (4001)
                    $acc_sales = $pdo->query("SELECT id FROM accounts WHERE code = '4001'")->fetchColumn();
                    // Fallback akun income apapun
                    if (!$acc_sales) $acc_sales = $pdo->query("SELECT id FROM accounts WHERE type = 'INCOME' LIMIT 1")->fetchColumn();

                    if ($acc_sales && $total_sell_val > 0) {
                        $desc_sales = "Penjualan: {$prod['name']} ($item[qty]) $wilayah_tag_origin";
                        $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'INCOME', ?, ?, ?, ?)")
                            ->execute([$date, $acc_sales, $total_sell_val, $desc_sales, $_SESSION['user_id']]);
                    }
                }

                // 8. LOGIKA PEMAKAIAN INTERNAL (INTERNAL, NO DEST) - EXPENSE 2105
                elseif ($out_type === 'INTERNAL' && empty($dest_wh_id)) {
                    // Cari Akun 2105 (Material Habis Pakai)
                    $acc_mat = $pdo->query("SELECT id FROM accounts WHERE code = '2105'")->fetchColumn();
                    // Fallback ke 2002 (Operasional)
                    if (!$acc_mat) $acc_mat = $pdo->query("SELECT id FROM accounts WHERE code = '2002'")->fetchColumn();

                    if ($acc_mat && $total_buy_val > 0) {
                        $desc_use = "Pemakaian Material: {$prod['name']} ($item[qty]) $wilayah_tag_origin";
                        $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                            ->execute([$date, $acc_mat, $total_buy_val, $desc_use, $_SESSION['user_id']]);
                    }
                }

                $count++;
            }
            
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$count jenis barang berhasil diproses ($out_type)."];
            echo "<script>window.location='?page=barang_keluar';</script>";
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: '.$e->getMessage()];
        }
    }
}

// ... SISA KODE VIEW BARANG KELUAR (GET Data, Form HTML, JS) ...
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$products_list = $pdo->query("SELECT sku, name, stock, sell_price FROM products WHERE stock > 0 ORDER BY name ASC")->fetchAll();
$app_ref_prefix = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));

// Recent TRX
$recent_trx = $pdo->query("SELECT i.*, p.name as prod_name, p.sku, w.name as wh_name 
    FROM inventory_transactions i 
    JOIN products p ON i.product_id = p.id 
    JOIN warehouses w ON i.warehouse_id = w.id
    WHERE i.type = 'OUT' 
    ORDER BY i.created_at DESC 
    LIMIT 10")->fetchAll();
?>

<!-- STYLE & UI (SAMA SEPERTI FILE ASLI, DISESUAIKAN UNTUK KELENGKAPAN) -->
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <!-- HEADER -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 border-b pb-4 gap-4">
            <h3 class="font-bold text-xl text-red-700 flex items-center gap-2">
                <i class="fas fa-dolly"></i> Input Barang Keluar
            </h3>
            <div class="flex gap-2">
                <button type="button" onclick="activateUSB()" class="bg-gray-700 text-white px-3 py-2 rounded shadow hover:bg-gray-800 transition text-sm flex items-center gap-2"><i class="fas fa-keyboard"></i> USB</button>
                <button type="button" onclick="initCamera()" class="bg-indigo-600 text-white px-3 py-2 rounded shadow hover:bg-indigo-700 transition text-sm flex items-center gap-2"><i class="fas fa-video"></i> Scan</button>
            </div>
        </div>

        <!-- SCANNER AREA -->
        <div id="scanner_area" class="hidden mb-6 bg-black rounded-lg relative overflow-hidden shadow-inner border-4 border-gray-800">
            <div id="reader" class="w-full h-64"></div>
            <button onclick="stopScan()" class="absolute top-2 right-2 bg-red-600 text-white rounded-full w-8 h-8"><i class="fas fa-times"></i></button>
        </div>

        <form method="POST" id="form_out" onsubmit="return validateCart()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_transaction" value="1">
            <input type="hidden" name="cart_json" id="cart_json">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Referensi / No. Invoice</label>
                    <div class="flex gap-2">
                        <input type="text" name="reference" id="reference_input" class="w-full border p-2 rounded uppercase" required>
                        <button type="button" onclick="generateReference()" class="bg-gray-200 px-3 rounded"><i class="fas fa-magic"></i></button>
                    </div>
                </div>
            </div>

            <!-- TIPE TRANSAKSI -->
            <div class="mb-4 bg-gray-50 p-3 rounded border">
                <label class="block text-sm font-bold text-gray-700 mb-2">Jenis Transaksi</label>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="out_type" value="EXTERNAL" checked onchange="toggleDestWarehouse()">
                        <span class="font-bold text-blue-700">Penjualan / External</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="out_type" value="INTERNAL" onchange="toggleDestWarehouse()">
                        <span class="font-bold text-orange-700">Internal / Mutasi / Pemakaian</span>
                    </label>
                </div>
            </div>

            <!-- GUDANG -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Dari Gudang (Asal)</label>
                    <select name="warehouse_id" class="w-full border p-2 rounded bg-white" required>
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="dest_wh_container" class="hidden">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Ke Gudang (Tujuan Mutasi)</label>
                    <select name="dest_warehouse_id" class="w-full border p-2 rounded bg-white">
                        <option value="">-- Pilih Jika Mutasi --</option>
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">* Kosongkan jika Pemakaian Sendiri (Beban).</p>
                </div>
            </div>

            <!-- ITEM INPUT -->
            <div class="bg-red-50 p-4 rounded border border-red-200 mb-6">
                <h4 class="font-bold text-red-800 mb-2 text-sm">Input Barang</h4>
                <div class="flex flex-col md:flex-row gap-2 items-end">
                    <div class="flex-1 w-full">
                        <label class="text-xs font-bold text-gray-600">Scan SKU / SN</label>
                        <input type="text" id="sku_input" class="w-full border p-2 rounded font-mono font-bold uppercase" placeholder="SCAN..." onchange="checkSku()">
                    </div>
                    <div class="w-24">
                        <label class="text-xs font-bold text-gray-600">Qty</label>
                        <input type="number" id="qty_input" class="w-full border p-2 rounded text-center font-bold" value="1" readonly>
                    </div>
                    <button type="button" onclick="addToCart()" class="bg-red-600 text-white px-4 py-2 rounded font-bold hover:bg-red-700">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <div id="sn_status" class="text-xs font-bold mt-1 min-h-[1rem]"></div>
            </div>

            <!-- CART TABLE -->
            <div class="border rounded overflow-hidden mb-6">
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
                        <tr><td colspan="4" class="p-4 text-center text-gray-400">Keranjang kosong.</td></tr>
                    </tbody>
                </table>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-3 rounded font-bold hover:bg-blue-700 text-lg shadow">
                Simpan Transaksi Keluar
            </button>
        </form>
    </div>
</div>

<script>
let cart = [];
let html5QrCode;
const APP_REF = "<?= $app_ref_prefix ?>";

function toggleDestWarehouse() {
    const type = document.querySelector('input[name="out_type"]:checked').value;
    const dest = document.getElementById('dest_wh_container');
    if(type === 'INTERNAL') {
        dest.classList.remove('hidden');
    } else {
        dest.classList.add('hidden');
        document.querySelector('select[name="dest_warehouse_id"]').value = "";
    }
}

async function checkSku() {
    const sku = document.getElementById('sku_input').value;
    const status = document.getElementById('sn_status');
    if(!sku) return;
    
    status.innerHTML = "Checking...";
    try {
        const res = await fetch(`api.php?action=get_product_by_sku&sku=${encodeURIComponent(sku)}`);
        const data = await res.json();
        
        if(data.id) {
            // Logic: 1 Item = 1 SN
            document.getElementById('sku_input').setAttribute('data-id', data.id);
            document.getElementById('sku_input').setAttribute('data-name', data.name);
            
            if(data.scanned_sn) {
                status.innerHTML = `<span class="text-green-600">OK: ${data.name} (SN: ${data.scanned_sn})</span>`;
                // Auto add
                cart.push({
                    id: data.id, sku: data.sku, name: data.name, qty: 1, sns: [data.scanned_sn], notes: ''
                });
                renderCart();
                document.getElementById('sku_input').value = '';
                status.innerHTML = '';
            } else {
                status.innerHTML = `<span class="text-red-600">Gunakan SCANNER untuk scan Serial Number langsung!</span>`;
            }
        } else {
            status.innerHTML = `<span class="text-red-600">Barang tidak ditemukan!</span>`;
        }
    } catch(e) { console.error(e); }
}

function addToCart() { checkSku(); } // Trigger manual

function renderCart() {
    const tbody = document.getElementById('cart_body');
    tbody.innerHTML = '';
    cart.forEach((item, idx) => {
        tbody.innerHTML += `
            <tr class="border-b">
                <td class="p-2">${item.name}<br><small>${item.sku}</small></td>
                <td class="p-2 text-center font-bold">${item.qty}</td>
                <td class="p-2 font-mono text-xs">${item.sns.join(', ')}</td>
                <td class="p-2 text-center"><button type="button" onclick="cart.splice(${idx},1);renderCart()" class="text-red-500"><i class="fas fa-trash"></i></button></td>
            </tr>
        `;
    });
    document.getElementById('cart_json').value = JSON.stringify(cart);
}

function validateCart() {
    if(cart.length === 0) { alert("Keranjang kosong!"); return false; }
    return confirm("Simpan transaksi?");
}

async function generateReference() {
    const d = new Date();
    const res = await fetch('api.php?action=get_next_trx_number&type=OUT');
    const data = await res.json();
    const ref = `${APP_REF}/OUT/${d.getDate()}/${d.getMonth()+1}/${d.getFullYear()}-${data.next_no}`;
    document.getElementById('reference_input').value = ref;
}

// Scanner Helpers (Simpel)
function activateUSB() { document.getElementById('sku_input').focus(); }
function initCamera() { alert("Fitur kamera sama seperti halaman Barang Masuk."); } // Placeholder to save space

document.addEventListener('DOMContentLoaded', generateReference);
</script>