<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. IMPORT EXCEL DATA
    if (isset($_POST['import_data'])) {
        $data = json_decode($_POST['import_data'], true);
        $count = 0;
        
        $pdo->beginTransaction();
        try {
            foreach($data as $row) {
                // Mapping kolom Excel (sesuaikan dengan template user jika perlu)
                $sku = $row['SKU'] ?? $row['sku'] ?? ('AUTO-'.rand(1000,9999));
                $name = $row['Nama'] ?? $row['nama'] ?? '';
                $cat = $row['Kategori'] ?? $row['kategori'] ?? 'Umum';
                $unit = $row['Satuan'] ?? $row['satuan'] ?? 'Pcs';
                $buy = $row['Nilai Aset'] ?? $row['nilai_aset'] ?? 0;
                $stock = $row['Stok'] ?? $row['stok'] ?? 0;
                
                if(empty($name)) continue;

                $check = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
                $check->execute([$sku]);
                if($check->rowCount() > 0) continue;

                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sku, $name, $cat, $unit, $buy, $buy*1.2, $stock]);
                $count++;
            }
            logActivity($pdo, 'IMPORT_BARANG', "Import $count data barang via Excel");
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$count Data berhasil diimport!"];
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal Import: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }

    // 2. SAVE PRODUCT (ADD/EDIT)
    if (isset($_POST['save_product'])) {
        $sku = $_POST['sku'];
        $name = $_POST['name'];
        $cat = $_POST['category'];
        $unit = $_POST['unit'];
        $buy = (float)str_replace('.', '', $_POST['buy_price']);
        $sell = (float)str_replace('.', '', $_POST['sell_price']);
        $stock = (int)$_POST['stock'];
        $id = $_POST['id'] ?? '';
        $image_url = $_POST['old_image'] ?? '';

        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = "prod_" . time() . "." . $ext;
            if (!is_dir('uploads/products')) mkdir('uploads/products', 0777, true);
            move_uploaded_file($_FILES['image']['tmp_name'], 'uploads/products/' . $filename);
            $image_url = 'uploads/products/' . $filename;
        }

        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE products SET sku=?, name=?, category=?, unit=?, buy_price=?, sell_price=?, image_url=? WHERE id=?");
                $stmt->execute([$sku, $name, $cat, $unit, $buy, $sell, $image_url, $id]);
                logActivity($pdo, 'UPDATE_BARANG', "Update barang: $name ($sku)");
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data Barang Diperbarui'];
            } else {
                $check = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
                $check->execute([$sku]);
                if($check->rowCount() > 0) throw new Exception("SKU $sku sudah ada!");

                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, unit, buy_price, sell_price, stock, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sku, $name, $cat, $unit, $buy, $sell, $stock, $image_url]);
                logActivity($pdo, 'ADD_BARANG', "Tambah barang baru: $name ($sku)");
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang Baru Ditambahkan'];
            }
        } catch(Exception $e) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>$e->getMessage()];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }

    // 3. DELETE PRODUCT
    if (isset($_POST['delete_id'])) {
        try {
            $prod = $pdo->query("SELECT name FROM products WHERE id=".$_POST['delete_id'])->fetch();
            $prodName = $prod ? $prod['name'] : 'Unknown';
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$_POST['delete_id']]);
            logActivity($pdo, 'DELETE_BARANG', "Hapus barang: $prodName");
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Barang dihapus'];
        } catch(Exception $e) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal hapus. Mungkin barang sudah memiliki transaksi.'];
        }
        echo "<script>window.location='?page=data_barang';</script>";
        exit;
    }
}

// --- GET DATA (MODIFIED FOR GROUPING, FILTER & SEARCH) ---
$search = $_GET['q'] ?? '';
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-d');

$sql = "SELECT 
            p.*, 
            COALESCE(it.reference, '-') as reference,
            COALESCE(it.date, DATE(p.created_at)) as trx_date,
            COALESCE(it.notes, '') as trx_notes,
            w.name as warehouse_name
        FROM products p
        LEFT JOIN (
            SELECT product_id, reference, date, warehouse_id, notes 
            FROM inventory_transactions 
            WHERE id IN (
                SELECT MAX(id) FROM inventory_transactions WHERE type='IN' GROUP BY product_id
            )
        ) it ON p.id = it.product_id
        LEFT JOIN warehouses w ON it.warehouse_id = w.id
        WHERE (p.name LIKE ? OR p.sku LIKE ? OR it.reference LIKE ? OR it.notes LIKE ?)
        AND (COALESCE(it.date, DATE(p.created_at)) BETWEEN ? AND ?)
        ORDER BY trx_date DESC, p.created_at DESC";

$stmt = $pdo->prepare($sql);
$likeSearch = "%$search%";
$stmt->execute([$likeSearch, $likeSearch, $likeSearch, $likeSearch, $start_date, $end_date]);
$products = $stmt->fetchAll();

$grouped_products = [];
foreach($products as $p) {
    $timestamp = strtotime($p['trx_date']);
    $month_num = date('n', $timestamp);
    $year = date('Y', $timestamp);
    $indo_months = [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'];
    $group_key = "Periode " . $indo_months[$month_num] . " " . $year;
    
    $grouped_products[$group_key]['items'][] = $p;
    if(!isset($grouped_products[$group_key]['total'])) $grouped_products[$group_key]['total'] = 0;
    $grouped_products[$group_key]['total'] += $p['buy_price'];
}
?>

<!-- Load JsBarcode & html2pdf -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<!-- CSS KHUSUS PRINT LABEL THERMAL -->
<style>
    @media print {
        body.label-print-mode * { visibility: hidden; }
        body.label-print-mode #label_print_area, body.label-print-mode #label_print_area * { visibility: visible; }
        body.label-print-mode #label_print_area {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
        }
        @page { size: 50mm 30mm; margin: 0; }
        .label-box {
            width: 48mm;
            height: 28mm;
            border: 1px solid #000;
            padding: 2px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-family: sans-serif;
            overflow: hidden;
        }
    }
    #label_print_area { display: none; }
    body.label-print-mode #label_print_area { display: flex; }
</style>

<!-- LABEL PRINT AREA (HIDDEN) -->
<div id="label_print_area">
    <div class="label-box">
        <div class="font-bold text-[10px] leading-tight mb-1 truncate w-full px-1" id="lbl_name">NAMA BARANG</div>
        <svg id="lbl_barcode" class="w-full h-8"></svg>
        <div class="font-bold text-[12px] mt-1" id="lbl_price">Rp 0</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
    
    <!-- FORM INPUT -->
    <div class="lg:col-span-1 hidden lg:block space-y-6 no-print">
        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2"><i class="fas fa-plus"></i> Tambah / Edit Barang</h3>
            
             <!-- Scanner Area -->
            <div id="scanner_area" class="hidden mb-4 bg-black rounded-lg relative overflow-hidden shadow-inner border-4 border-gray-800">
                <div id="reader" class="w-full h-56 bg-black"></div>
                <div class="absolute top-2 right-2 z-10 flex flex-col gap-2">
                    <button type="button" onclick="stopScan()" class="bg-red-600 text-white w-8 h-8 rounded-full shadow hover:bg-red-700 flex items-center justify-center"><i class="fas fa-times"></i></button>
                    <button type="button" id="btn_flash" onclick="toggleFlash()" class="hidden bg-yellow-400 text-black w-8 h-8 rounded-full shadow hover:bg-yellow-500 flex items-center justify-center"><i class="fas fa-bolt"></i></button>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="productForm">
                <input type="hidden" name="save_product" value="1">
                <input type="hidden" name="id" id="inp_id">
                <input type="hidden" name="old_image" id="inp_old_image">

                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">SKU (Barcode)</label>
                    <div class="flex gap-1 flex-wrap">
                        <input type="text" name="sku" id="inp_sku" class="w-full border p-2 rounded text-sm font-mono font-bold uppercase" placeholder="Scan/Generate..." oninput="previewBarcode()" onkeydown="handleEnter(event)" required>
                        
                        <!-- GENERATE CODE -->
                        <button type="button" onclick="generateSku()" class="bg-yellow-500 text-white px-2 py-2 rounded hover:bg-yellow-600 shadow" title="Generate Barcode Baru"><i class="fas fa-magic"></i></button>
                        
                        <!-- DOWNLOAD IMAGE -->
                        <button type="button" onclick="downloadBarcodeImg()" class="bg-green-600 text-white px-2 py-2 rounded hover:bg-green-700 shadow" title="Download Gambar Barcode"><i class="fas fa-image"></i></button>
                        
                        <!-- PRINT LABEL -->
                        <button type="button" onclick="printInputLabel()" class="bg-gray-700 text-white px-2 py-2 rounded hover:bg-gray-800 shadow" title="Print Label Stiker"><i class="fas fa-print"></i></button>
                        
                        <!-- SCAN CAMERA -->
                        <button type="button" onclick="startScan()" class="bg-indigo-600 text-white px-2 py-2 rounded hover:bg-indigo-700 shadow" title="Scan Kamera"><i class="fas fa-camera"></i></button>
                    </div>
                </div>

                <div id="barcode_container" class="mb-3 hidden text-center bg-gray-50 p-1 rounded border">
                    <svg id="barcode_preview" class="w-full h-12"></svg>
                </div>

                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Nama Barang</label>
                    <input type="text" name="name" id="inp_name" class="w-full border p-2 rounded text-sm" required>
                </div>

                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Harga Beli</label>
                        <input type="number" name="buy_price" id="inp_buy" class="w-full border p-2 rounded text-sm text-right" placeholder="0">
                    </div>
                     <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Harga Jual</label>
                        <input type="number" name="sell_price" id="inp_sell" class="w-full border p-2 rounded text-sm text-right" placeholder="0">
                    </div>
                </div>
                
                 <div class="grid grid-cols-2 gap-2 mb-3">
                     <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Kategori</label>
                        <input type="text" name="category" id="inp_category" class="w-full border p-2 rounded text-sm" placeholder="Umum">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Satuan</label>
                        <input type="text" name="unit" id="inp_unit" class="w-full border p-2 rounded text-sm" placeholder="Pcs">
                    </div>
                 </div>

                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Stok Awal</label>
                    <input type="number" name="stock" id="inp_stock" class="w-full border p-2 rounded text-sm font-bold" placeholder="0">
                </div>

                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Gambar</label>
                    <input type="file" name="image" id="inp_image" class="w-full border p-1 rounded text-xs">
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold text-sm shadow hover:bg-blue-700">Simpan</button>
                    <button type="button" onclick="resetForm()" class="px-3 py-2 bg-gray-200 text-gray-700 rounded text-sm hover:bg-gray-300">Reset</button>
                </div>
            </form>
        </div>
        
         <!-- IMPORT EXCEL MINI (Hidden) -->
        <div class="hidden">
             <form id="form_import" method="POST">
                <input type="hidden" name="import_data" id="import_json_data">
                <input type="file" id="file_excel" accept=".xlsx, .xls" onchange="processImportFile(this)">
            </form>
        </div>
    </div>

    <!-- KOLOM KANAN: TABEL DATA -->
    <div class="lg:col-span-3">
        <div class="bg-white p-6 rounded-lg shadow min-h-screen">
            
            <!-- HEADER & FILTER AREA -->
            <div class="mb-6 flex flex-col gap-4 no-print">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                    <h3 class="font-bold text-xl text-gray-800 mb-2 md:mb-0"><i class="fas fa-box"></i> Data Inventori & Aset</h3>
                    
                    <div class="flex flex-wrap gap-2">
                        <button onclick="document.getElementById('file_excel').click()" class="bg-indigo-600 text-white px-3 py-1.5 rounded text-sm font-bold hover:bg-indigo-700 flex items-center gap-2">
                            <i class="fas fa-file-import"></i> Import
                        </button>
                        <button onclick="exportToExcel()" class="bg-green-600 text-white px-3 py-1.5 rounded text-sm font-bold hover:bg-green-700 flex items-center gap-2">
                            <i class="fas fa-file-excel"></i> Export
                        </button>
                        <!-- Tombol Print Dihilangkan sesuai permintaan -->
                        <button onclick="savePDF()" class="bg-red-600 text-white px-3 py-1.5 rounded text-sm font-bold hover:bg-red-700 flex items-center gap-2">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                    </div>
                </div>

                <form method="GET" class="bg-gray-50 p-4 rounded border border-gray-200 flex flex-wrap gap-4 items-end">
                    <input type="hidden" name="page" value="data_barang">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
                        <input type="date" name="start" value="<?= $start_date ?>" class="border p-2 rounded text-sm w-full">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
                        <input type="date" name="end" value="<?= $end_date ?>" class="border p-2 rounded text-sm w-full">
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Pencarian (Nama, SKU, Ref, Ket)</label>
                        <input name="q" value="<?= $search ?>" class="border p-2 rounded text-sm w-full" placeholder="Cari barang...">
                    </div>
                    <button class="bg-blue-600 text-white px-6 py-2 rounded text-sm font-bold hover:bg-blue-700 h-[38px]">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </form>
            </div>

            <!-- TABLE CONTENT -->
            <div id="table_content" class="overflow-x-auto bg-white">
                <div class="hidden print:block mb-4 text-center">
                    <h2 class="text-xl font-bold uppercase">Laporan Data Barang</h2>
                    <p class="text-sm">Periode: <?= date('d/m/Y', strtotime($start_date)) ?> - <?= date('d/m/Y', strtotime($end_date)) ?></p>
                </div>

                <table id="products_table" class="w-full text-xs text-left border-collapse border border-gray-400">
                    <?php if(empty($grouped_products)): ?>
                        <tr><td class="p-4 text-center text-gray-500 bg-gray-50 border">Data tidak ditemukan dalam periode ini.</td></tr>
                    <?php else: ?>
                        <?php foreach($grouped_products as $period_label => $group): ?>
                        <tr class="bg-red-300 print:bg-red-200">
                            <td colspan="6" class="p-2 border border-gray-400 font-bold text-red-900 text-sm">
                                Total Material <?= $period_label ?>
                            </td>
                            <td colspan="6" class="p-2 border border-gray-400 font-bold text-red-900 text-sm text-right md:text-left">
                                Total: <?= formatRupiah($group['total']) ?>
                            </td>
                        </tr>
                        <tr class="bg-yellow-400 print:bg-yellow-200 text-gray-900 font-bold text-center">
                            <th class="p-2 border border-gray-500 w-12 no-export">Gbr</th>
                            <th class="p-2 border border-gray-500">SKU (Barcode)</th>
                            <th class="p-2 border border-gray-500">Tanggal</th>
                            <th class="p-2 border border-gray-500">Reference</th>
                            <th class="p-2 border border-gray-500">Nama Barang</th>
                            <th class="p-2 border border-gray-500">Harga Beli</th>
                            <th class="p-2 border border-gray-500">Lokasi Gudang</th>
                            <th class="p-2 border border-gray-500">Harga Jual</th>
                            <th class="p-2 border border-gray-500 w-10">Stok</th>
                            <th class="p-2 border border-gray-500">Satuan</th>
                            <th class="p-2 border border-gray-500">Keterangan</th>
                            <th class="p-2 border border-gray-500 w-24 no-print no-export">Aksi</th>
                        </tr>
                        <?php foreach($group['items'] as $p): 
                            $date_formatted = date('j F Y', strtotime($p['trx_date']));
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="p-1 border border-gray-400 text-center no-export">
                                <?php if($p['image_url']): ?>
                                    <img src="<?= $p['image_url'] ?>" class="w-8 h-8 object-cover mx-auto">
                                <?php endif; ?>
                            </td>
                            <td class="p-2 border border-gray-400 font-mono text-center"><?= $p['sku'] ?></td>
                            <td class="p-2 border border-gray-400 text-center whitespace-nowrap"><?= $date_formatted ?></td>
                            <td class="p-2 border border-gray-400 text-center uppercase"><?= $p['reference'] ?></td>
                            <td class="p-2 border border-gray-400 font-bold"><?= $p['name'] ?></td>
                            <td class="p-2 border border-gray-400 text-right"><?= formatRupiah($p['buy_price']) ?></td>
                            <td class="p-2 border border-gray-400 text-center"><?= $p['warehouse_name'] ?? '-' ?></td>
                            <td class="p-2 border border-gray-400 text-right"><?= formatRupiah($p['sell_price']) ?></td>
                            <td class="p-2 border border-gray-400 text-center font-bold"><?= $p['stock'] ?></td>
                            <td class="p-2 border border-gray-400 text-center"><?= $p['unit'] ?></td>
                            <td class="p-2 border border-gray-400 text-sm text-gray-700"><?= $p['trx_notes'] ?></td>
                            <td class="p-2 border border-gray-400 text-center flex justify-center gap-1 items-center h-full no-print no-export">
                                <button type="button" onclick='printLabel("<?= $p['sku'] ?>", "<?= htmlspecialchars($p['name']) ?>", "<?= formatRupiah($p['sell_price']) ?>")' class="text-gray-700 hover:text-black p-1" title="Cetak Label Barcode">
                                    <i class="fas fa-tag"></i>
                                </button>
                                <button onclick='editProduct(<?= json_encode($p) ?>)' class="text-blue-600 hover:text-blue-800 p-1" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Hapus?')" class="inline">
                                    <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                    <button class="text-red-500 hover:text-red-700 p-1"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="h-4 border-none bg-white"><td colspan="12" class="border-none"></td></tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
let html5QrCode;
let isFlashOn = false;

function handleEnter(e) {
    if(e.key === 'Enter') {
        e.preventDefault();
        previewBarcode();
        document.getElementById('inp_name').focus();
    }
}

async function generateSku() {
    try {
        const res = await fetch('api.php?action=generate_sku');
        const data = await res.json();
        if(data.sku) {
            document.getElementById('inp_sku').value = data.sku;
            previewBarcode();
            document.getElementById('inp_name').focus();
        }
    } catch(e) { alert("Gagal generate SKU"); }
}

// DOWNLOAD GAMBAR BARCODE (PNG)
function downloadBarcodeImg() {
    const sku = document.getElementById('inp_sku').value;
    const name = document.getElementById('inp_name').value || 'barcode';
    if(!sku) return alert("SKU belum diisi atau digenerate!");

    const canvas = document.createElement('canvas');
    try {
        JsBarcode(canvas, sku, { 
            format: "CODE128", 
            width: 2, 
            height: 60, 
            displayValue: true 
        });
        const link = document.createElement('a');
        link.download = name.replace(/\s+/g, '_') + '_' + sku + '.png';
        link.href = canvas.toDataURL("image/png");
        link.click();
    } catch(e) {
        alert("Gagal membuat gambar barcode. SKU tidak valid.");
    }
}

// PRINT LABEL DARI FORM INPUT
function printInputLabel() {
    const sku = document.getElementById('inp_sku').value;
    const name = document.getElementById('inp_name').value || 'Nama Barang';
    const sell = document.getElementById('inp_sell').value || 0;
    
    if(!sku) return alert("SKU harus diisi!");
    
    // Format Rupiah simple
    const priceFormatted = "Rp " + new Intl.NumberFormat('id-ID').format(sell);
    printLabel(sku, name, priceFormatted);
}

function previewBarcode() {
    const sku = document.getElementById('inp_sku').value;
    const container = document.getElementById('barcode_container');
    if (sku.length > 0) {
        container.classList.remove('hidden');
        try {
            JsBarcode("#barcode_preview", sku, { format: "CODE128", lineColor: "#000", width: 2, height: 40, displayValue: true });
        } catch(e) { container.classList.add('hidden'); }
    } else { container.classList.add('hidden'); }
}

function printLabel(sku, name, price) {
    document.getElementById('lbl_name').innerText = name;
    document.getElementById('lbl_price').innerText = price;
    try {
        JsBarcode("#lbl_barcode", sku, {
            format: "CODE128",
            width: 2,
            height: 30,
            displayValue: true,
            fontSize: 12,
            margin: 0
        });
    } catch(e) { alert("Gagal generate barcode."); return; }

    document.body.classList.add('label-print-mode');
    window.print();
    setTimeout(() => { document.body.classList.remove('label-print-mode'); }, 1000);
}

function resetForm() {
    document.getElementById('productForm').reset();
    document.getElementById('inp_id').value = '';
    document.getElementById('inp_old_image').value = '';
    document.getElementById('barcode_container').classList.add('hidden');
    document.getElementById('inp_stock').disabled = false;
    document.getElementById('inp_sku').focus();
}

function editProduct(data) {
    document.getElementById('inp_id').value = data.id;
    document.getElementById('inp_sku').value = data.sku;
    document.getElementById('inp_name').value = data.name;
    document.getElementById('inp_category').value = data.category;
    document.getElementById('inp_unit').value = data.unit;
    document.getElementById('inp_buy').value = data.buy_price;
    document.getElementById('inp_sell').value = data.sell_price;
    document.getElementById('inp_stock').value = data.stock;
    document.getElementById('inp_stock').disabled = true;
    document.getElementById('inp_old_image').value = data.image_url;
    previewBarcode();
    document.querySelector('form').scrollIntoView({ behavior: 'smooth' });
}

function processImportFile(input) {
    if(!input.files.length) return;
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        const data = new Uint8Array(e.target.result);
        const workbook = XLSX.read(data, {type: 'array'});
        const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
        const jsonData = XLSX.utils.sheet_to_json(firstSheet);
        if(jsonData.length > 0) {
            if(confirm('Akan mengimport ' + jsonData.length + ' data. Lanjutkan?')) {
                document.getElementById('import_json_data').value = JSON.stringify(jsonData);
                document.getElementById('form_import').submit();
            }
        } else { alert("Excel kosong."); }
    };
    reader.readAsArrayBuffer(file);
    input.value = '';
}

function exportToExcel() {
    const table = document.getElementById("products_table");
    const clone = table.cloneNode(true);
    
    // Hapus kolom yang tidak perlu diexport (Kolom "Gbr" dan "Aksi")
    // Menggunakan class 'no-export' yang sudah ada di TH dan TD
    const noExports = clone.querySelectorAll('.no-export');
    noExports.forEach(el => el.remove());
    
    // Note: Kita tidak perlu deleteCell manual berdasarkan index karena itu merusak row yang punya colspan (Header grouping).
    // Menghapus elemen by class sudah cukup aman.

    const wb = XLSX.utils.table_to_book(clone, {sheet: "Data Barang"});
    XLSX.writeFile(wb, "Data_Barang_" + new Date().toISOString().slice(0,10) + ".xlsx");
}

function savePDF() {
    const element = document.getElementById('table_content');
    const opt = {
        margin: 5,
        filename: 'Data_Barang_<?= date('Ymd') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };
    const noPrints = element.querySelectorAll('.no-print');
    noPrints.forEach(el => el.style.display = 'none');
    const printHeader = element.querySelector('.print\\:block');
    if(printHeader) printHeader.classList.remove('hidden');
    html2pdf().set(opt).from(element).save().then(() => {
        noPrints.forEach(el => el.style.display = '');
        if(printHeader) printHeader.classList.add('hidden');
    });
}

function startScan() {
    const scannerArea = document.getElementById('scanner_area');
    scannerArea.classList.remove('hidden');
    if(html5QrCode) html5QrCode.clear();
    html5QrCode = new Html5Qrcode("reader");
    html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: { width: 200, height: 150 } }, 
        (decodedText) => {
            document.getElementById('inp_sku').value = decodedText;
            previewBarcode();
            stopScan();
            new Audio('https://actions.google.com/sounds/v1/alarms/beep_short.ogg').play();
            document.getElementById('inp_name').focus();
        }, () => {}
    ).then(() => {
        const track = html5QrCode.getRunningTrackCameraCapabilities();
        if (track && track.torchFeature().isSupported()) document.getElementById('btn_flash').classList.remove('hidden');
    }).catch(err => { alert("Camera Error: " + err); scannerArea.classList.add('hidden'); });
}
function stopScan() { if(html5QrCode) html5QrCode.stop().then(() => document.getElementById('scanner_area').classList.add('hidden')); else document.getElementById('scanner_area').classList.add('hidden'); }
function toggleFlash() { if(html5QrCode) { isFlashOn = !isFlashOn; html5QrCode.applyVideoConstraints({ advanced: [{ torch: isFlashOn }] }); } }
</script>