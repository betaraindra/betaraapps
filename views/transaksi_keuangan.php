<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'SVP', 'MANAGER']);

// --- AMBIL DATA MASTER ---
$accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

// --- HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // SECURITY: Validate CSRF
    validate_csrf();
    
    // 1. HAPUS TRANSAKSI
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id']; 
        $stmt = $pdo->prepare("SELECT amount, type, description FROM finance_transactions WHERE id=?");
        $stmt->execute([$id]);
        $trx = $stmt->fetch();

        if ($trx) {
            $pdo->prepare("DELETE FROM finance_transactions WHERE id=?")->execute([$id]);
            logActivity($pdo, 'HAPUS_KEUANGAN', "Hapus Transaksi: {$trx['type']} Rp " . number_format($trx['amount']));
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Transaksi berhasil dihapus'];
        }
        echo "<script>window.location='?page=transaksi_keuangan';</script>";
        exit;
    }

    // 2. SIMPAN TRANSAKSI BARU
    if (isset($_POST['save_trx'])) {
        $date = $_POST['date'];
        $account_id = (int)$_POST['account_id'];
        
        // GUNAKAN cleanNumber() UNTUK MEMBERSIHKAN FORMAT RUPIAH (TITIK/KOMA)
        $amount = cleanNumber($_POST['amount']); 
        
        $description = strip_tags($_POST['description']); 
        $trx_type_input = $_POST['trx_type']; // INCOME atau EXPENSE
        
        // Validasi Akun
        $accTypeStmt = $pdo->prepare("SELECT type, code, name FROM accounts WHERE id = ?");
        $accTypeStmt->execute([$account_id]);
        $accData = $accTypeStmt->fetch(); 

        if (!$accData) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Akun Kategori tidak valid'];
        } else {
            // LOGIC BARU: PENANGANAN WILAYAH
            // Jika tipe EXPENSE (Keluar), User WAJIB pilih gudang/wilayah.
            // Tag [Wilayah: Nama] akan ditempel ke deskripsi agar masuk ke Laporan Internal
            
            if ($trx_type_input == 'EXPENSE') {
                if (empty($_POST['warehouse_id'])) {
                    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Untuk Pengeluaran, Wilayah/Gudang WAJIB dipilih!'];
                    echo "<script>window.location='?page=transaksi_keuangan';</script>";
                    exit;
                }
                
                $wh_id = (int)$_POST['warehouse_id'];
                $whNameStmt = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
                $whNameStmt->execute([$wh_id]);
                $whName = $whNameStmt->fetchColumn();
                
                if ($whName) {
                    $description .= " [Wilayah: $whName]";
                }
            } 
            // Jika INCOME, Wilayah Opsional (Kecuali Pemasukan Wilayah)
            elseif (!empty($_POST['warehouse_id'])) {
                $wh_id = (int)$_POST['warehouse_id'];
                $whNameStmt = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
                $whNameStmt->execute([$wh_id]);
                $whName = $whNameStmt->fetchColumn();
                if ($whName) $description .= " [Wilayah: $whName]";
            }

            $stmt = $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$date, $trx_type_input, $account_id, $amount, $description, $_SESSION['user_id']])) {
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Transaksi berhasil disimpan'];
            }
        }
        echo "<script>window.location='?page=transaksi_keuangan';</script>";
        exit;
    }
}

// AMBIL RIWAYAT TERAKHIR
$recent = $pdo->query("SELECT f.*, a.name as acc_name, a.code as acc_code, u.username FROM finance_transactions f JOIN accounts a ON f.account_id = a.id JOIN users u ON f.user_id = u.id ORDER BY f.created_at DESC LIMIT 10")->fetchAll();
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white p-6 rounded-lg shadow sticky top-6">
            <h3 class="font-bold text-xl mb-4 text-blue-800 border-b pb-2"><i class="fas fa-edit"></i> Input Keuangan</h3>
            
            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="save_trx" value="1">
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500" required>
                </div>

                <!-- PILIHAN TIPE TRANSAKSI (RADIO) -->
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-2">Jenis Transaksi</label>
                    <div class="flex gap-4">
                        <label class="flex items-center cursor-pointer p-2 border rounded hover:bg-green-50 transition border-green-200 w-full justify-center">
                            <input type="radio" name="trx_type" value="INCOME" class="mr-2" checked onchange="filterAccounts()">
                            <span class="text-green-700 font-bold text-xs md:text-sm"><i class="fas fa-arrow-down"></i> Pemasukan (In)</span>
                        </label>
                        <label class="flex items-center cursor-pointer p-2 border rounded hover:bg-red-50 transition border-red-200 w-full justify-center">
                            <input type="radio" name="trx_type" value="EXPENSE" class="mr-2" onchange="filterAccounts()">
                            <span class="text-red-700 font-bold text-xs md:text-sm"><i class="fas fa-arrow-up"></i> Pengeluaran (Out)</span>
                        </label>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Kategori (Akun)</label>
                    <select name="account_id" id="account_select" class="w-full border p-2 rounded bg-white focus:ring-2 focus:ring-blue-500 font-bold text-gray-700" required>
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach($accounts as $acc): ?>
                            <option value="<?= h($acc['id']) ?>" data-type="<?= h($acc['type']) ?>" data-code="<?= h($acc['code']) ?>">
                                <?= h($acc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- WAREHOUSE INPUT (OTOMATIS MUNCUL UNTUK SEMUA, WAJIB JIKA EXPENSE) -->
                <div id="warehouse_container" class="mb-4 bg-gray-50 p-3 rounded border border-gray-200 animate-fade-in">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Pilih Wilayah / Gudang <span id="lbl_wilayah_req" class="text-red-500">*</span></label>
                    <select name="warehouse_id" class="w-full border p-2 rounded bg-white text-sm focus:ring-2 focus:ring-blue-500 font-bold text-gray-800">
                        <option value="">-- Pilih Wilayah (Opsional untuk Pemasukan) --</option>
                        <?php foreach($warehouses as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[10px] text-gray-500 mt-1 italic">
                        <i class="fas fa-info-circle"></i> Lokasi untuk mencatat arus kas cabang/proyek.
                    </p>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Nominal (Rp)</label>
                    <!-- Input Type Text agar bisa diformat -->
                    <input type="text" name="amount" id="amount_input" onkeyup="formatRupiah(this)" class="w-full border p-2 rounded text-right font-mono font-bold text-lg focus:ring-2 focus:ring-blue-500" placeholder="0" required>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Keterangan Detail</label>
                    <textarea name="description" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500" rows="3" placeholder="Contoh: Beli Token Listrik / Gaji Staff A"></textarea>
                </div>

                <button type="submit" class="w-full bg-blue-600 text-white py-3 rounded font-bold shadow hover:bg-blue-700 transition transform active:scale-95">
                    <i class="fas fa-save"></i> Simpan Transaksi
                </button>
            </form>
        </div>
    </div>

    <!-- TABLE RIWAYAT -->
    <div class="lg:col-span-2">
        <div class="bg-white p-6 rounded-lg shadow h-full flex flex-col">
            <h3 class="font-bold text-lg text-gray-800 mb-4 border-b pb-2">Riwayat Transaksi Terakhir</h3>
            <div class="flex-1 overflow-y-auto">
                <table class="w-full text-sm text-left border-collapse">
                    <thead class="bg-gray-100 text-gray-700">
                        <tr>
                            <th class="p-3 border-b">Tanggal</th>
                            <th class="p-3 border-b">Kategori</th>
                            <th class="p-3 border-b">Ket</th>
                            <th class="p-3 border-b text-right">Jumlah</th>
                            <th class="p-3 border-b text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($recent as $r): ?>
                        <tr class="hover:bg-gray-50 group">
                            <td class="p-3 whitespace-nowrap text-gray-600"><?= date('d/m/y', strtotime($r['date'])) ?></td>
                            <td class="p-3">
                                <div class="font-bold text-gray-800"><?= h($r['acc_name']) ?></div>
                                <div class="text-xs text-gray-500 font-mono"><?= h($r['acc_code']) ?></div>
                            </td>
                            <td class="p-3 text-gray-600 italic truncate max-w-xs" title="<?= h($r['description']) ?>">
                                <?= h($r['description']) ?>
                            </td>
                            <td class="p-3 text-right font-bold font-mono <?= $r['type']=='INCOME'?'text-green-600':'text-red-600' ?>">
                                <?= $r['type']=='INCOME' ? '+' : '-' ?> <?= number_format($r['amount'], 0, ',', '.') ?>
                            </td>
                            <td class="p-3 text-center">
                                <form method="POST" onsubmit="return confirm('Hapus transaksi ini?')" class="inline opacity-50 group-hover:opacity-100 transition">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_id" value="<?= h($r['id']) ?>">
                                    <button class="text-red-400 hover:text-red-600 p-1"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($recent)): ?>
                            <tr><td colspan="5" class="p-4 text-center text-gray-400">Belum ada transaksi.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Format Rupiah saat mengetik (contoh: 10.000.000)
function formatRupiah(input) {
    let value = input.value.replace(/\D/g, '');
    if(value === '') { input.value = ''; return; }
    input.value = new Intl.NumberFormat('id-ID').format(value);
}

function filterAccounts() {
    const type = document.querySelector('input[name="trx_type"]:checked').value;
    const select = document.getElementById('account_select');
    const options = select.options;
    const whContainer = document.getElementById('warehouse_container');
    const whSelect = document.querySelector('select[name="warehouse_id"]');
    const reqLabel = document.getElementById('lbl_wilayah_req');
    
    // Reset selection
    select.value = "";

    // LOGIKA WILAYAH: 
    // Tampil untuk SEMUA. 
    // WAJIB (Required) jika PENGELUARAN (Expense).
    // OPSIONAL jika PEMASUKAN (Income).
    
    whContainer.classList.remove('hidden'); 

    if (type === 'EXPENSE') {
        whSelect.setAttribute('required', 'required');
        reqLabel.classList.remove('hidden');
        whContainer.classList.replace('bg-green-50', 'bg-red-50'); // Optional: visual cue
        whContainer.classList.replace('bg-gray-50', 'bg-red-50');
        whContainer.classList.add('bg-red-50');
    } else {
        whSelect.removeAttribute('required');
        reqLabel.classList.add('hidden');
        whContainer.classList.replace('bg-red-50', 'bg-green-50');
        whContainer.classList.remove('bg-red-50');
        whContainer.classList.add('bg-green-50');
    }

    for (let i = 0; i < options.length; i++) {
        const opt = options[i];
        const optType = opt.getAttribute('data-type');
        
        if (!optType) { // Placeholder
            opt.style.display = 'block';
            continue; 
        }

        // LOGIKA FILTER KATEGORI:
        // Jika INCOME (Uang Masuk): Tampilkan Akun INCOME, EQUITY (Modal), LIABILITY (Hutang)
        // Jika EXPENSE (Uang Keluar): Tampilkan Akun EXPENSE, EQUITY (Prive), LIABILITY (Bayar Hutang), ASSET (Beli Aset)
        
        let show = false;
        if (type === 'INCOME') {
            if (optType === 'INCOME' || optType === 'EQUITY' || optType === 'LIABILITY') show = true;
        } else {
            if (optType === 'EXPENSE' || optType === 'EQUITY' || optType === 'LIABILITY' || optType === 'ASSET') show = true;
        }

        opt.style.display = show ? 'block' : 'none';
    }
}

// Init filter on load
document.addEventListener('DOMContentLoaded', filterAccounts);
</script>