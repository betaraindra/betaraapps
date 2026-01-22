<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'SVP', 'MANAGER']);

// --- AMBIL DATA AKUN (Untuk Modal Edit) ---
$accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();

// --- HANDLE POST REQUESTS (DELETE & UPDATE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. DELETE
    if (isset($_POST['delete_id'])) {
        try {
            $pdo->prepare("DELETE FROM finance_transactions WHERE id=?")->execute([$_POST['delete_id']]);
            logActivity($pdo, 'HAPUS_KEUANGAN', "Menghapus transaksi ID: " . $_POST['delete_id']);
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Transaksi berhasil dihapus.'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal hapus: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_keuangan';</script>";
        exit;
    }

    // 2. UPDATE
    if (isset($_POST['update_trx'])) {
        try {
            $id = $_POST['edit_id'];
            $date = $_POST['edit_date'];
            $acc_id = $_POST['edit_account_id'];
            $amount = (float)str_replace(['.', ','], '', $_POST['edit_amount']);
            $desc = $_POST['edit_description'];
            
            // Cek Tipe Akun untuk update tipe transaksi (jika akun berubah)
            $accStmt = $pdo->prepare("SELECT type FROM accounts WHERE id = ?");
            $accStmt->execute([$acc_id]);
            $accType = $accStmt->fetchColumn(); // INCOME/EXPENSE
            
            $stmt = $pdo->prepare("UPDATE finance_transactions SET date=?, account_id=?, type=?, amount=?, description=? WHERE id=?");
            $stmt->execute([$date, $acc_id, $accType, $amount, $desc, $id]);
            
            logActivity($pdo, 'UPDATE_KEUANGAN', "Update transaksi ID: $id");
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Transaksi berhasil diperbarui.'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal update: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_keuangan';</script>";
        exit;
    }

    // 3. IMPORT EXCEL
    if (isset($_POST['import_data'])) {
        $data = json_decode($_POST['import_data'], true);
        $count = 0;
        
        $pdo->beginTransaction();
        try {
            // Data Excel hasil export memiliki struktur baru.
            foreach($data as $index => $row) {
                $col_tanggal = $row[0] ?? '';
                if (empty($col_tanggal)) continue;
                if (stripos($col_tanggal, 'Periode') !== false) continue;

                $date = $col_tanggal;
                if (is_numeric($col_tanggal)) {
                    $date = date('Y-m-d', ($col_tanggal - 25569) * 86400);
                } else {
                    if (!strtotime($date)) continue;
                }

                $acc_code = trim($row[1] ?? '');
                $uraian = $row[2] ?? '';
                if (empty($acc_code)) {
                    if (preg_match('/\[(.*?)\]/', $uraian, $matches)) {
                        $acc_code = $matches[1];
                    }
                }

                $clean_num = function($val) {
                    if(is_numeric($val)) return (float)$val;
                    return (float)str_replace(['Rp', '.', ',', ' '], '', $val);
                };

                $in_raw = $row[3] ?? 0;
                $out_raw = $row[5] ?? 0;
                
                $in = $clean_num($in_raw);
                $out = $clean_num($out_raw);
                $desc = $row[8] ?? ''; 

                if (!empty($acc_code)) {
                    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE code = ?");
                    $stmt->execute([$acc_code]);
                    $acc = $stmt->fetch();
                    
                    if ($acc) {
                        $user_id = $_SESSION['user_id'] ?? 0;
                        if ($in > 0) {
                            $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'INCOME', ?, ?, ?, ?)")
                                ->execute([$date, $acc['id'], $in, $desc, $user_id]);
                            $count++;
                        }
                        if ($out > 0) {
                            $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, 'EXPENSE', ?, ?, ?, ?)")
                                ->execute([$date, $acc['id'], $out, $desc, $user_id]);
                            $count++;
                        }
                    }
                }
            }
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success', 'message'=>"$count Transaksi berhasil diimport!"];
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal Import: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_keuangan';</script>";
        exit;
    }
}

// --- GET DATA & FILTER ---
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

// Query Data
$sql = "SELECT f.*, a.name as acc_name, a.code as acc_code 
        FROM finance_transactions f 
        JOIN accounts a ON f.account_id = a.id 
        WHERE f.date BETWEEN ? AND ? 
        ORDER BY f.date ASC, f.created_at ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$start, $end]);
$transactions = $stmt->fetchAll();

// --- GROUPING DATA BY MONTH ---
$grouped_data = [];
$grand_total_in = 0;
$grand_total_out = 0;

foreach ($transactions as $t) {
    $month_key = date('Y-m', strtotime($t['date']));
    $grouped_data[$month_key][] = $t;
    
    if($t['type'] === 'INCOME') $grand_total_in += $t['amount'];
    else $grand_total_out += $t['amount'];
}
$grand_total_balance = $grand_total_in - $grand_total_out;
?>

<!-- STYLE KHUSUS PRINT -->
<style>
    @media print {
        @page { size: landscape; margin: 10mm; }
        body { background: white; font-family: 'Times New Roman', serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .no-print, aside, header, .sidebar-scroll, #importModal, #editModal { display: none !important; }
        .print-only { display: block !important; }
        .shadow, .rounded-lg, .border-l-4 { box-shadow: none !important; border-radius: 0 !important; border-left: none !important; }
        
        /* Reset Table Style for Print */
        table { width: 100%; border-collapse: collapse; font-size: 10pt; }
        th, td { border: 1px solid #000 !important; padding: 4px !important; }
        .bg-purple-200, .bg-purple-300, .bg-purple-100 { background-color: #f3f4f6 !important; } /* Light gray for print */
        .bg-orange-200, .bg-orange-300 { background-color: #e5e7eb !important; }
        .text-white { color: black !important; }
    }
    /* Hide Print Header on Screen */
    .print-only { display: none; }
    /* Helper for Export Clean up */
    .no-export { } 
</style>

<!-- HEADER PERUSAHAAN (KOP SURAT) - HANYA TAMPIL SAAT PRINT -->
<div class="print-only mb-6">
    <div class="flex items-center border-b-2 border-black pb-4 mb-4">
        <?php if(!empty($settings['company_logo'])): ?>
            <div class="w-24 mr-4">
                <img src="<?= $settings['company_logo'] ?>" class="max-h-20 object-contain">
            </div>
        <?php endif; ?>
        <div class="flex-1 text-center">
             <h1 class="text-2xl font-bold uppercase tracking-wider"><?= $settings['company_name'] ?? 'NAMA PERUSAHAAN' ?></h1>
             <p class="text-sm"><?= nl2br($settings['company_address'] ?? '') ?></p>
        </div>
        <?php if(!empty($settings['company_logo'])): ?>
            <div class="w-24"></div> <!-- Spacer for balance -->
        <?php endif; ?>
    </div>
    <div class="text-center mb-4">
        <h2 class="font-bold text-lg uppercase border-2 border-black inline-block px-4 py-1">Laporan Data Keuangan</h2>
        <p class="text-sm mt-2 font-bold">Periode: <?= date('d F Y', strtotime($start)) ?> s/d <?= date('d F Y', strtotime($end)) ?></p>
    </div>
</div>

<!-- UI HEADER (SCREEN ONLY) -->
<div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4 no-print">
    <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-table text-blue-600"></i> Data Keuangan</h2>
    
    <div class="flex gap-2">
        <button onclick="document.getElementById('importModal').classList.remove('hidden')" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold hover:bg-indigo-700 shadow flex items-center gap-2">
            <i class="fas fa-file-import"></i> Import Excel
        </button>
        <button onclick="exportToExcel()" class="bg-green-600 text-white px-4 py-2 rounded font-bold hover:bg-green-700 shadow flex items-center gap-2">
            <i class="fas fa-file-export"></i> Export Excel
        </button>
    </div>
</div>

<!-- FILTER -->
<div class="bg-white p-4 rounded-lg shadow mb-6 border-l-4 border-blue-600 no-print">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <input type="hidden" name="page" value="data_keuangan">
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
            <input type="date" name="start" value="<?= $start ?>" class="border p-2 rounded text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
            <input type="date" name="end" value="<?= $end ?>" class="border p-2 rounded text-sm">
        </div>
        
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded font-bold hover:bg-blue-700 shadow flex items-center gap-2">
            <i class="fas fa-search"></i> Tampilkan
        </button>
        
        <!-- TOMBOL PRINT -->
        <button type="button" onclick="window.print()" class="bg-gray-700 text-white px-6 py-2 rounded font-bold hover:bg-gray-800 shadow flex items-center gap-2">
            <i class="fas fa-print"></i> Cetak
        </button>
    </form>
</div>

<!-- TABEL DATA -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table id="financeTable" class="w-full text-sm text-left border-collapse border border-gray-400">
            <thead>
                <!-- Row 1 Headers -->
                <tr class="bg-purple-200 text-gray-800 border-b border-gray-400">
                    <th rowspan="2" class="p-2 border-r border-gray-400 text-center align-middle font-bold">Tanggal</th>
                    <th rowspan="2" class="p-2 border-r border-gray-400 text-center align-middle font-bold">Referensi</th>
                    <th rowspan="2" class="p-2 border-r border-gray-400 text-center align-middle font-bold w-1/4">URAIAN</th>
                    
                    <th colspan="2" class="p-2 border-r border-gray-400 text-center font-bold bg-purple-300">Pemasukan</th>
                    <th colspan="2" class="p-2 border-r border-gray-400 text-center font-bold bg-purple-300">Pengeluaran</th>
                    
                    <th rowspan="2" class="p-2 border-r border-gray-400 text-center align-middle font-bold w-32 bg-purple-200">Sisa Saldo/Laba rugi Sub Total Perbulan</th>
                    <th rowspan="2" class="p-2 text-center align-middle font-bold border-r border-gray-400">Keterangan</th>
                    <th rowspan="2" class="p-2 text-center align-middle font-bold no-print no-export w-20">Aksi</th>
                </tr>
                <!-- Row 2 Sub-Headers -->
                <tr class="bg-purple-200 text-gray-800 border-b border-gray-400">
                    <th class="p-2 border-r border-gray-400 text-center font-bold w-32">Harian</th>
                    <th class="p-2 border-r border-gray-400 text-center font-bold w-32">Bulanan</th>
                    <th class="p-2 border-r border-gray-400 text-center font-bold w-32">Harian</th>
                    <th class="p-2 border-r border-gray-400 text-center font-bold w-32">Bulanan</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-300">
                <!-- GRAND TOTAL ROW (TOP) -->
                <tr class="bg-purple-100 font-bold border-b border-gray-400 text-gray-900">
                    <td class="p-2 border-r border-gray-400"></td>
                    <td class="p-2 border-r border-gray-400"></td>
                    <td class="p-2 border-r border-gray-400 text-right"></td> <!-- Kosong di Uraian -->
                    
                    <td class="p-2 border-r border-gray-400 text-right"><?= formatRupiah($grand_total_in) ?></td>
                    <td class="p-2 border-r border-gray-400 text-right"><?= formatRupiah($grand_total_in) ?></td> <!-- Total Bulanan (Accumulated) -->
                    
                    <td class="p-2 border-r border-gray-400 text-right"><?= formatRupiah($grand_total_out) ?></td>
                    <td class="p-2 border-r border-gray-400 text-right"><?= formatRupiah($grand_total_out) ?></td> <!-- Total Bulanan (Accumulated) -->
                    
                    <td class="p-2 border-r border-gray-400 text-right"><?= formatRupiah($grand_total_balance) ?></td>
                    <td class="p-2 border-r border-gray-400"></td>
                    <td class="p-2 no-print no-export"></td>
                </tr>

                <!-- LOOP PER PERIOD (MONTH) -->
                <?php foreach($grouped_data as $month_key => $items): 
                    $sub_in = 0;
                    $sub_out = 0;
                    // Prepare Date Range String
                    $first_date = date('01', strtotime($month_key . '-01'));
                    $last_date = date('t', strtotime($month_key . '-01'));
                    $month_name = date('F Y', strtotime($month_key . '-01'));
                    $month_indo = [
                        'January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni',
                        'July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'
                    ];
                    foreach($month_indo as $en => $id) $month_name = str_replace($en, $id, $month_name);
                    
                    $range_str = "$first_date - $last_date $month_name";
                    $periode_title = "Periode $month_name";
                ?>
                    
                    <!-- TRANSACTION ROWS -->
                    <?php foreach($items as $row): 
                        if($row['type'] == 'INCOME') $sub_in += $row['amount'];
                        else $sub_out += $row['amount'];
                    ?>
                    <tr class="hover:bg-gray-50 border-b border-gray-200">
                        <td class="p-2 text-center border-r border-gray-300">
                            <?= date('d F Y', strtotime($row['date'])) ?>
                        </td>
                        <td class="p-2 text-center border-r border-gray-300 font-mono text-gray-600">
                            <?= $row['acc_code'] ?>
                        </td>
                        <td class="p-2 border-r border-gray-300">
                            <?= $row['acc_name'] ?> <span class="hidden">[<?= $row['acc_code'] ?>]</span>
                        </td>
                        
                        <td class="p-2 border-r border-gray-300 text-right">
                            <?= $row['type']=='INCOME' ? formatRupiah($row['amount']) : '' ?>
                        </td>
                        <td class="p-2 border-r border-gray-300"></td> <!-- Bulanan IN (Empty) -->

                        <td class="p-2 border-r border-gray-300 text-right">
                            <?= $row['type']=='EXPENSE' ? formatRupiah($row['amount']) : '' ?>
                        </td>
                        <td class="p-2 border-r border-gray-300"></td> <!-- Bulanan OUT (Empty) -->

                        <td class="p-2 border-r border-gray-300 bg-gray-50"></td> <!-- Saldo (Empty) -->

                        <td class="p-2 border-r border-gray-400 text-gray-500 italic text-xs truncate max-w-xs">
                            <?= $row['description'] ?>
                        </td>
                        
                        <!-- KOLOM AKSI EDIT & HAPUS -->
                        <td class="p-2 text-center no-print no-export">
                            <div class="flex justify-center gap-1">
                                <button onclick='editTrx(<?= json_encode($row) ?>)' class="text-blue-500 hover:text-blue-700 p-1" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Hapus transaksi ini?')" class="inline">
                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                    <button class="text-red-500 hover:text-red-700 p-1" title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- PERIOD SUMMARY ROW (ORANGE) -->
                    <tr class="bg-orange-200 font-bold text-gray-900 border-b-2 border-gray-400">
                        <td class="p-2 border-r border-gray-400 font-bold"><?= $periode_title ?></td>
                        <td class="p-2 border-r border-gray-400"></td>
                        <td class="p-2 border-r border-gray-400 text-center"><?= $range_str ?></td>
                        
                        <td class="p-2 border-r border-gray-400 text-right"></td> <!-- Harian IN (Empty) -->
                        <td class="p-2 border-r border-gray-400 text-right"><?= formatRupiah($sub_in) ?></td>
                        
                        <td class="p-2 border-r border-gray-400 text-right"></td> <!-- Harian OUT (Empty) -->
                        <td class="p-2 border-r border-gray-400 text-right"><?= formatRupiah($sub_out) ?></td>
                        
                        <td class="p-2 border-r border-gray-400 text-right bg-orange-300 text-blue-900">
                            <?= formatRupiah($sub_in - $sub_out) ?>
                        </td>
                        <td class="p-2 border-r border-gray-400 text-right text-green-700">
                            <?= formatRupiah($sub_in - $sub_out) ?>
                        </td>
                        <td class="p-2 no-print no-export"></td>
                    </tr>

                <?php endforeach; ?>

                <?php if(empty($grouped_data)): ?>
                    <tr><td colspan="10" class="p-8 text-center text-gray-500">Tidak ada data untuk periode ini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- EDIT MODAL -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <button onclick="document.getElementById('editModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
            <i class="fas fa-times text-xl"></i>
        </button>
        <h3 class="text-xl font-bold mb-4 border-b pb-2"><i class="fas fa-edit text-blue-600"></i> Edit Transaksi</h3>
        
        <form method="POST">
            <input type="hidden" name="update_trx" value="1">
            <input type="hidden" name="edit_id" id="edit_id">
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                <input type="date" name="edit_date" id="edit_date" class="w-full border p-2 rounded text-sm" required>
            </div>
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Akun</label>
                <select name="edit_account_id" id="edit_account_id" class="w-full border p-2 rounded text-sm bg-white" required>
                    <?php foreach($accounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>"><?= $acc['code'] ?> - <?= $acc['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Nominal (Rp)</label>
                <input type="number" name="edit_amount" id="edit_amount" class="w-full border p-2 rounded text-sm" required>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Keterangan</label>
                <textarea name="edit_description" id="edit_description" class="w-full border p-2 rounded text-sm" rows="3"></textarea>
            </div>
            
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700">Simpan Perubahan</button>
        </form>
    </div>
</div>

<!-- IMPORT MODAL -->
<div id="importModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <button onclick="document.getElementById('importModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
            <i class="fas fa-times text-xl"></i>
        </button>
        
        <h3 class="text-xl font-bold mb-4 border-b pb-2 flex items-center gap-2">
            <i class="fas fa-file-excel text-green-600"></i> Import Data Keuangan
        </h3>

        <form id="form_import" method="POST">
            <input type="hidden" name="import_data" id="import_json_data">
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Pilih File Excel (.xlsx)</label>
                <input type="file" id="file_excel" accept=".xlsx, .xls" class="w-full border p-2 rounded text-sm bg-gray-50">
            </div>

            <div class="bg-blue-50 p-3 rounded text-xs text-blue-800 mb-4 border border-blue-100">
                <strong>INSTRUKSI IMPORT:</strong><br>
                1. Gunakan format Excel hasil export dari sistem ini.<br>
                2. Sistem akan otomatis melewati baris "Grand Total" dan "Summary Periode".<br>
                3. Pastikan kolom "Tanggal" dan "Referensi" (Kode Akun) terisi dengan benar.
            </div>

            <button type="button" onclick="processImport()" class="w-full bg-green-600 text-white py-2 rounded font-bold hover:bg-green-700">
                Upload & Proses
            </button>
        </form>
    </div>
</div>

<script>
// --- EDIT FUNCTION ---
function editTrx(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_date').value = data.date;
    document.getElementById('edit_account_id').value = data.account_id;
    document.getElementById('edit_amount').value = data.amount;
    document.getElementById('edit_description').value = data.description;
    
    document.getElementById('editModal').classList.remove('hidden');
}

// --- EXPORT FUNCTION ---
function exportToExcel() {
    const table = document.getElementById("financeTable");
    
    // Clone table to remove non-export columns (Actions)
    const clone = table.cloneNode(true);
    const noExports = clone.querySelectorAll('.no-export');
    noExports.forEach(el => el.remove());
    
    /* Generate Workbook */
    // table_to_book handles merged cells automatically
    let wb = XLSX.utils.table_to_book(clone, {sheet: "Data Keuangan"});
    /* Export to file */
    let filename = "Data_Keuangan_Periodik_" + new Date().toISOString().slice(0,10) + ".xlsx";
    XLSX.writeFile(wb, filename);
}

// --- IMPORT FUNCTION ---
function processImport() {
    const fileInput = document.getElementById('file_excel');
    if(!fileInput.files.length) {
        alert("Pilih file Excel terlebih dahulu!");
        return;
    }

    const file = fileInput.files[0];
    const reader = new FileReader();

    reader.onload = function(e) {
        const data = new Uint8Array(e.target.result);
        const workbook = XLSX.read(data, {type: 'array'});
        const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
        
        // Convert Sheet to JSON (Array of Arrays)
        const jsonData = XLSX.utils.sheet_to_json(firstSheet, {header: 1});

        // Skip 2 baris header pertama
        const transactionData = jsonData.slice(2);

        if(transactionData.length > 0) {
            if(confirm("Siap memproses data. Baris Grand Total dan Summary Periode akan dilewati otomatis. Lanjutkan?")) {
                 document.getElementById('import_json_data').value = JSON.stringify(transactionData);
                 document.getElementById('form_import').submit();
            }
        } else {
            alert("File Excel kosong.");
        }
    };
    
    reader.readAsArrayBuffer(file);
}
</script>