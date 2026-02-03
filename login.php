<?php
require_once 'config.php';

// Rate Limiting Config
$max_attempts = 5;
$lockout_time = 300; // 5 menit

if (isLoggedIn()) {
    header("Location: index.php");
    exit;
}

$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM settings");
    while ($row = $stmt->fetch()) $settings[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {}

$app_name = $settings['app_name'] ?? 'SIKI APP';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Rate Limiting Check (Simple Session Based)
    // Untuk production lebih baik menggunakan database/redis
    if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= $max_attempts) {
        $time_since_lockout = time() - $_SESSION['last_attempt_time'];
        if ($time_since_lockout < $lockout_time) {
            $remaining = ceil(($lockout_time - $time_since_lockout) / 60);
            $error = "Terlalu banyak percobaan gagal. Silakan tunggu $remaining menit.";
        } else {
            // Reset after lockout expired
            $_SESSION['login_attempts'] = 0;
        }
    }

    if (!$error) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            // Default fallback logic (Hardcoded passwords for initial setup)
            $is_default_user = in_array($username, ['superadmin', 'gudang', 'keuangan', 'svp', 'admin']);
            
            // Password verification logic
            $check_fallback = false;
            if ($is_default_user) {
                // Khusus user 'admin' passwordnya '@Nextlink1'
                if ($username === 'admin' && $password === '@Nextlink1') {
                    $check_fallback = true;
                } 
                // User default lain passwordnya 'admin123'
                elseif ($username !== 'admin' && $password === 'admin123') {
                    $check_fallback = true;
                }
            }

            // Check: Hash Valid OR Fallback Valid
            $is_password_correct = $user && (password_verify($password, $user['password']) || $check_fallback);

            if ($is_password_correct) {
                // Login Success
                session_regenerate_id(true); // Anti Session Fixation
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_attempts'] = 0; // Reset attempts

                // Re-hash password if needed (e.g. algorithm updated) and NOT a fallback login
                if (!$check_fallback && password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user['id']]);
                }
                // Jika login pakai fallback, otomatis update hash di DB agar ke depan bisa login normal (Self-healing)
                elseif ($check_fallback) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user['id']]);
                }

                logActivity($pdo, 'LOGIN', "User $username logged in");
                header("Location: index.php");
                exit;
            } else {
                // Login Failed
                $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                $_SESSION['last_attempt_time'] = time();
                $error = "Username atau password salah.";
            }
        } catch (PDOException $e) {
            error_log("Login DB Error: " . $e->getMessage());
            $error = "Terjadi kesalahan sistem.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= h($app_name) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 h-screen flex justify-center items-center">
    <div class="bg-white p-8 rounded shadow-md w-full max-w-md border-t-4 border-blue-600">
        <h2 class="text-2xl font-bold mb-6 text-center text-blue-800">Login <?= h($app_name) ?></h2>
        
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm border border-red-200 flex items-center">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="mb-4">
                <label class="block text-gray-700 mb-2 font-medium">Username</label>
                <input type="text" name="username" class="w-full border p-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Username" required autofocus>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 mb-2 font-medium">Password</label>
                <input type="password" name="password" class="w-full border p-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Password" required>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white p-2 rounded hover:bg-blue-700 transition font-bold shadow-lg transform active:scale-95">Masuk Aplikasi</button>
        </form>
    </div>
</body>
</html>