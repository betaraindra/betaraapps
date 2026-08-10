<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';
$_GET['page'] = 'cetak_laporan_gudang';
$_SESSION['role'] = 'SUPER_ADMIN';
require 'views/cetak_laporan_gudang.php';
