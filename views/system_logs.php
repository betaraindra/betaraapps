<?php
checkRole(['SUPER_ADMIN', 'SVP']);
$logs = $pdo->query("SELECT l.*, u.username FROM system_logs l LEFT JOIN users u ON l.user_id=u.id ORDER BY l.timestamp DESC LIMIT 100")->fetchAll();
?>
<div class="bg-white p-6 rounded shadow">
    <h3 class="font-bold mb-4">System Logs (100 Terakhir)</h3>
    <table class="w-full text-sm border">
        <thead class="bg-gray-100"><tr><th class="p-2">Waktu</th><th class="p-2">User</th><th class="p-2">Aksi</th><th class="p-2">Detail</th></tr></thead>
        <tbody><?php foreach($logs as $l): ?><tr><td class="p-2"><?= $l['timestamp'] ?></td><td class="p-2 font-bold"><?= $l['username'] ?></td><td class="p-2"><?= $l['action'] ?></td><td class="p-2"><?= $l['details'] ?></td></tr><?php endforeach; ?></tbody>
    </table>
</div>