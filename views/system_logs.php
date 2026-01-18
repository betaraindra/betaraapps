<?php
checkRole(['SUPER_ADMIN']);
$logs = $pdo->query("SELECT l.*, u.username FROM system_logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.timestamp DESC LIMIT 200")->fetchAll();
?>
<div class="bg-white p-6 rounded-lg shadow">
    <h3 class="text-lg font-bold mb-4">System Activity Logs</h3>
    <div class="overflow-x-auto h-[600px]">
        <table class="w-full text-sm text-left border-collapse">
            <thead class="bg-gray-800 text-white sticky top-0">
                <tr>
                    <th class="p-3">Waktu</th>
                    <th class="p-3">User</th>
                    <th class="p-3">Aksi</th>
                    <th class="p-3">Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($logs as $l): ?>
                <tr class="border-b hover:bg-gray-50">
                    <td class="p-3 whitespace-nowrap text-gray-500"><?= $l['timestamp'] ?></td>
                    <td class="p-3 font-bold"><?= $l['username'] ?? 'System' ?></td>
                    <td class="p-3"><span class="bg-blue-100 text-blue-800 px-2 rounded text-xs"><?= $l['action'] ?></span></td>
                    <td class="p-3 text-gray-700"><?= $l['details'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>