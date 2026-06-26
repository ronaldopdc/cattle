<?php
require_once 'src/config.php';

try {
    // Add email to users
    echo "Updating users table...\n";
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL AFTER username");
        echo "Added email column to users.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Duplicate column name") !== false) {
            echo "Column email already exists in users.\n";
        } else {
            throw $e;
        }
    }

    // Add email and phone to partners
    echo "Updating partners table...\n";
    try {
        $pdo->exec("ALTER TABLE partners ADD COLUMN email VARCHAR(255) NULL AFTER name");
        echo "Added email column to partners.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Duplicate column name") !== false) {
            echo "Column email already exists in partners.\n";
        } else {
            throw $e;
        }
    }

    try {
        $pdo->exec("ALTER TABLE partners ADD COLUMN phone VARCHAR(20) NULL AFTER email");
        echo "Added phone column to partners.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Duplicate column name") !== false) {
            echo "Column phone already exists in partners.\n";
        } else {
            throw $e;
        }
    }

    echo "Schema update completed successfully.\n";

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage() . "\n");
}
