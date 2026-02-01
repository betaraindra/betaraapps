<?php
checkRole(['SUPER_ADMIN']);

// Config Update
$github_zip_url = "https://github.com/betaraindra/aplikasiumkm/archive/refs/heads/main.zip";
$temp_zip = "update_temp.zip";
$temp_extract_dir = "temp_update_extract/";

$logs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_update'])) {
    
    // 1. EXTEND TIME LIMIT
    set_time_limit(300); // 5 Menit
    
    try {
        $logs[] = "Memulai proses update...";
        
        // 2. DOWNLOAD ZIP
        $logs[] = "Mendownload source code dari GitHub...";
        $arrContextOptions=array(
            "ssl"=>array(
                "verify_peer"=>false,
                "verify_peer_name"=>false,
            ),
            "http"=>array(
                "header"=>"User-Agent: MyApp/1.0\r\n" // GitHub butuh User Agent
            )
        );  
        
        $file_content = @file_get_contents($github_zip_url, false, stream_context_create($arrContextOptions));
        
        if ($file_content === false) {
            throw new Exception("Gagal mendownload file dari GitHub. Pastikan server terhubung ke internet.");
        }
        
        if (file_put_contents($temp_zip, $file_content) === false) {
            throw new Exception("Gagal menyimpan file update sementara.");
        }
        $logs[] = "Download berhasil.";

        // 3. EXTRACT ZIP
        $zip = new ZipArchive;
        if ($zip->open($temp_zip) === TRUE) {
            $logs[] = "Mengekstrak file...";
            if (!is_dir($temp_extract_dir)) mkdir($temp_extract_dir);
            $zip->extractTo($temp_extract_dir);
            $zip->close();
            $logs[] = "Ekstrak berhasil.";
        } else {
            throw new Exception("Gagal membuka file ZIP update.");
        }

        // 4. IDENTIFIKASI FOLDER UTAMA
        // GitHub biasanya ekstrak ke folder "namarepo-main"
        $files = scandir($temp_extract_dir);
        $source_dir = "";
        foreach($files as $f) {
            if ($f != '.' && $f != '..' && is_dir($temp_extract_dir . $f)) {
                $source_dir = $temp_extract_dir . $f . "/";
                break;
            }
        }
        
        if (empty($source_dir)) throw new Exception("Struktur folder update tidak dikenali.");
        
        // 5. COPY FILES (DENGAN PENGECUALIAN)
        $logs[] = "Menyalin file baru...";
        
        function recursiveCopy($src, $dst, &$logs) {
            $dir = opendir($src);
            @mkdir($dst);
            
            // LIST FILE YG DILARANG DI-OVERWRITE
            $protected_files = [
                'config.php', 
                'uploads', 
                'error_log.txt', 
                '.git',
                '.htaccess' // Opsional: biasanya aman diupdate, tapi kalau user custom htaccess, jangan
            ];

            while(false !== ( $file = readdir($dir)) ) {
                if (( $file != '.' ) && ( $file != '..' )) {
                    
                    // Cek Proteksi
                    if (in_array($file, $protected_files)) {
                        $logs[] = "SKIP: $file (Protected)";
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
        $logs[] = "Penyalinan file selesai.";

        // 6. CLEANUP
        $logs[] = "Membersihkan file sementara...";
        
        // Fungsi Hapus Folder Recursif
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
        
        $logs[] = "Update Selesai! Sistem telah diperbarui.";
        logActivity($pdo, 'UPDATE_SYSTEM', "Melakukan update sistem via GitHub");
        
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'Sistem berhasil diupdate ke versi terbaru!'];

    } catch (Exception $e) {
        $logs[] = "ERROR: " . $e->getMessage();
        // Cleanup if error
        if (file_exists($temp_zip)) unlink($temp_zip);
        if (is_dir($temp_extract_dir)) deleteDir($temp_extract_dir);
        
        $_SESSION['flash'] = ['type'=>'error', 'message'=>$e->getMessage()];
    }
}
?>

<div class="max-w-4xl mx-auto">
    <div class="bg-white p-6 rounded-lg shadow border-t-4 border-blue-600">
        <h2 class="text-2xl font-bold text-gray-800 mb-4 flex items-center gap-2">
            <i class="fas fa-sync-alt text-blue-600"></i> Update Sistem
        </h2>
        
        <div class="bg-blue-50 border border-blue-200 text-blue-800 p-4 rounded-lg mb-6">
            <h4 class="font-bold mb-2"><i class="fas fa-info-circle"></i> Informasi Penting</h4>
            <ul class="list-disc ml-5 text-sm space-y-1">
                <li>Fitur ini akan mengambil kode program terbaru dari repository GitHub: <a href="https://github.com/betaraindra/aplikasiumkm" target="_blank" class="underline font-bold">aplikasiumkm</a>.</li>
                <li>Proses ini <b>AMAN</b> untuk data Anda.</li>
                <li>File <b>config.php</b> (Koneksi Database) tidak akan ditimpa.</li>
                <li>Folder <b>uploads/</b> (Gambar & Logo) tidak akan dihapus.</li>
                <li>Database (Transaksi & Produk) tidak akan direset.</li>
                <li>Pastikan server terhubung ke internet.</li>
            </ul>
        </div>

        <?php if (empty($logs)): ?>
            <div class="text-center py-8">
                <p class="text-gray-600 mb-6">Klik tombol di bawah untuk memulai proses update. Proses mungkin memakan waktu beberapa detik hingga 1 menit tergantung koneksi internet.</p>
                <form method="POST" onsubmit="return confirm('Yakin ingin melakukan update sistem sekarang?');">
                    <button type="submit" name="start_update" value="1" class="bg-blue-600 text-white px-8 py-3 rounded-full font-bold shadow-lg hover:bg-blue-700 transition transform hover:scale-105 flex items-center gap-2 mx-auto">
                        <i class="fas fa-cloud-download-alt"></i> Mulai Update Sekarang
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="bg-gray-900 text-green-400 p-4 rounded-lg font-mono text-sm h-96 overflow-y-auto shadow-inner border border-gray-700">
                <div class="mb-2 border-b border-gray-700 pb-1 text-white font-bold">Update Log:</div>
                <?php foreach($logs as $log): ?>
                    <div class="mb-1">
                        <span class="text-gray-500">[<?= date('H:i:s') ?>]</span> 
                        <?php if(strpos($log, 'ERROR') !== false): ?>
                            <span class="text-red-500 font-bold"><?= htmlspecialchars($log) ?></span>
                        <?php elseif(strpos($log, 'SKIP') !== false): ?>
                            <span class="text-yellow-500"><?= htmlspecialchars($log) ?></span>
                        <?php else: ?>
                            <?= htmlspecialchars($log) ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="mt-4 pt-2 border-t border-gray-700">
                    <a href="?page=update_system" class="text-white underline hover:text-blue-300">Kembali / Refresh</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>