<?php
require_once __DIR__ . '/../../src/config.php';

// Fix existing settlement liquidations
$stmt = $pdo->query("SELECT * FROM partnership_liquidations WHERE is_settlement = 1");
$settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($settlements as $settlement) {
    $partnership_id = $settlement['partnership_id'];
    
    // Get partnership initial value
    $ptStmt = $pdo->prepare("SELECT total_value FROM partnerships WHERE id = ?");
    $ptStmt->execute([$partnership_id]);
    $partnership = $ptStmt->fetch();
    $total_value = floatval($partnership['total_value']);
    
    // Get principal paid BEFORE this settlement
    $stmtTotals = $pdo->prepare("SELECT SUM(amount_principal) as paid_principal FROM partnership_liquidations WHERE partnership_id = ? AND id != ? AND date <= ?");
    $stmtTotals->execute([$partnership_id, $settlement['id'], $settlement['date']]);
    $paidData = $stmtTotals->fetch();
    $paidPrincipalSoFar = floatval($paidData['paid_principal'] ?? 0);
    
    $remainingPrincipal = $total_value - $paidPrincipalSoFar;
    
    $amount_principal = $remainingPrincipal;
    $amount_interest = floatval($settlement['amount_total']) - $amount_principal;
    
    // Update
    $updateStmt = $pdo->prepare("UPDATE partnership_liquidations SET amount_principal = ?, amount_interest = ? WHERE id = ?");
    $updateStmt->execute([$amount_principal, $amount_interest, $settlement['id']]);
    
    echo "Fixed settlement ID " . $settlement['id'] . " for partnership " . $partnership_id . "\n";
}

echo "Done.\n";
?>
