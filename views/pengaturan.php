<?php
// Izinkan Admin Keuangan mengakses halaman ini
checkRole(['SUPER_ADMIN', 'ADMIN_KEUANGAN']);

// Handle Update Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='company_name'")->execute([$_POST['company_name']]);
    $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='initial_capital'")->execute([$_POST['initial_capital']]);
    
    $_SESSION['flash'] = ['type'=>'success', 'message'=>'Pengaturan disimpan'];
    echo "<script>window.location='?page=pengaturan';</script>";
    exit;
}

// Handle Reset Data (SUPER ADMIN ONLY)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_data'])) {
    if ($_SESSION['role'] !== 'SUPER_ADMIN') {
        echo "<script>alert('Akses Ditolak');</script>";
        exit;
    }

    $confirm = $_POST['confirm_text'];
    if ($confirm === 'HAPUS SEMUA') {
        $pdo->query("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->query("TRUNCATE TABLE finance_transactions");
        $pdo->query("TRUNCATE TABLE inventory_transactions");
        $pdo->query("TRUNCATE TABLE products");
        $pdo->query("TRUNCATE TABLE system_logs");
        // Don't truncate users or accounts usually
        $pdo->query("SET FOREIGN_KEY_CHECKS = 1");
        
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Semua data transaksi dan produk berhasil dihapus.'];
    } else {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Konfirmasi salah. Data tidak dihapus.'];
    }
    echo "<script>window.location='?page=pengaturan';</script>";
    exit;
}

// Handle Backup (Simple JSON Dump - SUPER ADMIN ONLY)
if (isset($_GET['backup'])) {
    if ($_SESSION['role'] !== 'SUPER_ADMIN') {
        exit("Akses Ditolak");
    }
    $data = [];
    $tables = ['users','accounts','warehouses','products','finance_transactions','inventory_transactions','settings'];
    foreach($tables as $t) {
        $data[$t] = $pdo->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="backup_siki_'.date('Y-m-d').'.json"');
    echo json_encode($data);
    exit;
}
?>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <!-- General Settings (Bisa diakses Super Admin & Admin Keuangan) -->
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="text-lg font-bold mb-4">Pengaturan Perusahaan</h3>
        <form method="POST">
            <input type="hidden" name="update_settings" value="1">
            <div class="mb-4">
                <label class="block text-sm font-medium">Nama Perusahaan</label>
                <input type="text" name="company_name" value="<?= $settings['company_name'] ?>" class="w-full border p-2 rounded">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-blue-700">Modal Awal / Saldo Awal (Rp)</label>
                <div class="flex items-center gap-2">
                    <span class="p-2 bg-gray-100 border border-r-0 rounded-l">Rp</span>
                    <input type="number" name="initial_capital" value="<?= $settings['initial_capital'] ?>" class="w-full border p-2 rounded-r font-bold">
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    Nilai ini adalah saldo awal sebelum ada transaksi apapun. Mengubah nilai ini akan mempengaruhi perhitungan Saldo Akhir dan Laporan Neraca secara keseluruhan.
                </p>
            </div>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded w-full hover:bg-blue-700">Simpan Pengaturan</button>
        </form>
    </div>

    <!-- Backup & Restore (SUPER ADMIN ONLY) -->
    <?php if ($_SESSION['role'] === 'SUPER_ADMIN'): ?>
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="text-lg font-bold mb-4">Backup Database</h3>
        <p class="text-sm text-gray-500 mb-4">Download semua data dalam format JSON.</p>
        <a href="?page=pengaturan&backup=true" class="bg-green-600 text-white px-4 py-2 rounded block text-center mb-6 hover:bg-green-700">
            <i class="fas fa-download"></i> Download Backup JSON
        </a>

        <hr class="my-6">
        
        <h3 class="text-lg font-bold mb-4 text-red-600">Danger Zone: Reset Data</h3>
        <p class="text-sm text-gray-500 mb-4">Tindakan ini akan menghapus SEMUA data Transaksi dan Produk. Data User dan Akun tidak dihapus.</p>
        <form method="POST" onsubmit="return confirm('Yakin hapus semua data?')">
            <input type="hidden" name="reset_data" value="1">
            <label class="block text-xs font-bold mb-1">Ketik "HAPUS SEMUA" untuk konfirmasi:</label>
            <input type="text" name="confirm_text" class="w-full border p-2 rounded mb-2 border-red-300" placeholder="HAPUS SEMUA" required>
            <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded w-full hover:bg-red-700">Hapus Semua Data</button>
        </form>
    </div>
    <?php else: ?>
    <!-- Info Box untuk Admin Keuangan -->
    <div class="bg-blue-50 p-6 rounded-lg shadow border border-blue-100">
        <h3 class="text-lg font-bold mb-2 text-blue-800">Informasi Akses</h3>
        <p class="text-sm text-blue-700">
            Anda login sebagai <strong>Admin Keuangan</strong>. Anda memiliki akses untuk mengubah Nama Perusahaan dan Modal Awal.
        </p>
        <p class="text-sm text-blue-700 mt-2">
            Fitur Backup & Reset Data hanya tersedia untuk Super Admin demi keamanan data.
        </p>
    </div>
    <?php endif; ?>
</div>