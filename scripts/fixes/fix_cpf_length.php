<?php
date_default_timezone_set('America/Sao_Paulo');

$host = '127.0.0.1';
$db = 'cattle_db';
$user = 'user';
$pass = 'password';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "Updating partners table to increase cpf column length...\n";

    // Alter column to VARCHAR(20) to accommodate formatted CNPJ (18 chars)
    $pdo->exec("ALTER TABLE partners MODIFY COLUMN cpf VARCHAR(20) NOT NULL");

    echo "Column 'cpf' updated to VARCHAR(20) successfully.\n";
    echo "Schema update completed successfully.\n";

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage() . "\n");
}
