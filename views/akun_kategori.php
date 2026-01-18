<?php
checkRole(['SUPER_ADMIN']);

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'];
    $name = $_POST['name'];
    $type = $_POST['type'];
    
    if (isset($_POST['id']) && !empty($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE accounts SET code=?, name=?, type=? WHERE id=?");
        $stmt->execute([$code, $name, $type, $_POST['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO accounts (code, name, type) VALUES (?, ?, ?)");
        $stmt->execute([$code, $name, $type]);
    }
    $_SESSION['flash'] = ['type'=>'success', 'message'=>'Akun Tersimpan'];
    echo "<script>window.location='?page=akun_kategori';</script>";
    exit;
}

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $pdo->query("DELETE FROM accounts WHERE id=$id");
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Akun dihapus'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: Akun sedang digunakan transaksi.'];
    }
    echo "<script>window.location='?page=akun_kategori';</script>";
    exit;
}

$accounts = $pdo->query("SELECT * FROM accounts ORDER BY code ASC")->fetchAll();
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="text-lg font-bold mb-4">Input Akun (COA)</h3>
        <form method="POST">
            <input type="hidden" name="id" id="form_id">
            <div class="mb-4">
                <label class="block text-sm font-medium">Kode Akun</label>
                <input type="text" name="code" id="form_code" class="w-full border p-2 rounded" required placeholder="Contoh: 1001">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium">Nama Akun</label>
                <input type="text" name="name" id="form_name" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium">Kategori</label>
                <select name="type" id="form_type" class="w-full border p-2 rounded">
                    <option value="ASSET">ASSET (Harta)</option>
                    <option value="LIABILITY">LIABILITY (Hutang)</option>
                    <option value="EQUITY">EQUITY (Modal)</option>
                    <option value="INCOME">INCOME (Pendapatan)</option>
                    <option value="EXPENSE">EXPENSE (Pengeluaran)</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 flex-1">Simpan</button>
                <button type="button" onclick="resetForm()" class="bg-gray-300 text-gray-700 px-4 py-2 rounded">Batal</button>
            </div>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow md:col-span-2 overflow-auto">
        <h3 class="text-lg font-bold mb-4">Daftar Akun</h3>
        <table class="w-full text-sm text-left border">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 border">Kode</th>
                    <th class="p-3 border">Nama Akun</th>
                    <th class="p-3 border">Tipe</th>
                    <th class="p-3 border text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($accounts as $d): ?>
                <tr>
                    <td class="p-3 border font-mono text-blue-600"><?= $d['code'] ?></td>
                    <td class="p-3 border font-bold"><?= $d['name'] ?></td>
                    <td class="p-3 border">
                        <span class="px-2 py-1 text-xs rounded 
                        <?= $d['type']=='INCOME'?'bg-green-100 text-green-800':
                           ($d['type']=='EXPENSE'?'bg-red-100 text-red-800':'bg-gray-100 text-gray-800') ?>">
                            <?= $d['type'] ?>
                        </span>
                    </td>
                    <td class="p-3 border text-center">
                        <button onclick="edit(<?= htmlspecialchars(json_encode($d)) ?>)" class="text-blue-600 mr-2"><i class="fas fa-edit"></i></button>
                        <a href="?page=akun_kategori&delete=<?= $d['id'] ?>" onclick="return confirm('Hapus?')" class="text-red-600"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function edit(data) {
    document.getElementById('form_id').value = data.id;
    document.getElementById('form_code').value = data.code;
    document.getElementById('form_name').value = data.name;
    document.getElementById('form_type').value = data.type;
}
function resetForm() {
    document.getElementById('form_id').value = '';
    document.getElementById('form_code').value = '';
    document.getElementById('form_name').value = '';
    document.getElementById('form_type').value = 'ASSET';
}
</script>