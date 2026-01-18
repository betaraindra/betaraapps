<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG']);

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $location = $_POST['location'];
    
    if (isset($_POST['id']) && !empty($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE warehouses SET name=?, location=? WHERE id=?");
        $stmt->execute([$name, $location, $_POST['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO warehouses (name, location) VALUES (?, ?)");
        $stmt->execute([$name, $location]);
    }
    $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data Gudang Disimpan'];
    echo "<script>window.location='?page=lokasi_gudang';</script>";
    exit;
}

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $pdo->query("DELETE FROM warehouses WHERE id=$id");
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Gudang dihapus'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal: Gudang sedang digunakan transaksi.'];
    }
    echo "<script>window.location='?page=lokasi_gudang';</script>";
    exit;
}

$data = $pdo->query("SELECT * FROM warehouses")->fetchAll();
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="text-lg font-bold mb-4">Input Gudang</h3>
        <form method="POST">
            <input type="hidden" name="id" id="form_id">
            <div class="mb-4">
                <label class="block text-sm font-medium">Nama Gudang</label>
                <input type="text" name="name" id="form_name" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium">Lokasi</label>
                <input type="text" name="location" id="form_location" class="w-full border p-2 rounded" required>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 flex-1">Simpan</button>
                <button type="button" onclick="resetForm()" class="bg-gray-300 text-gray-700 px-4 py-2 rounded">Batal</button>
            </div>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow md:col-span-2">
        <h3 class="text-lg font-bold mb-4">Daftar Gudang</h3>
        <table class="w-full text-sm text-left border">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 border">Nama</th>
                    <th class="p-3 border">Lokasi</th>
                    <th class="p-3 border text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($data as $d): ?>
                <tr>
                    <td class="p-3 border font-bold"><?= $d['name'] ?></td>
                    <td class="p-3 border"><?= $d['location'] ?></td>
                    <td class="p-3 border text-center">
                        <button onclick="edit(<?= htmlspecialchars(json_encode($d)) ?>)" class="text-blue-600 mr-2"><i class="fas fa-edit"></i></button>
                        <a href="?page=lokasi_gudang&delete=<?= $d['id'] ?>" onclick="return confirm('Hapus?')" class="text-red-600"><i class="fas fa-trash"></i></a>
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
    document.getElementById('form_name').value = data.name;
    document.getElementById('form_location').value = data.location;
}
function resetForm() {
    document.getElementById('form_id').value = '';
    document.getElementById('form_name').value = '';
    document.getElementById('form_location').value = '';
}
</script>