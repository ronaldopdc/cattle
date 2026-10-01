<?php
// A aplicação também cria esta coluna sob demanda (ensurePartnershipAttachmentNotesColumn).
require_once __DIR__ . '/../../src/config.php';

try {
    $sql = "ALTER TABLE partnership_attachments ADD COLUMN validation_notes TEXT NULL AFTER description";
    $pdo->exec($sql);
    echo "Column 'validation_notes' added to 'partnership_attachments' table successfully!\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column 'validation_notes' already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
