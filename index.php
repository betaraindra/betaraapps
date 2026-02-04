<?php
require_once 'config.php';
requireLogin();

// --- FETCH GLOBAL SETTINGS ---
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) { 
    // Fallback jika tabel belum ada atau error
    error_log("Settings fetch error: " . $e->getMessage());
}

// Routing Sederhana
$page = $_GET['page'] ?? 'dashboard';
$role = $_SESSION['role'];
$app_name = $settings['app_name'] ?? 'SIKI APP';

// Definisi Group Akses
$is_super = $role === 'SUPER_ADMIN';
$is_manager = $role === 'MANAGER';
$is_svp = $role === 'SVP';
$is_gudang = $role === 'ADMIN_GUDANG';
$is_keuangan = $role === 'ADMIN_KEUANGAN';

// Akses Gabungan
$access_inventory = $is_super || $is_manager || $is_svp || $is_gudang;
$access_finance = $is_super || $is_manager || $is_svp || $is_keuangan;
$access_user_mgmt = $is_super || $is_manager;
$access_settings = $is_super;

// Logout Logic
if ($page === 'logout') {
    logActivity($pdo, 'LOGOUT', "User {$_SESSION['username']} logged out");
    session_destroy();
    header("Location: login.php");
    exit;
}

// --- STANDALONE PAGE LOGIC (Untuk Cetak & Download) ---
// Jika halaman adalah cetak_surat_jalan, cetak_barcode, atau download_backup, jangan load layout utama
$standalone_pages = ['cetak_surat_jalan', 'cetak_barcode', 'download_backup'];
if (in_array($page, $standalone_pages)) {
    $filename = "views/" . $page . ".php";
    if (file_exists($filename)) {
        include $filename;
    } else {
        echo "Halaman tidak ditemukan.";
    }
    exit; // Stop script agar tidak load layout bawahnya
}

// --- LOGIC MENU ACTIVE STATE ---
// Menentukan menu mana yang terbuka otomatis berdasarkan halaman aktif
// UPDATE: lokasi_gudang dan akun_kategori dipindah ke admin pages
$menu_data_pages = ['data_barang', 'data_keuangan', 'data_transaksi'];
$menu_laporan_pages = ['laporan_gudang', 'laporan_aset', 'laporan_keuangan', 'laporan_internal'];
$menu_admin_pages = ['lokasi_gudang', 'akun_kategori', 'manajemen_user', 'system_logs', 'pengaturan', 'update_system'];

// Default: Data & Laporan OPEN, Admin CLOSED (kecuali sedang aktif)
$is_menu_data_open = true; 
$is_menu_laporan_open = true;
$is_menu_admin_open = in_array($page, $menu_admin_pages);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $settings['company_name'] ?? $app_name ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar-scroll::-webkit-scrollbar { width: 5px; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: #475569; border-radius: 5px; }
        
        /* CSS KHUSUS PRINT GLOBAL - Menyembunyikan UI Aplikasi */
        @media print {
            aside, header, .no-print { display: none !important; }
            body, main { 
                background-color: white !important; 
                margin: 0 !important; 
                padding: 0 !important; 
                height: auto !important; 
                overflow: visible !important;
                width: 100% !important;
            }
            /* Reset Layout Flexbox agar block normal */
            body { display: block !important; }
            main { display: block !important; }
        }
    </style>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden font-sans">
    <aside class="bg-slate-800 text-white w-64 flex-shrink-0 flex flex-col transition-all duration-300 hidden md:flex">
        <div class="h-16 flex items-center justify-center border-b border-slate-700 bg-slate-900">
            <h1 class="text-lg font-bold tracking-wider uppercase"><?= $app_name ?></h1>
        </div>
        <div class="flex-1 overflow-y-auto sidebar-scroll py-4">
            <nav class="px-2 space-y-1">
                <!-- DASHBOARDS -->
                <div class="px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Home</div>
                <a href="?page=dashboard" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'dashboard' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-home mr-3 w-6 text-center"></i> Dashboard Utama
                </a>

                <!-- INPUT TRANSAKSI -->
                <div class="mt-4 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Input Transaksi</div>
                <?php if ($access_finance): ?>
                <a href="?page=transaksi_keuangan" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'transaksi_keuangan' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-money-bill-wave mr-3 w-6 text-center"></i> Input Keuangan
                </a>
                <?php endif; ?>
                <?php if ($access_inventory): ?>
                <a href="?page=barang_masuk" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'barang_masuk' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-box-open mr-3 w-6 text-center"></i> Barang Masuk
                </a>
                <a href="?page=barang_keluar" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'barang_keluar' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-dolly mr-3 w-6 text-center"></i> Barang Keluar
                </a>
                <a href="?page=input_aktivitas" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'input_aktivitas' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-tools mr-3 w-6 text-center"></i> Input Aktivitas / Pemakaian
                </a>
                <?php endif; ?>

                <!-- COLLAPSIBLE: DATA KEUANGAN & INVENTORI -->
                <button onclick="toggleMenu('menu-data', this)" class="w-full flex justify-between items-center mt-4 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider hover:text-white focus:outline-none transition-colors">
                    <span>Data Keuangan & Inventori</span>
                    <i class="fas <?= $is_menu_data_open ? 'fa-minus' : 'fa-plus' ?> text-[10px]"></i>
                </button>
                <div id="menu-data" class="<?= $is_menu_data_open ? '' : 'hidden' ?> space-y-1 mt-1 pl-2 border-l-2 border-slate-700 ml-2">
                    <?php if ($access_inventory): ?>
                        <a href="?page=data_barang" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'data_barang' ? 'text-white' : 'text-slate-300' ?>">Data Barang</a>
                        <!-- Lokasi Gudang dipindah ke Admin -->
                    <?php endif; ?>
                    <?php if ($access_finance): ?>
                        <a href="?page=data_keuangan" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'data_keuangan' ? 'text-white' : 'text-slate-300' ?>">Data Keuangan</a>
                        <!-- Akun Kategori dipindah ke Admin -->
                    <?php endif; ?>
                    <a href="?page=data_transaksi" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'data_transaksi' ? 'text-white' : 'text-slate-300' ?>">Semua Transaksi</a>
                </div>

                <!-- COLLAPSIBLE: LAPORAN -->
                <button onclick="toggleMenu('menu-laporan', this)" class="w-full flex justify-between items-center mt-4 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider hover:text-white focus:outline-none transition-colors">
                    <span>Laporan</span>
                    <i class="fas <?= $is_menu_laporan_open ? 'fa-minus' : 'fa-plus' ?> text-[10px]"></i>
                </button>
                <div id="menu-laporan" class="<?= $is_menu_laporan_open ? '' : 'hidden' ?> space-y-1 mt-1 pl-2 border-l-2 border-slate-700 ml-2">
                    <?php if ($access_inventory): ?>
                        <a href="?page=laporan_gudang" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'laporan_gudang' ? 'text-white' : 'text-slate-300' ?>">Transaksi Gudang</a>
                        <a href="?page=laporan_aset" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'laporan_aset' ? 'text-white' : 'text-slate-300' ?>">Laporan Aset</a>
                    <?php endif; ?>
                    <?php if ($access_finance): ?>
                        <a href="?page=laporan_keuangan" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'laporan_keuangan' ? 'text-white' : 'text-slate-300' ?>">Laporan Pajak</a>
                        <a href="?page=laporan_internal" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'laporan_internal' ? 'text-white' : 'text-slate-300' ?>">Laporan Internal</a>
                    <?php endif; ?>
                </div>

                <!-- COLLAPSIBLE: PENGATURAN (ADMIN) -->
                <button onclick="toggleMenu('menu-admin', this)" class="w-full flex justify-between items-center mt-4 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider hover:text-white focus:outline-none transition-colors">
                    <span>Pengaturan</span>
                    <i class="fas <?= $is_menu_admin_open ? 'fa-minus' : 'fa-plus' ?> text-[10px]"></i>
                </button>
                <div id="menu-admin" class="<?= $is_menu_admin_open ? '' : 'hidden' ?> space-y-1 mt-1 pl-2 border-l-2 border-slate-700 ml-2">
                    <!-- PINDAHAN DARI DATA: Lokasi Gudang -->
                    <?php if ($access_inventory): ?>
                        <a href="?page=lokasi_gudang" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'lokasi_gudang' ? 'text-white' : 'text-slate-300' ?>">Lokasi Gudang</a>
                    <?php endif; ?>
                    
                    <!-- PINDAHAN DARI DATA: Akun & Kategori -->
                    <?php if ($access_finance): ?>
                        <a href="?page=akun_kategori" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'akun_kategori' ? 'text-white' : 'text-slate-300' ?>">Akun & Kategori</a>
                    <?php endif; ?>

                    <?php if ($access_user_mgmt): ?>
                        <a href="?page=manajemen_user" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'manajemen_user' ? 'text-white' : 'text-slate-300' ?>">Manajemen User</a>
                    <?php endif; ?>
                    <?php if ($access_settings): ?>
                        <a href="?page=system_logs" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'system_logs' ? 'text-white' : 'text-slate-300' ?>">System Logs</a>
                        <a href="?page=pengaturan" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'pengaturan' ? 'text-white' : 'text-slate-300' ?>">Pengaturan Perusahaan</a>
                        <a href="?page=update_system" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'update_system' ? 'text-white' : 'text-slate-300' ?>">
                            <i class="fas fa-sync-alt mr-2"></i> Update Sistem
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
        
        <!-- USER FOOTER -->
        <div class="border-t border-slate-700 p-4 bg-slate-900">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="h-8 w-8 rounded-full bg-slate-600 flex items-center justify-center text-xs font-bold">
                        <?= strtoupper(substr($_SESSION['username'], 0, 2)) ?>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-white"><?= $_SESSION['username'] ?></p>
                    <p class="text-xs text-slate-400"><?= $_SESSION['role'] ?></p>
                </div>
                <a href="?page=logout" class="ml-auto text-slate-400 hover:text-white" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </aside>

    <!-- CONTENT -->
    <main class="flex-1 flex flex-col h-screen overflow-hidden">
        <!-- TOPBAR MOBILE -->
        <header class="bg-white shadow-sm h-16 flex items-center justify-between px-4 md:hidden z-20">
            <h1 class="font-bold text-gray-800"><?= $app_name ?></h1>
            <button onclick="document.querySelector('aside').classList.toggle('hidden')" class="text-gray-600 focus:outline-none">
                <i class="fas fa-bars text-xl"></i>
            </button>
        </header>

        <!-- MAIN SCROLL -->
        <div class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-4 md:p-6">
            <?php
            $filename = "views/" . $page . ".php";
            if (file_exists($filename)) {
                include $filename;
            } else {
                echo "<div class='bg-white p-6 rounded shadow text-center'>
                        <h2 class='text-2xl font-bold text-gray-800'>404</h2>
                        <p>Halaman tidak ditemukan.</p>
                      </div>";
            }
            ?>
        </div>
    </main>

    <script>
        function toggleMenu(id, btn) {
            const menu = document.getElementById(id);
            const icon = btn.querySelector('i');
            if (menu.classList.contains('hidden')) {
                menu.classList.remove('hidden');
                icon.classList.remove('fa-plus');
                icon.classList.add('fa-minus');
            } else {
                menu.classList.add('hidden');
                icon.classList.remove('fa-minus');
                icon.classList.add('fa-plus');
            }
        }
    </script>
</body>
</html>