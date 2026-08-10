<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'SUPER_ADMIN';

// override config
require_once 'config.php';
ini_set('display_errors', 1);

$_GET['page'] = 'cetak_laporan_gudang';
$_GET['start'] = '2026-08-01';
$_GET['end'] = '2026-08-10';
$_GET['warehouse_id'] = 'ALL';
$_GET['type'] = 'ALL';
$_GET['q'] = '';

include "index.php";
