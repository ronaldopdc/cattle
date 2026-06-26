<?php
require_once __DIR__ . '/../../src/config.php';

// Fix existing settlement liquidations' balance_after
$stmt = $pdo->query("SELECT id, partnership_id FROM partnership_liquidations WHERE is_settlement = 1");
$settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($settlements as $settlement) {
    $updateStmt = $pdo->prepare("UPDATE partnership_liquidations SET balance_after = 0 WHERE id = ?");
    $updateStmt->execute([$settlement['id']]);
    echo "Fixed balance_after for settlement ID " . $settlement['id'] . "\n";
}

echo "Done.\n";
?>
