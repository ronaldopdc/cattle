<?php
require_once __DIR__ . '/../../src/config.php';

$apply = in_array('--apply', $argv, true);
$fromName = 'Rialma';
$toName = 'Emival';
$type = 'confinamento';

foreach ($argv as $arg) {
    if (strpos($arg, '--from=') === 0) {
        $fromName = trim(substr($arg, 7));
    } elseif (strpos($arg, '--to=') === 0) {
        $toName = trim(substr($arg, 5));
    } elseif (strpos($arg, '--type=') === 0) {
        $type = trim(substr($arg, 7));
    }
}

function findPartnerByNameAndType(PDO $pdo, string $name, string $type): array
{
    $stmt = $pdo->prepare("
        SELECT p.id, p.name
        FROM partners p
        JOIN partner_type_assignments pta ON p.id = pta.partner_id
        WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(?))
          AND pta.type = ?
        ORDER BY p.id
    ");
    $stmt->execute([$name, $type]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function describePartners(array $partners): string
{
    return implode(', ', array_map(function ($partner) {
        return '#' . $partner['id'] . ' ' . $partner['name'];
    }, $partners));
}

try {
    $fromPartners = findPartnerByNameAndType($pdo, $fromName, $type);
    $toPartners = findPartnerByNameAndType($pdo, $toName, $type);

    if (count($fromPartners) !== 1) {
        $found = count($fromPartners) ? describePartners($fromPartners) : 'none';
        throw new RuntimeException("Expected exactly one '{$type}' partner named '{$fromName}', found: {$found}");
    }

    if (count($toPartners) !== 1) {
        $found = count($toPartners) ? describePartners($toPartners) : 'none';
        throw new RuntimeException("Expected exactly one '{$type}' partner named '{$toName}', found: {$found}");
    }

    $from = $fromPartners[0];
    $to = $toPartners[0];

    $stmt = $pdo->prepare("
        SELECT id, owner_id, investor_id, total_value, start_date
        FROM partnerships
        WHERE confinamento_id = ?
        ORDER BY id
    ");
    $stmt->execute([$from['id']]);
    $partnerships = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Source confinement: #{$from['id']} {$from['name']}\n";
    echo "Target confinement: #{$to['id']} {$to['name']}\n";
    echo 'Partnerships to update: ' . count($partnerships) . "\n";

    if ($partnerships) {
        echo 'IDs: ' . implode(', ', array_column($partnerships, 'id')) . "\n";
    }

    if (!$apply) {
        echo "Dry run only. Re-run with --apply to update partnerships.confinamento_id.\n";
        exit(0);
    }

    $pdo->beginTransaction();

    $update = $pdo->prepare("
        UPDATE partnerships
        SET confinamento_id = ?
        WHERE confinamento_id = ?
    ");
    $update->execute([$to['id'], $from['id']]);

    $affected = $update->rowCount();
    $pdo->commit();

    echo "Updated {$affected} partnership(s) from {$from['name']} to {$to['name']}.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
