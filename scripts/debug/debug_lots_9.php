<?php
require __DIR__ . '/../../src/config.php';
$ids = [20, 21, 22];
$in  = str_repeat('?,', count($ids) - 1) . '?';
$sql = "SELECT id, lot_number, animal_count, protocol_weight, indexed_price, max_advance_percent FROM lots WHERE id IN ($in)";
$stmt = $pdo->prepare($sql);
$stmt->execute($ids);
$lots = $stmt->fetchAll();

echo "ID | Lot | Count | Weight | Price | Max% | TotalValue | MaxAdvance\n";
foreach ($lots as $l) {
    $totalValue = ($l['protocol_weight'] * $l['animal_count'] / 30) * $l['indexed_price'];
    $maxAdvance = $totalValue * ($l['max_advance_percent'] / 100);
    echo "{$l['id']} | {$l['lot_number']} | {$l['animal_count']} | {$l['protocol_weight']} | {$l['indexed_price']} | {$l['max_advance_percent']} | " . number_format($totalValue, 2) . " | " . number_format($maxAdvance, 2) . "\n";
}
