<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

// Handle Delete
if (isset($_GET['delete'])) {
    $check = $pdo->query("SELECT COUNT(*) FROM finance_transactions WHERE account_id=".$_GET['delete'])->fetchColumn();
    if ($check > 0) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal hapus: Akun ini sudah memiliki transaksi.'];
    } else {
        $pdo->prepare("DELETE FROM accounts WHERE id=?")->execute([$_GET['delete']]);
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Akun dihapus'];
    }
    echo "<script>window.location='?page=akun_kategori';</script>";
    exit;
}

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['edit_id'])) {
        $stmt = $pdo->prepare("UPDATE accounts SET code=?, name=?, type=? WHERE id=?");
        $stmt->execute([$_POST['code'], $_POST['name'], $_POST['type'], $_POST['edit_id']]);
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Akun diperbarui'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO accounts (code, name, type) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['code'], $_POST['name'], $_POST['type']]);
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Akun ditambahkan'];
    }
    echo "<script>window.location='?page=akun_kategori';</script>";
    exit;
}

$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_data = $pdo->query("SELECT * FROM accounts WHERE id=".$_GET['edit'])->fetch();
}

$accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();

// Mapping Tipe Akun untuk Display
$type_labels = [
    'ASSET' => 'ASSET (Harta)',
    'LIABILITY' => 'LIABILITY (Kewajiban/Hutang)',
    'EQUITY' => 'EQUITY (Modal)',
    'INCOME' => 'INCOME (Pendapatan)',
    'EXPENSE' => 'EXPENSE (Beban/Pengeluaran)'
];
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <!-- KOLOM KIRI: INPUT FORM -->
    <div class="md:col-span-1">
        <div class="bg-white p-6 rounded-lg shadow sticky top-6">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2">
                <i class="fas fa-edit"></i> <?= $edit_data ? 'Edit Akun' : 'Input Akun (COA)' ?>
            </h3>
            
            <form method="POST">
                <?php if($edit_data): ?>
                    <input type="hidden" name="edit_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Kode Akun</label>
                    <input type="text" name="code" value="<?= $edit_data['code']??'' ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono font-bold" placeholder="Contoh: 1001" required>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Nama Akun</label>
                    <input type="text" name="name" value="<?= $edit_data['name']??'' ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="Contoh: Kas Kecil" required>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Kategori</label>
                    <select name="type" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white">
                        <?php foreach($type_labels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($edit_data && $edit_data['type'] == $key) ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700 transition shadow">Simpan</button>
                    <?php if($edit_data): ?>
                        <a href="?page=akun_kategori" class="flex-1 bg-gray-500 text-white py-2 rounded font-bold hover:bg-gray-600 transition text-center shadow">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- KOLOM KANAN: DAFTAR AKUN -->
    <div class="md:col-span-2">
        <div class="bg-white p-6 rounded-lg shadow h-full flex flex-col">
            <h3 class="font-bold text-lg mb-4 text-gray-800 border-b pb-2">Daftar Akun</h3>
            
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-sm text-left border-collapse">
                    <thead class="bg-gray-100 text-gray-700">
                        <tr>
                            <th class="p-3 border-b w-24">Kode</th>
                            <th class="p-3 border-b">Nama Akun</th>
                            <th class="p-3 border-b">Tipe</th>
                            <th class="p-3 border-b text-center w-24">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($accounts as $a): ?>
                        <tr class="hover:bg-gray-50 group">
                            <td class="p-3 font-mono font-bold text-blue-600"><?= $a['code'] ?></td>
                            <td class="p-3 font-medium text-gray-800"><?= $a['name'] ?></td>
                            <td class="p-3">
                                <?php
                                    $badge_color = 'bg-gray-100 text-gray-800';
                                    if($a['type'] == 'INCOME') $badge_color = 'bg-green-100 text-green-800';
                                    elseif($a['type'] == 'EXPENSE') $badge_color = 'bg-red-100 text-red-800';
                                    elseif($a['type'] == 'ASSET') $badge_color = 'bg-blue-100 text-blue-800';
                                    elseif($a['type'] == 'LIABILITY') $badge_color = 'bg-yellow-100 text-yellow-800';
                                    elseif($a['type'] == 'EQUITY') $badge_color = 'bg-purple-100 text-purple-800';
                                ?>
                                <span class="px-2 py-1 rounded text-xs font-bold <?= $badge_color ?>"><?= $a['type'] ?></span>
                            </td>
                            <td class="p-3 text-center">
                                <div class="flex justify-center gap-2">
                                    <a href="?page=akun_kategori&edit=<?= $a['id'] ?>" class="text-blue-500 hover:text-blue-700 p-1" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?page=akun_kategori&delete=<?= $a['id'] ?>" onclick="return confirm('Hapus akun ini? Pastikan tidak ada transaksi terkait.')" class="text-red-500 hover:text-red-700 p-1" title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($accounts)): ?>
                            <tr><td colspan="4" class="p-4 text-center text-gray-400">Belum ada data akun.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>