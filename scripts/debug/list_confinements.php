<?php
require_once __DIR__ . '/../../src/config.php';

$stmt = $pdo->query("
    SELECT p.id, p.name, GROUP_CONCAT(pta.type ORDER BY pta.type) AS types
    FROM partners p
    LEFT JOIN partner_type_assignments pta ON p.id = pta.partner_id
    WHERE pta.type = 'confinamento'
       OR p.name LIKE '%Rial%'
       OR p.name LIKE '%Emiv%'
    GROUP BY p.id, p.name
    ORDER BY p.name
");

foreach ($stmt as $row) {
    echo '#' . $row['id'] . ' ' . $row['name'] . ' [' . $row['types'] . ']' . PHP_EOL;
}
