<?php
// Cek mode Edit atau Tambah Baru
$is_edit = isset($edit_item) && !empty($edit_item);
$form_title = $is_edit ? "Edit Barang" : "Tambah Barang Baru";
$btn_text = $is_edit ? "Simpan Perubahan" : "Simpan Barang Baru";
$btn_color = $is_edit ? "bg-yellow-600 hover:bg-yellow-700" : "bg-blue-600 hover:bg-blue-700";
$bg_header = $is_edit ? "bg-yellow-50 text-yellow-800" : "bg-blue-50 text-blue-800";

// Default Values
$val_sku = $edit_item['sku'] ?? '';
$val_name = $edit_item['name'] ?? '';
$val_cat = $edit_item['category'] ?? 'Umum';
$val_unit = $edit_item['unit'] ?? 'Pcs';
$val_buy = $edit_item['buy_price'] ?? 0;
$val_sell = $edit_item['sell_price'] ?? 0;
$val_stock = $edit_item['stock'] ?? 0;
$val_notes = $edit_item['notes'] ?? '';
$val_img = $edit_item['image_url'] ?? '';
?>

<div class="bg-white rounded-lg shadow border border-gray-200 sticky top-4">
    <!-- Header Form -->
    <div class="p-4 border-b <?= $bg_header ?> flex justify-between items-center rounded-t-lg">
        <h3 class="font-bold text-lg"><i class="fas <?= $is_edit ? 'fa-edit' : 'fa-plus-circle' ?>"></i> <?= $form_title ?></h3>
        <?php if($is_edit): ?>
            <a href="?page=data_barang" class="text-xs bg-white border border-gray-300 px-2 py-1 rounded hover:bg-gray-100 text-gray-600">
                <i class="fas fa-times"></i> Batal
            </a>
        <?php endif; ?>
    </div>

    <form method="POST" enctype="multipart/form-data" class="p-4 space-y-4 text-sm" onsubmit="return validateProductForm()">
        <?= csrf_field() ?>
        <input type="hidden" name="save_product" value="1">
        <?php if($is_edit): ?>
            <input type="hidden" name="edit_id" value="<?= $edit_item['id'] ?>">
        <?php endif; ?>

        <!-- GAMBAR -->
        <div class="flex items-center gap-4">
            <div class="w-20 h-20 bg-gray-100 border border-gray-300 rounded flex items-center justify-center overflow-hidden relative group">
                <img id="img_preview" src="<?= !empty($val_img) ? $val_img : 'https://via.placeholder.com/80x80?text=No+Img' ?>" class="w-full h-full object-cover">
                <?php if($is_edit && !empty($val_img)): ?>
                    <div class="absolute inset-0 bg-black/50 hidden group-hover:flex items-center justify-center">
                        <label class="cursor-pointer text-white text-xs text-center">
                            <input type="checkbox" name="delete_img" value="1" class="mr-1"> Hapus
                        </label>
                    </div>
                <?php endif; ?>
            </div>
            <div class="flex-1">
                <label class="block text-xs font-bold text-gray-600 mb-1">Upload Foto</label>
                <input type="file" name="image" accept="image/*" class="w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" onchange="previewImage(this)">
                <p class="text-[10px] text-gray-400 mt-1">Format: JPG/PNG. Max 2MB.</p>
            </div>
        </div>

        <!-- SKU & NAMA -->
        <div class="grid grid-cols-1 gap-3">
            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">SKU / Barcode <span class="text-red-500">*</span></label>
                <div class="flex gap-1">
                    <input type="text" name="sku" id="form_sku" value="<?= htmlspecialchars($val_sku) ?>" class="w-full border p-2 rounded font-mono font-bold text-blue-700 uppercase focus:ring-2 focus:ring-blue-500 focus:outline-none" required placeholder="Scan / Ketik">
                    <button type="button" onclick="generateSku()" class="bg-yellow-100 hover:bg-yellow-200 text-yellow-700 border border-yellow-300 rounded px-2" title="Generate Auto"><i class="fas fa-magic"></i></button>
                    <button type="button" onclick="initCamera()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-300 rounded px-2" title="Scan Kamera"><i class="fas fa-qrcode"></i></button>
                </div>
            </div>
            
            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">Nama Barang <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="form_name" value="<?= htmlspecialchars($val_name) ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none" required placeholder="Contoh: Modem Huawei...">
            </div>
        </div>

        <!-- KATEGORI & SATUAN -->
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">Kategori</label>
                <select name="category" id="form_category" class="w-full border p-2 rounded bg-white">
                    <option value="Umum">Umum</option>
                    <?php foreach($category_accounts as $c): ?>
                        <option value="<?= $c['code'] ?>" <?= $val_cat == $c['code'] ? 'selected' : '' ?>>
                            <?= $c['code'] ?> - <?= $c['name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">Satuan</label>
                <input type="text" name="unit" id="form_unit" value="<?= htmlspecialchars($val_unit) ?>" class="w-full border p-2 rounded" list="unit_list">
                <datalist id="unit_list">
                    <option value="Pcs"><option value="Unit"><option value="Box"><option value="Set"><option value="Meter"><option value="Roll">
                </datalist>
            </div>
        </div>

        <!-- HARGA -->
        <div class="grid grid-cols-2 gap-3 bg-gray-50 p-2 rounded border border-gray-200">
            <div>
                <label class="block text-xs font-bold text-gray-600 mb-1">Harga Beli (HPP)</label>
                <input type="text" name="buy_price" id="form_buy" value="<?= number_format($val_buy, 0, ',', '.') ?>" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-right">
            </div>
            <div>
                <label class="block text-xs font-bold text-green-700 mb-1">Harga Jual</label>
                <input type="text" name="sell_price" id="form_sell" value="<?= number_format($val_sell, 0, ',', '.') ?>" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-right font-bold text-green-700">
            </div>
        </div>

        <!-- STOK & GUDANG (LOGIC KHUSUS) -->
        <div class="bg-blue-50 p-3 rounded border border-blue-100">
            <div class="grid grid-cols-2 gap-3 mb-2">
                <div>
                    <label class="block text-xs font-bold text-blue-800 mb-1">
                        <?= $is_edit ? 'Stok Saat Ini' : 'Stok Awal' ?>
                    </label>
                    <!-- Jika Edit, stok readonly agar tidak ambigu (harus lewat transaksi/edit SN) -->
                    <input type="number" name="initial_stock" id="form_stock" value="<?= $val_stock ?>" class="w-full border p-2 rounded text-center font-bold <?= $is_edit ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-white' ?>" min="0" <?= $is_edit ? 'readonly' : '' ?>>
                    <?php if($is_edit): ?>
                        <p class="text-[9px] text-gray-500 mt-1 italic leading-tight">Untuk ubah stok/koreksi, gunakan menu <b>Edit SN</b> atau Transaksi.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="block text-xs font-bold text-blue-800 mb-1">Gudang Penempatan</label>
                    <select name="warehouse_id" class="w-full border p-2 rounded bg-white text-xs" <?= $is_edit ? 'disabled' : '' ?>>
                        <?php if(!$is_edit): ?><option value="">-- Pilih --</option><?php endif; ?>
                        <?php foreach($warehouses as $wh): ?>
                            <option value="<?= $wh['id'] ?>" <?= (isset($_GET['wh']) && $_GET['wh'] == $wh['id']) ? 'selected' : '' ?>><?= $wh['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- SN INPUT TOGGLE (Hanya Tampil saat Tambah Baru atau Stok > 0) -->
            <?php if(!$is_edit): ?>
            <div>
                <label class="flex items-center gap-2 cursor-pointer mb-2">
                    <input type="checkbox" id="chk_has_sn" class="rounded text-blue-600" onchange="toggleSnInput()">
                    <span class="text-xs font-bold text-gray-600">Input Serial Number (SN)</span>
                </label>
                <div id="sn_input_area" class="hidden">
                    <textarea name="sn_list_text" id="sn_list_input" rows="3" class="w-full border p-2 rounded text-xs font-mono uppercase" placeholder="Scan/Ketik SN dipisahkan koma..."></textarea>
                    <div class="flex justify-between mt-1">
                        <span class="text-[9px] font-bold text-gray-500" id="sn_counter">0 SN terinput</span>
                        <div class="flex gap-1">
                            <button type="button" onclick="openSnAppendScanner()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-2 py-1 rounded text-[10px]"><i class="fas fa-camera"></i> Scan</button>
                            <button type="button" onclick="generateBatchSN()" class="bg-yellow-100 hover:bg-yellow-200 text-yellow-700 px-2 py-1 rounded text-[10px]"><i class="fas fa-bolt"></i> Auto</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-700 mb-1">Catatan / Deskripsi</label>
            <textarea name="notes" id="form_notes" rows="2" class="w-full border p-2 rounded"><?= htmlspecialchars($val_notes) ?></textarea>
        </div>

        <button type="submit" class="w-full <?= $btn_color ?> text-white py-3 rounded font-bold shadow transition transform active:scale-95">
            <i class="fas fa-save"></i> <?= $btn_text ?>
        </button>
    </form>
</div>

<!-- SCANNER OVERLAY (Shared for Form) -->
<div id="scanner_area" class="hidden fixed inset-0 bg-black z-50 flex flex-col">
    <div class="bg-gray-900 p-4 flex justify-between items-center text-white">
        <h3 class="font-bold"><i class="fas fa-qrcode"></i> Scan Barcode</h3>
        <button onclick="stopScan()" class="text-red-500 text-xl"><i class="fas fa-times"></i></button>
    </div>
    <div id="reader" class="flex-1 bg-black"></div>
    <div class="bg-gray-900 p-4 text-center">
        <button onclick="toggleFlash()" id="btn_flash" class="bg-white/20 text-white p-3 rounded-full hidden"><i class="fas fa-bolt"></i></button>
    </div>
</div>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('img_preview').src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function validateProductForm() {
    const stock = parseInt(document.getElementById('form_stock').value) || 0;
    
    // SKIP validation untuk Mode Edit (karena stok readonly)
    const isEdit = <?= $is_edit ? 'true' : 'false' ?>;
    if (isEdit) return true;

    // VALIDASI TAMBAH BARU
    const wh = document.querySelector('select[name="warehouse_id"]').value;
    
    // 1. Cek Gudang
    if (stock > 0 && !wh) {
        alert("Pilih Gudang Penempatan untuk stok awal!");
        return false;
    }

    // 2. Cek SN vs Stock (VALIDASI KETAT)
    // Logika: 
    // - Jika SN diisi -> Jumlah Harus Sama
    // - Jika SN kosong -> Konfirmasi Auto Generate
    
    const snText = document.getElementById('sn_list_input').value.trim();
    const snList = snText ? snText.split(',').filter(item => item.trim() !== '') : [];
    const snCount = snList.length;

    if (stock > 0) {
        if (snCount > 0) {
            // User sudah mengisi sebagian SN
            if (snCount !== stock) {
                alert(`PERINGATAN:\n\nStok Awal: ${stock}\nJumlah SN Diinput: ${snCount}\n\nHarap lengkapi SN agar sesuai jumlah Stok, atau kosongkan sama sekali untuk Generate Otomatis.`);
                return false;
            }
        } else {
            // User mengosongkan SN
            if (!confirm(`Serial Number kosong.\n\nSistem akan membuat ${stock} SN Otomatis (Random).\nApakah Anda ingin melanjutkan?`)) {
                return false;
            }
        }
    }

    return true;
}

function toggleSnInput() {
    const chk = document.getElementById('chk_has_sn');
    const area = document.getElementById('sn_input_area');
    if(chk.checked) area.classList.remove('hidden'); else area.classList.add('hidden');
}

// Logic untuk menghitung SN di textarea
document.getElementById('sn_list_input').addEventListener('input', function() {
    const txt = this.value.trim();
    const count = txt ? txt.split(',').filter(item => item.trim() !== '').length : 0;
    const counterEl = document.getElementById('sn_counter');
    const stock = parseInt(document.getElementById('form_stock').value) || 0;
    
    counterEl.innerText = count + " SN terinput";
    
    // Visual Feedback
    if(stock > 0 && count !== stock) {
        counterEl.classList.remove('text-green-600', 'text-gray-500');
        counterEl.classList.add('text-red-600');
        counterEl.innerText += " (Kurang/Lebih)";
    } else if (stock > 0 && count === stock) {
        counterEl.classList.remove('text-red-600', 'text-gray-500');
        counterEl.classList.add('text-green-600');
        counterEl.innerText += " (Pas)";
    }
});

function generateBatchSN() {
    const sku = document.getElementById('form_sku').value || 'ITEM';
    const qty = parseInt(document.getElementById('form_stock').value) || 0;
    if(qty <= 0) return alert("Isi Stok Awal dulu!");
    
    let sns = [];
    for(let i=0; i<qty; i++) {
        sns.push(sku.toUpperCase() + "-" + Date.now().toString().slice(-6) + Math.floor(Math.random()*1000));
    }
    document.getElementById('sn_list_input').value = sns.join(', ');
    document.getElementById('sn_list_input').dispatchEvent(new Event('input'));
}
</script>