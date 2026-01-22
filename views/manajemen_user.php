<?php
// MANAGER bisa semua akses kecuali edit pengaturan. Jadi Manager BISA user management.
// SVP bisa semua kecuali pengaturan DAN user management.
checkRole(['SUPER_ADMIN', 'MANAGER']); 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- 1. HAPUS USER ---
    if (isset($_POST['delete'])) {
        $userToDelete = $pdo->query("SELECT username FROM users WHERE id=".$_POST['delete'])->fetch();
        if($userToDelete) {
             logActivity($pdo, 'DELETE_USER', "Menghapus user: " . $userToDelete['username']);
        }
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$_POST['delete']]);
        $_SESSION['flash'] = ['type'=>'success', 'message'=>'User berhasil dihapus'];
    } 
    
    // --- 2. EDIT USER ---
    elseif (isset($_POST['edit_user'])) {
        $id = $_POST['edit_id'];
        $username = $_POST['username'];
        $email = $_POST['email'];
        $role = $_POST['role'];
        $password = $_POST['password'];

        try {
            if (!empty($password)) {
                // Jika password diisi, update password baru
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, role=?, password=? WHERE id=?");
                $stmt->execute([$username, $email, $role, $hash, $id]);
            } else {
                // Jika password kosong, jangan update password
                $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, role=? WHERE id=?");
                $stmt->execute([$username, $email, $role, $id]);
            }
            
            logActivity($pdo, 'EDIT_USER', "Mengedit user: " . $username);
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'Data user berhasil diperbarui'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal update: Username/Email mungkin sudah ada.'];
        }
    }

    // --- 3. TAMBAH USER ---
    else {
        $username = $_POST['username'];
        $email = $_POST['email'];
        $role = $_POST['role'];
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        try {
            $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)")->execute([$username, $pass, $email, $role]);
            logActivity($pdo, 'ADD_USER', "Menambahkan user baru: " . $username);
            $_SESSION['flash'] = ['type'=>'success', 'message'=>'User baru berhasil ditambahkan'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'error', 'message'=>'Gagal tambah: Username sudah digunakan.'];
        }
    }
    
    echo "<script>window.location='?page=manajemen_user';</script>";
    exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY role ASC, username ASC")->fetchAll();
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <!-- FORM TAMBAH USER -->
    <div class="bg-white p-6 rounded-lg shadow h-fit">
        <h3 class="font-bold mb-4 text-blue-800 border-b pb-2"><i class="fas fa-user-plus"></i> Tambah User</h3>
        <form method="POST">
            <div class="mb-3">
                <label class="block text-sm mb-1 font-bold text-gray-700">Username</label>
                <input type="text" name="username" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500" required>
            </div>
            <div class="mb-3">
                <label class="block text-sm mb-1 font-bold text-gray-700">Email</label>
                <input type="email" name="email" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500" required>
            </div>
            <div class="mb-3">
                <label class="block text-sm mb-1 font-bold text-gray-700">Password</label>
                <input type="password" name="password" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500" required>
            </div>
            <div class="mb-4">
                <label class="block text-sm mb-1 font-bold text-gray-700">Role</label>
                <select name="role" class="w-full border p-2 rounded bg-white focus:ring-2 focus:ring-blue-500">
                    <option value="ADMIN_GUDANG">ADMIN GUDANG (Stok)</option>
                    <option value="ADMIN_KEUANGAN">ADMIN KEUANGAN (Keu)</option>
                    <?php if($_SESSION['role'] == 'SUPER_ADMIN'): ?>
                        <option value="MANAGER">MANAGER (Ops & User)</option>
                        <option value="SVP">SVP (View Only + Ops)</option>
                        <option value="SUPER_ADMIN">SUPER ADMIN</option>
                    <?php endif; ?>
                </select>
            </div>
            <button class="bg-blue-600 text-white px-4 py-2 rounded w-full font-bold hover:bg-blue-700 shadow transition">
                <i class="fas fa-save"></i> Simpan User
            </button>
        </form>
    </div>

    <!-- TABEL LIST USER -->
    <div class="bg-white p-6 rounded-lg shadow md:col-span-2">
        <h3 class="font-bold mb-4 text-gray-800 border-b pb-2">Daftar Pengguna</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm border text-left">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border-b">Username</th>
                        <th class="p-3 border-b">Email</th>
                        <th class="p-3 border-b">Role</th>
                        <th class="p-3 border-b text-center w-24">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($users as $u): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 font-bold"><?= htmlspecialchars($u['username']) ?></td>
                        <td class="p-3 text-gray-600"><?= htmlspecialchars($u['email']) ?></td>
                        <td class="p-3">
                            <?php 
                                $bg = 'bg-gray-200 text-gray-800';
                                if($u['role'] == 'SUPER_ADMIN') $bg = 'bg-purple-100 text-purple-800';
                                elseif($u['role'] == 'ADMIN_KEUANGAN') $bg = 'bg-green-100 text-green-800';
                                elseif($u['role'] == 'ADMIN_GUDANG') $bg = 'bg-blue-100 text-blue-800';
                            ?>
                            <span class="<?= $bg ?> px-2 py-1 rounded text-xs font-bold"><?= $u['role'] ?></span>
                        </td>
                        <td class="p-3 text-center">
                            <?php if($u['username'] != 'superadmin' || $_SESSION['role'] == 'SUPER_ADMIN'): ?>
                                <div class="flex justify-center gap-2">
                                    <!-- Tombol Edit -->
                                    <button onclick='openEditModal(<?= json_encode($u) ?>)' class="text-blue-600 hover:bg-blue-100 p-2 rounded transition" title="Edit User">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <!-- Tombol Hapus (Proteksi diri sendiri) -->
                                    <?php if($u['id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" onsubmit="return confirm('Hapus user <?= $u['username'] ?>?')" class="inline">
                                        <input type="hidden" name="delete" value="<?= $u['id'] ?>">
                                        <button class="text-red-600 hover:bg-red-100 p-2 rounded transition" title="Hapus User">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-gray-400 text-xs italic">Protected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL EDIT USER -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 relative">
        <button onclick="document.getElementById('editModal').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
            <i class="fas fa-times text-xl"></i>
        </button>
        
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-blue-800"><i class="fas fa-user-edit"></i> Edit User</h3>
        
        <form method="POST">
            <input type="hidden" name="edit_user" value="1">
            <input type="hidden" name="edit_id" id="edit_id">
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Username</label>
                <input type="text" name="username" id="edit_username" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500" required>
            </div>
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Email</label>
                <input type="email" name="email" id="edit_email" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500" required>
            </div>
            
            <div class="mb-3">
                <label class="block text-sm font-bold text-gray-700 mb-1">Role</label>
                <select name="role" id="edit_role" class="w-full border p-2 rounded bg-white focus:ring-2 focus:ring-blue-500">
                    <option value="ADMIN_GUDANG">ADMIN GUDANG</option>
                    <option value="ADMIN_KEUANGAN">ADMIN KEUANGAN</option>
                    <?php if($_SESSION['role'] == 'SUPER_ADMIN'): ?>
                        <option value="MANAGER">MANAGER</option>
                        <option value="SVP">SVP</option>
                        <option value="SUPER_ADMIN">SUPER ADMIN</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">Password Baru</label>
                <input type="password" name="password" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500" placeholder="Kosongkan jika tidak ingin mengubah password">
                <p class="text-xs text-gray-500 mt-1">* Isi hanya jika ingin mengganti password.</p>
            </div>
            
            <div class="flex gap-2">
                <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="flex-1 bg-gray-300 text-gray-700 py-2 rounded font-bold hover:bg-gray-400">Batal</button>
                <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded font-bold hover:bg-blue-700">Update User</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(user) {
    document.getElementById('edit_id').value = user.id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_role').value = user.role;
    
    // Tampilkan modal
    document.getElementById('editModal').classList.remove('hidden');
}
</script>