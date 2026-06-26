<?php
// Migration script to create partnership_liquidations table

require_once __DIR__ . '/../../src/config.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS partnership_liquidations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partnership_id INT NOT NULL,
        lot_id INT NULL DEFAULT NULL,
        date DATE NOT NULL,
        amount_principal DECIMAL(15, 2) NOT NULL,
        amount_interest DECIMAL(15, 2) NOT NULL,
        amount_total DECIMAL(15, 2) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (partnership_id) REFERENCES partnerships(id) ON DELETE CASCADE,
        INDEX idx_partnership_id (partnership_id),
        INDEX idx_lot_id (lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    echo "Table 'partnership_liquidations' created successfully!\n";
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
    exit(1);
}
