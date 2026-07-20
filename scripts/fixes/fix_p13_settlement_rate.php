<?php
// Corrige a taxa mensal gravada na quitação da Parceria #13 (e de qualquer
// parceria informada), substituindo a taxa da fórmula fechada antiga pela IRR
// ponderada no tempo, calculada a partir das liquidações reais.
//
// Uso:
//   php scripts/fixes/fix_p13_settlement_rate.php            (simulação, id 13)
//   php scripts/fixes/fix_p13_settlement_rate.php 13 apply   (grava id 13)
require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/financial_calculations.php';

$partnershipId = isset($argv[1]) ? intval($argv[1]) : 13;
$apply = (isset($argv[2]) && $argv[2] === 'apply');

$stmt = $pdo->prepare("SELECT * FROM partnerships WHERE id = ?");
$stmt->execute([$partnershipId]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) { fwrite(STDERR, "Parceria #$partnershipId não encontrada\n"); exit(1); }

$stmtL = $pdo->prepare("SELECT date, amount_total FROM partnership_liquidations WHERE partnership_id = ? ORDER BY date ASC, id ASC");
$stmtL->execute([$partnershipId]);
$liqs = $stmtL->fetchAll(PDO::FETCH_ASSOC);
if (empty($liqs)) { fwrite(STDERR, "Sem liquidações para a parceria #$partnershipId\n"); exit(1); }

$totalPaid = 0;
foreach ($liqs as $l) { $totalPaid += floatval($l['amount_total']); }

$settleDate = end($liqs)['date'];
$monthsSettled = calculateMonthsBetween($p['start_date'], $settleDate);

$oldRate = ($monthsSettled > 0)
    ? (pow(($totalPaid / floatval($p['total_value'])), (1 / $monthsSettled)) - 1) * 100
    : null;
$newRate = calculateSettlementIRR(floatval($p['total_value']), $liqs, $p['start_date'], $settleDate);

echo "=== Parceria #$partnershipId ===\n";
echo "  principal (total_value): " . number_format($p['total_value'], 2) . "\n";
echo "  total pago:              " . number_format($totalPaid, 2) . "\n";
echo "  data quitação:           $settleDate  (" . number_format($monthsSettled, 4) . " meses)\n";
echo "  taxa ANTIGA (fórmula fechada): " . number_format($oldRate, 4) . "%  -> gravada como " . number_format($oldRate, 2) . "%\n";
echo "  taxa NOVA (IRR):               " . number_format($newRate, 4) . "%  -> gravada como " . number_format($newRate, 2) . "%\n";

$stmtLots = $pdo->prepare("SELECT lot_id, monthly_rate FROM partnership_lots WHERE partnership_id = ?");
$stmtLots->execute([$partnershipId]);
$lots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);
echo "\n  Lotes:\n";
foreach ($lots as $lot) {
    echo "    lot_id={$lot['lot_id']}: {$lot['monthly_rate']}% -> " . number_format($newRate, 2) . "%\n";
}

if (!$apply) {
    echo "\n[SIMULAÇÃO] Nada gravado. Rode com: php " . basename(__FILE__) . " $partnershipId apply\n";
    exit(0);
}

$upd = $pdo->prepare("UPDATE partnership_lots SET monthly_rate = ? WHERE partnership_id = ?");
$upd->execute([round($newRate, 2), $partnershipId]);
echo "\n[APLICADO] monthly_rate atualizado para " . number_format($newRate, 2) . "% em " . $upd->rowCount() . " lote(s).\n";
