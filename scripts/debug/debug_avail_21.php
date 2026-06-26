<?php
require __DIR__ . '/../../src/config.php';
$id = 21;
$partnership_id = 9;

// Manual check of what get_available_lots would see
$stmt = $pdo->prepare("SELECT * FROM lots WHERE id = ?");
$stmt->execute([$id]);
$lot = $stmt->fetch();

$weightArrobas = ($lot['protocol_weight'] * $lot['animal_count']) / 30;
$totalValue = $weightArrobas * $lot['indexed_price'];
$maxAdvance = $totalValue * ($lot['max_advance_percent'] / 100);

echo "Lot 21 MaxAdvance: " . $maxAdvance . "\n";

$sqlAlloc = "SELECT pl.projected_value, pl.monthly_rate, pl.slaughter_date, p.id as p_id, p.start_date 
             FROM partnership_lots pl 
             JOIN partnerships p ON pl.partnership_id = p.id
             WHERE pl.lot_id = ? AND pl.partnership_id != ?";
$stmtAlloc = $pdo->prepare($sqlAlloc);
$stmtAlloc->execute([$id, $partnership_id]);
$allocs = $stmtAlloc->fetchAll();

$used = 0;
echo "Other allocations for Lot 21 (excluding P9):\n";
foreach ($allocs as $a) {
    $start = new DateTime($a['start_date']);
    $end = new DateTime($a['slaughter_date']);
    $interval = $start->diff($end);
    $months = $interval->y * 12 + $interval->m + ($interval->d / 30);
    if ($months <= 0) $months = 0.0001;
    $principal = $a['projected_value'] / pow((1 + $a['monthly_rate'] / 100), $months);
    $used += $principal;
    echo " - P{$a['p_id']} | Principal: $principal\n";
}

echo "Total Used: " . $used . "\n";
echo "Available: " . ($maxAdvance - $used) . "\n";
