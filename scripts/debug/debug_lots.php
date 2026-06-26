<?php
require __DIR__ . '/../../src/config.php';
$sql = "SELECT 
    l.id, 
    l.lot_number, 
    (SELECT COUNT(*) FROM partnership_lots pl WHERE pl.lot_id = l.id) as total_partnerships,
    (SELECT COUNT(*) 
     FROM partnership_lots pl 
     JOIN partnerships p ON pl.partnership_id = p.id 
     WHERE pl.lot_id = l.id 
     AND NOT EXISTS (
         SELECT 1 FROM partnership_liquidations liq 
         WHERE liq.partnership_id = p.id AND liq.is_settlement = 1
     )
    ) as active_partnerships,
    (CASE 
        WHEN NOT EXISTS (SELECT 1 FROM partnership_lots pl WHERE pl.lot_id = l.id) THEN 'ativo (no partnership)'
        WHEN EXISTS (
            SELECT 1 FROM partnership_lots pl
            JOIN partnerships p ON pl.partnership_id = p.id
            WHERE pl.lot_id = l.id AND NOT EXISTS (
                SELECT 1 FROM partnership_liquidations liq 
                WHERE liq.partnership_id = p.id AND liq.is_settlement = 1
            )
        ) THEN 'ativo (active partnership)'
        ELSE 'inativo'
    END) as status_logic
FROM lots l";

$stmt = $pdo->query($sql);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "ID | Lot | Total P | Active P | Logic Status\n";
foreach ($results as $r) {
    echo "{$r['id']} | {$r['lot_number']} | {$r['total_partnerships']} | {$r['active_partnerships']} | {$r['status_logic']}\n";
}

echo "\n--- Liquidations with is_settlement ---\n";
$stmtLiq = $pdo->query("SELECT partnership_id, date, amount_total, is_settlement FROM partnership_liquidations WHERE is_settlement = 1");
print_r($stmtLiq->fetchAll(PDO::FETCH_ASSOC));
