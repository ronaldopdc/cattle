<?php
require_once __DIR__ . '/../../src/config.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS partner_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partner_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        file_content LONGBLOB NOT NULL,
        file_type VARCHAR(100),
        file_size INT,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    echo "Table 'partner_attachments' created successfully!\n";
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
?>