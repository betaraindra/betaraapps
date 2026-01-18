<?php
require_once 'config.php';
requireLogin();

// Routing Sederhana
$page = $_GET['page'] ?? 'dashboard';
$role = $_SESSION['role'];

// Logout Logic
if ($page === 'logout') {
    logActivity($pdo, 'LOGOUT', "User {$_SESSION['username']} logged out");
    session_destroy();
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $settings['company_name'] ?? 'SIKI App' ?></title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Chart JS -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- QR Scanner -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <!-- Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Custom scrollbar for sidebar */
        .sidebar-scroll::-webkit-scrollbar { width: 5px; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: #475569; border-radius: 5px; }
    </style>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden font-sans">

    <!-- Sidebar -->
    <aside class="bg-slate-800 text-white w-64 flex-shrink-0 flex flex-col transition-all duration-300 hidden md:flex">
        <div class="h-16 flex items-center justify-center border-b border-slate-700 bg-slate-900">
            <h1 class="text-lg font-bold tracking-wider">SIKI APP</h1>
        </div>
        
        <div class="flex-1 overflow-y-auto sidebar-scroll py-4">
            <nav class="px-2 space-y-1">
                <!-- Dashboard -->
                <a href="?page=dashboard" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'dashboard' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-home mr-3 w-6 text-center"></i> Dashboard
                </a>

                <!-- Transaksi Header -->
                <div class="mt-4 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Input Transaksi</div>
                
                <?php if ($role !== 'ADMIN_GUDANG'): ?>
                <a href="?page=transaksi_keuangan" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'transaksi_keuangan' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-money-bill-wave mr-3 w-6 text-center"></i> Input Keuangan
                </a>
                <?php endif; ?>

                <?php if ($role !== 'ADMIN_KEUANGAN'): ?>
                <a href="?page=barang_masuk" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'barang_masuk' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-box-open mr-3 w-6 text-center"></i> Barang Masuk
                </a>
                <a href="?page=barang_keluar" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'barang_keluar' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-dolly mr-3 w-6 text-center"></i> Barang Keluar
                </a>
                <?php endif; ?>

                <!-- Data Master -->
                <div class="mt-4 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Data Master</div>
                
                <?php if ($role !== 'ADMIN_KEUANGAN'): ?>
                <a href="?page=data_barang" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'data_barang' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-boxes mr-3 w-6 text-center"></i> Data Barang
                </a>
                <a href="?page=lokasi_gudang" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'lokasi_gudang' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-map-marker-alt mr-3 w-6 text-center"></i> Lokasi Gudang
                </a>
                <?php endif; ?>

                <?php if ($role !== 'ADMIN_GUDANG'): ?>
                <a href="?page=akun_kategori" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'akun_kategori' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-tags mr-3 w-6 text-center"></i> Akun & Kategori
                </a>
                <a href="?page=data_transaksi" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'data_transaksi' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-list-alt mr-3 w-6 text-center"></i> Data Transaksi
                </a>
                <?php endif; ?>

                <!-- Laporan -->
                <div class="mt-4 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Laporan</div>
                
                <?php if ($role !== 'ADMIN_KEUANGAN'): ?>
                <a href="?page=laporan_gudang" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'laporan_gudang' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-warehouse mr-3 w-6 text-center"></i> Laporan Gudang
                </a>
                <?php endif; ?>

                <?php if ($role !== 'ADMIN_GUDANG'): ?>
                <a href="?page=laporan_keuangan" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'laporan_keuangan' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-chart-line mr-3 w-6 text-center"></i> Laporan Keuangan
                </a>
                <?php endif; ?>

                <!-- Admin & System -->
                <div class="mt-4 px-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Admin</div>
                
                <?php if ($role === 'SUPER_ADMIN'): ?>
                <a href="?page=manajemen_user" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'manajemen_user' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-users-cog mr-3 w-6 text-center"></i> Manajemen User
                </a>
                <a href="?page=system_logs" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'system_logs' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-history mr-3 w-6 text-center"></i> System Logs
                </a>
                <?php endif; ?>
                
                <?php if ($role === 'SUPER_ADMIN' || $role === 'ADMIN_KEUANGAN'): ?>
                <a href="?page=pengaturan" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md hover:bg-slate-700 <?= $page == 'pengaturan' ? 'bg-slate-900 text-white' : 'text-slate-300' ?>">
                    <i class="fas fa-cogs mr-3 w-6 text-center"></i> Pengaturan
                </a>
                <?php endif; ?>
            </nav>
        </div>
        
        <div class="p-4 border-t border-slate-700">
            <div class="flex items-center">
                <div class="ml-3">
                    <p class="text-sm font-medium text-white"><?= $_SESSION['username'] ?></p>
                    <p class="text-xs text-slate-400"><?= str_replace('_', ' ', $_SESSION['role']) ?></p>
                </div>
                <a href="?page=logout" class="ml-auto text-slate-400 hover:text-white"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col overflow-hidden">
        
        <!-- Mobile Header -->
        <header class="md:hidden flex items-center justify-between bg-white border-b p-4">
            <div class="font-bold text-lg">SIKI APP</div>
            <button onclick="document.querySelector('aside').classList.toggle('hidden');" class="text-gray-600 focus:outline-none">
                <i class="fas fa-bars"></i>
            </button>
        </header>

        <!-- Page Content -->
        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-6">
            <?php
            // Flash Message
            if (isset($_SESSION['flash'])) {
                $bg = $_SESSION['flash']['type'] == 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
                echo "<div class='mb-4 p-4 rounded $bg'>{$_SESSION['flash']['message']}</div>";
                unset($_SESSION['flash']);
            }

            // Router Include
            $filename = "views/" . $page . ".php";
            if (file_exists($filename)) {
                include $filename;
            } else {
                echo "<div class='text-center mt-10'><h1 class='text-4xl font-bold text-gray-300'>404</h1><p>Halaman tidak ditemukan.</p></div>";
            }
            ?>
        </main>
    </div>

</body>
</html>