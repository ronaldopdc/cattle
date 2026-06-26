<?php
require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/financial_calculations.php';

$partnership_id = 9;

// Fetch Partnership
$stmt = $pdo->prepare("SELECT * FROM partnerships WHERE id = ?");
$stmt->execute([$partnership_id]);
$p = $stmt->fetch();

if (!$p) {
    die("Partnership 9 not found.\n");
}

echo "Partnership ID: " . $p['id'] . "\n";
echo "Start Date: " . $p['start_date'] . "\n";
echo "Total Value (Initial Principal): " . $p['total_value'] . "\n";

// Fetch Lots
$stmtLots = $pdo->prepare("SELECT * FROM partnership_lots WHERE partnership_id = ?");
$stmtLots->execute([$partnership_id]);
$lots = $stmtLots->fetchAll();

echo "\n--- Lots ---\n";
$maxDate = null;
$sumLotProjections = 0;
foreach ($lots as $l) {
    echo "Lot ID: {$l['lot_id']} | Rate: {$l['monthly_rate']}% | Slaughter: {$l['slaughter_date']} | Projected: {$l['projected_value']}\n";
    $sumLotProjections += $l['projected_value'];
    $d = new DateTime($l['slaughter_date']);
    if (!$maxDate || $d > $maxDate) $maxDate = $d;
}
echo "Sum of Lot Projections: " . $sumLotProjections . "\n";
echo "Max Slaughter Date: " . ($maxDate ? $maxDate->format('Y-m-d') : 'N/A') . "\n";

// Fetch Liquidations
$stmtLiq = $pdo->prepare("SELECT * FROM partnership_liquidations WHERE partnership_id = ? ORDER BY date ASC");
$stmtLiq->execute([$partnership_id]);
$liquidations = $stmtLiq->fetchAll();

echo "\n--- Liquidations ---\n";
foreach ($liquidations as $liq) {
    echo "Date: {$liq['date']} | Total: {$liq['amount_total']} | Principal: {$liq['amount_principal']} | Interest: {$liq['amount_interest']} | Settlement: " . ($liq['is_settlement'] ? 'YES' : 'NO') . "\n";
}

// Perform Calculation
$state = calculatePartnershipState($p, $lots, $liquidations);
echo "\n--- Calculation Events ---\n";
foreach ($state['events'] as $e) {
    echo "Type: {$e['type']} | Date: {$e['date']} | Rate: {$e['rate']}% | Months: " . round($e['months'], 4) . " | Before: {$e['balance_before']} | Int: {$e['interest_accrued']} | Gross: {$e['gross_balance']} | Pay: " . ($e['payment_total'] ?? 0) . " | After: {$e['balance_after']}\n";
}

echo "\nCurrent Balance: " . $state['current_balance'] . "\n";

// Projected Balance
$furthestLotRate = 0;
if (!empty($lots)) {
    usort($lots, function ($a, $b) {
        return strtotime($a['slaughter_date']) - strtotime($b['slaughter_date']);
    });
    $furthestLotRate = floatval($lots[count($lots) - 1]['monthly_rate']);
}

$projectedBalance = calculateProjectedBalance($state['current_balance'], date('Y-m-d'), $furthestLotRate, $lots, $liquidations);
echo "Furthest Lot Rate used for projection: " . $furthestLotRate . "%\n";
echo "Final Projected Balance (Saldo Previsto): " . $projectedBalance . "\n";
