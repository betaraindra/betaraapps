<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'SUPER_ADMIN';

require_once 'config.php';
$_GET['page'] = 'cetak_laporan_gudang';
$page = 'cetak_laporan_gudang';
include "views/cetak_laporan_gudang.php";
