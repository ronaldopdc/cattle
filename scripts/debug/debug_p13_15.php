<?php
require_once __DIR__ . '/../../src/config.php';

// Calculate total allocated principal for each lot across all partnerships
$lotTotalPrincipalMap = [];
$allAllocations = $pdo->query("
    SELECT pl.lot_id, pl.projected_value, pl.monthly_rate, pl.slaughter_date, p.start_date
    FROM partnership_lots pl
    JOIN partnerships p ON pl.partnership_id = p.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($allAllocations as $alloc) {
    $start = new DateTime($alloc['start_date']);
    $end = new DateTime($alloc['slaughter_date']);
    $interval = $start->diff($end);
    $months = $interval->y * 12 + $interval->m + ($interval->d / 30);
    if ($months <= 0) $months = 0.0001;
    
    $rate = floatval($alloc['monthly_rate']);
    $projected = floatval($alloc['projected_value']);
    $principal = $projected / pow((1 + $rate / 100), $months);
    
    if (!isset($lotTotalPrincipalMap[$alloc['lot_id']])) {
        $lotTotalPrincipalMap[$alloc['lot_id']] = 0;
    }
    $lotTotalPrincipalMap[$alloc['lot_id']] += $principal;
}

$pids = [13, 15];

foreach ($pids as $id) {
    echo "--- Partnership #$id ---\n";
    $stmt = $pdo->prepare("SELECT * FROM partnerships WHERE id = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmtLots = $pdo->prepare("
        SELECT pl.*, l.lot_number, l.animal_count, l.protocol_weight, l.indexed_price, l.max_advance_percent 
        FROM partnership_lots pl
        JOIN lots l ON pl.lot_id = l.id
        WHERE pl.partnership_id = ?
    ");
    $stmtLots->execute([$id]);
    $lots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

    $totalAnimalsP = 0;
    foreach ($lots as $lot) {
        // Calculate Principal (Allocated Amount)
        $start = new DateTime($p['start_date']);
        $end = new DateTime($lot['slaughter_date']);
        $interval = $start->diff($end);
        $months = $interval->y * 12 + $interval->m + ($interval->d / 30);
        if ($months <= 0) $months = 0.0001;
        
        $rate = floatval($lot['monthly_rate']);
        $projected = floatval($lot['projected_value']);
        $allocated_amount = $projected / pow((1 + $rate / 100), $months);
        
        $totalLotPrincipal = $lotTotalPrincipalMap[$lot['lot_id']] ?? $allocated_amount;
        if ($totalLotPrincipal <= 0) $totalLotPrincipal = $allocated_amount;
        
        $fraction = $allocated_amount / $totalLotPrincipal;
        $animals = round(intval($lot['animal_count']) * $fraction);
        $totalAnimalsP += $animals;
        
        echo "  Lot " . $lot['lot_number'] . ": $animals animals (Share: " . ($fraction*100) . "%)\n";
    }
    echo "Total Partnership Animals: $totalAnimalsP\n\n";
}
