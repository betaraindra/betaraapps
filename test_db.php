<?php
require 'config.php';
$stmt = $pdo->query("SELECT notes FROM inventory_transactions WHERE notes LIKE '%PHOTOS%' LIMIT 5");
$rows = $stmt->fetchAll();
foreach ($rows as $row) {
    echo $row['notes'] . "\n";
}
