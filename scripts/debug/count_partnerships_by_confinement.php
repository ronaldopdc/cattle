<?php
require_once __DIR__ . '/../../src/config.php';

$stmt = $pdo->query("
    SELECT conf.id, conf.name, COUNT(p.id) AS partnership_count
    FROM partners conf
    JOIN partner_type_assignments pta ON conf.id = pta.partner_id
    LEFT JOIN partnerships p ON p.confinamento_id = conf.id
    WHERE pta.type = 'confinamento'
    GROUP BY conf.id, conf.name
    ORDER BY conf.name
");

foreach ($stmt as $row) {
    echo '#' . $row['id'] . ' ' . $row['name'] . ': ' . $row['partnership_count'] . PHP_EOL;
}
