<?php
require 'config.php';
$stmt = $pdo->query("SHOW COLUMNS FROM inventory_transactions");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
