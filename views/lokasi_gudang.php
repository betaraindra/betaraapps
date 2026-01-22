<?php
checkRole(['SUPER_ADMIN', 'ADMIN_GUDANG', 'MANAGER', 'SVP']);

// --- LOGIC HANDLE REQUEST ---

// 1. Simpan (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
        $stmt = $pdo->prepare("UPDATE warehouses SET name=?, location=? WHERE id=?");
        $stmt->execute([$_POST['name'], $_POST['location'], $_POST['edit_id']]);
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data Gudang Diperbarui'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO warehouses (name, location) VALUES (?, ?)");
        $stmt->execute([$_POST['name'], $_POST['location']]);
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Gudang Baru Ditambahkan'];
    }
    echo "<script>window.location='?page=lokasi_gudang';</script>";
    exit;
}

// 2. Hapus
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    // Cek ketergantungan data sebelum hapus
    $check = $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE warehouse_id=$id")->fetchColumn();
    if($check > 0) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal hapus: Gudang ini memiliki riwayat transaksi.'];
    } else {
        $pdo->prepare("DELETE FROM warehouses WHERE id=?")->execute([$id]);
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Gudang dihapus'];
    }
    echo "<script>window.location='?page=lokasi_gudang';</script>";
    exit;
}

// 3. Ambil Data Edit
$edit_data = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $edit_data = $stmt->fetch();
}

// 4. Ambil Semua Data Gudang
$data = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <!-- KOLOM KIRI: INPUT GUDANG -->
    <div class="md:col-span-1">
        <div class="bg-white p-6 rounded-lg shadow sticky top-6">
            <h3 class="font-bold text-lg mb-4 text-blue-800 border-b pb-2">
                <i class="fas fa-warehouse"></i> <?= $edit_data ? 'Edit Gudang' : 'Input Gudang' ?>
            </h3>
            
            <form method="POST">
                <?php if($edit_data): ?>
                    <input type="hidden" name="edit_id" value="<?= $edit_data['id'] ?>">
                <?php endif; ?>

                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Nama Gudang</label>
                    <input type="text" name="name" value="<?= $edit_data['name']??'' ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="Contoh: Gudang Utama" required>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Lokasi</label>
                    <input type="text" name="location" value="<?= $edit_data['location']??'' ?>" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="Contoh: Jakarta Pusat" required>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700 transition">Simpan</button>
                    <?php if($edit_data): ?>
                        <a href="?page=lokasi_gudang" class="flex-1 bg-gray-500 text-white py-2 rounded font-bold hover:bg-gray-600 transition text-center">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- KOLOM KANAN: DAFTAR GUDANG -->
    <div class="md:col-span-2">
        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="font-bold text-lg mb-4 text-gray-800 border-b pb-2">Daftar Gudang</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left border-collapse">
                    <thead class="bg-gray-100 text-gray-700">
                        <tr>
                            <th class="p-3 border-b">Nama Gudang</th>
                            <th class="p-3 border-b">Lokasi</th>
                            <th class="p-3 border-b text-center" width="150">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($data as $d): ?>
                        <tr class="hover:bg-gray-50 group">
                            <td class="p-3">
                                <a href="?page=detail_gudang&id=<?= $d['id'] ?>" class="font-bold text-blue-600 hover:underline flex items-center gap-2" title="Lihat Detail Stok">
                                    <i class="fas fa-box"></i> <?= $d['name'] ?>
                                </a>
                            </td>
                            <td class="p-3 text-gray-600"><?= $d['location'] ?></td>
                            <td class="p-3 text-center">
                                <div class="flex justify-center gap-2">
                                    <a href="?page=lokasi_gudang&edit=<?= $d['id'] ?>" class="text-blue-500 hover:text-blue-700 bg-blue-50 hover:bg-blue-100 p-2 rounded transition" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?page=lokasi_gudang&delete=<?= $d['id'] ?>" onclick="return confirm('Hapus gudang ini?')" class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 p-2 rounded transition" title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($data)): ?>
                            <tr><td colspan="3" class="p-4 text-center text-gray-400">Belum ada data gudang.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-400 mt-3 italic">* Klik nama gudang untuk melihat detail transaksi stok di lokasi tersebut.</p>
        </div>
    </div>
</div>