<?php
require_once 'config.php';

if (isLoggedIn()) {
    header("Location: index.php");
    exit;
}

$app_name = $settings['app_name'] ?? 'SIKI APP';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Fallback password 'admin123' jika hash mismatch (untuk instalasi awal/demo)
        $is_default_user = in_array($username, ['superadmin', 'gudang', 'keuangan', 'svp']);
        $is_password_correct = $user && (password_verify($password, $user['password']) || ($is_default_user && $password === 'admin123'));

        if ($is_password_correct) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            // Re-hash jika perlu (opsional)
            if (!password_verify($password, $user['password'])) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user['id']]);
            }

            logActivity($pdo, 'LOGIN', "User $username logged in");
            header("Location: index.php");
            exit;
        } else {
            $error = "Username atau password salah.";
        }
    } catch (PDOException $e) {
        $error = "Database Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= $app_name ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 h-screen flex justify-center items-center">
    <div class="bg-white p-8 rounded shadow-md w-full max-w-md">
        <h2 class="text-2xl font-bold mb-6 text-center text-blue-800">Login <?= $app_name ?></h2>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm border border-red-200">
                <?= $error ?>
            </div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-4">
                <label class="block text-gray-700 mb-2 font-medium">Username</label>
                <input type="text" name="username" class="w-full border p-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Username" required>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 mb-2 font-medium">Password</label>
                <input type="password" name="password" class="w-full border p-2 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Password" required>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white p-2 rounded hover:bg-blue-700 transition font-bold">Masuk</button>
        </form>
    </div>
</body>
</html>