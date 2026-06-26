<?php
require_once __DIR__ . '/../../src/config.php';
try {
    $sql = "ALTER TABLE partnership_liquidations ADD COLUMN quantity INT NULL";
    $pdo->exec($sql);
    echo "Column 'quantity' added successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
