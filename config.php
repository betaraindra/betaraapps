<?php
// config.php
session_start();
date_default_timezone_set('Asia/Jakarta');

// Database Connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'siki_db';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Koneksi Gagal: " . $e->getMessage() . "<br>Pastikan file database.sql sudah diimport.");
}

// Global Functions
function base_url() {
    return "http://" . $_SERVER['SERVER_NAME'] . dirname($_SERVER['REQUEST_URI']);
}

function formatRupiah($angka) {
    return "Rp " . number_format($angka, 0, ',', '.');
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function checkRole($allowed_roles) {
    if (!in_array($_SESSION['role'], $allowed_roles)) {
        echo "<script>alert('Akses Ditolak!'); window.history.back();</script>";
        exit;
    }
}

function logActivity($pdo, $action, $details) {
    $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'] ?? 0, $action, $details]);
}

// Get Settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

?>