<?php
// Migration: add lot_id column to partnership_liquidations
require_once __DIR__ . '/../../src/config.php';

try {
    $pdo->exec("ALTER TABLE partnership_liquidations ADD COLUMN IF NOT EXISTS lot_id INT NULL DEFAULT NULL");
    echo "Column 'lot_id' added to 'partnership_liquidations' table successfully!\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Column 'lot_id' already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
