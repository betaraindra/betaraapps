<?php
// config.php - PERFORMANCE & SECURITY CORE v2.2 (Production Ready)

// 1. ENVIRONMENT & PERFORMANCE CONFIG
// Di Production, Ubah display_errors menjadi 0
ini_set('display_errors', 0); 
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt'); // Pastikan file ini tidak bisa diakses publik via .htaccess
ini_set('memory_limit', '256M'); 
error_reporting(E_ALL);

// 2. HTTP SECURITY HEADERS
// Mencegah Clickjacking
header("X-Frame-Options: SAMEORIGIN");
// Mencegah MIME-Sniffing
header("X-Content-Type-Options: nosniff");
// Mencegah XSS (Untuk browser lama)
header("X-XSS-Protection: 1; mode=block");
// Referrer Policy
header("Referrer-Policy: strict-origin-when-cross-origin");
// HSTS (Aktifkan jika sudah menggunakan HTTPS)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}

// 3. SESSION SECURITY HARDENING
if (session_status() === PHP_SESSION_NONE) {
    // Session Cookie Settings
    $cookieParams = [
        'lifetime' => 0, // Session cooke deletes on browser close
        'path' => '/',
        'domain' => '', // Set domain spesifik jika di production, misal: .domain.com
        'secure' => isset($_SERVER['HTTPS']), // True jika HTTPS
        'httponly' => true, // Tidak bisa diakses JS
        'samesite' => 'Strict' // Mencegah CSRF
    ];
    session_set_cookie_params($cookieParams);
    session_start();
}

// Regenerate ID (Anti Fixation)
if (!isset($_SESSION['last_regen'])) {
    $_SESSION['last_regen'] = time();
} elseif (time() - $_SESSION['last_regen'] > 300) { 
    session_regenerate_id(true);
    $_SESSION['last_regen'] = time();
}

date_default_timezone_set('Asia/Jakarta');

// 4. DATABASE CONNECTION (PDO)
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'siki_db';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); 
    $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'"); 
} catch (PDOException $e) {
    // Jangan tampilkan pesan error asli DB ke user di production
    error_log("DB Connection Error: " . $e->getMessage());
    die("<h3>Kesalahan Sistem</h3><p>Terjadi masalah koneksi database. Hubungi Administrator.</p>");
}

// --- PERFORMANCE: AUTOMATIC INDEXING (MIGRATION) ---
function ensureIndex($pdo, $table, $indexName, $columns) {
    try {
        $check = $pdo->query("SHOW INDEX FROM $table WHERE Key_name = '$indexName'")->fetch();
        if (!$check) {
            $pdo->exec("CREATE INDEX $indexName ON $table ($columns)");
        }
    } catch (Exception $e) { /* Ignore */ }
}

function ensureColumn($pdo, $table, $column, $definition) {
    try {
        $check = $pdo->query("SHOW COLUMNS FROM $table LIKE '$column'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    } catch (Exception $e) { error_log("Migration Col Error: " . $e->getMessage()); }
}

// --- AUTO MIGRATION SERIAL NUMBER ---
ensureColumn($pdo, 'products', 'has_serial_number', 'TINYINT(1) DEFAULT 0');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_serials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        serial_number VARCHAR(100) NOT NULL,
        status ENUM('AVAILABLE','SOLD','RETURNED','DEFECTIVE') DEFAULT 'AVAILABLE',
        warehouse_id INT DEFAULT NULL,
        in_transaction_id INT DEFAULT NULL,
        out_transaction_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_sn_product (product_id, serial_number),
        INDEX idx_sn (serial_number),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { error_log("Migration Table Error: " . $e->getMessage()); }

ensureIndex($pdo, 'finance_transactions', 'idx_date_type', 'date, type');
ensureIndex($pdo, 'finance_transactions', 'idx_acc_date', 'account_id, date');
ensureIndex($pdo, 'inventory_transactions', 'idx_date_type', 'date, type');
ensureIndex($pdo, 'inventory_transactions', 'idx_prod_wh', 'product_id, warehouse_id');
ensureIndex($pdo, 'products', 'idx_name_sku', 'name, sku');

// --- PERFORMANCE: SIMPLE FILE CACHE ---
class SimpleCache {
    private $cacheDir = 'cache/';
    private $defaultExpiry = 300; 

    public function __construct() {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
            // Secure cache dir
            @file_put_contents($this->cacheDir . '.htaccess', 'Deny from all');
        }
    }

    public function get($key) {
        $file = $this->cacheDir . md5($key) . '.cache';
        if (file_exists($file)) {
            $data = unserialize(file_get_contents($file));
            if ($data['expire'] > time()) {
                return $data['content'];
            }
            unlink($file); 
        }
        return null;
    }

    public function set($key, $content, $ttl = null) {
        $ttl = $ttl ?? $this->defaultExpiry;
        $file = $this->cacheDir . md5($key) . '.cache';
        $data = [
            'expire' => time() + $ttl,
            'content' => $content
        ];
        file_put_contents($file, serialize($data));
    }

    public function delete($key) {
        $file = $this->cacheDir . md5($key) . '.cache';
        if (file_exists($file)) unlink($file);
    }
    
    public function flush() {
        $files = glob($this->cacheDir . '*');
        foreach($files as $file) if(is_file($file) && basename($file) !== '.htaccess') unlink($file);
    }
}

$cache = new SimpleCache();

// --- SECURITY HELPER FUNCTIONS ---

function h($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

function csrf_field() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed');
            die('Security Violation: CSRF Token Mismatch. Mohon refresh halaman.');
        }
    }
}

function cleanNumber($number) {
    $clean = str_replace('.', '', $number);
    $clean = str_replace(',', '.', $clean);
    return (float)$clean;
}

function formatRupiah($angka) {
    return "Rp " . number_format((float)$angka, 0, ',', '.');
}

// --- AUTHENTICATION HELPERS ---

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
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
        http_response_code(403);
        echo "<div style='padding:20px;text-align:center;color:red;font-family:sans-serif;'>
                <h2>403 Akses Ditolak</h2>
                <p>Anda tidak memiliki izin untuk mengakses halaman ini.</p>
                <a href='index.php'>Kembali ke Dashboard</a>
              </div>";
        exit;
    }
}

function logActivity($pdo, $action, $details) {
    global $cache;
    if(in_array($action, ['LOGIN', 'LOGOUT']) === false) {
        $cache->flush(); 
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, details) VALUES (?, ?, ?)");
        $clean_details = substr(strip_tags($details), 0, 255); 
        $stmt->execute([$_SESSION['user_id'] ?? 0, $action, $clean_details]);
    } catch (Exception $e) {
        // Silent error logging
        error_log("Logging Error: " . $e->getMessage());
    }
}

$beep_sound = "data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YU"; 
?>