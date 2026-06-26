<?php
require __DIR__ . '/../../src/config.php';
$ids = [20, 21, 22];
$in  = str_repeat('?,', count($ids) - 1) . '?';

echo "Used Allocation Check for Lots 20, 21, 22:\n";
foreach ($ids as $id) {
    $sql = "SELECT pl.*, p.id as p_id, p.total_value as p_total_value, p.start_date 
            FROM partnership_lots pl 
            JOIN partnerships p ON pl.partnership_id = p.id 
            WHERE pl.lot_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $allocs = $stmt->fetchAll();
    
    echo "\nLot ID: $id\n";
    foreach ($allocs as $a) {
        $start = new DateTime($a['start_date']);
        $end = new DateTime($a['slaughter_date']);
        $interval = $start->diff($end);
        $months = $interval->y * 12 + $interval->m + ($interval->d / 30);
        if ($months <= 0) $months = 0.0001;
        $principal = $a['projected_value'] / pow((1 + $a['monthly_rate'] / 100), $months);
        
        echo " - Partnership {$a['p_id']} | Projected: {$a['projected_value']} | Calculated Principal: " . number_format($principal, 2) . "\n";
    }
}
