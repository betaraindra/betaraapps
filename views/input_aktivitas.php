<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- HANDLE SAVE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_activity'])) {
    validate_csrf();
    
    $date = $_POST['date'];
    $wh_id = $_POST['warehouse_id'];
    $main_desc = trim($_POST['description']);
    $ref_custom = trim($_POST['reference']);
    $cart = json_decode($_POST['cart_json'], true);

    if (empty($wh_id) || empty($cart)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Data tidak lengkap. Pilih gudang dan minimal 1 barang.'];
    } else {
        $pdo->beginTransaction();
        try {
            // Ambil Nama Gudang untuk Log
            $wh_name = $pdo->query("SELECT name FROM warehouses WHERE id = $wh_id")->fetchColumn();
            
            // Generate Reference jika kosong
            if(empty($ref_custom)) {
                $ref_custom = "ACT/" . date('ymd') . "/" . rand(100,999);
            }

            $items_saved = 0;

            foreach ($cart as $item) {
                // 1. Validasi Barang & Ambil Harga Beli (HPP)
                $stmt = $pdo->prepare("SELECT id, name, buy_price, stock, has_serial_number FROM products WHERE sku = ?");
                $stmt->execute([$item['sku']]);
                $prod = $stmt->fetch();
                
                if (!$prod) throw new Exception("Barang SKU {$item['sku']} tidak ditemukan.");

                $qty = (int)$item['qty'];
                $sns = $item['sns'] ?? [];
                
                // 2. Validasi SN (1 Qty = 1 SN)
                if ($prod['has_serial_number'] == 1) {
                    if (count($sns) !== $qty) {
                        throw new Exception("Error {$prod['name']}: Jumlah Qty ($qty) harus sama dengan jumlah SN yang dipilih (" . count($sns) . ").");
                    }
                    
                    // Cek status SN apakah masih AVAILABLE di gudang ini (Anti Race Condition)
                    $placeholders = implode(',', array_fill(0, count($sns), '?'));
                    // Pastikan hanya cek SN yang statusnya AVAILABLE
                    $chkSn = $pdo->prepare("SELECT COUNT(*) FROM product_serials WHERE product_id = ? AND warehouse_id = ? AND status = 'AVAILABLE' AND serial_number IN ($placeholders)");
                    $paramsSn = [$prod['id'], $wh_id, ...$sns];
                    $chkSn->execute($paramsSn);
                    $foundAvailable = $chkSn->fetchColumn();
                    
                    if ($foundAvailable < count($sns)) {
                        throw new Exception("Gagal: Salah satu SN untuk {$prod['name']} sudah tidak tersedia (Sold/Moved). Silakan refresh halaman.");
                    }
                }

                // 3. Catat Transaksi Inventori (OUT)
                $item_note = "Aktivitas: $main_desc";
                if(!empty($item['notes'])) $item_note .= " (" . $item['notes'] . ")";
                // Tambahkan list SN ke notes transaksi agar mudah dibaca di history
                if(!empty($sns)) $item_note .= " [SN: " . implode(', ', $sns) . "]";

                $stmtTrx = $pdo->prepare("INSERT INTO inventory_transactions (date, type, product_id, warehouse_id, quantity, reference, notes, user_id) VALUES (?, 'OUT', ?, ?, ?, ?, ?, ?)");
                $stmtTrx->execute([$date, $prod['id'], $wh_id, $qty, $ref_custom, $item_note, $_SESSION['user_id']]);
                $trx_id = $pdo->lastInsertId();

                // 4. Update Stok Fisik
                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")->execute([$qty, $prod['id']]);

                // 5. Update Status SN menjadi SOLD
                if (!empty($sns)) {
                    $updSn = $pdo->prepare("UPDATE product_serials SET status='SOLD', out_transaction_id=? WHERE product_id=? AND serial_number=? AND warehouse_id=?");
                    foreach ($sns as $sn) {
                        $updSn->execute([$trx_id, $prod['id'], $sn, $wh_id]);
                    }
                }

                $items_saved++;
            }

            // 6. UPDATE: TIDAK MENCATAT KE KEUANGAN (Hanya Logistik)
            // Sesuai permintaan: "hasil input aktivitas tidak tercatat di keuangan hanya catatan alokasi material di wilayah"
            $msg_finance = " (Logistik Only)";

            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"Berhasil menyimpan aktivitas ($items_saved item). Stok berkurang & SN ditandai Terpakai." . $msg_finance];
            echo "<script>window.location='?page=input_aktivitas';</script>";
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>$e->getMessage()];
        }
    }
}

// Data Master
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
// Load produk sederhana untuk autocomplete awal
$products_list = $pdo->query("SELECT sku, name FROM products ORDER BY name ASC")->fetchAll();

// --- FETCH HISTORY AKTIVITAS TERAKHIR ---
$history = $pdo->query("
    SELECT i.id, i.date, i.reference, i.quantity, i.notes, 
           p.name as prod_name, p.sku, 
           w.name as wh_name, 
           u.username
    FROM inventory_transactions i
    JOIN products p ON i.product_id = p.id
    JOIN warehouses w ON i.warehouse_id = w.id
    LEFT JOIN users u ON i.user_id = u.id
    WHERE i.type = 'OUT' AND (i.notes LIKE 'Aktivitas:%' OR i.notes LIKE '%[PEMAKAIAN]%')
    ORDER BY i.created_at DESC, i.date DESC
    LIMIT 20
")->fetchAll();
?>

<div class="max-w-5xl mx-auto space-y-6">
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-purple-600">
        <div class="flex justify-between items-start">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-network-wired text-purple-600"></i> Input Aktivitas Wilayah
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Catat pemakaian material (Modem, Kabel, dll) untuk instalasi atau maintenance.
                    <br><span class="text-red-500 font-bold text-xs">* SN akan ditandai SOLD (Terpakai). Transaksi ini HANYA mencatat mutasi stok (Logistik), tidak masuk Laporan Keuangan.</span>
                </p>
            </div>
        </div>
    </div>

    <form method="POST" id="form_activity" onsubmit="return validateCart()">
        <?= csrf_field() ?>
        <input type="hidden" name="save_activity" value="1">
        <input type="hidden" name="cart_json" id="cart_json">

        <!-- HEADER INFO -->
        <div class="bg-white p-6 rounded-lg shadow mb-6">
            <h3 class="font-bold text-gray-700 border-b pb-2 mb-4">Informasi Aktivitas</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Tanggal Pengerjaan</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded text-sm bg-gray-50">
                </div>
                <div>
                    <label class="block text-xs font-bold text-purple-700 mb-1">Lokasi Gudang / Wilayah (Sumber Stok)</label>
                    <select name="warehouse_id" id="warehouse_id" class="w-full border p-2 rounded text-sm bg-white font-bold" required onchange="resetCart()">
                        <option value="">-- Pilih Wilayah --</option>
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Referensi / ID Pelanggan (Opsional)</label>
                    <input type="text" name="reference" class="w-full border p-2 rounded text-sm uppercase" placeholder="Contoh: PLG-001">
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Deskripsi Pekerjaan <span class="text-red-500">*</span></label>
                    <input type="text" name="description" class="w-full border p-2 rounded text-sm" placeholder="Contoh: Pasang Baru Bpk. Budi - Paket 50Mbps" required>
                </div>
            </div>
        </div>

        <!-- INPUT BARANG -->
        <div class="bg-purple-50 p-6 rounded-lg shadow border border-purple-100 mb-6 relative">
            <div id="overlay_block" class="absolute inset-0 bg-white/80 z-10 flex items-center justify-center rounded-lg">
                <div class="text-center">
                    <i class="fas fa-warehouse text-4xl text-gray-300 mb-2"></i>
                    <p class="font-bold text-gray-500">Pilih Wilayah Terlebih Dahulu</p>
                </div>
            </div>

            <h3 class="font-bold text-purple-800 border-b border-purple-200 pb-2 mb-4 flex justify-between">
                <span>Input Material / Barang</span>
                <span class="text-xs font-normal text-gray-600 bg-white px-2 py-1 rounded border">Mode: 1 Qty = 1 SN</span>
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <!-- Select Barang -->
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang</label>
                    <select id="product_select" class="w-full p-2 rounded text-sm">
                        <option value="">-- Cari Nama / SKU --</option>
                        <?php foreach($products_list as $p): ?>
                            <option value="<?= $p['sku'] ?>"><?= $p['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Info Stok -->
                <div class="bg-white p-2 rounded border border-gray-200 flex justify-between items-center px-4">
                    <div class="text-xs text-gray-500">
                        <span class="block">SKU: <b id="info_sku">-</b></span>
                        <span class="block">Satuan: <b id="info_unit">-</b></span>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] text-gray-400 uppercase font-bold">Available SN Stock</span>
                        <div class="text-2xl font-bold text-blue-600" id="info_stock">0</div>
                    </div>
                </div>
            </div>

            <!-- Area SN & Qty -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-start mb-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-600 mb-1">
                        Pilih Serial Number (SN) 
                        <span class="text-purple-600 text-[10px] font-normal italic ml-1">(Hanya SN Available yang muncul)</span>
                    </label>
                    <select id="sn_select" class="w-full border rounded" multiple="multiple" style="width: 100%" disabled></select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Qty Pemakaian</label>
                    <input type="number" id="inp_qty" class="w-full border p-2 rounded text-center font-bold bg-gray-100 text-purple-700" readonly value="0">
                    <p class="text-[10px] text-gray-400 mt-1">*Otomatis mengikuti jumlah SN yang dipilih</p>
                </div>
            </div>

            <div class="flex gap-4 items-end">
                <div class="flex-1">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Catatan Item (Opsional)</label>
                    <input type="text" id="inp_notes" class="w-full border p-2 rounded text-sm" placeholder="Keterangan khusus barang ini...">
                </div>
                <button type="button" onclick="addToCart()" class="bg-purple-600 text-white px-6 py-2 rounded font-bold hover:bg-purple-700 shadow flex items-center gap-2">
                    <i class="fas fa-plus"></i> Tambah
                </button>
            </div>
        </div>

        <!-- TABEL CART -->
        <div class="bg-white rounded-lg shadow overflow-hidden border border-gray-200 mb-6">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-100 text-gray-700 border-b">
                    <tr>
                        <th class="p-3">Nama Barang</th>
                        <th class="p-3 text-center w-20">Qty</th>
                        <th class="p-3">Daftar SN (Akan Digunakan)</th>
                        <th class="p-3">Catatan</th>
                        <th class="p-3 text-center w-16">Hapus</th>
                    </tr>
                </thead>
                <tbody id="cart_body" class="divide-y">
                    <tr><td colspan="5" class="p-6 text-center text-gray-400 italic">Belum ada barang ditambahkan.</td></tr>
                </tbody>
            </table>
        </div>

        <button type="submit" id="btn_submit" class="w-full bg-green-600 text-white py-4 rounded-lg font-bold hover:bg-green-700 shadow text-lg disabled:bg-gray-400 disabled:cursor-not-allowed transition" disabled>
            <i class="fas fa-save mr-2"></i> Simpan Aktivitas & Update Stok
        </button>
    </form>

    <!-- RIWAYAT INPUT TERAKHIR (NEW) -->
    <div class="bg-white p-6 rounded-lg shadow border-t-4 border-gray-500">
        <h3 class="font-bold text-gray-700 border-b pb-2 mb-4 flex items-center gap-2">
            <i class="fas fa-history text-gray-500"></i> Histori Input Aktivitas (20 Terakhir)
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border-b">Tanggal</th>
                        <th class="p-3 border-b">Ref</th>
                        <th class="p-3 border-b">Lokasi</th>
                        <th class="p-3 border-b">Barang</th>
                        <th class="p-3 border-b text-center">Qty</th>
                        <th class="p-3 border-b">Detail / SN</th>
                        <th class="p-3 border-b">User</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($history as $row): 
                        // Clean note for display
                        $clean_note = trim(str_replace(['Aktivitas:', '[PEMAKAIAN]'], '', $row['notes']));
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 whitespace-nowrap text-xs"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
                        <td class="p-3 font-mono text-xs text-blue-600 font-bold"><?= htmlspecialchars($row['reference']) ?></td>
                        <td class="p-3 text-xs"><?= htmlspecialchars($row['wh_name']) ?></td>
                        <td class="p-3">
                            <div class="font-bold text-gray-800 text-xs"><?= htmlspecialchars($row['prod_name']) ?></div>
                            <div class="text-[10px] text-gray-500"><?= htmlspecialchars($row['sku']) ?></div>
                        </td>
                        <td class="p-3 text-center font-bold text-gray-700"><?= $row['quantity'] ?></td>
                        <td class="p-3 text-xs text-gray-600 max-w-sm italic break-words">
                            <?= htmlspecialchars($clean_note) ?>
                        </td>
                        <td class="p-3 text-xs text-gray-500"><?= htmlspecialchars($row['username']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($history)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-gray-400 italic">Belum ada riwayat input.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- JAVASCRIPT LOGIC -->
<script>
let cart = [];
const productSelect = $('#product_select');
const snSelect = $('#sn_select');

$(document).ready(function() {
    productSelect.select2({ placeholder: 'Pilih Barang...', width: '100%' });
    snSelect.select2({ placeholder: 'Cari/Pilih SN...', width: '100%', tags: false }); // Tags false = User must select from list (Available only)
});

// 1. GUDANG CHANGE -> RESET UI
$('#warehouse_id').on('change', function() {
    const val = $(this).val();
    if(val) {
        $('#overlay_block').addClass('hidden');
    } else {
        $('#overlay_block').removeClass('hidden');
    }
    resetCart();
    resetInput();
});

// 2. PRODUCT CHANGE -> LOAD DETAILS & SN
productSelect.on('select2:select', async function (e) {
    const sku = $(this).val();
    const whId = $('#warehouse_id').val();
    if(!sku || !whId) return;

    // Reset SN Select
    snSelect.empty().trigger('change').prop('disabled', true);
    
    try {
        // A. Get Product Details
        const resProd = await fetch(`api.php?action=get_product_by_sku&sku=${encodeURIComponent(sku)}`);
        const prodData = await resProd.json();
        
        if(prodData.id) {
            document.getElementById('info_sku').innerText = prodData.sku;
            document.getElementById('info_unit').innerText = prodData.unit;
            
            $('#inp_qty').data('prod-id', prodData.id);
            $('#inp_qty').data('prod-name', prodData.name);
            $('#inp_qty').data('has-sn', 1); 

            // B. Get Available SNs in this Warehouse
            const resSn = await fetch(`api.php?action=get_available_sns&product_id=${prodData.id}&warehouse_id=${whId}`);
            const sns = await resSn.json();
            
            // C. Update Stock Display based on Count SN
            document.getElementById('info_stock').innerText = sns.length;
            
            // D. Populate SN Select
            if(sns.length > 0) {
                sns.forEach(sn => {
                    const option = new Option(sn, sn, false, false);
                    snSelect.append(option);
                });
                snSelect.prop('disabled', false).trigger('change');
                document.getElementById('info_stock').classList.remove('text-red-600');
                document.getElementById('info_stock').classList.add('text-blue-600');
            } else {
                snSelect.prop('disabled', true);
                document.getElementById('info_stock').classList.add('text-red-600');
                document.getElementById('info_stock').classList.remove('text-blue-600');
            }
        }
    } catch(err) {
        console.error(err);
        alert("Gagal memuat data barang.");
    }
});

// 3. SN SELECTION -> UPDATE QTY
snSelect.on('change', function() {
    const selected = $(this).val();
    const qty = selected ? selected.length : 0;
    document.getElementById('inp_qty').value = qty;
});

// 4. ADD TO CART
function addToCart() {
    const sku = productSelect.val();
    const name = $('#inp_qty').data('prod-name');
    const qty = parseInt(document.getElementById('inp_qty').value);
    const sns = snSelect.val(); // Array of strings
    const notes = document.getElementById('inp_notes').value;

    if(!sku || qty <= 0) {
        alert("Pilih barang dan minimal 1 Serial Number!");
        return;
    }

    // Validasi 1 Qty = 1 SN
    if(sns.length !== qty) {
        alert("Jumlah SN yang dipilih tidak sama dengan Qty!");
        return;
    }

    // Cek duplikat di cart
    const exists = cart.find(i => i.sku === sku);
    if(exists) {
        alert("Barang ini sudah ada di daftar. Hapus dulu jika ingin mengubah.");
        return;
    }

    cart.push({ sku, name, qty, sns, notes });
    renderCart();
    resetInput();
}

// 5. RENDER CART
function renderCart() {
    const tbody = document.getElementById('cart_body');
    tbody.innerHTML = '';

    if(cart.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="p-6 text-center text-gray-400 italic">Belum ada barang ditambahkan.</td></tr>';
        document.getElementById('btn_submit').disabled = true;
        return;
    }

    document.getElementById('btn_submit').disabled = false;

    cart.forEach((item, idx) => {
        // Tampilkan SN sebagai chips/badges
        const snHtml = item.sns.map(s => `<span class="inline-block bg-purple-100 text-purple-800 text-[10px] px-1 rounded border border-purple-200 mr-1 mb-1 font-mono">${s}</span>`).join('');

        const tr = document.createElement('tr');
        tr.className = 'hover:bg-gray-50 border-b';
        tr.innerHTML = `
            <td class="p-3 font-medium text-gray-800">
                ${item.name}
                <div class="text-xs text-gray-400 font-mono">${item.sku}</div>
            </td>
            <td class="p-3 text-center font-bold text-blue-600 text-lg">${item.qty}</td>
            <td class="p-3 max-w-xs">${snHtml}</td>
            <td class="p-3 text-sm text-gray-500 italic">${item.notes || '-'}</td>
            <td class="p-3 text-center">
                <button type="button" onclick="removeFromCart(${idx})" class="text-red-500 hover:text-red-700 bg-red-50 p-2 rounded transition">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('cart_json').value = JSON.stringify(cart);
}

function removeFromCart(idx) {
    cart.splice(idx, 1);
    renderCart();
}

function resetInput() {
    productSelect.val(null).trigger('change');
    snSelect.empty().trigger('change').prop('disabled', true);
    document.getElementById('inp_qty').value = 0;
    document.getElementById('inp_notes').value = '';
    document.getElementById('info_sku').innerText = '-';
    document.getElementById('info_unit').innerText = '-';
    document.getElementById('info_stock').innerText = '0';
    document.getElementById('info_stock').classList.remove('text-red-600');
}

function resetCart() {
    cart = [];
    renderCart();
}

function validateCart() {
    if(cart.length === 0) {
        alert("Daftar aktivitas masih kosong!");
        return false;
    }
    return confirm("Pastikan data sudah benar. Stok akan berkurang dan SN akan ditandai TERPAKAI (SOLD). Lanjutkan?");
}
</script>