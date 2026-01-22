<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'SVP', 'MANAGER']);

// --- 1. HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // DELETE
    if (isset($_POST['delete_id'])) {
        $id = $_POST['delete_id'];
        $stmt = $pdo->prepare("SELECT amount, type FROM finance_transactions WHERE id=?");
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

    // ADD NEW
    if (isset($_POST['save_trx'])) {
        $date = $_POST['date'];
        $type = $_POST['type'];
        $account_id = $_POST['account_id'];
        $amount = (float)str_replace(['.',','], '', $_POST['amount']); 
        $description = $_POST['description'];

        $stmt = $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$date, $type, $account_id, $amount, $description, $_SESSION['user_id']])) {
            $accName = $pdo->query("SELECT name FROM accounts WHERE id=$account_id")->fetchColumn();
            logActivity($pdo, 'ADD_KEUANGAN', "Input $type: Rp " . number_format($amount) . " ($accName)");
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Transaksi berhasil disimpan'];
            echo "<script>window.location='?page=transaksi_keuangan';</script>";
            exit;
        }
    }

    // UPDATE
    if (isset($_POST['update_trx'])) {
        $id = $_POST['edit_id'];
        $date = $_POST['edit_date'];
        $account_id = $_POST['edit_account_id'];
        $amount = (float)str_replace(['.',','], '', $_POST['edit_amount']);
        $description = $_POST['edit_description'];
        
        // Ambil tipe akun untuk update tipe transaksi otomatis
        $accType = $pdo->query("SELECT type FROM accounts WHERE id=$account_id")->fetchColumn();

        $stmt = $pdo->prepare("UPDATE finance_transactions SET date=?, type=?, account_id=?, amount=?, description=? WHERE id=?");
        if ($stmt->execute([$date, $accType, $account_id, $amount, $description, $id])) {
            logActivity($pdo, 'EDIT_KEUANGAN', "Edit Transaksi ID: $id");
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Transaksi berhasil diperbarui'];
            echo "<script>window.location='?page=transaksi_keuangan';</script>";
            exit;
        }
    }
}

$accounts = $pdo->query("SELECT * FROM accounts ORDER BY type, code")->fetchAll();
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- FORM INPUT -->
    <div class="bg-white p-6 rounded-lg shadow lg:col-span-1 h-fit">
        <h3 class="text-xl font-bold mb-4 text-blue-800 border-b pb-2"><i class="fas fa-money-bill-wave"></i> Input Keuangan</h3>
        
        <form method="POST" id="financeForm">
            <input type="hidden" name="save_trx" value="1">
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="w-full border border-gray-300 rounded p-2 focus:ring-2 focus:ring-blue-500 focus:outline-none">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Jenis Transaksi</label>
                <div class="grid grid-cols-2 gap-4">
                    <label class="cursor-pointer border p-2 rounded hover:bg-green-50 flex items-center gap-2">
                        <input type="radio" name="type" value="INCOME" checked class="text-green-600 focus:ring-green-500" onchange="filterAccounts()"> 
                        <span class="font-medium text-green-800">Pemasukan</span>
                    </label>
                    <label class="cursor-pointer border p-2 rounded hover:bg-red-50 flex items-center gap-2">
                        <input type="radio" name="type" value="EXPENSE" class="text-red-600 focus:ring-red-500" onchange="filterAccounts()"> 
                        <span class="font-medium text-red-800">Pengeluaran</span>
                    </label>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Akun</label>
                <select name="account_id" id="accountSelect" class="w-full border border-gray-300 rounded p-2 focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white" required>
                    <option value="">-- Pilih Akun --</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>" data-type="<?= $acc['type'] ?>"><?= $acc['code'] ?> - <?= $acc['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Nominal (Rp)</label>
                <div class="relative">
                    <span class="absolute left-3 top-2 text-gray-500 text-sm">Rp</span>
                    <input type="number" name="amount" class="w-full border border-gray-300 rounded p-2 pl-10 font-bold text-lg focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="0" required>
                </div>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-bold text-gray-700 mb-1">Keterangan</label>
                <textarea name="description" class="w-full border border-gray-300 rounded p-2 focus:ring-2 focus:ring-blue-500 focus:outline-none" rows="3" placeholder="Contoh: Pembayaran Listrik Bulan Januari"></textarea>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-3 rounded-lg font-bold hover:bg-blue-700 transition shadow-lg transform active:scale-95">
                <i class="fas fa-save mr-2"></i> Simpan Transaksi
            </button>
        </form>
    </div>

    <!-- RIWAYAT TRANSAKSI -->
    <div class="bg-white p-6 rounded-lg shadow lg:col-span-2 flex flex-col h-full min-h-[500px]">
        <h3 class="text-xl font-bold mb-4 text-gray-800 border-b pb-2">Riwayat Transaksi Hari Ini</h3>
        
        <div class="flex-1 overflow-y-auto max-h-[600px]">
            <table class="w-full text-sm text-left border-collapse">
                <thead class="bg-gray-100 text-gray-600 sticky top-0 z-10 shadow-sm">
                    <tr>
                        <th class="p-3 border-b">Waktu</th>
                        <th class="p-3 border-b">Akun</th>
                        <th class="p-3 border-b">Ket</th>
                        <th class="p-3 border-b text-right">Nominal</th>
                        <th class="p-3 border-b text-center w-24">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php
                    $today = date('Y-m-d');
                    // Tampilkan semua jika manager/admin, atau filter per hari ini saja
                    $txs = $pdo->query("SELECT f.*, a.name as account_name FROM finance_transactions f JOIN accounts a ON f.account_id = a.id WHERE f.date = '$today' ORDER BY f.created_at DESC")->fetchAll();
                    
                    foreach($txs as $t): 
                        $time = date('H:i', strtotime($t['created_at']));
                    ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="p-3 text-gray-500 font-mono"><?= $time ?></td>
                            <td class="p-3 font-bold text-gray-700"><?= $t['account_name'] ?></td>
                            <td class="p-3 text-gray-600 max-w-xs truncate"><?= $t['description'] ?></td>
                            <td class="p-3 text-right font-bold <?= $t['type']=='INCOME'?'text-green-600':'text-red-600' ?>">
                                <?= $t['type']=='INCOME' ? '+' : '-' ?> <?= formatRupiah($t['amount']) ?>
                            </td>
                            <td class="p-3 text-center">
                                <div class="flex justify-center gap-1">
                                    <button onclick='editTrx(<?= json_encode($t) ?>)' class="bg-yellow-100 text-yellow-600 hover:bg-yellow-200 p-2 rounded transition" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus transaksi ini?');" class="inline">
                                        <input type="hidden" name="delete_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="bg-red-100 text-red-600 hover:bg-red-200 p-2 rounded transition" title="Hapus">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if(empty($txs)): ?>
                        <tr>
                            <td colspan="5" class="p-8 text-center text-gray-400">
                                <i class="fas fa-file-invoice-dollar text-4xl mb-2"></i>
                                <p>Belum ada transaksi hari ini.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL EDIT -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <button onclick="document.getElementById('editModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
            <i class="fas fa-times text-xl"></i>
        </button>
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-blue-800"><i class="fas fa-edit"></i> Edit Transaksi</h3>
        
        <form method="POST">
            <input type="hidden" name="update_trx" value="1">
            <input type="hidden" name="edit_id" id="edit_id">
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Tanggal</label>
                <input type="date" name="edit_date" id="edit_date" class="w-full border p-2 rounded text-sm">
            </div>
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Akun</label>
                <select name="edit_account_id" id="edit_account_id" class="w-full border p-2 rounded text-sm bg-white">
                    <?php foreach($accounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>"><?= $acc['code'] ?> - <?= $acc['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Nominal (Rp)</label>
                <input type="number" name="edit_amount" id="edit_amount" class="w-full border p-2 rounded text-sm">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Keterangan</label>
                <textarea name="edit_description" id="edit_description" class="w-full border p-2 rounded text-sm" rows="3"></textarea>
            </div>
            
            <div class="flex gap-2">
                <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="flex-1 bg-gray-300 text-gray-700 py-2 rounded font-bold hover:bg-gray-400">Batal</button>
                <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
function filterAccounts() {
    const type = document.querySelector('input[name="type"]:checked').value;
    const select = document.getElementById('accountSelect');
    const options = select.querySelectorAll('option');
    
    let firstVisible = "";
    options.forEach(opt => {
        if (!opt.value) return; 
        const accType = opt.getAttribute('data-type');
        let isVisible = false;
        if (type === 'INCOME') isVisible = ['INCOME', 'EQUITY', 'LIABILITY'].includes(accType);
        else isVisible = ['EXPENSE', 'ASSET', 'LIABILITY'].includes(accType);

        opt.style.display = isVisible ? 'block' : 'none';
        if (isVisible && !firstVisible) firstVisible = opt.value;
    });
    select.value = ""; 
}

function editTrx(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_date').value = data.date;
    document.getElementById('edit_account_id').value = data.account_id;
    document.getElementById('edit_amount').value = data.amount;
    document.getElementById('edit_description').value = data.description;
    
    document.getElementById('editModal').classList.remove('hidden');
}

document.addEventListener('DOMContentLoaded', filterAccounts);
</script>