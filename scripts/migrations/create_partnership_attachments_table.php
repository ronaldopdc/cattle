<?php
require_once __DIR__ . '/../../src/config.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS partnership_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partnership_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        file_data LONGBLOB NOT NULL,
        file_type VARCHAR(100) NOT NULL,
        file_size INT NOT NULL,
        description TEXT,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (partnership_id) REFERENCES partnerships(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $pdo->exec($sql);
    echo "Tabela 'partnership_attachments' criada com sucesso!";
} catch (PDOException $e) {
    echo "Erro ao criar tabela: " . $e->getMessage();
}
?>