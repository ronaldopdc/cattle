<?php
require_once __DIR__ . '/../../src/config.php';

try {
    // 1. Find ronaldo's user ID
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'ronaldo'");
    $stmt->execute();
    $ronaldo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ronaldo) {
        die("User 'ronaldo' not found.\n");
    }

    $ronaldo_id = $ronaldo['id'];
    echo "Ronaldo's User ID: $ronaldo_id\n";

    // 2. Add created_by column if not exists and update
    $tables = ['partners', 'lots', 'partnerships', 'contracts'];
    
    foreach ($tables as $table) {
        // Check if column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'created_by'");
        $columnExists = $stmt->fetch();
        
        if (!$columnExists) {
            echo "Adding created_by to $table...\n";
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `created_by` INT DEFAULT NULL");
            $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `fk_{$table}_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)");
        } else {
            echo "Column created_by already exists on $table.\n";
        }
        
        // Update existing rows
        echo "Updating existing rows in $table to user_id $ronaldo_id...\n";
        $pdo->exec("UPDATE `$table` SET `created_by` = $ronaldo_id WHERE `created_by` IS NULL");
    }

    echo "Update complete.\n";

} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}
