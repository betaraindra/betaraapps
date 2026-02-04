<?php
checkRole(['SUPER_ADMIN']);

// --- KONFIGURASI REPO ---
$repo_user = "betaraindra";
$repo_name = "betaraapps";
$branch = "main";

// URL Sumber
$github_raw_metadata = "https://raw.githubusercontent.com/$repo_user/$repo_name/$branch/metadata.json";
$github_zip_url = "https://github.com/$repo_user/$repo_name/archive/refs/heads/$branch.zip";

// Temp Files
$temp_zip = "update_temp.zip";
$temp_extract_dir = "temp_update_extract/";

// --- 1. GET LOCAL VERSION ---
$local_meta_file = 'metadata.json';
$local_data = file_exists($local_meta_file) ? json_decode(file_get_contents($local_meta_file), true) : [];
$current_version = $local_data['version'] ?? '0.0.1'; // Default legacy
$current_desc = $local_data['description'] ?? '-';

$logs = [];
$update_available = false;
$remote_info = null;
$check_message = "";

// --- 2. HANDLE: CHECK UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_update'])) {
    
    // Ambil Metadata Remote
    $ch = curl_init($github_raw_metadata);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SIKI-APP-UPDATER');
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $remote_info = json_decode($response, true);
        $remote_ver = $remote_info['version'] ?? '0.0.0';
        
        // Bandingkan Versi
        if (version_compare($remote_ver, $current_version, '>')) {
            $update_available = true;
            $check_message = "Ditemukan versi baru: <b>v$remote_ver</b>";
        } else {
            $check_message = "Sistem Anda sudah menggunakan versi terbaru (v$current_version).";
        }
    } else {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal terhubung ke server update. Cek koneksi internet.'];
    }
}

// --- 3. HANDLE: PROCESS UPDATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_update'])) {
    
    set_time_limit(600); // 10 Menit
    
    try {
        $logs[] = "Memulai proses update sistem...";
        
        // A. DOWNLOAD ZIP
        $logs[] = "Mendownload source code dari GitHub...";
        $fp = fopen($temp_zip, 'w+');
        if($fp === false) throw new Exception("Izin ditolak: Tidak bisa membuat file temporary.");
        
        $ch = curl_init($github_zip_url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_FILE, $fp); 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SIKI-APP-UPDATER');
        
        $exec = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$exec || $http_code !== 200) {
             throw new Exception("Download Gagal (HTTP $http_code). $curl_error");
        }
        $logs[] = "Download selesai. Ukuran: " . round(filesize($temp_zip) / 1024, 2) . " KB";

        // B. EXTRACT
        $zip = new ZipArchive;
        if ($zip->open($temp_zip) === TRUE) {
            $logs[] = "Mengekstrak paket update...";
            if (!is_dir($temp_extract_dir)) mkdir($temp_extract_dir);
            $zip->extractTo($temp_extract_dir);
            $zip->close();
        } else {
            throw new Exception("Gagal membuka file ZIP.");
        }

        // C. FIND ROOT FOLDER
        $files = scandir($temp_extract_dir);
        $source_dir = "";
        foreach($files as $f) {
            if ($f != '.' && $f != '..' && is_dir($temp_extract_dir . $f)) {
                $source_dir = $temp_extract_dir . $f . "/";
                break;
            }
        }
        if (empty($source_dir)) throw new Exception("Struktur folder update tidak valid.");
        
        // D. COPY FILES
        $logs[] = "Mengupdate file sistem...";
        
        function recursiveCopy($src, $dst, &$logs) {
            $dir = opendir($src);
            @mkdir($dst);
            
            // FILE YANG TIDAK BOLEH DI-TIMPA
            $protected_files = [
                'config.php', 
                'uploads', 
                'error_log.txt', 
                '.git',
                '.htaccess'
            ];

            while(false !== ( $file = readdir($dir)) ) {
                if (( $file != '.' ) && ( $file != '..' )) {
                    if (in_array($file, $protected_files)) {
                        $logs[] = "SKIP: $file (User Config/Data)";
                        continue;
                    }
                    if ( is_dir($src . '/' . $file) ) {
                        recursiveCopy($src . '/' . $file, $dst . '/' . $file, $logs);
                    } else {
                        copy($src . '/' . $file, $dst . '/' . $file);
                    }
                }
            }
            closedir($dir);
        }

        recursiveCopy($source_dir, ".", $logs);
        
        // E. CLEANUP
        $logs[] = "Membersihkan file sampah...";
        function deleteDir($dirPath) {
            if (! is_dir($dirPath)) return;
            if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') $dirPath .= '/';
            $files = glob($dirPath . '*', GLOB_MARK);
            foreach ($files as $file) {
                if (is_dir($file)) deleteDir($file); else unlink($file);
            }
            rmdir($dirPath);
        }
        deleteDir($temp_extract_dir);
        unlink($temp_zip);
        
        // F. SELESAI
        $logs[] = "UPDATE BERHASIL SELESAI.";
        
        // Update Session / Log
        logActivity($pdo, 'UPDATE_SYSTEM', "System updated successfully.");
        
        // Reload metadata lokal untuk log terakhir
        $local_data = json_decode(file_get_contents($local_meta_file), true);
        $new_version = $local_data['version'] ?? 'Unknown';
        
        $_SESSION['flash'] = ['type'=>'success', 'message'=>"Sistem berhasil diupdate ke versi v$new_version!"];

    } catch (Exception $e) {
        $logs[] = "CRITICAL ERROR: " . $e->getMessage();
        if (file_exists($temp_zip)) unlink($temp_zip);
        if (is_dir($temp_extract_dir)) deleteDir($temp_extract_dir);
        $_SESSION['flash'] = ['type'=>'error', 'message'=>$e->getMessage()];
    }
}
?>

<div class="max-w-5xl mx-auto space-y-6">
    
    <!-- HEADER -->
    <div class="bg-white p-6 rounded-lg shadow border-l-4 border-blue-600 flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-sync-alt text-blue-600"></i> Update Sistem
            </h2>
            <p class="text-gray-500 text-sm mt-1">Kelola pembaruan aplikasi secara aman.</p>
        </div>
        <div class="text-right">
            <div class="text-xs text-gray-400 uppercase font-bold">Versi Saat Ini</div>
            <div class="text-3xl font-bold text-gray-800">v<?= $current_version ?></div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- KOLOM KIRI: STATUS & AKSI -->
        <div class="lg:col-span-1 space-y-6">
            
            <!-- PANEL CEK UPDATE -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h3 class="font-bold text-gray-700 mb-4 border-b pb-2">Status Pembaruan</h3>
                
                <?php if (empty($logs)): ?>
                    <?php if (!$update_available && !isset($_POST['check_update'])): ?>
                        <!-- 1. INITIAL STATE -->
                        <div class="text-center py-6">
                            <i class="fas fa-cloud-download-alt text-4xl text-gray-300 mb-3"></i>
                            <p class="text-sm text-gray-500 mb-4">Cek ketersediaan fitur baru dari server pusat.</p>
                            
                            <div class="flex flex-col gap-3">
                                <form method="POST">
                                    <button type="submit" name="check_update" value="1" class="w-full bg-blue-600 text-white px-4 py-2 rounded font-bold hover:bg-blue-700 transition shadow">
                                        <i class="fas fa-search"></i> Cek Pembaruan
                                    </button>
                                </form>

                                <div class="relative flex py-2 items-center">
                                    <div class="flex-grow border-t border-gray-200"></div>
                                    <span class="flex-shrink-0 mx-4 text-gray-300 text-xs">ATAU</span>
                                    <div class="flex-grow border-t border-gray-200"></div>
                                </div>

                                <form method="POST" onsubmit="return confirm('PERINGATAN: Update Langsung akan menimpa file sistem dengan versi terbaru dari Branch Main. Config & Database aman. Lanjutkan?');">
                                    <button type="submit" name="start_update" value="1" class="w-full bg-yellow-50 text-yellow-700 border border-yellow-300 px-4 py-2 rounded hover:bg-yellow-100 text-sm font-bold flex items-center justify-center gap-2">
                                        <i class="fas fa-sync-alt"></i> Update Langsung (Force)
                                    </button>
                                </form>
                            </div>
                        </div>

                    <?php elseif (!$update_available && isset($_POST['check_update'])): ?>
                        <!-- 2. NO UPDATE FOUND -->
                        <div class="text-center py-6 bg-green-50 rounded border border-green-200">
                            <i class="fas fa-check-circle text-4xl text-green-500 mb-3"></i>
                            <p class="text-sm font-bold text-green-800">Sistem Terbaru</p>
                            <p class="text-xs text-green-600 mt-1">Anda menggunakan versi v<?= $current_version ?></p>
                        </div>
                        
                        <!-- ADDED: FORCE UPDATE BUTTON (EXISTING LOGIC) -->
                        <div class="mt-4 border-t pt-4">
                            <p class="text-[10px] text-gray-500 mb-2 text-center">Jika Anda yakin ada update di GitHub yang belum terdeteksi (misal versi metadata belum naik):</p>
                            <form method="POST" onsubmit="return confirm('PERINGATAN: Paksa update akan menimpa file sistem dengan versi terbaru dari Branch Main. Config & Database aman. Lanjutkan?');">
                                <button type="submit" name="start_update" value="1" class="w-full bg-yellow-100 text-yellow-700 border border-yellow-300 px-4 py-2 rounded hover:bg-yellow-200 text-sm font-bold flex items-center justify-center gap-2">
                                    <i class="fas fa-sync-alt"></i> Paksa Update / Re-Install
                                </button>
                            </form>
                        </div>

                        <form method="POST" class="mt-2">
                            <button type="submit" name="check_update" value="1" class="w-full bg-gray-100 text-gray-600 px-4 py-2 rounded hover:bg-gray-200 text-sm">
                                Cek Lagi
                            </button>
                        </form>

                    <?php elseif ($update_available): ?>
                        <!-- 3. UPDATE AVAILABLE -->
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle text-yellow-600"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-yellow-700">
                                        <?= $check_message ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <form method="POST" onsubmit="return confirm('PENTING: Pastikan Anda sudah Backup Database sebelum update. Lanjutkan?');">
                            <button type="submit" name="start_update" value="1" class="w-full bg-green-600 text-white px-4 py-3 rounded font-bold hover:bg-green-700 shadow-lg animate-pulse flex items-center justify-center gap-2">
                                <i class="fas fa-download"></i> Proses Update Sekarang
                            </button>
                        </form>
                        
                        <form method="POST" class="mt-2">
                            <button type="submit" name="check_update" value="1" class="w-full bg-white border border-gray-300 text-gray-600 px-4 py-2 rounded hover:bg-gray-50 text-sm">
                                Refresh
                            </button>
                        </form>
                    <?php endif; ?>
                
                <?php else: ?>
                    <!-- 4. PROCESSING STATE (LOGS EXIST) -->
                    <div class="text-center py-6">
                        <i class="fas fa-cog fa-spin text-4xl text-blue-500 mb-3"></i>
                        <p class="text-sm font-bold text-gray-700">Proses Selesai</p>
                        <a href="?page=update_system" class="mt-4 inline-block bg-blue-600 text-white px-4 py-2 rounded font-bold hover:bg-blue-700 text-sm">Kembali</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- INFO PENTING -->
            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 text-blue-900 text-sm">
                <h4 class="font-bold mb-2 flex items-center gap-2"><i class="fas fa-shield-alt"></i> Keamanan Update</h4>
                <ul class="list-disc ml-4 space-y-1 text-xs">
                    <li>Database transaksi <b>TIDAK</b> akan dihapus.</li>
                    <li>Konfigurasi (config.php) <b>TIDAK</b> akan ditimpa.</li>
                    <li>File upload (Gambar/Logo) <b>AMAN</b>.</li>
                    <li>Disarankan Backup Database secara berkala.</li>
                </ul>
            </div>
        </div>

        <!-- KOLOM KANAN: DETAIL / LOG -->
        <div class="lg:col-span-2">
            
            <?php if ($update_available && $remote_info): ?>
                <!-- RINCIAN UPDATE -->
                <div class="bg-white p-6 rounded-lg shadow mb-6">
                    <h3 class="font-bold text-lg text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-list-alt text-purple-600"></i> Rincian Pembaruan
                    </h3>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm border-collapse">
                            <tr class="bg-gray-100 border-b">
                                <th class="p-3 text-left w-1/3">Komponen</th>
                                <th class="p-3 text-left w-1/3 text-gray-500">Versi Anda</th>
                                <th class="p-3 text-left w-1/3 text-green-600 font-bold">Versi Terbaru</th>
                            </tr>
                            <tr class="border-b">
                                <td class="p-3 font-bold">Nomor Versi</td>
                                <td class="p-3 text-gray-500">v<?= $current_version ?></td>
                                <td class="p-3 text-green-600 font-bold">v<?= $remote_info['version'] ?></td>
                            </tr>
                            <tr class="border-b">
                                <td class="p-3 font-bold align-top">Deskripsi / Changelog</td>
                                <td class="p-3 text-gray-500 align-top"><?= $current_desc ?></td>
                                <td class="p-3 text-gray-800 align-top"><?= $remote_info['description'] ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($logs)): ?>
                <!-- LIVE TERMINAL LOGS -->
                <div class="bg-gray-900 rounded-lg shadow-inner border border-gray-700 overflow-hidden flex flex-col h-[400px]">
                    <div class="bg-gray-800 px-4 py-2 border-b border-gray-700 flex justify-between items-center">
                        <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Terminal Output</span>
                        <span class="text-[10px] text-green-400 animate-pulse">● Active</span>
                    </div>
                    <div class="p-4 overflow-y-auto font-mono text-xs space-y-1 flex-1">
                        <?php foreach($logs as $log): ?>
                            <div class="flex gap-2">
                                <span class="text-gray-500 shrink-0">[<?= date('H:i:s') ?>]</span>
                                <?php if(strpos($log, 'ERROR') !== false || strpos($log, 'Gagal') !== false): ?>
                                    <span class="text-red-400 font-bold"><?= $log ?></span>
                                <?php elseif(strpos($log, 'SKIP') !== false): ?>
                                    <span class="text-yellow-500"><?= $log ?></span>
                                <?php elseif(strpos($log, 'BERHASIL') !== false): ?>
                                    <span class="text-green-400 font-bold border-t border-gray-600 pt-1 mt-1 block w-full"><?= $log ?></span>
                                <?php else: ?>
                                    <span class="text-gray-300"><?= $log ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- PLACEHOLDER GAMBAR / INFO -->
                <div class="bg-white p-10 rounded-lg shadow text-center border-dashed border-2 border-gray-200 h-full flex flex-col justify-center items-center">
                    <i class="fab fa-github text-6xl text-gray-200 mb-4"></i>
                    <h3 class="text-gray-400 font-bold">Repository Source</h3>
                    <p class="text-xs text-gray-400 mt-1">https://github.com/<?= $repo_user ?>/<?= $repo_name ?></p>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>