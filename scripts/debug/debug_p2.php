<?php
require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/financial_calculations.php';

$id = 2; // Partnership 2

echo "Debugging Partnership $id\n";
echo "--------------------------------------------------\n";

// Fetch Partnership
$stmt = $pdo->prepare("SELECT * FROM partnerships WHERE id = ?");
$stmt->execute([$id]);
$partnership = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$partnership) {
    die("Partnership not found.\n");
}

echo "Start Date: " . $partnership['start_date'] . "\n";
echo "Initial Value: " . $partnership['total_value'] . "\n";

// Fetch Lots
$stmt = $pdo->prepare("SELECT * FROM partnership_lots WHERE partnership_id = ?");
$stmt->execute([$id]);
$lots = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nLots (" . count($lots) . "):\n";
foreach ($lots as $l) {
    echo " - Let: " . $l['lot_id'] . " | Rate: " . $l['monthly_rate'] . "% | Slaughter: " . $l['slaughter_date'] . " | Projected: " . $l['projected_value'] . "\n";
}

// Fetch Liquidations
$stmt = $pdo->prepare("SELECT * FROM partnership_liquidations WHERE partnership_id = ? ORDER BY date ASC");
$stmt->execute([$id]);
$liquidations = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\nLiquidations (" . count($liquidations) . "):\n";
foreach ($liquidations as $l) {
    echo " - Date: " . $l['date'] . " | Principal: " . $l['amount_principal'] . " | Interest: " . $l['amount_interest'] . " | Total: " . $l['amount_total'] . "\n";
}

echo "\nRunning Calculation...\n";
$state = calculatePartnershipState($partnership, $lots, $liquidations);

echo "\nCurrent Balance: " . $state['current_balance'] . "\n";
echo "\nEvents Trace:\n";
foreach ($state['events'] as $e) {
    echo " [" . $e['date'] . "] " . $e['type'] . "\n";
    if ($e['type'] == 'liquidation') {
        echo "   Rate Used: " . $e['rate'] . "%\n";
        echo "   Days: " . $e['days_since_last'] . " (Months: " . number_format($e['months'], 4) . ")\n";
        echo "   Bal Before: " . number_format($e['balance_before'], 2) . "\n";
        echo "   Interest:   " . number_format($e['interest_accrued'], 2) . "\n";
        echo "   Gross:      " . number_format($e['gross_balance'], 2) . "\n";
        echo "   Payment:    -" . number_format($e['payment_total'], 2) . "\n";
        echo "   Bal After:  " . number_format($e['balance_after'], 2) . "\n";
    } else {
        echo "   Rate Used: " . $e['rate'] . "%\n";
        echo "   Days: " . $e['days_since_last'] . "\n";
        echo "   Interest: " . number_format($e['interest_accrued'], 2) . "\n";
        echo "   Bal After: " . number_format($e['balance_after'], 2) . "\n";
    }
}
