<?php
// debug_partnership.php
require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/financial_calculations.php';

// List partnerships
echo "Fetching partnerships...\n";
$stmt = $pdo->query("SELECT * FROM partnerships");
$partnerships = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($partnerships as $p) {
    echo "\n------------------------------------------------\n";
    echo "Partnership ID: " . $p['id'] . "\n";
    echo "Start Date: " . $p['start_date'] . "\n";
    echo "Total Value: " . $p['total_value'] . "\n";

    // Get Lots
    $stmtLots = $pdo->prepare("SELECT * FROM partnership_lots WHERE partnership_id = ?");
    $stmtLots->execute([$p['id']]);
    $lots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);
    echo "Lots Count: " . count($lots) . "\n";
    foreach ($lots as $l) {
        echo "  - Lot {$l['lot_id']} Rate: {$l['monthly_rate']}% End: {$l['slaughter_date']}\n";
    }

    // Get Liquidations
    $stmtLiq = $pdo->prepare("SELECT * FROM partnership_liquidations WHERE partnership_id = ?");
    $stmtLiq->execute([$p['id']]);
    $liquidations = $stmtLiq->fetchAll(PDO::FETCH_ASSOC);
    echo "Liquidations Count: " . count($liquidations) . "\n";
    foreach ($liquidations as $l) {
        echo "  - {$l['date']}: {$l['amount_total']} (Prin: {$l['amount_principal']} | Int: {$l['amount_interest']})\n";
    }

    // Calculate
    $state = calculatePartnershipState($p, $lots, $liquidations);
    echo "Calculated Balance: " . $state['current_balance'] . "\n";

    // Check Events
    if (empty($state['events'])) {
        echo "Events: None\n";
    } else {
        foreach ($state['events'] as $e) {
            echo "  Event [{$e['type']}] ({$e['date']}): Bal Before: {$e['balance_before']} -> Int Accrued: {$e['interest_accrued']} -> Pay: " . ($e['payment_total'] ?? 0) . " -> Bal After: {$e['balance_after']}\n";
        }
    }
}
