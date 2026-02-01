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

    // 3. IMPORT EXCEL SESUAI FORMAT BARU (3 ROW HEADER)
    if (isset($_POST['import_data'])) {
        $data = json_decode($_POST['import_data'], true);
        $count = 0;
        $new_accounts = 0;
        
        $pdo->beginTransaction();
        try {
            // Cache Existing Accounts: Map Code -> Data AND Name -> Data
            $accStmt = $pdo->query("SELECT id, code, name, type FROM accounts");
            $existingAccounts = []; // Map Code -> Data
            $existingAccountsByName = []; // Map Name -> Data
            while($a = $accStmt->fetch()) {
                $existingAccounts[strtoupper(trim($a['code']))] = $a;
                $existingAccountsByName[strtoupper(trim($a['name']))] = $a;
            }

            // Helper sanitasi currency dari Excel
            $cleanExcelCurrency = function($val) {
                if (is_numeric($val)) return (float)$val;
                $val = strval($val);
                // Hapus Rp, spasi, dan karakter non-angka/koma/titik
                $val = preg_replace('/[^\d,.-]/', '', $val); 
                
                // Deteksi format: 1.000.000 (Indo) vs 1,000,000 (US)
                if (strpos($val, '.') !== false && strpos($val, ',') !== false) {
                    // Ada titik dan koma.
                    if (strrpos($val, ',') > strrpos($val, '.')) {
                         // Indo: 1.000,00
                         $val = str_replace('.', '', $val);
                         $val = str_replace(',', '.', $val);
                    } else {
                        // US: 1,000.00
                        $val = str_replace(',', '', $val);
                    }
                } elseif (strpos($val, ',') !== false) {
                    // Cuma ada koma
                    if (substr_count($val, ',') > 1) {
                        $val = str_replace(',', '', $val); // 1,000,000 -> 1000000
                    } else {
                        // Cek panjang desimal
                        $parts = explode(',', $val);
                        if (strlen(end($parts)) == 2) { 
                            $val = str_replace(',', '.', $val); // 500,00 -> 500.00
                        } else {
                            $val = str_replace(',', '', $val); // 500,000 -> 500000
                        }
                    }
                } else {
                    // Cuma ada titik
                    if (substr_count($val, '.') > 1) {
                        $val = str_replace('.', '', $val); // 1.000.000 -> 1000000
                    } else {
                         $parts = explode('.', $val);
                         if (strlen(end($parts)) == 3) {
                             $val = str_replace('.', '', $val); // 500.000 -> 500000
                         }
                    }
                }
                
                return (float)$val;
            };

            foreach($data as $index => $row) {
                // Pastikan row memiliki minimal kolom
                if (empty($row) || count($row) < 2) continue;
                
                $col0 = trim(strval($row[0] ?? '')); // Tanggal
                
                // --- SKIP LOGIC HEADER ---
                // Skip jika kolom Tanggal berisi "TANGGAL"
                if (stripos($col0, 'TANGGAL') !== false) continue;
                // Skip jika kolom Tanggal kosong DAN kolom Pemasukan/Pengeluaran berisi Header
                $col3 = isset($row[3]) ? strval($row[3]) : '';
                if (empty($col0) && (stripos($col3, 'Pemasukan') !== false || stripos($col3, 'RP') !== false)) continue;
                // Skip baris Total/Periode di dalam data
                if (stripos($col0, 'Periode') !== false || stripos($col0, 'TOTAL') !== false) continue;

                // --- MAPPING KOLOM (SESUAI EXCEL USER) ---
                // [0] TANGGAL
                // [1] Referensi
                // [2] URAIAN
                // [3] Pemasukan (RP)
                // [4] Pemasukan Bulanan (Ignored)
                // [5] Pengeluaran (RP)
                // [6] Pengeluaran Bulanan (Ignored)
                // [7] Sisa Saldo (Ignored)
                // [8] Keterangan

                // 1. Parsing Tanggal
                $dateRaw = $row[0] ?? '';
                if (empty($dateRaw)) continue; // Skip jika tanggal kosong

                $dateDB = date('Y-m-d');
                if (is_numeric($dateRaw)) {
                    $unixDate = ($dateRaw - 25569) * 86400;
                    $dateDB = gmdate("Y-m-d", $unixDate);
                } else {
                    $dateRaw = trim($dateRaw);
                    // Support Indo Text Dates: "3 Jan 2025" or "03 Januari 2025"
                    $id_months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember',
                                  'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
                    $en_months = ['January','February','March','April','May','June','July','August','September','October','November','December',
                                  'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                    
                    $dateStr = str_ireplace($id_months, $en_months, $dateRaw);
                    $ts = strtotime($dateStr);
                    if ($ts) $dateDB = date('Y-m-d', $ts);
                }

                // 2. Nominal & Tipe
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
                    continue; // Skip jika nominal 0
                }

                // 3. Resolve Account (Referensi -> Kode/Nama Akun)
                $refInput = isset($row[1]) ? trim(strval($row[1])) : '';
                $uraian = isset($row[2]) ? trim(strval($row[2])) : 'Transaksi Import';
                $refUpper = strtoupper($refInput);
                
                $accId = null;

                // A. Cek berdasarkan KODE
                if (!empty($refInput) && isset($existingAccounts[$refUpper])) {
                    $accId = $existingAccounts[$refUpper]['id'];
                }
                // B. Cek berdasarkan NAMA
                elseif (!empty($refInput) && isset($existingAccountsByName[$refUpper])) {
                    $accId = $existingAccountsByName[$refUpper]['id'];
                }
                // C. Auto-Create
                else {
                    if (empty($refInput)) {
                        $refInput = ($trxType == 'INCOME') ? 'Pendapatan Lain' : 'Pengeluaran Umum';
                    }

                    $prefix = ($trxType == 'INCOME') ? '1' : '2';
                    $newCode = $prefix . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                    
                    // Ensure unique code
                    $safety = 0;
                    while(isset($existingAccounts[$newCode]) && $safety < 100) {
                        $newCode = $prefix . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                        $safety++;
                    }

                    $newAccName = $refInput;

                    $stmtNew = $pdo->prepare("INSERT INTO accounts (code, name, type) VALUES (?, ?, ?)");
                    $stmtNew->execute([$newCode, $newAccName, $trxType]);
                    $accId = $pdo->lastInsertId();
                    
                    $newAccountData = ['id'=>$accId, 'code'=>$newCode, 'name'=>$newAccName, 'type'=>$trxType];
                    $existingAccounts[$newCode] = $newAccountData;
                    $existingAccountsByName[strtoupper($newAccName)] = $newAccountData;
                    $new_accounts++;
                }

                // 4. Keterangan (Col 8 / Index 8)
                $keteranganCol = isset($row[8]) ? trim(strval($row[8])) : '';
                $desc = $uraian;
                
                if (!empty($keteranganCol) && $keteranganCol !== '-') {
                    $desc .= " [Ket: $keteranganCol]";
                }

                // 5. Insert Transaction
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
// Set Default Range to Current Year to show more data
$start = $_GET['start'] ?? date('Y-01-01');
$end = $_GET['end'] ?? date('Y-12-31');
$search = $_GET['q'] ?? '';
$filter_acc = $_GET['account_id'] ?? 'ALL';
$filter_type = $_GET['type'] ?? 'ALL';
$filter_user = $_GET['user_id'] ?? 'ALL';
$filter_wh = $_GET['warehouse_id'] ?? 'ALL';

$sql = "SELECT f.*, a.name as acc_name, a.code as acc_code, u.username 
        FROM finance_transactions f 
        JOIN accounts a ON f.account_id = a.id 
        LEFT JOIN users u ON f.user_id = u.id
        WHERE f.date BETWEEN ? AND ?";

$params = [$start, $end];

if (!empty($search)) {
    $sql .= " AND (f.description LIKE ? OR a.name LIKE ? OR a.code LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($filter_acc !== 'ALL') {
    $sql .= " AND f.account_id = ?"; $params[] = $filter_acc;
}
if ($filter_type !== 'ALL') {
    $sql .= " AND f.type = ?"; $params[] = $filter_type;
}
if ($filter_user !== 'ALL') {
    $sql .= " AND f.user_id = ?"; $params[] = $filter_user;
}
if ($filter_wh !== 'ALL') {
    $stmt_wh = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
    $stmt_wh->execute([$filter_wh]);
    $wh_name = $stmt_wh->fetchColumn();
    if ($wh_name) {
        $sql .= " AND f.description LIKE ?"; $params[] = "%$wh_name%";
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
        .bg-purple-200 { background-color: #e9d5ff !important; }
        .bg-green-100 { background-color: #dcfce7 !important; }
        .bg-red-100 { background-color: #fee2e2 !important; }
        .bg-blue-100 { background-color: #dbeafe !important; }
        .bg-cyan-300 { background-color: #67e8f9 !important; }
    }
    .print-only { display: none; }
</style>

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

<!-- FILTER GRID -->
<div class="bg-white p-4 rounded-lg shadow mb-6 border-l-4 border-blue-600 no-print">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-5 gap-4 items-end">
        <input type="hidden" name="page" value="data_keuangan">
        
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Cari (Uraian/Ref)</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-blue-500" placeholder="Ketik kata kunci...">
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Dari Tanggal</label>
            <input type="date" name="start" value="<?= $start ?>" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Sampai Tanggal</label>
            <input type="date" name="end" value="<?= $end ?>" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Akun</label>
            <select name="account_id" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-blue-500">
                <option value="ALL">-- Semua --</option>
                <?php foreach($accounts as $acc): ?>
                    <option value="<?= $acc['id'] ?>" <?= $filter_acc == $acc['id'] ? 'selected' : '' ?>>
                        <?= $acc['code'] ?> - <?= $acc['name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Filter Wilayah</label>
            <select name="warehouse_id" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-blue-500">
                <option value="ALL">-- Semua Wilayah --</option>
                <?php foreach($warehouses as $wh): ?>
                    <option value="<?= $wh['id'] ?>" <?= $filter_wh == $wh['id'] ? 'selected' : '' ?>>
                        <?= $wh['name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="md:col-span-4 lg:col-span-5 flex justify-end gap-2 border-t pt-3 mt-1">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded font-bold hover:bg-blue-700 shadow text-sm">
                <i class="fas fa-filter"></i> Tampilkan
            </button>
            <button type="button" onclick="window.print()" class="bg-gray-700 text-white px-6 py-2 rounded font-bold hover:bg-gray-800 shadow text-sm">
                <i class="fas fa-print"></i> Cetak
            </button>
        </div>
    </form>
</div>

<!-- TABEL DATA SESUAI REQUEST (HEADER BERTINGKAT 3 BARIS) -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table id="financeTable" class="w-full text-sm text-left border-collapse border border-gray-400">
            <!-- 3 HEADER ROWS -->
            <thead class="bg-purple-200 text-gray-900 font-bold border-b-2 border-gray-400">
                <tr>
                    <th rowspan="3" class="p-2 border border-gray-400 text-center w-24 align-middle">TANGGAL</th>
                    <th rowspan="3" class="p-2 border border-gray-400 text-center w-32 align-middle">Referensi</th>
                    <th rowspan="3" class="p-2 border border-gray-400 align-middle">URAIAN</th>
                    
                    <th colspan="2" class="p-2 border border-gray-400 text-center bg-green-100">Pemasukan</th>
                    <th colspan="2" class="p-2 border border-gray-400 text-center bg-red-100">Pengeluaran</th>
                    
                    <th rowspan="3" class="p-2 border border-gray-400 text-right w-32 align-middle">Sisa Saldo/<br>Sub Total Perbulan</th>
                    <th rowspan="3" class="p-2 border border-gray-400 align-middle w-48">Keterangan</th>
                    <!-- Kolom Aksi (No Print) -->
                    <th rowspan="3" class="p-2 border border-gray-400 text-center w-16 align-middle no-print">Aksi</th>
                </tr>
                <tr>
                    <!-- Sub Header Level 2 -->
                    <th class="p-2 border border-gray-400 text-center w-28 bg-green-50">Pemasukan</th>
                    <th class="p-2 border border-gray-400 text-center w-28 bg-green-50">Bulanan</th>
                    <th class="p-2 border border-gray-400 text-center w-28 bg-red-50">Pengeluaran</th>
                    <th class="p-2 border border-gray-400 text-center w-28 bg-red-50">Bulanan</th>
                </tr>
                <tr>
                    <!-- Sub Header Level 3 -->
                    <th class="p-1 border border-gray-400 text-center text-xs">RP</th>
                    <th class="p-1 border border-gray-400 text-center text-xs">RP</th>
                    <th class="p-1 border border-gray-400 text-center text-xs">RP</th>
                    <th class="p-1 border border-gray-400 text-center text-xs">RP</th>
                </tr>
            </thead>
            <tbody>
                <!-- BARIS GRAND TOTAL DI ATAS -->
                <?php if(!empty($transactions)): ?>
                    <tr class="bg-cyan-300 text-gray-900 font-extrabold border-b-4 border-double border-gray-800">
                        <td colspan="3" class="p-2 text-center uppercase border border-gray-800">URAIAN (TOTAL)</td>
                        
                        <!-- Total Pemasukan -->
                        <td class="p-2 text-right border border-gray-800"><?= formatRupiah($grand_total_in) ?></td>
                        <td class="p-2 text-right border border-gray-800"><?= formatRupiah($grand_total_in) ?></td>
                        
                        <!-- Total Pengeluaran -->
                        <td class="p-2 text-right border border-gray-800"><?= formatRupiah($grand_total_out) ?></td>
                        <td class="p-2 text-right border border-gray-800"><?= formatRupiah($grand_total_out) ?></td>
                        
                        <!-- Net -->
                        <td class="p-2 text-right border border-gray-800 <?= ($grand_total_in - $grand_total_out) >= 0 ? 'text-blue-900' : 'text-red-900' ?>">
                            <?= formatRupiah($grand_total_in - $grand_total_out) ?>
                        </td>
                        
                        <td class="border border-gray-800"></td>
                        <td class="border border-gray-800 no-print"></td>
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
                        $month_num = date('n', strtotime($month_key . '-01'));
                        $month_display = $id_months[$month_num-1] . " " . date('Y', strtotime($month_key . '-01'));
                ?>
                    
                    <!-- DATA TRANSAKSI -->
                    <?php foreach($items as $row): 
                        $in_amount = ($row['type'] == 'INCOME') ? $row['amount'] : 0;
                        $out_amount = ($row['type'] == 'EXPENSE') ? $row['amount'] : 0;
                        $month_in += $in_amount;
                        $month_out += $out_amount;

                        // Ekstrak Keterangan [Ket: ...] -> Masukkan ke kolom Keterangan
                        $desc_clean = $row['description'];
                        $extra_ket = '';
                        if (preg_match('/\[Ket:\s*(.*?)\]/', $row['description'], $matches)) {
                            $extra_ket = $matches[1];
                            $desc_clean = trim(str_replace($matches[0], '', $row['description']));
                        }
                    ?>
                    <tr class="hover:bg-gray-50 border-b border-gray-300">
                        <!-- Tanggal: 3 Jan 2025 -->
                        <td class="p-2 border border-gray-300 text-center whitespace-nowrap">
                            <?= date('d M Y', strtotime($row['date'])) ?>
                        </td>
                        <!-- Referensi (Nama Akun) -->
                        <td class="p-2 border border-gray-300 text-center font-bold text-gray-700">
                            <?= $row['acc_name'] ?>
                        </td>
                        <!-- Uraian -->
                        <td class="p-2 border border-gray-300">
                            <?= $desc_clean ?>
                        </td>
                        
                        <!-- Pemasukan -->
                        <td class="p-2 border border-gray-300 text-right text-gray-700 bg-green-50/30">
                            <?= $in_amount > 0 ? formatRupiah($in_amount) : '' ?>
                        </td>
                        <td class="p-2 border border-gray-300 bg-gray-50"></td>
                        
                        <!-- Pengeluaran -->
                        <td class="p-2 border border-gray-300 text-right text-gray-700 bg-red-50/30">
                            <?= $out_amount > 0 ? formatRupiah($out_amount) : '' ?>
                        </td>
                        <td class="p-2 border border-gray-300 bg-gray-50"></td>
                        
                        <!-- Sisa Saldo (Empty) -->
                        <td class="p-2 border border-gray-300 bg-gray-50"></td>

                        <!-- Keterangan -->
                        <td class="p-2 border border-gray-300 text-sm text-gray-600">
                            <?= $extra_ket ?>
                        </td>
                        
                        <!-- Aksi (No Print) -->
                        <td class="p-2 border border-gray-300 text-center no-print">
                            <div class="flex justify-center gap-2">
                                <button onclick='editTrx(<?= json_encode($row) ?>)' class="text-blue-500 hover:text-blue-700"><i class="fas fa-edit"></i></button>
                                <form method="POST" onsubmit="return confirm('Hapus?')" class="inline">
                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                    <button class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- SUMMARY BULANAN -->
                    <?php $month_net = $month_in - $month_out; ?>
                    <tr class="bg-orange-100 font-bold text-gray-800 border-t-2 border-gray-400">
                        <td colspan="3" class="p-2 border border-gray-400 text-center uppercase">
                            Periode <?= $month_display ?>
                        </td>
                        
                        <!-- Total Pemasukan Bulanan -->
                        <td class="p-2 border border-gray-400 bg-gray-100"></td>
                        <td class="p-2 border border-gray-400 text-right text-green-800"><?= formatRupiah($month_in) ?></td>
                        
                        <!-- Total Pengeluaran Bulanan -->
                        <td class="p-2 border border-gray-400 bg-gray-100"></td>
                        <td class="p-2 border border-gray-400 text-right text-red-800"><?= formatRupiah($month_out) ?></td>
                        
                        <!-- Sisa Saldo Bulanan -->
                        <td class="p-2 border border-gray-400 text-right <?= $month_net>=0 ? 'text-blue-800' : 'text-red-800' ?>">
                            <?= formatRupiah($month_net) ?>
                        </td>
                        
                        <td class="p-2 border border-gray-400 text-center text-xs italic text-gray-500">
                            <?= $month_net >= 0 ? 'Surplus' : 'Defisit' ?>
                        </td>
                        <td class="p-2 border border-gray-400 no-print"></td>
                    </tr>
                    
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
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Uraian / Keterangan</label>
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
            <i class="fas fa-file-excel text-green-600"></i> Import Excel
        </h3>
        <div class="text-sm text-gray-600 mb-4 bg-yellow-50 p-3 rounded border border-yellow-200">
            <p class="font-bold mb-1">Panduan Import:</p>
            <ul class="list-disc ml-4">
                <li>Gunakan <b>Export Excel</b> untuk mendapatkan template.</li>
                <li>Format Tanggal Excel (misal: 3 Jan 2025) akan otomatis dikenali.</li>
                <li>Jika Nama Akun di kolom Referensi tidak ditemukan, akun baru akan dibuat otomatis.</li>
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
    
    // Bersihkan tag ket dari deskripsi untuk edit (opsional)
    // let cleanDesc = data.description.replace(/\[Ket:.*?\]/, '').trim();
    document.getElementById('edit_description').value = data.description;
    
    document.getElementById('editModal').classList.remove('hidden');
}

// --- EXPORT FUNCTION ---
function exportToExcel() {
    const table = document.getElementById("financeTable");
    const clone = table.cloneNode(true);
    const noExports = clone.querySelectorAll('.no-print');
    noExports.forEach(el => el.remove());
    let wb = XLSX.utils.table_to_book(clone, {sheet: "Laporan Keuangan"});
    XLSX.writeFile(wb, "Laporan_Keuangan_Export.xlsx");
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