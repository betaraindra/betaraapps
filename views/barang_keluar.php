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
                // Karena logic baru 1 item = 1 row = 1 SN, maka array sns pasti isi 1
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
                            // Cek apakah SN ini ada tapi tidak available
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

                    // D. Catat Transaksi Keuangan (Opsional: Nilai Transfer antar Wilayah)
                    if ($total_buy_val > 0) {
                        $acc_out_id = $pdo->query("SELECT id FROM accounts WHERE code = '2009'")->fetchColumn();
                        if ($acc_out_id) {
                            $desc_trf_out = "Mutasi Keluar: {$prod['name']} ke $dest_wh_name{$wilayah_tag_origin}";
                            $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                                ->execute([$date, $acc_out_id, $total_buy_val, $desc_trf_out, $_SESSION['user_id']]);
                        }

                        $acc_in_id = $pdo->query("SELECT id FROM accounts WHERE code = '1004'")->fetchColumn();
                        if ($acc_in_id) {
                            $desc_trf_in = "Mutasi Masuk: {$prod['name']} dari $wh_name{$wilayah_tag_dest}";
                            $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'INCOME', ?, ?, ?, ?)")
                                ->execute([$date, $acc_in_id, $total_buy_val, $desc_trf_in, $_SESSION['user_id']]);
                        }
                    }
                }

                // 7. LOGIKA PENJUALAN (EXTERNAL)
                elseif ($out_type === 'EXTERNAL') {
                    if ($total_sell_val > 0) {
                        $acc_rev_id = $pdo->query("SELECT id FROM accounts WHERE code = '1001'")->fetchColumn();
                        if ($acc_rev_id) {
                            $desc_rev = "[PENJUALAN] {$prod['name']} ({$item['qty']} unit). Ref: $ref{$wilayah_tag_origin}";
                            $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'INCOME', ?, ?, ?, ?)")
                                ->execute([$date, $acc_rev_id, $total_sell_val, $desc_rev, $_SESSION['user_id']]);
                        }
                    }
                }
                
                // 8. LOGIKA PEMAKAIAN INTERNAL
                elseif ($out_type === 'INTERNAL' && empty($dest_wh_id)) {
                    if ($total_buy_val > 0) {
                        $acc_op_id = $pdo->query("SELECT id FROM accounts WHERE code = '2002'")->fetchColumn(); // Beban Ops
                        if ($acc_op_id) {
                            $desc_use = "[PEMAKAIAN] {$prod['name']} ({$item['qty']} unit). Ref: $ref{$wilayah_tag_origin}";
                            $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                                ->execute([$date, $acc_op_id, $total_buy_val, $desc_use, $_SESSION['user_id']]);
                        }
                    }
                }

                $count++;
            }
            
            $pdo->commit();
            
            $msg = "$count item berhasil diproses.";
            if ($out_type === 'INTERNAL' && $dest_wh_id) $msg .= " Mutasi stok dan keuangan wilayah tercatat.";
            elseif ($out_type === 'EXTERNAL') $msg .= " Penjualan (Omzet) tercatat.";
            
            $_SESSION['flash'] = ['type'=>'success', 'message'=>$msg];
            echo "<script>window.location='?page=barang_keluar';</script>";
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: '.$e->getMessage()];
        }
    }
}

$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
$recent_trx = $pdo->query("SELECT i.*, p.name as prod_name, p.sku, w.name as wh_name FROM inventory_transactions i JOIN products p ON i.product_id = p.id JOIN warehouses w ON i.warehouse_id = w.id WHERE i.type = 'OUT' ORDER BY i.created_at DESC LIMIT 10")->fetchAll();
$app_ref_prefix = strtoupper(str_replace(' ', '', $settings['app_name'] ?? 'SIKI'));
?>

<!-- UI HALAMAN -->
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 border-b pb-4 gap-4">
            <h3 class="font-bold text-xl text-red-700 flex items-center gap-2">
                <i class="fas fa-dolly"></i> Input Barang Keluar / Mutasi / Penjualan
            </h3>
            
            <div class="flex gap-2 flex-wrap">
                <button type="button" onclick="activateUSB()" class="bg-gray-700 text-white px-3 py-2 rounded shadow hover:bg-gray-800 transition text-sm flex items-center gap-2" title="Scanner USB / Keyboard">
                    <i class="fas fa-keyboard"></i> USB Mode
                </button>
                <input type="file" id="scan_image_file" accept="image/*" capture="environment" class="hidden" onchange="handleFileScan(this)">
                <button type="button" onclick="document.getElementById('scan_image_file').click()" class="bg-purple-600 text-white px-3 py-2 rounded shadow hover:bg-purple-700 transition text-sm flex items-center gap-2" title="Upload Foto Barcode">
                    <i class="fas fa-camera"></i> Foto Scan
                </button>
                <button type="button" onclick="initCamera()" class="bg-indigo-600 text-white px-3 py-2 rounded shadow hover:bg-indigo-700 transition text-sm flex items-center gap-2" title="Kamera Webcam / HP">
                    <i class="fas fa-video"></i> Live Scan
                </button>
            </div>
        </div>

        <!-- Scanner & Form Section -->
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
        
        <form method="POST" id="form_transaction" onsubmit="return validateCart()">
            <?= csrf_field() ?>
            <input type="hidden" name="save_transaction" value="1">
            <input type="hidden" name="cart_json" id="cart_json">

            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Tanggal</label>
                        <input type="date" name="date" id="trx_date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">No. Referensi</label>
                        <div class="flex gap-1">
                            <input type="text" name="reference" id="reference_input" class="w-full border p-2 rounded text-sm uppercase font-bold" required>
                            <button type="button" id="btn_auto_ref" onclick="generateReference()" class="bg-gray-200 px-3 rounded text-gray-600 hover:bg-gray-300"><i class="fas fa-magic"></i></button>
                        </div>
                    </div>
                    
                    <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4 border-t pt-4 mt-2">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Dari Gudang Asal <span class="text-red-500">*</span></label>
                            <select name="warehouse_id" id="warehouse_id" class="w-full border p-2 rounded text-sm bg-white font-bold text-gray-800 transition-colors duration-300" onchange="toggleDestWarehouse()" required>
                                <option value="" selected disabled>-- Pilih Gudang Asal --</option>
                                <?php foreach($warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-[10px] text-gray-500 mt-1 italic">
                                * Otomatis terpilih jika barang discan (berdasarkan mutasi masuk terakhir).
                            </p>
                        </div>
                        
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Jenis Transaksi <span class="text-red-500">*</span></label>
                            <select name="out_type" id="out_type" class="w-full border p-2 rounded text-sm bg-white font-bold text-blue-800" onchange="toggleDestWarehouse()" required>
                                <option value="" selected disabled>-- Pilih Jenis Transaksi --</option>
                                <option value="EXTERNAL">PENJUALAN (Catat Omzet Saja)</option>
                                <option value="INTERNAL">MUTASI / TRANSFER (Pindah Gudang)</option>
                            </select>
                            <p class="text-[10px] text-gray-500 mt-1" id="type_hint">*Silakan pilih jenis transaksi.</p>
                        </div>
                    </div>

                    <div id="dest_wh_container" class="md:col-span-2 hidden bg-orange-100 p-3 rounded border border-orange-200">
                        <label class="block text-xs font-bold text-orange-800 mb-1">Ke Gudang Tujuan</label>
                        <select name="dest_warehouse_id" class="w-full border p-2 rounded text-sm bg-white">
                            <option value="">-- Pilih Gudang Tujuan --</option>
                            <?php foreach($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 mb-6">
                <h4 class="font-bold text-blue-800 mb-3 text-sm border-b border-blue-200 pb-1">Input Barang</h4>
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                    <div class="md:col-span-4">
                        <label class="block text-xs font-bold text-gray-600 mb-1">SKU Barcode / SN</label>
                        <div class="flex gap-1">
                            <input type="text" id="sku_input" class="w-full border-2 border-blue-500 p-2 rounded font-mono font-bold text-lg uppercase" placeholder="SCAN..." onchange="checkSku()" onkeydown="if(event.key === 'Enter'){ checkSku(); event.preventDefault(); }">
                            <button type="button" onclick="checkSku()" class="bg-blue-600 text-white px-3 rounded hover:bg-blue-700"><i class="fas fa-search"></i></button>
                        </div>
                        <span id="sku_status" class="text-[10px] block mt-1 font-bold"></span>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Qty</label>
                        <!-- Qty Readonly 1 karena 1 SN = 1 Stock -->
                        <input type="number" id="qty_input" class="w-full border p-2 rounded font-bold text-center bg-gray-100 cursor-not-allowed" value="1" readonly title="1 SN = 1 Stok">
                    </div>

                    <!-- SINGLE SN INPUT FIELD -->
                    <div class="md:col-span-4" id="sn_single_container">
                        <label class="block text-xs font-bold text-purple-700 mb-1">Serial Number (SN)</label>
                        <input type="text" id="sn_single_input" class="w-full border-2 border-purple-400 p-2 rounded font-mono font-bold uppercase" placeholder="Scan/Ketik SN" onkeydown="if(event.key === 'Enter'){ addToCart(); event.preventDefault(); }">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Catatan</label>
                        <input type="text" id="notes_input" class="w-full border p-2 rounded" placeholder="Opsional..." onkeydown="if(event.key === 'Enter'){ addToCart(); event.preventDefault(); }">
                    </div>

                    <div class="md:col-span-12 mt-2">
                        <button type="button" onclick="addToCart()" class="w-full bg-orange-500 text-white py-2 rounded font-bold hover:bg-orange-600 shadow flex justify-center items-center gap-2">
                            <i class="fas fa-plus"></i> Tambah ke Keranjang
                        </button>
                    </div>
                </div>

                <input type="hidden" id="h_name"><input type="hidden" id="h_stock">
            </div>

            <div class="mb-6">
                <h4 class="font-bold text-gray-800 mb-2">Keranjang <span id="cart_count" class="bg-red-600 text-white text-xs px-2 rounded-full">0</span></h4>
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
            
            <button type="submit" id="btn_process" class="w-full bg-red-600 text-white py-3 rounded-lg font-bold text-lg hover:bg-red-700 shadow-lg disabled:bg-gray-400 flex justify-center items-center gap-2">
                <i class="fas fa-paper-plane"></i> Proses Transaksi
            </button>
        </form>
    </div>

    <!-- TABEL RIWAYAT -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="font-bold text-gray-800 mb-4 border-b pb-2">Riwayat Terakhir</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-700">
                    <tr><th class="p-3">Tanggal</th><th class="p-3">Ref</th><th class="p-3">Barang</th><th class="p-3 text-right">Qty</th><th class="p-3 text-center">Gudang</th><th class="p-3 text-center">Aksi</th></tr>
                </thead>
                <tbody class="divide-y">
                    <?php if(empty($recent_trx)): ?><tr><td colspan="6" class="p-4 text-center text-gray-400">Kosong.</td></tr><?php else: ?>
                    <?php foreach($recent_trx as $trx): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 whitespace-nowrap"><?= date('d/m/Y', strtotime($trx['date'])) ?></td>
                        <td class="p-3 font-mono text-xs font-bold text-blue-600"><?= h($trx['reference']) ?></td>
                        <td class="p-3"><div class="font-bold"><?= h($trx['prod_name']) ?></div><div class="text-xs text-gray-500"><?= h($trx['sku']) ?></div></td>
                        <td class="p-3 text-right font-bold text-red-700">-<?= number_format($trx['quantity']) ?></td>
                        <td class="p-3 text-center text-xs"><?= h($trx['wh_name']) ?></td>
                        <td class="p-3 text-center">
                            <form method="POST" onsubmit="return confirm('Batal transaksi? Stok akan kembali.')" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="cancel_id" value="<?= $trx['id'] ?>">
                                <button class="text-red-500 hover:text-red-700 p-1 font-bold text-xs"><i class="fas fa-undo"></i> Batal</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let html5QrCode;
let audioCtx = null;
let isFlashOn = false;
let cart = [];
const APP_NAME_PREFIX = "<?= $app_ref_prefix ?>";

function toggleDestWarehouse() {
    const type = document.getElementById('out_type').value;
    const destContainer = document.getElementById('dest_wh_container');
    const hint = document.getElementById('type_hint');
    
    if (type === 'INTERNAL') {
        destContainer.classList.remove('hidden');
        hint.innerHTML = "*Mutasi stok antar gudang.";
    } else if (type === 'EXTERNAL') {
        destContainer.classList.add('hidden');
        hint.innerHTML = "*Catat Penjualan (Pemasukan).";
        document.querySelector('select[name="dest_warehouse_id"]').value = "";
    } else {
        destContainer.classList.add('hidden');
        hint.innerHTML = "*Silakan pilih jenis transaksi.";
    }
}

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
    const snInput = document.getElementById('sn_single_input');
    
    stat.innerHTML = 'Searching...'; 
    try { 
        const r = await fetch(`api.php?action=get_product_by_sku&sku=${encodeURIComponent(s)}`); 
        const d = await r.json(); 
        
        if(d && d.id) { 
            stat.innerHTML = `<span class="text-green-600">Found: ${d.name} (${d.stock})</span>`; 
            n.value = d.name; 
            st.value = d.stock; 
            
            // Cek apakah scan SN langsung?
            if (d.scanned_sn) {
                // Auto Fill SN Field
                snInput.value = d.scanned_sn;
                // Auto Add (Since 1 SN = 1 Qty)
                setTimeout(() => addToCart(), 200);
            } else {
                snInput.value = '';
                snInput.focus(); 
            }

            // --- AUTO SELECT GUDANG ASAL ---
            if(d.last_warehouse_id) {
                const whSelect = document.getElementById('warehouse_id');
                // Ubah value
                whSelect.value = d.last_warehouse_id;
                // Visual effect supaya user sadar
                whSelect.classList.remove('bg-white');
                whSelect.classList.add('bg-green-100');
                setTimeout(() => {
                    whSelect.classList.remove('bg-green-100');
                    whSelect.classList.add('bg-white');
                }, 1000);
            }
        } else { 
            stat.innerHTML = '<span class="text-red-600">Not Found!</span>'; 
            n.value=''; 
            st.value=0; 
            snInput.value='';
        } 
    } catch(e){ 
        stat.innerText = "Error API"; 
    } 
}

function addToCart() { 
    const s = document.getElementById('sku_input').value.trim(); 
    const n = document.getElementById('h_name').value; 
    const st = parseFloat(document.getElementById('h_stock').value||0); 
    const q = 1; // FORCE 1 SN = 1 QTY
    const sn = document.getElementById('sn_single_input').value.trim();
    const nt = document.getElementById('notes_input').value; 
    
    if(!n) return alert("Scan item first"); 
    if(!sn) return alert("Serial Number (SN) wajib diisi!");
    if(q > st) return alert("Stok kurang!"); 
    
    // Cek Duplikat SN di Cart
    const exists = cart.some(item => item.sns && item.sns.includes(sn));
    if(exists) return alert("Serial Number " + sn + " sudah ada di keranjang!");

    // Add as NEW ROW (Don't merge)
    cart.push({
        sku: s, 
        name: n, 
        qty: 1, 
        notes: nt, 
        sns: [sn], 
        has_sn: true
    }); 
    
    renderCart(); 
    
    // Reset Form
    document.getElementById('sku_input').value=''; 
    document.getElementById('h_name').value=''; 
    document.getElementById('h_stock').value=''; 
    document.getElementById('sn_single_input').value='';
    document.getElementById('notes_input').value=''; 
    document.getElementById('sku_status').innerText=''; 
    
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
            if(i.has_sn && i.sns.length > 0) {
                snHtml = `<span class="bg-purple-100 text-purple-800 text-[10px] px-1 rounded font-bold">${i.sns[0]}</span>`;
            }
            
            const r = document.createElement('tr'); 
            r.innerHTML = `
                <td class="p-3 font-mono">${i.sku}</td>
                <td class="p-3">${i.name}</td>
                <td class="p-3 text-center font-bold">1</td>
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
    const type = document.getElementById('out_type').value;
    if(cart.length===0) { alert('Keranjang kosong'); return false; }
    if(!wh) { alert('Pilih Gudang Asal!'); return false; }
    if(!type) { alert('Pilih Jenis Transaksi!'); return false; }
    return confirm("Proses transaksi?"); 
}
async function generateReference() { const b = document.getElementById('btn_auto_ref'); if(b) b.disabled=true; try { const r = await fetch('api.php?action=get_next_trx_number&type=OUT'); const d = await r.json(); const n = d.next_no.toString().padStart(3,'0'); const dt = new Date(); document.getElementById('reference_input').value = `${APP_NAME_PREFIX}/OUT/${dt.getDate()}/${dt.getMonth()+1}/${dt.getFullYear()}-${n}`; } catch(e){} if(b) b.disabled=false; }

document.addEventListener('DOMContentLoaded', () => { generateReference(); toggleDestWarehouse(); });
</script>