<?php
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'MANAGER', 'SVP']);

// Define Locked Accounts (System Accounts)
// 3003: Persediaan, 2005: Pembelian Stok
// 1004: Pemasukan Wilayah, 2009: Pengeluaran Wilayah
$locked_codes = ['3003', '2005', '1004', '2009'];

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Cek Kode Akun
    $stmt = $pdo->prepare("SELECT code FROM accounts WHERE id=?");
    $stmt->execute([$id]);
    $code = $stmt->fetchColumn();

    if (in_array($code, $locked_codes)) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: Akun Sistem (3003/2005/1004/2009) terkunci dan tidak dapat dihapus.'];
    } else {
        $check = $pdo->query("SELECT COUNT(*) FROM finance_transactions WHERE account_id=$id")->fetchColumn();
        if ($check > 0) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal hapus: Akun ini sudah memiliki transaksi.'];
        } else {
            $pdo->prepare("DELETE FROM accounts WHERE id=?")->execute([$id]);
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Akun dihapus'];
        }
    }
    echo "<script>window.location='?page=akun_kategori';</script>";
    exit;
}

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['edit_id'])) {
        $id = $_POST['edit_id'];
        
        // Cek Data Akun Sebelum Update
        $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id=?");
        $stmt->execute([$id]);
        $current_acc = $stmt->fetch();

        if (in_array($current_acc['code'], $locked_codes)) {
            // Validasi Strict untuk Akun Sistem
            // User hanya boleh ganti nama, tidak boleh ganti kode atau tipe
            if ($_POST['code'] !== $current_acc['code']) {
                $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: Kode Akun Sistem tidak boleh diubah.'];
            } elseif ($_POST['type'] !== $current_acc['type']) {
                $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: Tipe Akun Sistem tidak boleh diubah.'];
            } else {
                // Update Name Only
                $stmt = $pdo->prepare("UPDATE accounts SET name=? WHERE id=?");
                $stmt->execute([$_POST['name'], $id]);
                $_SESSION['flash'] = ['type'=>'success', 'message'=>'Nama Akun Sistem diperbarui'];
            }
        } else {
            // Akun Biasa: Bebas update
            $stmt = $pdo->prepare("UPDATE accounts SET code=?, name=?, type=? WHERE id=?");
            $stmt->execute([$_POST['code'], $_POST['name'], $_POST['type'], $id]);
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Akun diperbarui'];
        }
    } else {
        // Cek jika mencoba menambah kode sistem
        if (in_array($_POST['code'], $locked_codes)) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: Kode akun '.$_POST['code'].' dicadangkan oleh sistem.'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO accounts (code, name, type) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['code'], $_POST['name'], $_POST['type']]);
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Akun ditambahkan'];
        }
    }
    echo "<script>window.location='?page=akun_kategori';</script>";
    exit;
}

$edit_data = null;
$is_edit_locked = false;

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $edit_data = $stmt->fetch();
    
    if ($edit_data && in_array($edit_data['code'], $locked_codes)) {
        $is_edit_locked = true;
    }
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
                    <input type="text" name="code" value="<?= $edit_data['code']??'' ?>" 
                           class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono font-bold <?= $is_edit_locked ? 'bg-gray-200 cursor-not-allowed' : '' ?>" 
                           placeholder="Contoh: 1001" 
                           required 
                           <?= $is_edit_locked ? 'readonly' : '' ?>>
                    <?php if($is_edit_locked): ?>
                        <p class="text-[10px] text-red-500 mt-1">* Kode Akun Sistem tidak dapat diubah.</p>
                    <?php endif; ?>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Nama Akun</label>
                    <input type="text" name="name" value="<?= $edit_data['name']??'' ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="Contoh: Kas Kecil" required>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Kategori</label>
                    <?php if($is_edit_locked): ?>
                        <!-- Hidden input untuk mengirim value saat disabled -->
                        <input type="hidden" name="type" value="<?= $edit_data['type'] ?>">
                        <input type="text" value="<?= $type_labels[$edit_data['type']] ?>" class="w-full border p-2 rounded bg-gray-200 cursor-not-allowed text-sm" readonly>
                        <p class="text-[10px] text-red-500 mt-1">* Tipe Akun Sistem tidak dapat diubah.</p>
                    <?php else: ?>
                        <select name="type" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white">
                            <?php foreach($type_labels as $key => $label): ?>
                                <option value="<?= $key ?>" <?= ($edit_data && $edit_data['type'] == $key) ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
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
                        <?php foreach($accounts as $a): 
                            $is_locked = in_array($a['code'], $locked_codes);
                        ?>
                        <tr class="hover:bg-gray-50 group <?= $is_locked ? 'bg-blue-50' : '' ?>">
                            <td class="p-3 font-mono font-bold text-blue-600">
                                <?= $a['code'] ?>
                                <?php if($is_locked): ?>
                                    <i class="fas fa-lock text-gray-400 ml-1 text-[10px]" title="Akun Sistem"></i>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 font-medium text-gray-800">
                                <?= $a['name'] ?>
                                <?php if($is_locked): ?>
                                    <span class="text-[10px] text-blue-600 italic block">(System Default)</span>
                                <?php endif; ?>
                            </td>
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
                                    <a href="?page=akun_kategori&edit=<?= $a['id'] ?>" class="text-blue-500 hover:text-blue-700 p-1" title="Edit Nama">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if(!$is_locked): ?>
                                    <a href="?page=akun_kategori&delete=<?= $a['id'] ?>" onclick="return confirm('Hapus akun ini? Pastikan tidak ada transaksi terkait.')" class="text-red-500 hover:text-red-700 p-1" title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
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