<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// Ambil Data Barang untuk Pencarian
$products = $pdo->query("SELECT id, sku, name, sell_price, unit FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Load JsBarcode -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"></script>

<!-- STYLE KHUSUS PRINT LABEL 50mm x 30mm -->
<style>
    @media print {
        @page { size: 50mm 30mm; margin: 0; }
        body { background: white; margin: 0; padding: 0; }
        .no-print, aside, header, .main-content { display: none !important; }
        #print_area { display: block !important; }
        .label-sticker {
            width: 48mm; height: 28mm; border: 1px solid #000; margin: 1mm auto; 
            page-break-after: always; display: flex; flex-direction: column;
            align-items: center; justify-content: center; text-align: center;
            overflow: hidden; font-family: Arial, Helvetica, sans-serif;
        }
        .lbl-name { font-size: 8pt; font-weight: bold; line-height: 1; margin-bottom: 2px; max-height: 2.2em; overflow: hidden; width: 100%; padding: 0 2px; }
        .lbl-price { font-size: 9pt; font-weight: bold; margin-top: 2px; }
        svg.barcode-svg { width: 95% !important; height: 35px !important; }
    }
    #print_area { display: none; }
</style>

<div class="main-content">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-qrcode text-blue-600"></i> Cetak Label Barcode</h2>
        <div class="text-sm text-gray-500 bg-yellow-100 p-2 rounded border border-yellow-200">
            <i class="fas fa-info-circle"></i> Layout khusus Printer Thermal ukuran <b>50mm x 30mm</b>.
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- KOLOM KIRI: PILIH BARANG (Tabs) -->
        <div class="bg-white p-6 rounded-lg shadow lg:col-span-1">
            <div class="border-b mb-4">
                <nav class="-mb-px flex space-x-4" aria-label="Tabs">
                    <button onclick="switchTab('db')" id="tab-db" class="border-blue-500 text-blue-600 whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm">
                        Dari Database
                    </button>
                    <button onclick="switchTab('custom')" id="tab-custom" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm">
                        Buat Manual / Custom
                    </button>
                </nav>
            </div>

            <!-- TAB 1: DARI DATABASE -->
            <div id="content-db">
                <div class="mb-4">
                    <input type="text" id="search_product" class="w-full border p-2 rounded text-sm" placeholder="Cari Nama / SKU...">
                </div>
                <div class="overflow-y-auto max-h-[350px] border rounded">
                    <table class="w-full text-sm text-left">
                        <tbody id="product_list">
                            <?php foreach($products as $p): ?>
                            <tr class="border-b hover:bg-gray-50 cursor-pointer group" onclick='addToQueue(<?= json_encode($p) ?>)'>
                                <td class="p-2">
                                    <div class="font-bold text-gray-700 group-hover:text-blue-600"><?= $p['name'] ?></div>
                                    <div class="text-xs text-gray-500"><?= $p['sku'] ?> | Rp <?= number_format($p['sell_price'],0,',','.') ?></div>
                                </td>
                                <td class="p-2 text-right">
                                    <button class="text-blue-600 hover:text-blue-800"><i class="fas fa-plus-circle"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: CUSTOM MANUAL -->
            <div id="content-custom" class="hidden">
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Nama Label / Barang</label>
                        <input type="text" id="c_name" class="w-full border p-2 rounded text-sm" placeholder="Contoh: Kripik Pisang">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Kode / Barcode</label>
                        <div class="flex gap-2">
                            <input type="text" id="c_sku" class="w-full border p-2 rounded text-sm font-mono" placeholder="Scan/Generate">
                            <button onclick="generateCustomSku()" class="bg-yellow-500 text-white px-3 rounded hover:bg-yellow-600 text-xs font-bold">Auto</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Harga (Opsional)</label>
                        <input type="number" id="c_price" class="w-full border p-2 rounded text-sm" placeholder="0">
                    </div>
                    <div class="flex gap-2">
                        <button onclick="addCustomToQueue()" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700 text-sm">
                            <i class="fas fa-plus"></i> Antrian
                        </button>
                        <button onclick="downloadCustomBarcode()" class="w-12 bg-green-600 text-white py-2 rounded font-bold hover:bg-green-700 text-sm" title="Download Gambar Barcode">
                            <i class="fas fa-image"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- KOLOM KANAN: ANTRIAN CETAK -->
        <div class="bg-white p-6 rounded-lg shadow lg:col-span-2 flex flex-col">
            <div class="flex justify-between items-center border-b pb-2 mb-4">
                <h3 class="font-bold">Antrian Cetak</h3>
                <button onclick="clearQueue()" class="text-red-500 text-xs hover:underline">Hapus Semua</button>
            </div>

            <div class="flex-1 overflow-y-auto max-h-[400px] mb-4">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100 text-gray-700 sticky top-0">
                        <tr>
                            <th class="p-2">Barang</th>
                            <th class="p-2 text-center w-24">Jumlah Label</th>
                            <th class="p-2 text-center w-16">Hapus</th>
                        </tr>
                    </thead>
                    <tbody id="print_queue_body">
                        <tr><td colspan="3" class="p-4 text-center text-gray-400 italic">Antrian kosong. Pilih barang di samping.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="bg-gray-50 p-4 rounded border flex justify-between items-center">
                <div class="text-sm font-bold">
                    Total Label: <span id="total_labels" class="text-blue-600 text-lg">0</span>
                </div>
                <button onclick="printLabels()" id="btn_print" class="bg-blue-600 text-white px-6 py-2 rounded font-bold hover:bg-blue-700 shadow flex items-center gap-2 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                    <i class="fas fa-print"></i> CETAK SEKARANG
                </button>
            </div>
        </div>
    </div>
</div>

<div id="print_area"></div>

<script>
let printQueue = [];
const allProducts = <?= json_encode($products) ?>;

document.getElementById('search_product').addEventListener('keyup', function(e) {
    const term = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#product_list tr');
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
});

function switchTab(tab) {
    const dbBtn = document.getElementById('tab-db');
    const custBtn = document.getElementById('tab-custom');
    const dbContent = document.getElementById('content-db');
    const custContent = document.getElementById('content-custom');

    if(tab === 'db') {
        dbBtn.className = "border-blue-500 text-blue-600 whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm";
        custBtn.className = "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm";
        dbContent.classList.remove('hidden');
        custContent.classList.add('hidden');
    } else {
        custBtn.className = "border-blue-500 text-blue-600 whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm";
        dbBtn.className = "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm";
        custContent.classList.remove('hidden');
        dbContent.classList.add('hidden');
    }
}

async function generateCustomSku() {
    try {
        const res = await fetch('api.php?action=generate_sku');
        const data = await res.json();
        if(data.sku) document.getElementById('c_sku').value = data.sku;
    } catch(e) { alert("Gagal generate SKU"); }
}

function downloadCustomBarcode() {
    const sku = document.getElementById('c_sku').value;
    const name = document.getElementById('c_name').value || 'barcode';
    if(!sku) return alert("Isi Kode/SKU dulu!");

    const canvas = document.createElement('canvas');
    try {
        JsBarcode(canvas, sku, { format: "CODE128", width: 2, height: 60, displayValue: true });
        const link = document.createElement('a');
        link.download = name.replace(/\s+/g, '_') + '_' + sku + '.png';
        link.href = canvas.toDataURL("image/png");
        link.click();
    } catch(e) { alert("Gagal generate gambar barcode"); }
}

function addCustomToQueue() {
    const name = document.getElementById('c_name').value;
    const sku = document.getElementById('c_sku').value;
    const price = document.getElementById('c_price').value || 0;

    if(!name || !sku) {
        alert("Nama dan Kode Barcode wajib diisi!");
        return;
    }

    addToQueue({
        id: 'custom_' + Date.now(), 
        name: name,
        sku: sku,
        sell_price: price,
        is_custom: true
    });

    document.getElementById('c_name').value = '';
    document.getElementById('c_sku').value = '';
    document.getElementById('c_price').value = '';
}

function addToQueue(product) {
    const exist = printQueue.find(item => item.sku === product.sku);
    if(exist) { exist.qty++; } else { printQueue.push({ ...product, qty: 1 }); }
    renderQueue();
}

function removeFromQueue(index) {
    printQueue.splice(index, 1);
    renderQueue();
}

function updateQty(index, val) {
    if(val < 1) val = 1;
    printQueue[index].qty = parseInt(val);
    renderQueue(false);
}

function clearQueue() {
    if(confirm('Hapus semua antrian?')) {
        printQueue = [];
        renderQueue();
    }
}

function renderQueue(fullRender = true) {
    const tbody = document.getElementById('print_queue_body');
    const totalEl = document.getElementById('total_labels');
    const btnPrint = document.getElementById('btn_print');
    
    let total = 0;
    
    if(fullRender) {
        tbody.innerHTML = '';
        if(printQueue.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-gray-400 italic">Antrian kosong. Pilih barang di samping.</td></tr>';
            btnPrint.disabled = true;
            totalEl.innerText = 0;
            return;
        }

        printQueue.forEach((item, index) => {
            total += item.qty;
            const tr = document.createElement('tr');
            tr.className = 'border-b hover:bg-gray-50';
            tr.innerHTML = `
                <td class="p-2">
                    <div class="font-bold text-gray-700">${item.name}</div>
                    <div class="text-xs text-gray-500 font-mono">${item.sku}</div>
                </td>
                <td class="p-2 text-center">
                    <input type="number" min="1" value="${item.qty}" onchange="updateQty(${index}, this.value)" class="w-16 border rounded text-center p-1 font-bold">
                </td>
                <td class="p-2 text-center">
                    <button onclick="removeFromQueue(${index})" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    } else {
        printQueue.forEach(item => total += item.qty);
    }

    totalEl.innerText = total;
    btnPrint.disabled = (total === 0);
}

function printLabels() {
    const area = document.getElementById('print_area');
    area.innerHTML = ''; 

    printQueue.forEach(item => {
        for(let i=0; i < item.qty; i++) {
            const sticker = document.createElement('div');
            sticker.className = 'label-sticker';
            
            const nameDiv = document.createElement('div');
            nameDiv.className = 'lbl-name';
            nameDiv.innerText = item.name;
            sticker.appendChild(nameDiv);

            const svg = document.createElement('svg');
            svg.className = 'barcode-svg';
            try {
                JsBarcode(svg, item.sku, {
                    format: "CODE128", width: 2, height: 35, displayValue: true, fontSize: 12, margin: 0, fontOptions: "bold"
                });
            } catch(e) {}
            sticker.appendChild(svg);

            const priceDiv = document.createElement('div');
            priceDiv.className = 'lbl-price';
            priceDiv.innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(item.sell_price);
            sticker.appendChild(priceDiv);

            area.appendChild(sticker);
        }
    });
    window.print();
}
</script>