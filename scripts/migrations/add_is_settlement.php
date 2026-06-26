<?php
require_once __DIR__ . '/../../src/config.php';

try {
    $sql = "ALTER TABLE partnership_liquidations ADD COLUMN is_settlement TINYINT(1) DEFAULT 0";
    $pdo->exec($sql);
    echo "Column 'is_settlement' added to 'partnership_liquidations' table successfully!\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column 'is_settlement' already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
