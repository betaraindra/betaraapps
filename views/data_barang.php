<!-- FILTER BAR -->
<div class="bg-white p-4 rounded-lg shadow mb-6 border-l-4 border-indigo-600 no-print">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
        <input type="hidden" name="page" value="data_barang">
        <div class="lg:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Cari Barang</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-indigo-500" placeholder="Nama / SKU / SN / Ref...">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Kategori</label>
            <select name="category" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                <option value="ALL">-- Semua --</option>
                <?php foreach($existing_categories as $ec): ?>
                    <?php $label = $ec['category']; if(!empty($ec['name'])) $label .= " - " . $ec['name']; ?>
                    <option value="<?= $ec['category'] ?>" <?= $cat_filter==$ec['category'] ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Status Stok</label>
            <select name="stock_status" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                <option value="ALL">-- Semua --</option>
                <option value="AVAILABLE" <?= $stock_status=='AVAILABLE' ? 'selected' : '' ?>>Tersedia (> 0)</option>
                <option value="LOW" <?= $stock_status=='LOW' ? 'selected' : '' ?>>Menipis (< 10)</option>
                <option value="EMPTY" <?= $stock_status=='EMPTY' ? 'selected' : '' ?>>Habis (0)</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Urutkan</label>
            <select name="sort" class="w-full border p-2 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500">
                <option value="newest" <?= $sort_by=='newest'?'selected':'' ?>>Update Terbaru</option>
                <option value="name_asc" <?= $sort_by=='name_asc'?'selected':'' ?>>Nama (A-Z)</option>
                <option value="stock_high" <?= $sort_by=='stock_high'?'selected':'' ?>>Stok Terbanyak</option>
            </select>
        </div>
        <div class="lg:col-span-1">
            <label class="block text-xs font-bold text-gray-600 mb-1">Update Terakhir</label>
            <div class="flex gap-1">
                <input type="date" name="start" value="<?= $start_date ?>" class="w-1/2 border p-2 rounded text-xs">
                <input type="date" name="end" value="<?= $end_date ?>" class="w-1/2 border p-2 rounded text-xs">
            </div>
        </div>
        <div>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold hover:bg-indigo-700 w-full shadow text-sm h-[38px]">
                <i class="fas fa-filter"></i> Filter
            </button>
        </div>
    </form>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6 page-content-wrapper">
    <!-- FORM SECTION -->
    <div class="lg:col-span-1 space-y-6 no-print">
<?php
// --- BUILD QUERY ---
$sql = "SELECT p.*, 
        it.id as trx_id, 
        COALESCE(it.reference, '-') as reference, 
        COALESCE(it.date, DATE(p.created_at)) as trx_date, 
        COALESCE(it.notes, '') as trx_notes, 
        it.warehouse_id as current_wh_id, 
        w.name as warehouse_name 
        FROM products p 
        LEFT JOIN (
            SELECT id, product_id, reference, date, warehouse_id, notes 
            FROM inventory_transactions 
            WHERE id IN (
                SELECT MAX(id) FROM inventory_transactions GROUP BY product_id
            )
        ) it ON p.id = it.product_id 
        LEFT JOIN warehouses w ON it.warehouse_id = w.id 
        WHERE (
            p.name LIKE ? 
            OR p.sku LIKE ? 
            OR it.reference LIKE ? 
            OR it.notes LIKE ? 
            OR EXISTS (SELECT 1 FROM product_serials ps WHERE ps.product_id = p.id AND ps.serial_number LIKE ?)
        ) 
        AND (COALESCE(it.date, DATE(p.created_at)) BETWEEN ? AND ?)";

$params = ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%", $start_date, $end_date];

if ($warehouse_filter !== 'ALL') { $sql .= " AND it.warehouse_id = ?"; $params[] = $warehouse_filter; }
if ($cat_filter !== 'ALL') { $sql .= " AND p.category = ?"; $params[] = $cat_filter; }
if ($stock_status === 'LOW') $sql .= " AND p.stock < 10 AND p.stock > 0";
elseif ($stock_status === 'EMPTY') $sql .= " AND p.stock = 0";
elseif ($stock_status === 'AVAILABLE') $sql .= " AND p.stock > 0";

if ($sort_by === 'name_asc') $sql .= " ORDER BY p.name ASC";
elseif ($sort_by === 'name_desc') $sql .= " ORDER BY p.name DESC";
elseif ($sort_by === 'stock_high') $sql .= " ORDER BY p.stock DESC";
elseif ($sort_by === 'stock_low') $sql .= " ORDER BY p.stock ASC";
else $sql .= " ORDER BY trx_date DESC, p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
?>