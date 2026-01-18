<?php
checkRole(['SUPER_ADMIN']);

// Handle Post
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    
    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    }

    if (isset($_POST['id']) && !empty($_POST['id'])) {
        // Edit
        $sql = "UPDATE users SET username=?, email=?, role=?";
        $params = [$username, $email, $role];
        if (!empty($password)) {
            $sql .= ", password=?";
            $params[] = $password;
        }
        $sql .= " WHERE id=?";
        $params[] = $_POST['id'];
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        // Add
        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $password, $email, $role]);
    }
    $_SESSION['flash'] = ['type'=>'success', 'message'=>'User tersimpan'];
    echo "<script>window.location='?page=manajemen_user';</script>";
    exit;
}

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    if ($id == $_SESSION['user_id']) {
        echo "<script>alert('Tidak bisa menghapus diri sendiri!'); window.location='?page=manajemen_user';</script>";
        exit;
    }
    $pdo->query("DELETE FROM users WHERE id=$id");
    $_SESSION['flash'] = ['type'=>'success', 'message'=>'User dihapus'];
    echo "<script>window.location='?page=manajemen_user';</script>";
    exit;
}

$users = $pdo->query("SELECT * FROM users")->fetchAll();
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="bg-white p-6 rounded-lg shadow">
        <h3 class="text-lg font-bold mb-4">Input User</h3>
        <form method="POST">
            <input type="hidden" name="id" id="form_id">
            <div class="mb-4">
                <label class="block text-sm">Username</label>
                <input type="text" name="username" id="form_username" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm">Email</label>
                <input type="email" name="email" id="form_email" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm">Password</label>
                <input type="password" name="password" class="w-full border p-2 rounded" placeholder="Kosongkan jika tidak ubah">
            </div>
            <div class="mb-4">
                <label class="block text-sm">Role</label>
                <select name="role" id="form_role" class="w-full border p-2 rounded">
                    <option value="SUPER_ADMIN">SUPER ADMIN</option>
                    <option value="ADMIN_GUDANG">ADMIN GUDANG</option>
                    <option value="ADMIN_KEUANGAN">ADMIN KEUANGAN</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded flex-1">Simpan</button>
                <button type="button" onclick="resetForm()" class="bg-gray-200 px-4 rounded">Batal</button>
            </div>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow lg:col-span-2">
        <table class="w-full text-sm text-left border">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 border">Username</th>
                    <th class="p-3 border">Email</th>
                    <th class="p-3 border">Role</th>
                    <th class="p-3 border text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td class="p-3 border font-bold"><?= $u['username'] ?></td>
                    <td class="p-3 border"><?= $u['email'] ?></td>
                    <td class="p-3 border"><span class="bg-gray-100 px-2 rounded text-xs"><?= $u['role'] ?></span></td>
                    <td class="p-3 border text-center">
                        <button onclick="edit(<?= htmlspecialchars(json_encode($u)) ?>)" class="text-blue-600 mr-2"><i class="fas fa-edit"></i></button>
                        <a href="?page=manajemen_user&delete=<?= $u['id'] ?>" onclick="return confirm('Hapus user ini?')" class="text-red-600"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function edit(data) {
    document.getElementById('form_id').value = data.id;
    document.getElementById('form_username').value = data.username;
    document.getElementById('form_email').value = data.email;
    document.getElementById('form_role').value = data.role;
}
function resetForm() {
    document.getElementById('form_id').value = '';
    document.getElementById('form_username').value = '';
    document.getElementById('form_email').value = '';
    document.getElementById('form_role').value = 'ADMIN_GUDANG';
}
</script>