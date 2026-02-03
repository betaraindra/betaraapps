<?php
// MANAGER & SVP "Bisa semua akses KECUALI edit pengaturan"
// Jadi hanya SUPER_ADMIN yang boleh akses halaman ini
checkRole(['SUPER_ADMIN']);

// Handle Post Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. DELETE LOGO
    if (isset($_POST['delete_logo'])) {
        $current_logo = $settings['company_logo'] ?? '';
        // Hapus file fisik jika ada
        if (!empty($current_logo) && file_exists($current_logo)) {
            unlink($current_logo);
        }
        // Kosongkan di database
        $pdo->prepare("UPDATE settings SET setting_value='' WHERE setting_key='company_logo'")->execute();
        logActivity($pdo, 'SETTINGS', "Menghapus logo perusahaan");
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Logo perusahaan berhasil dihapus.'];
        echo "<script>window.location='?page=pengaturan';</script>";
        exit;
    }

    // 2. UPDATE SETTINGS
    if (isset($_POST['update_settings'])) {
        $keys_to_update = ['company_name','company_address','company_npwp','initial_capital','app_name'];
        
        foreach($keys_to_update as $k) {
            $val = $_POST[$k] ?? ''; // Default empty string if not set
            
            // Khusus Initial Capital, bersihkan format rupiah (hapus titik, ubah koma ke titik desimal)
            if ($k === 'initial_capital') {
                $val = cleanNumber($val);
            }
            
            // Cek apakah key sudah ada
            $check = $pdo->prepare("SELECT setting_key FROM settings WHERE setting_key = ?");
            $check->execute([$k]);
            
            if ($check->rowCount() > 0) {
                // Update
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$val, $k]);
            } else {
                // Insert
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $val]);
            }
        }
        
        // Upload Logo Baru (Ganti)
        if(isset($_FILES['logo']) && $_FILES['logo']['error']==0) {
            // Hapus logo lama dulu jika ada
            $old_logo = $settings['company_logo'] ?? '';
            if(!empty($old_logo) && file_exists($old_logo)) {
                unlink($old_logo);
            }

            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $filename = "logo_" . time() . "." . $ext; // Rename biar unik
            $path = "uploads/" . $filename;
            
            if (!is_dir('uploads')) mkdir('uploads');
            move_uploaded_file($_FILES['logo']['tmp_name'], $path);
            
            // Simpan path logo (UPSERT logic manual)
            $k_logo = 'company_logo';
            $check_logo = $pdo->prepare("SELECT setting_key FROM settings WHERE setting_key = ?");
            $check_logo->execute([$k_logo]);
            if ($check_logo->rowCount() > 0) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$path, $k_logo]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k_logo, $path]);
            }
        }
        
        logActivity($pdo, 'SETTINGS', "Mengupdate profil & nama aplikasi");
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Pengaturan berhasil disimpan.'];
        echo "<script>window.location='?page=pengaturan';</script>";
        exit;
    }

    // 3. RESTORE SQL
    if (isset($_POST['restore']) && isset($_FILES['backup_file'])) {
        if ($_FILES['backup_file']['error'] == 0) {
            $sql = file_get_contents($_FILES['backup_file']['tmp_name']);
            
            // Split SQL by semicolon + newline to execute statement by statement
            $queries = explode(";\n", $sql);
            
            $pdo->beginTransaction();
            try {
                // Disable foreign key checks for restore
                $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                
                foreach ($queries as $query) {
                    $query = trim($query);
                    if (!empty($query) && (strpos($query, 'INSERT') === 0 || strpos($query, 'TRUNCATE') === 0 || strpos($query, 'SET') === 0)) {
                        $pdo->exec($query);
                    }
                }
                
                $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                $pdo->commit();
                
                logActivity($pdo, 'RESTORE', "Melakukan restore database");
                echo "<script>alert('Restore Berhasil! Database telah dipulihkan.'); window.location='?page=pengaturan';</script>";
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo "<script>alert('Error Restore: " . addslashes($e->getMessage()) . "'); window.location='?page=pengaturan';</script>";
            }
        }
    }

    // 4. RESET DATA
    if (isset($_POST['reset']) && $_POST['confirm'] == 'RESET') {
        $pdo->query("SET FOREIGN_KEY_CHECKS=0");
        $pdo->query("TRUNCATE TABLE finance_transactions");
        $pdo->query("TRUNCATE TABLE inventory_transactions");
        $pdo->query("TRUNCATE TABLE products");
        $pdo->query("SET FOREIGN_KEY_CHECKS=1");
        
        logActivity($pdo, 'RESET_DATA', "MENGHAPUS SEMUA DATA TRANSAKSI & BARANG");
        echo "<script>alert('Semua data transaksi dan barang telah dihapus!'); window.location='?page=pengaturan';</script>";
    }
}
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-cogs text-blue-600"></i> Pengaturan Perusahaan</h2>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white p-6 rounded shadow">
        <h3 class="font-bold mb-4 border-b pb-2">Identitas Perusahaan & Aplikasi</h3>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="update_settings" value="1">
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-blue-800 mb-1">Nama Aplikasi (Tampil di Header & Login)</label>
                <input type="text" name="app_name" value="<?= htmlspecialchars($settings['app_name'] ?? 'SIKI APP') ?>" class="w-full border p-2 rounded bg-blue-50 text-blue-900 font-bold" placeholder="Contoh: INVENTORI TOKO ABC">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Nama Perusahaan (Kop Surat)</label>
                <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" class="w-full border p-2 rounded" placeholder="PT. Contoh Maju">
            </div>
             <div class="mb-4">
                <label class="block text-sm font-medium mb-1">NPWP Perusahaan</label>
                <input type="text" name="company_npwp" value="<?= htmlspecialchars($settings['company_npwp'] ?? '') ?>" class="w-full border p-2 rounded" placeholder="00.000.000.0-000.000">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Alamat Lengkap</label>
                <textarea name="company_address" class="w-full border p-2 rounded" rows="3" placeholder="Jl. Raya No. 1, Kota..."><?= htmlspecialchars($settings['company_address'] ?? '') ?></textarea>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Logo Perusahaan</label>
                
                <?php if(!empty($settings['company_logo'])): ?>
                    <div class="flex items-center gap-4 mb-3 p-3 border rounded bg-gray-50">
                        <img src="<?= $settings['company_logo'] ?>" class="h-16 object-contain">
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Logo saat ini</p>
                            <button type="submit" name="delete_logo" value="1" onclick="return confirm('Yakin ingin menghapus logo?')" class="bg-red-100 text-red-600 px-3 py-1 rounded text-xs font-bold hover:bg-red-200 border border-red-200">
                                <i class="fas fa-trash"></i> Hapus Logo
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <input type="file" name="logo" class="w-full border p-2 rounded text-sm bg-white">
                <p class="text-xs text-gray-500 mt-1">
                    <?= !empty($settings['company_logo']) ? 'Upload file baru untuk <b>MENGGANTI</b> logo lama.' : 'Upload file untuk menambahkan logo.' ?>
                    Format: PNG/JPG.
                </p>
            </div>
            
            <h3 class="font-bold mt-6 mb-4 border-b pb-2">Pengaturan Aplikasi</h3>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Modal Awal (Saldo Awal)</label>
                <div class="relative">
                    <span class="absolute left-3 top-2 text-gray-500 text-sm">Rp</span>
                    <input type="text" name="initial_capital" id="initial_capital" 
                           value="<?= number_format((float)($settings['initial_capital'] ?? 0), 0, ',', '.') ?>" 
                           onkeyup="formatRupiah(this)" 
                           class="w-full border p-2 pl-10 rounded bg-gray-50 font-mono text-right font-bold">
                </div>
            </div>
            
            <button class="bg-blue-600 text-white px-4 py-2 rounded font-bold hover:bg-blue-700 w-full shadow">Simpan Perubahan</button>
        </form>
    </div>

    <div class="space-y-6">
        <div class="bg-white p-6 rounded shadow">
            <h3 class="font-bold mb-4">Backup & Restore Database</h3>
            <div class="flex gap-4 mb-6">
                <!-- FORM DOWNLOAD DIUBAH KE HALAMAN STANDALONE -->
                <form method="POST" action="?page=download_backup" class="w-full">
                    <input type="hidden" name="backup" value="1">
                    <button class="bg-green-600 text-white px-4 py-2 rounded flex items-center gap-2 font-medium hover:bg-green-700 w-full justify-center shadow">
                        <i class="fas fa-download"></i> Download Backup (.sql)
                    </button>
                    <p class="text-[10px] text-gray-500 mt-2 text-center italic">
                        (Jika tidak download otomatis: Select All, Copy text, Open Notepad, Save As nama.sql)
                    </p>
                </form>
            </div>
            <hr class="mb-4">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="restore" value="1">
                <label class="block text-sm mb-2 font-medium">Restore File (.sql)</label>
                <div class="flex gap-2">
                    <input type="file" name="backup_file" class="border p-2 w-full rounded text-sm bg-gray-50" accept=".sql" required>
                    <button class="bg-yellow-600 text-white px-4 py-2 rounded font-medium hover:bg-yellow-700 shadow" onclick="return confirm('Restore akan menimpa data yang ada. Lanjutkan?')">Restore</button>
                </div>
                <p class="text-xs text-gray-500 mt-2 italic">Pastikan file adalah hasil backup dari aplikasi ini.</p>
            </form>
        </div>

        <div class="bg-red-50 p-6 rounded shadow border border-red-200">
            <h4 class="font-bold text-red-600 mb-2">Zona Bahaya</h4>
            <p class="text-sm text-gray-600 mb-4">Menghapus semua data Transaksi dan Produk. Data User dan Pengaturan tidak dihapus.</p>
            <form method="POST" onsubmit="return confirm('Yakin hapus semua data?')">
                <input type="hidden" name="reset" value="1">
                <div class="flex gap-2">
                    <input type="text" name="confirm" placeholder="Ketik RESET" class="border p-2 w-full rounded focus:ring-red-500 bg-white">
                    <button class="bg-red-600 text-white px-4 py-2 rounded whitespace-nowrap font-bold hover:bg-red-700 shadow">Hapus Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function formatRupiah(input) {
    // Hapus karakter selain angka
    let value = input.value.replace(/\D/g, '');
    if(value === '') {
        input.value = '';
        return;
    }
    // Format angka dengan separator ribuan (titik untuk ID)
    input.value = new Intl.NumberFormat('id-ID').format(value);
}
</script>