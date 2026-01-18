<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'];
    $type = $_POST['type'];
    $account_id = $_POST['account_id'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];

    $stmt = $pdo->prepare("INSERT INTO finance_transactions (date, type, account_id, amount, description, user_id) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$date, $type, $account_id, $amount, $description, $_SESSION['user_id']])) {
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Transaksi berhasil disimpan'];
        echo "<script>window.location='?page=transaksi_keuangan';</script>";
        exit;
    }
}

$accounts = $pdo->query("SELECT * FROM accounts ORDER BY type, code")->fetchAll();
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Form Input -->
    <div class="bg-white p-6 rounded-lg shadow lg:col-span-1">
        <h3 class="text-lg font-bold mb-4">Input Keuangan</h3>
        <form method="POST" id="financeForm">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Tanggal</label>
                <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="mt-1 block w-full border rounded-md shadow-sm p-2">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Jenis</label>
                <div class="mt-1 flex gap-4">
                    <label class="inline-flex items-center">
                        <input type="radio" name="type" value="INCOME" checked class="text-blue-600" onchange="filterAccounts()">
                        <span class="ml-2">Pemasukan</span>
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="type" value="EXPENSE" class="text-red-600" onchange="filterAccounts()">
                        <span class="ml-2">Pengeluaran</span>
                    </label>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Akun</label>
                <select name="account_id" id="accountSelect" class="mt-1 block w-full border rounded-md shadow-sm p-2" required>
                    <option value="">-- Pilih Akun --</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= $acc['id'] ?>" data-type="<?= $acc['type'] ?>">
                            <?= $acc['code'] ?> - <?= $acc['name'] ?> (<?= $acc['type'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Nominal (Rp)</label>
                <input type="number" name="amount" class="mt-1 block w-full border rounded-md shadow-sm p-2" placeholder="0" required>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Keterangan</label>
                <textarea name="description" class="mt-1 block w-full border rounded-md shadow-sm p-2" rows="3"></textarea>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded hover:bg-blue-700">Simpan Transaksi</button>
        </form>
    </div>

    <!-- Data Table -->
    <div class="bg-white p-6 rounded-lg shadow lg:col-span-2">
        <h3 class="text-lg font-bold mb-4">Riwayat Transaksi Hari Ini</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 text-gray-600 uppercase">
                    <tr>
                        <th class="p-3">Waktu</th>
                        <th class="p-3">Akun</th>
                        <th class="p-3">Ket</th>
                        <th class="p-3 text-right">Nominal</th>
                        <th class="p-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php
                    $today = date('Y-m-d');
                    $txs = $pdo->query("SELECT f.*, a.name as account_name FROM finance_transactions f JOIN accounts a ON f.account_id = a.id WHERE date = '$today' ORDER BY id DESC")->fetchAll();
                    
                    if (count($txs) == 0): ?>
                        <tr><td colspan="5" class="p-4 text-center text-gray-500">Belum ada transaksi hari ini.</td></tr>
                    <?php else: foreach($txs as $t): ?>
                        <tr>
                            <td class="p-3"><?= substr($t['created_at'], 11, 5) ?></td>
                            <td class="p-3"><?= $t['account_name'] ?></td>
                            <td class="p-3"><?= $t['description'] ?></td>
                            <td class="p-3 text-right font-medium <?= $t['type']=='INCOME'?'text-green-600':'text-red-600' ?>">
                                <?= formatRupiah($t['amount']) ?>
                            </td>
                            <td class="p-3 text-center">
                                <a href="?page=delete_transaction&id=<?= $t['id'] ?>&type=finance" onclick="return confirm('Hapus?')" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// LocalStorage untuk Draft Input
const form = document.getElementById('financeForm');
form.addEventListener('input', () => {
    localStorage.setItem('finance_draft', JSON.stringify(Object.fromEntries(new FormData(form))));
});

// Restore Draft
const draft = JSON.parse(localStorage.getItem('finance_draft'));
if (draft) {
    // Implement simple restore if needed
}

function filterAccounts() {
    const type = document.querySelector('input[name="type"]:checked').value;
    const select = document.getElementById('accountSelect');
    const options = select.querySelectorAll('option');
    
    options.forEach(opt => {
        if (!opt.value) return;
        const accType = opt.getAttribute('data-type');
        // Logic mapping: Income -> INCOME/REVENUE, Expense -> EXPENSE/COST
        // Tapi user minta simple Pemasukan/Pengeluaran.
        // Kita tampilkan Akun Income/Expense sesuai tipe, Asset/Liability bisa muncul di keduanya atau spesifik.
        // Untuk simplifikasi aplikasi ini:
        if (type === 'INCOME') {
            opt.style.display = (accType === 'INCOME' || accType === 'EQUITY' || accType === 'LIABILITY') ? 'block' : 'none';
        } else {
            opt.style.display = (accType === 'EXPENSE' || accType === 'ASSET' || accType === 'LIABILITY') ? 'block' : 'none';
        }
    });
    select.value = "";
}
// Init
filterAccounts();
</script>