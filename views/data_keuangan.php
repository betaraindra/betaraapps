<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'SVP', 'MANAGER']);

// --- AMBIL DATA MASTER (Untuk Filter & Edit) ---
$accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();
$users = $pdo->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll();
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

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
            
            // Gunakan cleanNumber untuk sanitasi format rupiah
            $amount = cleanNumber($_POST['edit_amount']);
            
            $desc = $_POST['edit_description']; // Uraian
            $notes = $_POST['edit_notes']; // Keterangan
            
            // Gabungkan Notes ke Description jika ada
            $final_desc = $desc;
            if (!empty($notes)) {
                $final_desc .= " [Ket: $notes]";
            }
            
            // Cek Tipe Akun untuk update tipe transaksi (jika akun berubah)
            $accStmt = $pdo->prepare("SELECT type FROM accounts WHERE id = ?");
            $accStmt->execute([$acc_id]);
            $accType = $accStmt->fetchColumn(); // INCOME/EXPENSE
            
            $stmt = $pdo->prepare("UPDATE finance_transactions SET date=?, account_id=?, type=?, amount=?, description=? WHERE id=?");
            $stmt->execute([$date, $acc_id, $accType, $amount, $final_desc, $id]);
            
            logActivity($pdo, 'UPDATE_KEUANGAN', "Update transaksi ID: $id");
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Transaksi berhasil diperbarui.'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal update: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_keuangan';</script>";
        exit;
    }

    // 3. IMPORT EXCEL SESUAI FORMAT MASTER
    if (isset($_POST['import_data'])) {
        $data = json_decode($_POST['import_data'], true);
        $count = 0;
        $new_accounts = 0;
        
        $pdo->beginTransaction();
        try {
            // Cache Existing Accounts
            $accStmt = $pdo->query("SELECT id, code, name, type FROM accounts");
            $existingAccounts = []; // Map Code -> Data
            $existingAccountsByName = []; // Map Name -> Data
            while($a = $accStmt->fetch()) {
                $existingAccounts[strtoupper(trim($a['code']))] = $a;
                $existingAccountsByName[strtoupper(trim($a['name']))] = $a;
            }

            // Helper sanitasi currency
            $cleanExcelCurrency = function($val) {
                if (is_numeric($val)) return (float)$val;
                $val = strval($val);
                // Hapus Rp, spasi, dan karakter non-angka/koma/titik
                $val = preg_replace('/[^\d,.-]/', '', $val); 
                
                // Deteksi format: 1.000.000 (Indo) vs 1,000,000 (US)
                if (strpos($val, '.') !== false && strpos($val, ',') !== false) {
                    if (strrpos($val, ',') > strrpos($val, '.')) {
                         $val = str_replace('.', '', $val);
                         $val = str_replace(',', '.', $val);
                    } else {
                        $val = str_replace(',', '', $val);
                    }
                } elseif (strpos($val, ',') !== false) {
                    if (substr_count($val, ',') > 1) {
                        $val = str_replace(',', '', $val);
                    } else {
                        $parts = explode(',', $val);
                        if (strlen(end($parts)) == 2) { 
                            $val = str_replace(',', '.', $val); 
                        } else {
                            $val = str_replace(',', '', $val); 
                        }
                    }
                } else {
                    if (substr_count($val, '.') > 1) {
                        $val = str_replace('.', '', $val);
                    } else {
                         $parts = explode('.', $val);
                         if (strlen(end($parts)) == 3) {
                             $val = str_replace('.', '', $val); 
                         }
                    }
                }
                return (float)$val;
            };

            foreach($data as $index => $row) {
                // Ignore empty rows or rows with less than 3 columns
                if (empty($row) || count($row) < 3) continue;

                // Column Mapping based on Master Spreadsheet
                // 0: Tanggal, 1: Reference, 2: URAIAN, 3: Pemasukan Hari, 4: Pemasukan Bulan, 5: Pengeluaran Hari, 6: Pengeluaran Bulan, 7: Saldo, 8: KETERANGAN
                
                $col0 = trim(strval($row[0] ?? '')); // Tanggal
                $col2 = trim(strval($row[2] ?? '')); // Uraian

                // Skip Headers
                if ($index < 2) continue;
                if (stripos($col0, 'Tanggal') !== false) continue;
                
                // Skip Rows "Total Tahun", "Mutasi All Transaksi", etc.
                if (stripos($col0, 'Total') !== false || stripos($col2, 'Mutasi') !== false) continue;
                if (stripos($col0, ' - ') !== false && stripos($col2, 'Mutasi') !== false) continue;

                // Parsing Tanggal
                $dateRaw = $row[0] ?? '';
                if (empty($dateRaw)) continue;

                $dateDB = date('Y-m-d');
                if (is_numeric($dateRaw)) {
                    $unixDate = ($dateRaw - 25569) * 86400;
                    $dateDB = gmdate("Y-m-d", $unixDate);
                } else {
                    // Try parsing string date
                    $ts = strtotime($dateRaw);
                    if ($ts) $dateDB = date('Y-m-d', $ts);
                }

                // Nominal (Hanya baca kolom Per HARI)
                $valIn = isset($row[3]) ? $cleanExcelCurrency($row[3]) : 0;
                $valOut = isset($row[5]) ? $cleanExcelCurrency($row[5]) : 0;
                
                $amount = 0;
                $trxType = 'EXPENSE'; 

                if ($valIn > 0) {
                    $amount = $valIn;
                    $trxType = 'INCOME';
                } elseif ($valOut > 0) {
                    $amount = $valOut;
                    $trxType = 'EXPENSE';
                } else {
                    continue; // Skip nominal 0
                }

                // Resolve Account (Reference)
                $refInput = isset($row[1]) ? trim(strval($row[1])) : '';
                $uraian = isset($row[2]) ? trim(strval($row[2])) : 'Transaksi Import';
                $refUpper = strtoupper($refInput);
                
                $accId = null;

                if (!empty($refInput) && isset($existingAccounts[$refUpper])) {
                    $accId = $existingAccounts[$refUpper]['id'];
                } elseif (!empty($refInput) && isset($existingAccountsByName[$refUpper])) {
                    $accId = $existingAccountsByName[$refUpper]['id'];
                } else {
                    // Auto create account if reference is not empty and not found
                    if (!empty($refInput)) {
                        $prefix = ($trxType == 'INCOME') ? '1' : '2';
                        $newCode = $prefix . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                        $newAccName = $refInput;

                        $stmtNew = $pdo->prepare("INSERT INTO accounts (code, name, type) VALUES (?, ?, ?)");
                        $stmtNew->execute([$newCode, $newAccName, $trxType]);
                        $accId = $pdo->lastInsertId();
                        
                        $newAccountData = ['id'=>$accId, 'code'=>$newCode, 'name'=>$newAccName, 'type'=>$trxType];
                        $existingAccounts[$newCode] = $newAccountData;
                        $existingAccountsByName[strtoupper($newAccName)] = $newAccountData;
                        $new_accounts++;
                    } else {
                        // Fallback default account
                        $defName = ($trxType == 'INCOME') ? 'Pendapatan Lain' : 'Pengeluaran Umum';
                        if(isset($existingAccountsByName[strtoupper($defName)])) {
                            $accId = $existingAccountsByName[strtoupper($defName)]['id'];
                        }
                    }
                }
                
                if(!$accId) continue; // Skip if no account

                // Keterangan (Col 8)
                $keteranganCol = isset($row[8]) ? trim(strval($row[8])) : '';
                $desc = $uraian;
                if (!empty($keteranganCol) && $keteranganCol !== '-') {
                    $desc .= " [Ket: $keteranganCol]";
                }

                $stmtIns = $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$dateDB, $trxType, $accId, $amount, $desc, $_SESSION['user_id']]);
                $count++;
            }
            
            $pdo->commit();
            $msg = "$count Transaksi berhasil diimport.";
            if ($new_accounts > 0) $msg .= " ($new_accounts Akun baru dibuat otomatis)";
            
            logActivity($pdo, 'IMPORT_KEUANGAN', "Import Data Excel: $count Trx");
            $_SESSION['flash'] = ['type'=>'success', 'message'=>$msg];
        } catch(Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal Import: '.$e->getMessage()];
        }
        echo "<script>window.location='?page=data_keuangan';</script>";
        exit;
    }
}

// --- GET DATA & FILTER ---
$start = $_GET['start'] ?? date('Y-01-01');
$end = $_GET['end'] ?? date('Y-12-31');
$search = $_GET['q'] ?? '';
$filter_acc = $_GET['account_id'] ?? 'ALL';
$filter_wh = $_GET['warehouse_id'] ?? 'ALL';

$sql = "SELECT f.*, a.name as acc_name, a.code as acc_code 
        FROM finance_transactions f 
        JOIN accounts a ON f.account_id = a.id 
        WHERE f.date BETWEEN ? AND ?";

$params = [$start, $end];

if (!empty($search)) {
    $sql .= " AND (f.description LIKE ? OR a.name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($filter_acc !== 'ALL') {
    $sql .= " AND f.account_id = ?"; $params[] = $filter_acc;
}

// FILTER WILAYAH (Berdasarkan Tag Description)
if ($filter_wh !== 'ALL') {
    $stmt_wh_name = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
    $stmt_wh_name->execute([$filter_wh]);
    $wh_target_name = $stmt_wh_name->fetchColumn();
    if ($wh_target_name) {
        $sql .= " AND f.description LIKE ?";
        $params[] = "%[Wilayah: $wh_target_name]%";
    }
}

$sql .= " ORDER BY f.date ASC, f.created_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// --- PRE-CALCULATE GRAND TOTAL ---
$grand_total_in = 0;
$grand_total_out = 0;
foreach($transactions as $t) {
    if($t['type'] === 'INCOME') $grand_total_in += $t['amount'];
    else $grand_total_out += $t['amount'];
}

// --- GROUPING DATA BY MONTH ---
$grouped_data = [];
foreach ($transactions as $t) {
    $month_key = date('Y-m', strtotime($t['date']));
    $grouped_data[$month_key][] = $t;
}
?>

<!-- STYLE KHUSUS PRINT -->
<style>
    @media print {
        @page { size: landscape; margin: 5mm; }
        body { background: white; font-family: sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-size: 9pt; }
        .no-print, aside, header, .sidebar-scroll, #importModal, #editModal { display: none !important; }
        .print-only { display: block !important; }
        .shadow, .rounded-lg, .border-l-4 { box-shadow: none !important; border-radius: 0 !important; border-left: none !important; }
        
        table { width: 100%; border-collapse: collapse; font-size: 9pt; }
        th, td { border: 1px solid #000 !important; padding: 4px !important; }
        .bg-purple-200, .bg-gray-100 { background-color: #f3f4f6 !important; }
        .bg-green-100 { background-color: #dcfce7 !important; }
        .bg-red-100 { background-color: #fee2e2 !important; }
        .bg-blue-100 { background-color: #dbeafe !important; }
    }
    .print-only { display: none; }
</style>

<!-- UI HEADER (SCREEN ONLY) -->
<div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4 no-print">
    <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-table text-blue-600"></i> Data Keuangan (Master)</h2>
    
    <div class="flex gap-2">
        <button onclick="document.getElementById('importModal').classList.remove('hidden')" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold hover:bg-indigo-700 shadow flex items-center gap-2">
            <i class="fas fa-file-import"></i> Import Master
        </button>
        <button onclick="exportToExcel()" class="bg-green-600 text-white px-4 py-2 rounded font-bold hover:bg-green-700 shadow flex items-center gap-2">
            <i class="fas fa-file-export"></i> Export Master
        </button>
    </div>
</div>

<!-- FILTER -->
<div class="bg-white p-4 rounded-lg shadow mb-6 border-l-4 border-blue-600 no-print">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-6 gap-4 items-end">
        <input type="hidden" name="page" value="data_keuangan">
        
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Cari (Uraian/Ref)</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="w-full border p-2 rounded text-sm" placeholder="Ketik kata kunci...">
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
            <input type="date" name="start" value="<?= $start ?>" class="w-full border p-2 rounded text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
            <input type="date" name="end" value="<?= $end ?>" class="w-full border p-2 rounded text-sm">
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Akun</label>
            <select name="account_id" class="w-full border p-2 rounded text-sm bg-white">
                <option value="ALL">-- Semua --</option>
                <?php foreach($accounts as $acc): ?>
                    <option value="<?= $acc['id'] ?>" <?= $filter_acc == $acc['id'] ? 'selected' : '' ?>>
                        <?= $acc['code'] ?> - <?= $acc['name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Wilayah</label>
            <select name="warehouse_id" class="w-full border p-2 rounded text-sm bg-white">
                <option value="ALL">-- Semua --</option>
                <?php foreach($warehouses as $wh): ?>
                    <option value="<?= $wh['id'] ?>" <?= $filter_wh == $wh['id'] ? 'selected' : '' ?>>
                        <?= $wh['name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded font-bold hover:bg-blue-700 shadow text-sm w-full">
                <i class="fas fa-filter"></i> Tampilkan
            </button>
        </div>
    </form>
</div>

<!-- TABEL DATA -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table id="financeTable" class="w-full text-sm text-left border-collapse border border-gray-400">
            <!-- HEADER SESUAI MASTER SPREADSHEET -->
            <thead class="bg-gray-100 text-gray-900 font-bold border-b-2 border-gray-400 text-center">
                <tr>
                    <th rowspan="2" class="p-2 border border-gray-400 w-24 align-middle bg-white">Tanggal</th>
                    <th rowspan="2" class="p-2 border border-gray-400 w-32 align-middle bg-white">Reference</th>
                    <th rowspan="2" class="p-2 border border-gray-400 align-middle bg-white">URAIAN</th>
                    
                    <th colspan="2" class="p-2 border border-gray-400 bg-green-50">PEMASUKAN</th>
                    <th colspan="2" class="p-2 border border-gray-400 bg-red-50">PENGELUARAN</th>
                    
                    <th rowspan="2" class="p-2 border border-gray-400 w-32 align-middle bg-white">Sisa Saldo / Selisih Perbulan</th>
                    <th rowspan="2" class="p-2 border border-gray-400 w-48 align-middle bg-white">KETERANGAN</th>
                    <th rowspan="2" class="p-2 border border-gray-400 w-16 align-middle no-print bg-white">Aksi</th>
                </tr>
                <tr>
                    <!-- Sub Headers -->
                    <th class="p-2 border border-gray-400 w-28 bg-green-50 text-xs">Per HARI</th>
                    <th class="p-2 border border-gray-400 w-28 bg-green-50 text-xs">PER BULAN</th>
                    <th class="p-2 border border-gray-400 w-28 bg-red-50 text-xs">Per HARI</th>
                    <th class="p-2 border border-gray-400 w-28 bg-red-50 text-xs">PER BULAN</th>
                </tr>
            </thead>
            <tbody>
                <!-- GRAND TOTAL ROW (Top) -->
                <?php if(!empty($transactions)): ?>
                    <tr class="bg-blue-100 text-gray-900 font-bold border-b border-gray-400">
                        <td class="p-2 border border-gray-400">Total Periode</td>
                        <td class="p-2 border border-gray-400"></td>
                        <td class="p-2 border border-gray-400"></td>
                        
                        <!-- Total Pemasukan Harian -->
                        <td class="p-2 text-right border border-gray-400 text-green-900"><?= formatRupiah($grand_total_in) ?></td>
                        <td class="p-2 border border-gray-400 bg-gray-200"></td>
                        
                        <!-- Total Pengeluaran Harian -->
                        <td class="p-2 text-right border border-gray-400 text-red-900"><?= formatRupiah($grand_total_out) ?></td>
                        <td class="p-2 border border-gray-400 bg-gray-200"></td>
                        
                        <!-- Net -->
                        <td class="p-2 text-right border border-gray-400 <?= ($grand_total_in - $grand_total_out) >= 0 ? 'text-blue-900' : 'text-red-900' ?>">
                            <?= formatRupiah($grand_total_in - $grand_total_out) ?>
                        </td>
                        <td class="border border-gray-400 bg-gray-200"></td>
                        <td class="border border-gray-400 bg-gray-200 no-print"></td>
                    </tr>
                <?php endif; ?>

                <?php 
                if(empty($grouped_data)): ?>
                    <tr><td colspan="10" class="p-8 text-center text-gray-500">Tidak ada data transaksi.</td></tr>
                <?php else:
                    foreach($grouped_data as $month_key => $items): 
                        $month_in = 0;
                        $month_out = 0;
                        $month_net = 0;
                        
                        $id_months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                        $month_ts = strtotime($month_key . '-01');
                        $month_name_id = $id_months[date('n', $month_ts)-1];
                        $month_display = $month_name_id . " " . date('Y', $month_ts);
                        
                        $month_start_date = date('1 F Y', $month_ts);
                        $month_end_date = date('t F Y', $month_ts);
                        // Translate English months to ID for display
                        $en_m = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                        $month_start_date = str_ireplace($en_m, $id_months, $month_start_date);
                        $month_end_date = str_ireplace($en_m, $id_months, $month_end_date);
                ?>
                    
                    <!-- DATA TRANSAKSI ROWS -->
                    <?php foreach($items as $row): 
                        $in_amount = ($row['type'] == 'INCOME') ? $row['amount'] : 0;
                        $out_amount = ($row['type'] == 'EXPENSE') ? $row['amount'] : 0;
                        $month_in += $in_amount;
                        $month_out += $out_amount;

                        // Separate Description and Notes
                        $desc_main = $row['description'];
                        $desc_note = '';
                        // Pattern: "Main Description [Ket: Additional Notes]"
                        if (preg_match('/^(.*?)\[Ket:\s*(.*?)\]$/s', $row['description'], $matches)) {
                            $desc_main = trim($matches[1]);
                            $desc_note = trim($matches[2]);
                        }
                        
                        // Row Date Format (d F Y for export compatibility or d Month Y)
                        $row_date_ts = strtotime($row['date']);
                        $row_date_str = date('j F Y', $row_date_ts); // e.g. 1 January 2026
                        $row_date_str = str_ireplace($en_m, $id_months, $row_date_str); // Indo
                    ?>
                    <tr class="hover:bg-gray-50 border-b border-gray-300">
                        <td class="p-2 border border-gray-400 text-center whitespace-nowrap"><?= $row_date_str ?></td>
                        <td class="p-2 border border-gray-400 text-center font-bold text-gray-700"><?= $row['acc_name'] ?></td>
                        <td class="p-2 border border-gray-400"><?= $desc_main ?></td>
                        
                        <!-- Pemasukan Per Hari -->
                        <td class="p-2 border border-gray-400 text-right text-green-700">
                            <?= $in_amount > 0 ? formatRupiah($in_amount) : '' ?>
                        </td>
                        <!-- Pemasukan Per Bulan (Empty) -->
                        <td class="p-2 border border-gray-400 bg-gray-50"></td>
                        
                        <!-- Pengeluaran Per Hari -->
                        <td class="p-2 border border-gray-400 text-right text-red-700">
                            <?= $out_amount > 0 ? formatRupiah($out_amount) : '' ?>
                        </td>
                        <!-- Pengeluaran Per Bulan (Empty) -->
                        <td class="p-2 border border-gray-400 bg-gray-50"></td>
                        
                        <!-- Sisa Saldo (Empty) -->
                        <td class="p-2 border border-gray-400 bg-gray-50"></td>

                        <!-- Keterangan -->
                        <td class="p-2 border border-gray-400 text-sm text-gray-600"><?= $desc_note ?></td>
                        
                        <!-- Aksi -->
                        <td class="p-2 border border-gray-400 text-center no-print">
                            <div class="flex justify-center gap-1">
                                <button onclick='editTrx(<?= json_encode($row) ?>)' class="text-blue-500 hover:text-blue-700"><i class="fas fa-edit"></i></button>
                                <form method="POST" onsubmit="return confirm('Hapus?')" class="inline">
                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                    <button class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- SUMMARY BULANAN (MUTASI) -->
                    <?php $month_net = $month_in - $month_out; ?>
                    <tr class="bg-gray-100 font-bold text-gray-900 border-t-2 border-gray-600">
                        <td class="p-2 border border-gray-400 text-center text-xs">
                            <?= $month_start_date ?> - <?= $month_end_date ?>
                        </td>
                        <td class="p-2 border border-gray-400 bg-gray-200"></td>
                        <td class="p-2 border border-gray-400 text-center uppercase">
                            Mutasi All Transaksi Periode <?= $month_display ?>
                        </td>
                        
                        <!-- Daily Cols (Empty) -->
                        <td class="p-2 border border-gray-400 bg-gray-200"></td>
                        <!-- Total Pemasukan Bulanan -->
                        <td class="p-2 border border-gray-400 text-right text-green-800"><?= formatRupiah($month_in) ?></td>
                        
                        <!-- Daily Cols (Empty) -->
                        <td class="p-2 border border-gray-400 bg-gray-200"></td>
                        <!-- Total Pengeluaran Bulanan -->
                        <td class="p-2 border border-gray-400 text-right text-red-800"><?= formatRupiah($month_out) ?></td>
                        
                        <!-- Sisa Saldo Bulanan -->
                        <td class="p-2 border border-gray-400 text-right <?= $month_net>=0 ? 'text-blue-800' : 'text-red-800' ?>">
                            <?= formatRupiah($month_net) ?>
                        </td>
                        
                        <td class="p-2 border border-gray-400 bg-gray-200"></td>
                        <td class="p-2 border border-gray-400 bg-gray-200 no-print"></td>
                    </tr>
                    
                    <!-- Spacer -->
                    <tr><td colspan="10" class="h-4 border-none bg-white"></td></tr>

                <?php endforeach; endif; ?>
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
                <label class="block text-sm font-bold text-gray-700 mb-1">Referensi / Akun</label>
                <select name="edit_account_id" id="edit_account_id" class="w-full border p-2 rounded text-sm bg-white" required>
                    <?php foreach($accounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>"><?= $acc['code'] ?> - <?= $acc['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Nominal (Rp)</label>
                <input type="text" name="edit_amount" id="edit_amount" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-sm text-right" required>
            </div>
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Uraian (Utama)</label>
                <input type="text" name="edit_description" id="edit_description" class="w-full border p-2 rounded text-sm">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Keterangan (Tambahan)</label>
                <textarea name="edit_notes" id="edit_notes" class="w-full border p-2 rounded text-sm" rows="2"></textarea>
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
            <i class="fas fa-file-excel text-green-600"></i> Import Master
        </h3>
        <div class="text-sm text-gray-600 mb-4 bg-yellow-50 p-3 rounded border border-yellow-200">
            <p class="font-bold mb-1">Panduan Import:</p>
            <ul class="list-disc ml-4">
                <li>Gunakan <b>Export Excel</b> untuk template yang sesuai.</li>
                <li>Format Tanggal Excel (misal: 1 January 2026) akan dikenali.</li>
                <li>Data "Total" dan "Mutasi" (baris rekap) akan otomatis dilewati.</li>
                <li>Kolom: Tanggal(A), Ref(B), Uraian(C), Masuk Hari(D), Masuk Bulan(E), Keluar Hari(F), Keluar Bulan(G), Saldo(H), Keterangan(I).</li>
            </ul>
        </div>
        <form id="form_import" method="POST">
            <input type="hidden" name="import_data" id="import_json_data">
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Pilih File Excel (.xlsx)</label>
                <input type="file" id="file_excel" accept=".xlsx, .xls" class="w-full border p-2 rounded text-sm bg-gray-50">
            </div>
            <button type="button" onclick="processImport()" class="w-full bg-green-600 text-white py-2 rounded font-bold hover:bg-green-700">
                Upload & Proses
            </button>
        </form>
    </div>
</div>

<script>
// --- FORMAT RUPIAH ---
function formatRupiah(input) {
    let value = input.value.replace(/\D/g, '');
    if(value === '') { input.value = ''; return; }
    input.value = new Intl.NumberFormat('id-ID').format(value);
}

// --- EDIT FUNCTION ---
function editTrx(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_date').value = data.date;
    document.getElementById('edit_account_id').value = data.account_id;
    document.getElementById('edit_amount').value = new Intl.NumberFormat('id-ID').format(data.amount);
    
    // Split Description & Notes
    let desc = data.description;
    let notes = '';
    const match = desc.match(/^(.*?)\[Ket:\s*(.*?)\]$/s);
    if(match) {
        desc = match[1].trim();
        notes = match[2].trim();
    }
    
    document.getElementById('edit_description').value = desc;
    document.getElementById('edit_notes').value = notes;
    document.getElementById('editModal').classList.remove('hidden');
}

// --- EXPORT FUNCTION (CLIENT SIDE) ---
function exportToExcel() {
    const table = document.getElementById("financeTable");
    const clone = table.cloneNode(true);
    
    // 1. Hapus kolom/elemen yang tidak perlu (no-print)
    const noExports = clone.querySelectorAll('.no-print');
    noExports.forEach(el => el.remove());

    // 2. Hapus Baris Summary (Total Periode & Mutasi Bulanan) agar sesuai format Master
    const rows = clone.querySelectorAll('tbody tr'); // Hanya cek di body
    rows.forEach(row => {
        // Ambil text dari row, convert ke lowercase untuk safety
        const text = (row.innerText || row.textContent).toLowerCase();
        
        // Keyword yang menandakan baris summary
        // "Total Periode" ada di baris paling atas body
        // "Mutasi All Transaksi" ada di akhir setiap bulan
        if(text.includes('total periode') || text.includes('mutasi all transaksi')) {
            row.remove();
        }
    });

    let wb = XLSX.utils.table_to_book(clone, {sheet: "Laporan Keuangan"});
    XLSX.writeFile(wb, "Laporan_Keuangan_Master.xlsx");
}

// --- IMPORT FUNCTION ---
function processImport() {
    const fileInput = document.getElementById('file_excel');
    if(!fileInput.files.length) return alert("Pilih file!");
    const file = fileInput.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        const data = new Uint8Array(e.target.result);
        const workbook = XLSX.read(data, {type: 'array'});
        // Gunakan header:1 untuk mendapatkan array of arrays (karena header bertingkat)
        const jsonData = XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], {header: 1});
        document.getElementById('import_json_data').value = JSON.stringify(jsonData);
        document.getElementById('form_import').submit();
    };
    reader.readAsArrayBuffer(file);
}
</script>