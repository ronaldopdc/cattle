<?php
require_once 'src/config.php';

function getColumns($pdo, $table)
{
    $stmt = $pdo->query("DESCRIBE $table");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

echo "Partners: " . implode(", ", getColumns($pdo, 'partners')) . "\n";
echo "Lots: " . implode(", ", getColumns($pdo, 'lots')) . "\n";
echo "Partnerships: " . implode(", ", getColumns($pdo, 'partnerships')) . "\n";
