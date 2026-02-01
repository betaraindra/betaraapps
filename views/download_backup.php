<?php
// views/download_backup.php
checkRole(['SUPER_ADMIN']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup'])) {
    logActivity($pdo, 'BACKUP', "Melakukan backup database");
    
    $sqlScript = "SET FOREIGN_KEY_CHECKS=0;\n";
    $sqlScript .= "-- SIKI DATABASE BACKUP\n";
    $sqlScript .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    $tables = ['users', 'accounts', 'warehouses', 'products', 'finance_transactions', 'inventory_transactions', 'settings', 'system_logs'];
    
    foreach ($tables as $table) {
        $sqlScript .= "-- Table structure and data for table `$table`\n";
        $sqlScript .= "TRUNCATE TABLE $table;\n";
        
        $result = $pdo->query("SELECT * FROM $table");
        $num_fields = $result->columnCount();
        
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $sqlScript .= "INSERT INTO $table VALUES(";
            for ($j = 0; $j < $num_fields; $j++) {
                if (!isset($row[$j])) {
                    $sqlScript .= "NULL";
                } else {
                    $row[$j] = addslashes($row[$j]);
                    $row[$j] = str_replace("\n", "\\n", $row[$j]);
                    $row[$j] = str_replace("\r", "\\r", $row[$j]);
                    $sqlScript .= "'" . $row[$j] . "'";
                }
                if ($j < ($num_fields - 1)) { $sqlScript .= ','; }
            }
            $sqlScript .= ");\n";
        }
        $sqlScript .= "\n";
    }
    
    $sqlScript .= "SET FOREIGN_KEY_CHECKS=1;";

    $filename = 'backup_siki_' . date('Y-m-d_H-i-s') . '.sql';

    header('Content-Type: application/octet-stream');
    header('Content-Transfer-Encoding: Binary');
    header('Content-disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sqlScript));
    
    echo $sqlScript;
    exit;
} else {
    // Redirect if direct access
    header("Location: ?page=pengaturan");
    exit;
}
?>