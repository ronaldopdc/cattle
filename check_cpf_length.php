<?php
require_once 'src/config.php';
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM partners LIKE 'cpf'");
    $row = $stmt->fetch();
    echo "Column 'cpf' info:\n";
    print_r($row);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
