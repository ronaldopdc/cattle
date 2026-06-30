<?php
require_once 'auth.php';
require_login();
require_once 'config.php';

// Include attachment handlers
include_once 'attachment_handlers.php';
include_once 'liquidation_handlers.php';
require_once __DIR__ . '/financial_calculations.php';

// API endpoint for fetching available lots
if (isset($_GET['action']) && $_GET['action'] === 'get_available_lots') {
    $owner_id = $_GET['owner_id'] ?? null;
    $partnership_id = $_GET['partnership_id'] ?? null;

    if (!$owner_id) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    // Get lots for this owner
    $sql = "SELECT l.* FROM lots l WHERE l.partner_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$owner_id]);
    $lotsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate used allocation for each lot
    foreach ($lotsList as &$lot) {
        $sqlAlloc = "SELECT pl.projected_value, pl.monthly_rate, pl.slaughter_date, p.start_date 
                     FROM partnership_lots pl 
                     JOIN partnerships p ON pl.partnership_id = p.id
                     WHERE pl.lot_id = ? AND pl.partnership_id != COALESCE(?, 0)";
        $stmtAlloc = $pdo->prepare($sqlAlloc);
        $stmtAlloc->execute([$lot['id'], $partnership_id]);
        $allocations = $stmtAlloc->fetchAll(PDO::FETCH_ASSOC);

        $used_allocation = 0;
        $used_animals = 0;

        // Common lot values
        $weightArrobasTotal = ($lot['protocol_weight'] * $lot['animal_count']) / 30;
        $totalValueLot = $weightArrobasTotal * $lot['indexed_price'];
        $maxAdvanceLot = $totalValueLot * ($lot['max_advance_percent'] / 100);

        foreach ($allocations as $alloc) {
            // Official rule: months = total days / 30 (calculateMonthsBetween)
            $months = calculateMonthsBetween($alloc['start_date'], $alloc['slaughter_date']);
            if ($months <= 0)
                $months = 0.0001;

            $rate = floatval($alloc['monthly_rate']);
            $projected = floatval($alloc['projected_value']);

            // Compound Interest: Principal = Projected / (1 + Rate)^Months
            $principal = $projected / pow((1 + $rate / 100), $months);
            $used_allocation += $principal;

            // Calculate used animals for this allocation
            if ($maxAdvanceLot > 0) {
                $fraction = $principal / $maxAdvanceLot;
                $used_animals += round(intval($lot['animal_count']) * $fraction);
            }
        }

        $lot['used_allocation'] = $used_allocation;
        $lot['used_animals'] = $used_animals;

        // Calculate total available value and animals
        $lot['available_value'] = max(0, $maxAdvanceLot - $used_allocation);
        $lot['available_animals'] = max(0, intval($lot['animal_count']) - $used_animals);
    }
    unset($lot);

    header('Content-Type: application/json');
    echo json_encode(array_values($lotsList));
    exit;
}

$message = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        if (isset($_POST['action']) && $_POST['action'] === 'delete') {
            // Verify ownership
            $stmtOwner = $pdo->prepare("SELECT created_by, investor_id FROM partnerships WHERE id = ?");
            $stmtOwner->execute([$_POST['id']]);
            $pData = $stmtOwner->fetch();
            $owner = $pData['created_by'];
            $investorId = $pData['investor_id'];
            $isInvestor = ($_SESSION['partner_id'] && $_SESSION['partner_id'] == $investorId);

            if ($owner != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin' && !$isInvestor) {
                throw new Exception("Você não tem permissão para excluir esta parceria.");
            }

            // Delete Partnership
            $partnership_id = $_POST['id'];

            // First delete partnership_lots
            $stmtDeleteLots = $pdo->prepare("DELETE FROM partnership_lots WHERE partnership_id = ?");
            $stmtDeleteLots->execute([$partnership_id]);

            // Then delete partnership
            $stmtDelete = $pdo->prepare("DELETE FROM partnerships WHERE id = ?");
            $stmtDelete->execute([$partnership_id]);

            $message = "Parceria excluída com sucesso!";
        } else {
            if (!empty($_POST['id'])) {
                // Verify ownership
                $stmtOwner = $pdo->prepare("SELECT created_by, investor_id FROM partnerships WHERE id = ?");
                $stmtOwner->execute([$_POST['id']]);
                $pData = $stmtOwner->fetch();
                $owner = $pData['created_by'];
                $investorId = $pData['investor_id'];
                $isInvestor = ($_SESSION['partner_id'] && $_SESSION['partner_id'] == $investorId);

                if ($owner != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin' && !$isInvestor) {
                    throw new Exception("Você não tem permissão para editar esta parceria.");
                }

                // Update Partnership
                $sql = "UPDATE partnerships SET owner_id = ?, investor_id = ?, confinamento_id = ?, start_date = ?, total_value = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_POST['owner_id'],
                    $_POST['investor_id'],
                    !empty($_POST['confinamento_id']) ? $_POST['confinamento_id'] : null,
                    $_POST['start_date'],
                    $_POST['total_value'],
                    $_POST['id']
                ]);
                $partnership_id = $_POST['id'];

                // Delete existing lots to replace them
                $stmtDelete = $pdo->prepare("DELETE FROM partnership_lots WHERE partnership_id = ?");
                $stmtDelete->execute([$partnership_id]);
            } else {
                // Insert Partnership
                $sql = "INSERT INTO partnerships (owner_id, investor_id, confinamento_id, start_date, total_value, created_by) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_POST['owner_id'],
                    $_POST['investor_id'],
                    !empty($_POST['confinamento_id']) ? $_POST['confinamento_id'] : null,
                    $_POST['start_date'],
                    $_POST['total_value'],
                    $_SESSION['user_id']
                ]);
                $partnership_id = $pdo->lastInsertId();
            }

            // Insert Partnership Lots
            if (isset($_POST['lots']) && is_array($_POST['lots'])) {
                $sqlLot = "INSERT INTO partnership_lots (partnership_id, lot_id, monthly_rate, slaughter_date, projected_value) VALUES (?, ?, ?, ?, ?)";
                $stmtLot = $pdo->prepare($sqlLot);

                foreach ($_POST['lots'] as $lotData) {
                    // Calculate Projected Value
                    $allocated = floatval($lotData['allocated_amount']);
                    $rate = floatval($lotData['monthly_rate']);
                    // Official rule: months = total days / 30 (calculateMonthsBetween)
                    $months = calculateMonthsBetween($_POST['start_date'], $lotData['slaughter_date']);

                    if ($months <= 0)
                        $months = 0.0001;

                    // Compound Interest: Projected = Allocated * (1 + Rate)^Months
                    $projected = $allocated * pow((1 + $rate / 100), $months);

                    $stmtLot->execute([
                        $partnership_id,
                        $lotData['lot_id'],
                        $rate,
                        $lotData['slaughter_date'],
                        $projected
                    ]);
                }
            }
            $message = "Parceria salva com sucesso!";
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Erro ao salvar: " . $e->getMessage();
    }
}

// Fetch Data for Dropdowns
$owners = $pdo->query("SELECT p.* FROM partners p JOIN partner_type_assignments pta ON p.id = pta.partner_id WHERE pta.type = 'owner'")->fetchAll();
$investors = $pdo->query("SELECT p.* FROM partners p JOIN partner_type_assignments pta ON p.id = pta.partner_id WHERE pta.type = 'investor'")->fetchAll();
$confinements = $pdo->query("SELECT p.* FROM partners p JOIN partner_type_assignments pta ON p.id = pta.partner_id WHERE pta.type = 'confinamento'")->fetchAll();
$lots = $pdo->query("SELECT * FROM lots")->fetchAll();

// Determine User Access
$userRole = get_current_user_role();
$userPartnerId = get_current_user_partner_id();

$whereClause = "1=1";
$params = [];

if ($userRole === 'user') {
    if (!$userPartnerId) {
        $whereClause = "1=0";
    } else {
        $whereClause = "(p.owner_id = ? OR p.investor_id = ? OR p.confinamento_id = ?)";
        $params = [$userPartnerId, $userPartnerId, $userPartnerId];
    }
}

// Fetch Partnerships for List
$partnerships = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, 
               own.name as owner_name, own.cpf as owner_cpf,
               inv.name as investor_name, inv.cpf as investor_cpf,
               conf.name as confinement_name
        FROM partnerships p
        JOIN partners own ON p.owner_id = own.id
        JOIN partners inv ON p.investor_id = inv.id
        LEFT JOIN partners conf ON p.confinamento_id = conf.id
        WHERE $whereClause
        ORDER BY p.created_at DESC
    ");
    $stmt->execute($params);
    $partnerships = $stmt->fetchAll();
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Unknown column 'confinamento_id'") !== false) {
        $message = "Erro no banco de dados: A coluna 'confinamento_id' está faltando. Por favor, execute o script de migração.";
    } else {
        $message = "Erro ao buscar parcerias: " . $e->getMessage();
    }
}

// Fetch lots for each partnership to pass to JS
$partnershipLots = [];
$plResult = $pdo->query("
    SELECT pl.*, l.lot_number, l.animal_count, l.protocol_weight, l.indexed_price, l.max_advance_percent 
    FROM partnership_lots pl
    JOIN lots l ON pl.lot_id = l.id
");
while ($row = $plResult->fetch(PDO::FETCH_ASSOC)) {
    $partnershipLots[$row['partnership_id']][] = $row;
}

// Fetch Liquidations
$partnershipLiquidations = [];
$liqResult = $pdo->query("SELECT * FROM partnership_liquidations ORDER BY date ASC");
while ($row = $liqResult->fetch(PDO::FETCH_ASSOC)) {
    $partnershipLiquidations[$row['partnership_id']][] = $row;
}

// Calculate total allocated principal for each lot across all partnerships
$lotTotalPrincipalMap = [];
$allAllocations = $pdo->query("
    SELECT pl.lot_id, pl.projected_value, pl.monthly_rate, pl.slaughter_date, p.start_date
    FROM partnership_lots pl
    JOIN partnerships p ON pl.partnership_id = p.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($allAllocations as $alloc) {
    // Official rule: months = total days / 30 (calculateMonthsBetween)
    $months = calculateMonthsBetween($alloc['start_date'], $alloc['slaughter_date']);
    if ($months <= 0) $months = 0.0001;
    
    $rate = floatval($alloc['monthly_rate']);
    $projected = floatval($alloc['projected_value']);
    $principal = $projected / pow((1 + $rate / 100), $months);
    
    if (!isset($lotTotalPrincipalMap[$alloc['lot_id']])) {
        $lotTotalPrincipalMap[$alloc['lot_id']] = 0;
    }
    $lotTotalPrincipalMap[$alloc['lot_id']] += $principal;
}

// Store calculation memories
$partnershipMemories = [];

// Calculate Values
foreach ($partnerships as &$p) {
    $p['current_value_calc'] = 0;
    $p['projected_value_calc'] = 0;
    $p['current_balance'] = 0;
    $p['projected_balance'] = 0;

    $liquidations = $partnershipLiquidations[$p['id']] ?? [];
    $today = date('Y-m-d');
    $settlementDisplayDate = null;
    $latestLiquidationDate = null;
    $total_liquidated_principal = 0;
    $total_liquidated_amount = 0;

    foreach ($liquidations as $liq) {
        $total_liquidated_principal += floatval($liq['amount_principal']);
        $total_liquidated_amount += floatval($liq['amount_total']);
        if (!$latestLiquidationDate || $liq['date'] > $latestLiquidationDate) {
            $latestLiquidationDate = $liq['date'];
        }
        if (!empty($liq['is_settlement']) || floatval($liq['balance_after']) <= 0.05) {
            if (!$settlementDisplayDate || $liq['date'] > $settlementDisplayDate) {
                $settlementDisplayDate = $liq['date'];
            }
        }
    }

    $lots = $partnershipLots[$p['id']] ?? [];

    // --- NEW Calculation Logic using `financial_calculations.php` ---
    require_once __DIR__ . '/financial_calculations.php';

    // Calculate State (Rolling Balance)
    // $lots and $liquidations are already defined above (lines 197, 206)
    $displayDate = ($latestLiquidationDate && $latestLiquidationDate > $today) ? $latestLiquidationDate : $today;
    $calcState = calculatePartnershipState($p, $lots, $liquidations, $displayDate);

    // Update Partnership Values
    $p['current_balance'] = $calcState['current_balance'];

    // Calculate Total Liquidated (sum) for display
    $total_liquidated_amount_rolling = 0;
    foreach ($liquidations as $pl) {
        if ($pl['date'] <= $displayDate) {
            $total_liquidated_amount_rolling += floatval($pl['amount_total']);
        }
    }

    // Current Value (Gross) = Balance + Liquidations
    $p['current_value_calc'] = $p['current_balance'] + $total_liquidated_amount_rolling;

    // Projected Value (Contractual Goal)
    // The "Projected Value" column usually means "What it should be at the end".
    // But "Saldo Previsto" means "What we expect to have in hand at the end given current state".

    // We already have 'current_balance' from calculatePartnershipState.

    // Calculate Projected Balance using furthest lot rate
    // Get the rate from the lot with the furthest slaughter date
    $furthestLotRate = 0;
    if (!empty($lots)) {
        usort($lots, function ($a, $b) {
            return strtotime($a['slaughter_date']) - strtotime($b['slaughter_date']);
        });
        $furthestLotRate = floatval($lots[count($lots) - 1]['monthly_rate']);
    }

    // Calculate Projected Balance. Future liquidations already recorded must affect the projection.
    if (!empty($liquidations)) {
        $projectionEndDate = $today;
        foreach ($lots as $lot) {
            if ($lot['slaughter_date'] > $projectionEndDate) {
                $projectionEndDate = $lot['slaughter_date'];
            }
        }
        foreach ($liquidations as $liq) {
            if ($liq['date'] > $projectionEndDate) {
                $projectionEndDate = $liq['date'];
            }
        }
        $projectedState = calculatePartnershipState($p, $lots, $liquidations, $projectionEndDate);
        $projected_balance_val = $projectedState['current_balance'];
    } else {
        $projected_balance_val = calculateProjectedBalance($p['current_balance'], $today, $furthestLotRate, $lots, $liquidations);
    }

    $p['projected_balance'] = $projected_balance_val;

    // "Projected Value" (original target) - displayed as REF only?
    // User wants "Saldo Previsto" to be correct.
    // The previous logic for 'projected_value_calc' was sum of lot projections. This is the "Goal".

    $isInvestor = (isset($_SESSION['partner_id']) && $_SESSION['partner_id'] && $_SESSION['partner_id'] == $p['investor_id']);
    $hasEditPermission = ($p['created_by'] == $_SESSION['user_id'] || $_SESSION['role'] === 'admin' || $isInvestor);

    // Initialize memory array
    $memory = [
        'partnership_id' => $p['id'],
        'owner_name' => $p['owner_name'],
        'owner_cpf' => $p['owner_cpf'],
        'investor_name' => $p['investor_name'],
        'investor_cpf' => $p['investor_cpf'],
        'start_date' => $p['start_date'],
        'total_initial_principal' => $p['total_value'],
        'total_liquidated_amount' => $total_liquidated_amount_rolling,
        'lots' => [],
        'liquidations' => [],
        'initial_cattle_count' => 0,
        'has_edit_permission' => $hasEditPermission
    ];

    $active_projected_value = 0;
    $initial_cattle_count = 0;

    // First pass: build lot entries with allocated principal and allocated animals
    $memoLots = [];
    foreach ($lots as $lot) {
        $projected = floatval($lot['projected_value']);
        $active_projected_value += $projected;

        // Capture lot data for memory
        // Official rule: months = total days / 30 (calculateMonthsBetween)
        $monthsTotal = calculateMonthsBetween($p['start_date'], $lot['slaughter_date']);
        if ($monthsTotal <= 0) $monthsTotal = 0.0001;
        $rate = floatval($lot['monthly_rate']);
        $allocated_amount = $projected / pow((1 + $rate / 100), $monthsTotal);

        // Allocated animals for this lot
        $weightArrobas = (floatval($lot['protocol_weight']) * intval($lot['animal_count'])) / 30;
        $totalValue = $weightArrobas * floatval($lot['indexed_price']);
        $maxAdvance = $totalValue * (floatval($lot['max_advance_percent']) / 100);

        $allocated_animals = 0;
        if ($maxAdvance > 0) {
            $totalLotPrincipal = $lotTotalPrincipalMap[$lot['lot_id']] ?? $allocated_amount;
            if ($totalLotPrincipal <= 0) $totalLotPrincipal = $allocated_amount;

            $fraction = $totalLotPrincipal > 0 ? ($allocated_amount / $totalLotPrincipal) : 0;
            $allocated_animals = round(intval($lot['animal_count']) * $fraction);
            $initial_cattle_count += $allocated_animals;
        }

        $memoLots[] = [
            'lot_id' => $lot['lot_id'],
            'lot_number' => $lot['lot_number'],
            'animal_count' => $lot['animal_count'],
            'allocated_animals' => $allocated_animals,
            'slaughter_date' => $lot['slaughter_date'],
            'monthly_rate' => $lot['monthly_rate'],
            'rate' => $lot['monthly_rate'], // For modal compatibility
            'projected_value' => $projected,
            'allocated_amount' => $allocated_amount,
            'original_principal' => $allocated_amount,
            'current_value' => 0
        ];
    }

    // Second pass: compute per-lot balances (distributes unassigned liquidations proportionally).
    // Use the latest liquidation date so newly entered liquidations immediately
    // update cattle balances even when their date is ahead of the server date.
    $lotBalanceTargetDate = $today;
    foreach ($liquidations as $liq) {
        if ($liq['date'] > $lotBalanceTargetDate) {
            $lotBalanceTargetDate = $liq['date'];
        }
    }

    // Head (cattle) balances are computed independently of the lot's projected_value
    // so they stay correct even after a liquidation rewrites a lot's projected_value
    // and monthly_rate. This mirrors the memory modal: initial allocated head minus
    // slaughtered head (explicit informed quantity + principal-based estimate).
    $avgPrincipalPerAnimalList = $initial_cattle_count > 0
        ? floatval($p['total_value']) / $initial_cattle_count
        : 0;
    $lotHeadBalances = computeLotHeadBalances($memoLots, $liquidations, $lotBalanceTargetDate, $avgPrincipalPerAnimalList);

    // Coherence rule: a fully liquidated partnership (current balance ~ 0) cannot
    // have remaining head, so every lot's head balance is forced to zero.
    $partnershipFullyLiquidated = (floatval($p['current_balance']) < 0.01 && count($liquidations) > 0);

    // First, gather each lot's remaining head balance (after the coherence rule).
    $lotRemainingHead = [];
    $totalRemainingHead = 0;
    foreach ($memoLots as $ml) {
        $head = $lotHeadBalances[$ml['lot_id']] ?? ['balance_animals' => 0];
        $remHead = $partnershipFullyLiquidated ? 0 : intval($head['balance_animals']);
        $lotRemainingHead[$ml['lot_id']] = $remHead;
        $totalRemainingHead += $remHead;
    }

    // The per-lot VALUE balance is distributed from the partnership rolling balance
    // proportionally to each lot's remaining head. This keeps lots with remaining
    // head showing a value (instead of carry-over piling everything on one lot),
    // and the sum always equals the partnership current balance.
    $partnershipBalanceForLots = max(0, floatval($p['current_balance']));

    $available_cattle_count = 0;
    $valueDistributed = 0;
    $lastMemoIndex = count($memoLots) - 1;
    foreach ($memoLots as $idx => $ml) {
        $remHead = $lotRemainingHead[$ml['lot_id']];

        if ($totalRemainingHead > 0) {
            if ($idx === $lastMemoIndex) {
                // Last lot absorbs the rounding remainder so the sum matches exactly.
                $lotValue = max(0, $partnershipBalanceForLots - $valueDistributed);
            } else {
                $lotValue = $partnershipBalanceForLots * ($remHead / $totalRemainingHead);
                $valueDistributed += $lotValue;
            }
        } else {
            $lotValue = 0;
        }

        $ml['balance_value'] = $lotValue;
        $ml['balance_animals'] = intval($remHead);
        $available_cattle_count += $ml['balance_animals'];
        $memory['lots'][] = $ml;
    }

    $memory['initial_cattle_count'] = $initial_cattle_count;

    // Gross projected value = what has already been paid + expected remaining balance.
    // This keeps "Valor Previsto" comparable to "Valor Atual", which also includes
    // liquidations already paid plus the current rolling balance.
    $grossProjectedValue = $total_liquidated_amount_rolling + floatval($p['projected_balance']);
    $p['projected_value_calc'] = max($grossProjectedValue, floatval($p['current_value_calc']));
    
    // Adjust projected value for settled partnerships to match current value
    if ($p['current_balance'] < 0.01 && $p['projected_balance'] < 0.01) {
        $p['projected_value_calc'] = $p['current_value_calc'];
    }
    // $p['projected_balance'] = ... (already set above via calculateProjectedBalance)

    // --- Build Memory ---
    $memoryTargetDate = $today;
    foreach ($liquidations as $liq) {
        if ($liq['date'] > $memoryTargetDate) {
            $memoryTargetDate = $liq['date'];
        }
    }
    $memoryState = calculatePartnershipState($p, $lots, $liquidations, $memoryTargetDate);
    $memory['events'] = $memoryState['events'];
    $memory['current_balance'] = $p['current_balance'];

    // Helper function to get rate based on furthest slaughter date
    $getRateForDate = function ($dateStr) use ($lots) {
        $date = new DateTime($dateStr);
        $sortedLots = $lots;
        usort($sortedLots, function ($a, $b) {
            return strtotime($a['slaughter_date']) - strtotime($b['slaughter_date']);
        });

        // Find first lot that ends on or after the date
        foreach ($sortedLots as $l) {
            if (new DateTime($l['slaughter_date']) >= $date) {
                return floatval($l['monthly_rate']);
            }
        }

        // Fallback to last lot (furthest slaughter date)
        if (count($sortedLots) > 0) {
            return floatval($sortedLots[count($sortedLots) - 1]['monthly_rate']);
        }
        return 0;
    };

    // Build a lookup of lot_id -> lot info for this partnership (used to label liquidations by lot)
    $lotInfoMap = [];
    foreach ($lots as $lot) {
        $lotInfoMap[$lot['lot_id']] = [
            'lot_number' => $lot['lot_number'],
            'animal_count' => $lot['animal_count']
        ];
    }

    // Populate liquidations with correct rates and balance_after
    // Build a consumable list of liquidation events so that multiple liquidations
    // on the SAME date are matched 1:1 (in order) instead of all receiving the
    // balance_after of the first event for that date.
    $liquidationEvents = [];
    foreach ($memoryState['events'] as $event) {
        if ($event['type'] === 'liquidation') {
            $liquidationEvents[] = $event;
        }
    }
    $usedEventKeys = [];
    foreach ($liquidations as $liq) {
        $liqRate = $getRateForDate($liq['date']);

        // Find the balance_after from events for this liquidation.
        // Match by date + payment total, consuming each event only once so that
        // same-day liquidations map to their own event in chronological order.
        $balanceAfter = 0;
        $payment = floatval($liq['amount_total']);
        $matchedFallback = null;
        foreach ($liquidationEvents as $idx => $event) {
            if (isset($usedEventKeys[$idx])) {
                continue;
            }
            if ($event['date'] !== $liq['date']) {
                continue;
            }
            // Remember first available same-date event as a fallback
            if ($matchedFallback === null) {
                $matchedFallback = $idx;
            }
            // Prefer an exact payment match
            if (abs(floatval($event['payment_total']) - $payment) < 0.01) {
                $balanceAfter = $event['balance_after'];
                $usedEventKeys[$idx] = true;
                $matchedFallback = null; // consumed via exact match
                break;
            }
        }
        // If no exact payment match was found, consume the next same-date event
        if ($matchedFallback !== null) {
            $balanceAfter = $liquidationEvents[$matchedFallback]['balance_after'];
            $usedEventKeys[$matchedFallback] = true;
        }

        // Resolve lot number from lot_id (when liquidation is tied to a specific lot)
        $lotNumber = null;
        if (!empty($liq['lot_id']) && isset($lotInfoMap[$liq['lot_id']])) {
            $lotNumber = $lotInfoMap[$liq['lot_id']]['lot_number'];
        }

        $memory['liquidations'][] = [
            'id' => $liq['id'],
            'date' => $liq['date'],
            'amount_total' => $liq['amount_total'],
            'amount_principal' => $liq['amount_principal'],
            'amount_interest' => $liq['amount_interest'],
            'created_at' => $liq['created_at'] ?? null,
            'rate' => $liqRate,
            'balance_after' => $balanceAfter,
            'is_settlement' => $liq['is_settlement'],
            'quantity' => $liq['quantity'],
            'lot_id' => $liq['lot_id'] ?? null,
            'lot_number' => $lotNumber
        ];
    }

    // Calculate weighted average rate for display
    // If liquidations exist: weighted by liquidation principals
    // If no liquidations: use current rate (furthest lot)
    $total_liquidated_quantity = 0;
    $average_principal_per_animal = $initial_cattle_count > 0 ? floatval($p['total_value']) / $initial_cattle_count : 0;

    if (count($liquidations) > 0) {
        $totalWeightedRate = 0;
        $totalPrincipal = 0;
        $cumulative_estimated_principal = 0;
        $cumulative_estimated_cattle = 0;

        // Iterate by reference so we can store the estimated cattle count (head)
        // per liquidation. Cumulative tracker runs in chronological order over
        // ALL liquidations; the frontend then sums only those within the chosen
        // calculation date. Estimated values are flagged so the UI can mark them.
        foreach ($memory['liquidations'] as &$liqRef) {
            $q = intval($liqRef['quantity'] ?? 0);
            if ($q > 0) {
                // Explicit head count provided
                $liqRef['estimated_quantity'] = $q;
                $liqRef['quantity_is_estimated'] = false;
            } else if ($average_principal_per_animal > 0 && floatval($liqRef['amount_principal']) > 0) {
                // Liquidation by value without explicit quantity (Cumulative Tracker)
                $cumulative_estimated_principal += floatval($liqRef['amount_principal']);
                $new_cumulative_cattle = round($cumulative_estimated_principal / $average_principal_per_animal);
                $estimated_q = $new_cumulative_cattle - $cumulative_estimated_cattle;
                $cumulative_estimated_cattle = $new_cumulative_cattle;
                $liqRef['estimated_quantity'] = $estimated_q;
                $liqRef['quantity_is_estimated'] = true;
            } else {
                $liqRef['estimated_quantity'] = 0;
                $liqRef['quantity_is_estimated'] = false;
            }

            // Totals (weighted rate and slaughtered head) consider only
            // liquidations effective up to today for the backend summary.
            if ($liqRef['date'] <= $today) {
                $totalWeightedRate += floatval($liqRef['rate']) * floatval($liqRef['amount_principal']);
                $totalPrincipal += floatval($liqRef['amount_principal']);
                $total_liquidated_quantity += intval($liqRef['estimated_quantity']);
            }
        }
        unset($liqRef);
        $memory['weighted_rate'] = $totalPrincipal > 0 ? $totalWeightedRate / $totalPrincipal : $getRateForDate(date('Y-m-d'));
    } else {
        // No liquidations: use the rate from the furthest lot
        $memory['weighted_rate'] = $getRateForDate(date('Y-m-d'));
    }

    if ($p['current_balance'] < 0.01 && count($liquidations) > 0) {
        $totalPaidToDate = 0;
        $settlementDate = null;
        foreach ($liquidations as $liq) {
            if ($liq['date'] <= $today) {
                $totalPaidToDate += floatval($liq['amount_total']);
                if (!empty($liq['is_settlement']) || floatval($liq['balance_after']) <= 0.05) {
                    $settlementDate = $liq['date'];
                }
            }
        }

        $initialPrincipal = floatval($p['total_value']);
        if ($settlementDate && $initialPrincipal > 0 && $totalPaidToDate > 0) {
            $monthsSettled = calculateMonthsBetween($p['start_date'], $settlementDate);
            if ($monthsSettled > 0) {
                $memory['weighted_rate'] = (pow(($totalPaidToDate / $initialPrincipal), (1 / $monthsSettled)) - 1) * 100;
            }
        }
    }

    $p['available_animals'] = max(0, $available_cattle_count);
    $memory['available_animals'] = $p['available_animals'];
    $partnershipMemories[$p['id']] = $memory;
}
unset($p);
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcerias - Cattle Invest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- jQuery + Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        .lot-row {
            background: rgba(255, 255, 255, 0.03);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        /* Searchable Select Styles */
        .searchable-select {
            position: relative;
            width: 100%;
        }

        .select-trigger {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 46px;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }

        .select-trigger:hover {
            border-color: var(--primary-color);
        }

        .select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            margin-top: 0.5rem;
            z-index: 1000;
            display: none;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: transparent;
            border: none;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            outline: none;
        }

        .options-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .option-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .option-item:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .option-item.selected {
            color: var(--primary-color);
            background: rgba(56, 189, 248, 0.05);
        }

        /* ── Select2 Dark Theme Override ── */
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            align-items: flex-end;
        }
        .filter-bar .filter-group {
            flex: 1;
            min-width: 200px;
        }
        .filter-bar .filter-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-size: 0.8rem;
            color: #94a3b8;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Select2 container */
        .select2-container--default .select2-selection--single {
            background: rgba(15, 23, 42, 0.6) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 8px !important;
            height: 42px !important;
            display: flex !important;
            align-items: center !important;
            padding: 0 0.75rem !important;
            transition: border-color 0.3s ease;
        }
        .select2-container--default .select2-selection--single:hover {
            border-color: var(--primary-color) !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #e2e8f0 !important;
            line-height: 42px !important;
            padding-left: 0 !important;
            font-size: 0.9rem;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 42px !important;
            right: 8px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-color: #64748b transparent transparent transparent !important;
        }
        .select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b {
            border-color: transparent transparent #64748b transparent !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: #64748b !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__clear {
            color: #94a3b8 !important;
            font-size: 1.2rem;
            margin-right: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__clear:hover {
            color: #f87171 !important;
        }

        /* Dropdown */
        .select2-dropdown {
            background: #1e293b !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 8px !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4) !important;
            overflow: hidden;
        }
        .select2-container--default .select2-search--dropdown .select2-search__field {
            background: rgba(15, 23, 42, 0.8) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 6px !important;
            color: #e2e8f0 !important;
            padding: 0.5rem 0.75rem !important;
            outline: none !important;
        }
        .select2-container--default .select2-search--dropdown .select2-search__field:focus {
            border-color: var(--primary-color) !important;
        }
        .select2-container--default .select2-results__option {
            color: #cbd5e1 !important;
            padding: 0.6rem 0.75rem !important;
            font-size: 0.9rem;
            transition: background 0.15s ease;
        }
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background: rgba(56, 189, 248, 0.15) !important;
            color: #38bdf8 !important;
        }
        .select2-container--default .select2-results__option[aria-selected=true] {
            background: rgba(56, 189, 248, 0.08) !important;
            color: #38bdf8 !important;
        }
        .select2-results__options {
            max-height: 250px !important;
        }
        .select2-results__options::-webkit-scrollbar {
            width: 6px;
        }
        .select2-results__options::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.03);
        }
        .select2-results__options::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
        }

        .filter-bar .btn-clear-filters {
            background: rgba(248, 113, 113, 0.1);
            border: 1px solid rgba(248, 113, 113, 0.2);
            color: #f87171;
            padding: 0.55rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            height: 42px;
            white-space: nowrap;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .filter-bar .btn-clear-filters:hover {
            background: rgba(248, 113, 113, 0.2);
            border-color: rgba(248, 113, 113, 0.4);
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <div class="container">

        <?php if ($message): ?>
            <div class="card" style="border-left: 4px solid var(--primary-color); padding: 1rem;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="grid">
            <div id="formContainer" style="display: none; margin-bottom: 2rem;">
                <!-- Registration Form -->
                <div class="card">
                    <h2 id="formTitle">Nova Parceria</h2>
                    <form method="POST" action="" id="partnershipForm">
                        <input type="hidden" name="id" id="partnership_id">
                        <div class="form-group">
                            <label>Proprietário</label>
                            <div class="searchable-select" id="ownerSearch">
                                <div class="select-trigger" onclick="toggleSearchDropdown('ownerSearch')">
                                    <span class="trigger-text">Selecione...</span>
                                    <i class="fas fa-chevron-down" style="color: #64748b; font-size: 0.8rem;"></i>
                                </div>
                                <div class="select-dropdown">
                                    <input type="text" class="search-input" placeholder="Pesquisar..."
                                        oninput="filterSearchOptions(this)">
                                    <div class="options-list">
                                        <?php foreach ($owners as $p): ?>
                                            <div class="option-item" data-value="<?= $p['id'] ?>"
                                                onclick="selectSearchOption('ownerSearch', '<?= $p['id'] ?>', '<?= addslashes($p['name']) ?>', true)">
                                                <?= htmlspecialchars($p['name']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <input type="hidden" name="owner_id" id="owner_id" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Investidor</label>
                            <div class="searchable-select" id="investorSearch">
                                <div class="select-trigger" onclick="toggleSearchDropdown('investorSearch')">
                                    <span class="trigger-text">Selecione...</span>
                                    <i class="fas fa-chevron-down" style="color: #64748b; font-size: 0.8rem;"></i>
                                </div>
                                <div class="select-dropdown">
                                    <input type="text" class="search-input" placeholder="Pesquisar..."
                                        oninput="filterSearchOptions(this)">
                                    <div class="options-list">
                                        <?php foreach ($investors as $p): ?>
                                            <div class="option-item" data-value="<?= $p['id'] ?>"
                                                onclick="selectSearchOption('investorSearch', '<?= $p['id'] ?>', '<?= addslashes($p['name']) ?>')">
                                                <?= htmlspecialchars($p['name']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <input type="hidden" name="investor_id" id="investor_id" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Confinamento</label>
                            <div class="searchable-select" id="confinamentoSearch">
                                <div class="select-trigger" onclick="toggleSearchDropdown('confinamentoSearch')">
                                    <span class="trigger-text">Selecione...</span>
                                    <i class="fas fa-chevron-down" style="color: #64748b; font-size: 0.8rem;"></i>
                                </div>
                                <div class="select-dropdown">
                                    <input type="text" class="search-input" placeholder="Pesquisar..."
                                        oninput="filterSearchOptions(this)">
                                    <div class="options-list">
                                        <?php foreach ($confinements as $conf): ?>
                                            <div class="option-item" data-value="<?= $conf['id'] ?>"
                                                onclick="selectSearchOption('confinamentoSearch', '<?= $conf['id'] ?>', '<?= addslashes($conf['name']) ?>')">
                                                <?= htmlspecialchars($conf['name']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <input type="hidden" name="confinamento_id" id="confinamento_id" required>
                            </div>
                        </div>

                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Data Início</label>
                                <input type="date" name="start_date" id="start_date" required>
                            </div>
                            <div class="form-group">
                                <label>Valor Total (R$)</label>
                                <input type="number" step="0.01" name="total_value" id="total_value" required>
                            </div>
                        </div>

                        <h3>Lotes da Parceria</h3>
                        <div id="lots-container">
                            <!-- Lots will be added here -->
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="addLot()" style="width: 100%; margin-bottom: 1.5rem;">
                            <i class="fas fa-plus"></i> Adicionar Lote
                        </button>

                        <div id="allocation-summary" style="background: rgba(255, 255, 255, 0.05); padding: 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid rgba(255, 255, 255, 0.1);">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem;">
                                <span style="color: #94a3b8; font-size: 0.9rem;">Soma dos Valores Alocados:</span>
                                <span id="sum-allocated" style="font-weight: 600; color: #38bdf8;">R$ 0,00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #94a3b8; font-size: 0.9rem;">Diferença (Valor Total - Alocado):</span>
                                <span id="diff-allocated" style="font-weight: 600; color: #fbbf24;">R$ 0,00</span>
                            </div>
                        </div>

                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <button type="button" class="btn btn-secondary" id="cancelBtn" onclick="hideForm()" style="display: none;">Cancelar</button>
                            <button type="submit" class="btn btn-primary" id="submitBtn">Cadastrar Parceria</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h2 style="margin: 0;" id="pageTitle">Parcerias Ativas</h2>
                    <div style="display: flex; gap: 1rem; align-items: center;">
                        <button class="btn btn-secondary" onclick="openSimulationModal()" style="background-color: #38bdf8; border-color: #38bdf8; color: white;">
                            <i class="fas fa-calculator"></i> Simulação
                        </button>
                        <button class="btn btn-secondary" onclick="generatePartnershipReport()">
                            <i class="fas fa-file-pdf"></i> Relatório
                        </button>
                        <button class="btn btn-primary" onclick="showForm()">+ Nova Parceria</button>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <div class="filter-group">
                        <label><i class="fas fa-user-tie"></i> Proprietário</label>
                        <select id="filterOwner" class="filter-select" style="width: 100%;">
                            <option value="">Todos</option>
                            <?php
                            $uniqueOwners = [];
                            foreach ($partnerships as $fp) {
                                if (!isset($uniqueOwners[$fp['owner_id']])) {
                                    $uniqueOwners[$fp['owner_id']] = trim($fp['owner_name']);
                                }
                            }
                            asort($uniqueOwners);
                            foreach ($uniqueOwners as $oid => $oname): ?>
                                <option value="<?= htmlspecialchars($oname) ?>"><?= htmlspecialchars($oname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-hand-holding-usd"></i> Investidor</label>
                        <select id="filterInvestor" class="filter-select" style="width: 100%;">
                            <option value="">Todos</option>
                            <?php
                            $uniqueInvestors = [];
                            foreach ($partnerships as $fp) {
                                if (!isset($uniqueInvestors[$fp['investor_id']])) {
                                    $uniqueInvestors[$fp['investor_id']] = trim($fp['investor_name']);
                                }
                            }
                            asort($uniqueInvestors);
                            foreach ($uniqueInvestors as $iid => $iname): ?>
                                <option value="<?= htmlspecialchars($iname) ?>"><?= htmlspecialchars($iname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-warehouse"></i> Confinamento</label>
                        <select id="filterConfinement" class="filter-select" style="width: 100%;">
                            <option value="">Todos</option>
                            <?php
                            $uniqueConfinements = [];
                            foreach ($partnerships as $fp) {
                                $cname = trim($fp['confinement_name'] ?? '-');
                                $ckey = $fp['confinamento_id'] ?? 'none';
                                if (!isset($uniqueConfinements[$ckey])) {
                                    $uniqueConfinements[$ckey] = $cname;
                                }
                            }
                            asort($uniqueConfinements);
                            foreach ($uniqueConfinements as $cid => $cname): ?>
                                <option value="<?= htmlspecialchars($cname) ?>"><?= htmlspecialchars($cname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-box"></i> Número do Lote</label>
                        <select id="filterLot" class="filter-select" style="width: 100%;">
                            <option value="">Todos</option>
                            <?php
                            $uniqueLotNumbers = [];
                            foreach ($partnerships as $fp) {
                                foreach (($partnershipLots[$fp['id']] ?? []) as $flot) {
                                    $lotNumber = trim((string) $flot['lot_number']);
                                    if ($lotNumber !== '') {
                                        $uniqueLotNumbers[$lotNumber] = $lotNumber;
                                    }
                                }
                            }
                            natsort($uniqueLotNumbers);
                            foreach ($uniqueLotNumbers as $lotNumber): ?>
                                <option value="<?= htmlspecialchars($lotNumber) ?>"><?= htmlspecialchars($lotNumber) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-toggle-on"></i> Status</label>
                        <select id="filterStatus" class="filter-select" style="width: 100%;">
                            <option value="active" selected>Ativas</option>
                            <option value="closed">Encerradas</option>
                            <option value="all">Todas</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-handshake"></i> Tipo</label>
                        <select id="filterType" class="filter-select" style="width: 100%;">
                            <option value="all" selected>Todos</option>
                            <option value="parceria">Parceria</option>
                            <option value="propria">Própria</option>
                        </select>
                    </div>
                    <button class="btn-clear-filters" onclick="clearAllFilters()" title="Limpar filtros">
                        <i class="fas fa-times-circle"></i> Limpar
                    </button>
                </div>

                <!-- List -->
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th></th> <!-- Expand Toggle -->
                                <th>ID</th>
                                <th>Proprietário</th>
                                <th>Investidor</th>
                                <th>Confinamento</th>
                                <th>Data</th>
                                <th>Lotes</th>
                                <th>Valor Inicial</th>
                                <th>Valor Atual</th>
                                <th>Valor Previsto</th>
                                <th>Saldo Atual</th>
                                <th>Saldo Previsto</th>
                                <th>Animais</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($partnerships as $p):
                                $rowLots = $partnershipLots[$p['id']] ?? [];
                                $rowLotNumbers = array_map('strval', array_column($rowLots, 'lot_number'));
                                // Determine status
                                $isClosed = ($p['current_balance'] < 0.01 && $p['projected_balance'] < 0.01);
                                $status = $isClosed ? 'closed' : 'active';
                                $displayStyle = $isClosed ? 'display: none;' : '';
                                ?>
                                  <tr class="partnership-row" data-id="<?= $p['id'] ?>" data-status="<?= $status ?>"
                                     data-owner="<?= htmlspecialchars(trim($p['owner_name'])) ?>"
                                     data-investor="<?= htmlspecialchars(trim($p['investor_name'])) ?>"
                                      data-type="<?= ($p['owner_id'] == $p['investor_id']) ? 'propria' : 'parceria' ?>"
                                      data-confinement="<?= htmlspecialchars(trim($p['confinement_name'] ?? '-')) ?>"
                                      data-lots="<?= htmlspecialchars(json_encode($rowLotNumbers), ENT_QUOTES, 'UTF-8') ?>"
                                      data-val-inicial="<?= $p['total_value'] ?>"
                                     data-val-atual="<?= $p['current_value_calc'] ?>"
                                     data-val-previsto="<?= $p['projected_value_calc'] ?>"
                                     data-saldo-atual="<?= $p['current_balance'] ?>"
                                     data-saldo-previsto="<?= $p['projected_balance'] ?>"
                                     data-animais="<?= $p['available_animals'] ?>"
                                     style="<?= $displayStyle ?>">
                                     <td data-label="Expandir">
                                         <button class="btn-icon btn-toggle" onclick="toggleLots(<?= $p['id'] ?>)"
                                             style="background: none; border: none; color: #94a3b8; cursor: pointer;">
                                             <i class="fas fa-chevron-right"></i>
                                         </button>
                                     </td>
                                     <td data-label="ID">#<?= $p['id'] ?></td>
                                     <td data-label="Proprietário"><?= htmlspecialchars(trim($p['owner_name'])) ?></td>
                                     <td data-label="Investidor"><?= htmlspecialchars(trim($p['investor_name'])) ?></td>
                                     <td data-label="Confinamento"><?= htmlspecialchars(trim($p['confinement_name'] ?? '-')) ?>
                                     </td>
                                    <td data-label="Data"><?= date('d/m/Y', strtotime($p['start_date'])) ?></td>
                                     <td data-label="Lotes">
                                         <?php
                                         $lotNumbers = $rowLotNumbers;
                                         if (count($lotNumbers) > 0) {
                                             if (count($lotNumbers) === 1) {
                                                 echo htmlspecialchars($lotNumbers[0]);
                                            } else {
                                                $last = array_pop($lotNumbers);
                                                echo htmlspecialchars(implode(', ', $lotNumbers) . ' e ' . $last);
                                            }
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td data-label="Valor Inicial">R$ <?= number_format($p['total_value'], 2, ',', '.') ?>
                                    </td>
                                    <td data-label="Valor Atual" style="color: #60a5fa">R$
                                        <?= number_format($p['current_value_calc'], 2, ',', '.') ?>
                                    </td>
                                    <td data-label="Valor Previsto" style="color: #10b981">R$
                                        <?= number_format($p['projected_value_calc'], 2, ',', '.') ?>
                                    </td>
                                    <td data-label="Saldo Atual" style="color: #818cf8; font-weight: 600;">R$
                                        <?= number_format($p['current_balance'], 2, ',', '.') ?>
                                    </td>
                                    <td data-label="Saldo Previsto" style="color: #34d399; font-weight: 600;">R$
                                        <?= number_format($p['projected_balance'], 2, ',', '.') ?>
                                    </td>
                                    <td data-label="Animais" style="color: #fbbf24; font-weight: 600;">
                                        <?= $p['available_animals'] ?>
                                    </td>
                                    <td data-label="Ações">
                                        <?php 
                                        $isInvestor = ($_SESSION['partner_id'] && $_SESSION['partner_id'] == $p['investor_id']);
                                        if ($p['created_by'] == $_SESSION['user_id'] || $_SESSION['role'] === 'admin' || $isInvestor): ?>
                                        <button class="btn btn-icon btn-edit"
                                            onclick='editPartnership(<?= json_encode($p) ?>, <?= json_encode($partnershipLots[$p['id']] ?? []) ?>)'
                                            title="Editar">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-icon" onclick="openAttachmentsModal(<?= $p['id'] ?>)"
                                            title="Anexos"
                                            style="background: rgba(139, 92, 246, 0.1); border-color: rgba(139, 92, 246, 0.2); color: #a78bfa;">
                                            <i class="fas fa-paperclip"></i>
                                        </button>
                                        <button class="btn btn-icon" onclick="openMemoryModal(<?= $p['id'] ?>)"
                                            title="Memória de Cálculo"
                                            style="background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.2); color: #60a5fa;">
                                            <i class="fas fa-calculator"></i>
                                        </button>
                                        <?php if ($p['created_by'] == $_SESSION['user_id'] || $_SESSION['role'] === 'admin' || $isInvestor): ?>
                                        <button class="btn btn-icon" onclick="openLiquidationModal(<?= $p['id'] ?>)"
                                            title="Liquidar"
                                            style="background: rgba(251, 191, 36, 0.1); border-color: rgba(251, 191, 36, 0.2); color: #fbbf24;">
                                            <i class="fas fa-dollar-sign"></i>
                                        </button>
                                        <button class="btn btn-icon btn-delete" onclick="deletePartnership(<?= $p['id'] ?>)"
                                            title="Excluir">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <!-- Lots Row (Hidden by default) -->
                                <tr id="lots-row-<?= $p['id'] ?>"
                                    style="display: none; background: rgba(0, 0, 0, 0.2);">
                                    <td colspan="14" style="padding: 0;">
                                        <div style="padding: 1rem 2rem;">
                                            <h4 style="margin-top: 0; color: #60a5fa; margin-bottom: 0.5rem;">Lotes da Parceria</h4>
                                            <?php
                                            $pLots = $partnershipLots[$p['id']] ?? [];
                                            if (empty($pLots)): ?>
                                                <p style="color: #94a3b8; font-size: 0.9rem;">Nenhum lote registrado.</p>
                                            <?php else: ?>
                                                <?php
                                                    // Reuse the per-lot balances already computed for the memory/liquidation modal,
                                                    // keyed by lot_id, to keep the tree consistent with the modal.
                                                    $treeMemoryLots = [];
                                                    if (isset($partnershipMemories[$p['id']]['lots'])) {
                                                        foreach ($partnershipMemories[$p['id']]['lots'] as $mlot) {
                                                            $treeMemoryLots[$mlot['lot_id']] = $mlot;
                                                        }
                                                    }
                                                ?>
                                                <table style="width: 100%; font-size: 0.9rem;">
                                                    <thead>
                                                        <tr style="background: rgba(255, 255, 255, 0.05);">
                                                            <th>Lote</th>
                                                            <th>Animais Alocados</th>
                                                            <th>Valor Alocado</th>
                                                            <th>Taxa Mensal</th>
                                                            <th>Data Abate/Liquidação</th>
                                                            <th>Valor Projetado</th>
                                                            <th>Saldo (Valor)</th>
                                                            <th>Saldo (Bois)</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($pLots as $lot): 
                                                            // Official rule: months = total days / 30 (calculateMonthsBetween)
                                                            $monthsTotal = calculateMonthsBetween($p['start_date'], $lot['slaughter_date']);
                                                            if ($monthsTotal <= 0) $monthsTotal = 0.0001;
                                                            $rate = floatval($lot['monthly_rate']);
                                                            $projected = floatval($lot['projected_value']);
                                                            $allocated_amount = $projected / pow((1 + $rate / 100), $monthsTotal);

                                                            // Pull allocated animals + balances from the precomputed memory lots
                                                            $mlot = $treeMemoryLots[$lot['lot_id']] ?? null;
                                                            $allocated_animals = $mlot ? intval($mlot['allocated_animals']) : 0;
                                                            $lot_balance_value = $mlot ? floatval($mlot['balance_value']) : 0;
                                                            $lot_balance_animals = $mlot ? intval($mlot['balance_animals']) : 0;
                                                        ?>
                                                            <tr>
                                                                <td data-label="Lote">Lote <?= htmlspecialchars($lot['lot_number']) ?></td>
                                                                <td data-label="Animais Alocados"><?= $allocated_animals ?></td>
                                                                <td data-label="Valor Alocado">R$ <?= number_format($allocated_amount, 2, ',', '.') ?></td>
                                                                <td data-label="Taxa Mensal"><?= number_format($lot['monthly_rate'], 2, ',', '.') ?>%</td>
                                                                <td data-label="Data Abate/Liquidação"><?= date('d/m/Y', strtotime($lot['slaughter_date'])) ?></td>
                                                                <td data-label="Valor Projetado">R$ <?= number_format($projected, 2, ',', '.') ?></td>
                                                                <td data-label="Saldo (Valor)" style="color: #818cf8; font-weight: 600;">
                                                                    <?php if ($lot_balance_value < 0.01): ?>
                                                                        <span style="color: #10b981;">R$ 0,00 (Liquidado)</span>
                                                                    <?php else: ?>
                                                                        R$ <?= number_format($lot_balance_value, 2, ',', '.') ?>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td data-label="Saldo (Bois)" style="color: #fbbf24; font-weight: 600;">
                                                                    <?php if ($allocated_animals > 0 && $lot_balance_animals <= 0): ?>
                                                                        <span style="color: #10b981;">0 (Liquidado)</span>
                                                                    <?php else: ?>
                                                                        <?= intval($lot_balance_animals) ?>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr id="totalsRow" style="background: rgba(56, 189, 248, 0.06); border-top: 2px solid rgba(56, 189, 248, 0.2);">
                                <td></td>
                                <td></td>
                                <td colspan="4" style="font-weight: 700; color: #e2e8f0; font-size: 0.95rem;">
                                    <i class="fas fa-sigma" style="margin-right: 0.4rem; color: #38bdf8;"></i> TOTAIS
                                    <span id="totalsCount" style="font-weight: 400; color: #94a3b8; font-size: 0.8rem; margin-left: 0.5rem;"></span>
                                </td>
                                <td></td>
                                <td style="font-weight: 700; color: #e2e8f0;" id="totalValInicial">R$ 0,00</td>
                                <td style="font-weight: 700; color: #60a5fa;" id="totalValAtual">R$ 0,00</td>
                                <td style="font-weight: 700; color: #10b981;" id="totalValPrevisto">R$ 0,00</td>
                                <td style="font-weight: 700; color: #818cf8;" id="totalSaldoAtual">R$ 0,00</td>
                                <td style="font-weight: 700; color: #34d399;" id="totalSaldoPrevisto">R$ 0,00</td>
                                <td style="font-weight: 700; color: #fbbf24;" id="totalAnimais">0</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        let lotIndex = 0;
        let lotsData = <?= json_encode($lots) ?>;
        let currentPartnershipId = null;
        let partnershipMemories = <?= json_encode($partnershipMemories) ?>;

        function showForm() {
            document.getElementById('formContainer').style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function hideForm() {
            document.getElementById('formContainer').style.display = 'none';
            resetForm();
        }

        // Map to store lot details by ID for easy access
        let lotsMap = {};

        function initLotsMap() {
            lotsMap = {};
            lotsData.forEach(lot => {
                lotsMap[lot.id] = lot;
            });
        }
        initLotsMap();

        async function updateAvailableLots() {
            const ownerId = document.getElementById('owner_id').value;
            if (!ownerId) {
                lotsData = [];
                initLotsMap();
                document.getElementById('lots-container').innerHTML = '';
                return;
            }

            try {
                const response = await fetch(`partnerships.php?action=get_available_lots&owner_id=${ownerId}&partnership_id=${currentPartnershipId || ''}`);
                lotsData = await response.json();
                initLotsMap();

                // Clear and re-add lots
                document.getElementById('lots-container').innerHTML = '';
                addLot();
            } catch (error) {
                console.error('Error fetching lots:', error);
            }
        }

        function onLotSelect(select) {
            const row = select.closest('.lot-row');
            const lotId = select.value;
            if (lotId && lotsMap[lotId]) {
                const lot = lotsMap[lotId];
                const dateInput = row.querySelector('input[name*="[slaughter_date]"]');
                if (dateInput && lot.exit_forecast_date) {
                    dateInput.value = lot.exit_forecast_date;
                }
            }
            recalculateAllocations();
        }

        function addLot(data = null) {
            const container = document.getElementById('lots-container');
            const div = document.createElement('div');
            div.className = 'lot-row';
            div.dataset.index = lotIndex;

            let options = '<option value="">Selecione o Lote...</option>';
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            lotsData.forEach(lot => {
                const selected = data && data.lot_id == lot.id ? 'selected' : '';
                
                // Exclude lots that have already passed their slaughter/liquidation date
                if (!selected && lot.exit_forecast_date) {
                    const [year, month, day] = lot.exit_forecast_date.split('-');
                    const forecast = new Date(year, month - 1, day);
                    if (forecast < today) {
                        return; // do not include in dropdown
                    }
                }

                const availableVal = lot.available_value ? parseFloat(lot.available_value) : 0;
                const avail = availableVal.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                const availAnimals = lot.available_animals ? parseInt(lot.available_animals) : 0;
                options += `<option value="${lot.id}" ${selected}>Lote ${lot.lot_number} - ${lot.breed} (Disp: ${avail} - Qtd: ${availAnimals})</option>`;
            });

            div.innerHTML = `
                <div class="form-group">
                    <label>Lote</label>
                    <select name="lots[${lotIndex}][lot_id]" class="lot-select" required onchange="onLotSelect(this)">
                        ${options}
                    </select>
                </div>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label>Valor Alocado (R$)</label>
                        <input type="number" step="0.01" name="lots[${lotIndex}][allocated_amount]" class="allocated-input" required placeholder="Calculado automaticamente" value="${data && data.allocated_amount ? parseFloat(data.allocated_amount).toFixed(2) : ''}">
                    </div>
                    <div class="form-group">
                        <label>Taxa Mensal (%)</label>
                        <input type="number" step="0.01" name="lots[${lotIndex}][monthly_rate]" required value="${data ? data.monthly_rate : ''}">
                    </div>
                </div>
                <div class="form-group">
                    <label>Data Abate/Liquidação</label>
                    <input type="date" name="lots[${lotIndex}][slaughter_date]" required value="${data ? data.slaughter_date : ''}">
                </div>
                <button type="button" class="btn btn-secondary" onclick="removeLot(this)" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.3);">Remover</button>
            `;

            container.appendChild(div);
            
            // Add listener to new allocated input
            div.querySelector('.allocated-input').addEventListener('input', updateAllocationSummary);
            
            lotIndex++;

            if (!data) {
                recalculateAllocations();
            } else {
                updateAllocationSummary();
            }
        }

        function removeLot(btn) {
            btn.parentElement.remove();
            recalculateAllocations();
        }

        document.getElementById('total_value').addEventListener('input', () => {
            recalculateAllocations();
            updateAllocationSummary();
        });

        function updateAllocationSummary() {
            const totalValue = parseFloat(document.getElementById('total_value').value) || 0;
            const allocatedInputs = document.querySelectorAll('.allocated-input');
            let sumAllocated = 0;

            allocatedInputs.forEach(input => {
                sumAllocated += parseFloat(input.value) || 0;
            });

            const diff = totalValue - sumAllocated;

            document.getElementById('sum-allocated').innerText = sumAllocated.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            document.getElementById('diff-allocated').innerText = diff.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            
            // Color coding for difference
            const diffEl = document.getElementById('diff-allocated');
            if (Math.abs(diff) < 0.01) {
                diffEl.style.color = '#10b981'; // Green
            } else if (diff > 0) {
                diffEl.style.color = '#fbbf24'; // Yellow (Warning: Unallocated)
            } else {
                diffEl.style.color = '#ef4444'; // Red (Overallocated)
            }
        }

        function recalculateAllocations() {
            const totalPartnershipValue = parseFloat(document.getElementById('total_value').value) || 0;
            const lotSelects = document.querySelectorAll('.lot-select');
            const allocatedInputs = document.querySelectorAll('.allocated-input');

            let selectedLots = [];
            let totalAvailableLimit = 0;

            // Gather selected lots and their limits
            lotSelects.forEach((select, index) => {
                const lotId = select.value;
                if (lotId && lotsMap[lotId]) {
                    const lot = lotsMap[lotId];
                    const limit = parseFloat(lot.available_value);
                    selectedLots.push({
                        index: index,
                        limit: limit,
                        input: allocatedInputs[index]
                    });
                    totalAvailableLimit += limit;
                } else {
                    // If no lot selected, clear input
                    allocatedInputs[index].value = '';
                }
            });

            if (selectedLots.length === 0) return;

            // Distribute
            let factor = 1;
            if (totalPartnershipValue < totalAvailableLimit) {
                factor = totalPartnershipValue / totalAvailableLimit;
            } else {
                // If partnership value is huge, we just cap at limits
                factor = 1;
            }

            selectedLots.forEach(item => {
                let allocated = item.limit * factor;
                // Round to 2 decimals
                allocated = Math.round(allocated * 100) / 100;
                item.input.value = allocated.toFixed(2);
            });

            updateAllocationSummary();
        }

        function distributeDifference() {
            const totalValue = parseFloat(document.getElementById('total_value').value) || 0;
            const allocatedInputs = document.querySelectorAll('.allocated-input');
            let currentSum = 0;
            let inputs = [];

            allocatedInputs.forEach(input => {
                const val = parseFloat(input.value) || 0;
                currentSum += val;
                inputs.push({ el: input, val: val });
            });

            const diff = totalValue - currentSum;
            if (Math.abs(diff) < 0.01) return;

            if (currentSum === 0) {
                // Distribute equally if everything is zero
                const perLot = totalValue / inputs.length;
                inputs.forEach(item => {
                    item.el.value = perLot.toFixed(2);
                });
            } else {
                // Distribute proportionally
                inputs.forEach(item => {
                    const share = item.val / currentSum;
                    const newVal = item.val + (diff * share);
                    item.el.value = newVal.toFixed(2);
                });
            }
            updateAllocationSummary();
        }

        function calculateMonthsJS(startStr, endStr) {
            const start = new Date(startStr);
            const end = new Date(endStr);

            // Calculate difference in milliseconds
            const diffTime = end - start;
            // Convert to days (ceil to standard)
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

            // Total months = Total Days / 30
            let totalMonths = diffDays / 30;

            if (totalMonths <= 0) totalMonths = 0.0001;

            return totalMonths;
        }

        async function editPartnership(partnership, lots) {
            showForm();
            document.getElementById('formTitle').innerText = 'Editar Parceria #' + partnership.id;
            document.getElementById('partnership_id').value = partnership.id;
            currentPartnershipId = partnership.id;

            // Set searchable dropdowns
            selectSearchOption('ownerSearch', partnership.owner_id, partnership.owner_name, false);
            selectSearchOption('investorSearch', partnership.investor_id, partnership.investor_name, false);
            selectSearchOption('confinamentoSearch', partnership.confinamento_id || '', partnership.confinement_name || 'Selecione...', false);

            document.getElementById('start_date').value = partnership.start_date;
            document.getElementById('total_value').value = partnership.total_value;

            document.getElementById('submitBtn').innerText = 'Gravar Alterações';
            document.getElementById('cancelBtn').style.display = 'inline-block';
            document.getElementById('cancelBtn').onclick = hideForm;

            // Fetch available lots for this owner (including current partnership lots)
            await updateAvailableLots();

            // Clear existing lots
            document.getElementById('lots-container').innerHTML = '';

            // Add lots
            if (lots && lots.length > 0) {
                lots.forEach(lot => {
                    // Reverse calculate allocated amount from projected value
                    if (!lot.allocated_amount && lot.projected_value) {
                        const months = calculateMonthsJS(partnership.start_date, lot.slaughter_date);
                        const rate = parseFloat(lot.monthly_rate);
                        const projected = parseFloat(lot.projected_value);
                        const allocated = projected / Math.pow((1 + rate / 100), months);
                        lot.allocated_amount = allocated;
                    }
                    addLot(lot);
                });
            } else {
                addLot();
            }
            updateAllocationSummary();
        }

        function resetForm() {
            document.getElementById('formTitle').innerText = 'Nova Parceria';
            document.getElementById('partnershipForm').reset();
            document.getElementById('partnership_id').value = '';

            // Reset searchable dropdowns
            document.querySelectorAll('.searchable-select').forEach(container => {
                container.querySelector('.trigger-text').innerText = 'Selecione...';
                container.querySelector('input[type="hidden"]').value = '';
            });

            currentPartnershipId = null;
            lotsData = [];
            document.getElementById('submitBtn').innerText = 'Cadastrar Parceria';
            document.getElementById('cancelBtn').style.display = 'none';
            document.getElementById('lots-container').innerHTML = '';
        }

        function toggleSearchDropdown(id) {
            const container = document.getElementById(id);
            const dropdown = container.querySelector('.select-dropdown');
            const isOpen = dropdown.style.display === 'block';

            // Close all other dropdowns
            document.querySelectorAll('.select-dropdown').forEach(d => {
                if (d !== dropdown) d.style.display = 'none';
            });

            if (!isOpen) {
                dropdown.style.display = 'block';
                const input = container.querySelector('.search-input');
                input.value = '';
                filterSearchOptions(input);
                input.focus();
            } else {
                dropdown.style.display = 'none';
            }
        }

        function filterSearchOptions(input) {
            const query = input.value.toLowerCase();
            const container = input.closest('.searchable-select');
            const options = container.querySelectorAll('.option-item');

            options.forEach(opt => {
                const text = opt.innerText.toLowerCase();
                opt.style.display = text.includes(query) ? 'flex' : 'none';
            });
        }

        function selectSearchOption(containerId, value, text, shouldUpdateLots = false) {
            const container = document.getElementById(containerId);
            container.querySelector('.trigger-text').innerText = text;
            container.querySelector('input[type="hidden"]').value = value;
            container.querySelector('.select-dropdown').style.display = 'none';

            if (shouldUpdateLots) {
                updateAvailableLots();
            }
        }

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.searchable-select')) {
                document.querySelectorAll('.select-dropdown').forEach(d => d.style.display = 'none');
            }
        });

        function deletePartnership(id) {
            if (confirm('Tem certeza que deseja excluir esta parceria? Esta ação não pode ser desfeita.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'id';
                inputId.value = id;

                const inputAction = document.createElement('input');
                inputAction.type = 'hidden';
                inputAction.name = 'action';
                inputAction.value = 'delete';

                form.appendChild(inputId);
                form.appendChild(inputAction);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function toggleLots(id) {
            const row = document.getElementById('lots-row-' + id);
            const icon = document.querySelector(`tr[data-id="${id}"] .btn-toggle i`);

            if (row.style.display === 'none') {
                row.style.display = 'table-row';
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-down');
            } else {
                row.style.display = 'none';
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-right');
            }
        }

        // ── Select2 Initialization & Filtering ──
        const partnershipFilterStorageKey = 'cattle_partnership_filters';

        function getPartnershipFilters() {
            return {
                owner: $('#filterOwner').val() || '',
                investor: $('#filterInvestor').val() || '',
                confinement: $('#filterConfinement').val() || '',
                lot: $('#filterLot').val() || '',
                status: $('#filterStatus').val() || 'active',
                type: $('#filterType').val() || 'all'
            };
        }

        function savePartnershipFilters() {
            try {
                sessionStorage.setItem(partnershipFilterStorageKey, JSON.stringify(getPartnershipFilters()));
            } catch (e) {
                // Keep filtering functional even if browser storage is blocked.
            }
        }

        function restorePartnershipFilters() {
            try {
                const saved = sessionStorage.getItem(partnershipFilterStorageKey);
                if (!saved) return;

                const filters = JSON.parse(saved);
                $('#filterOwner').val(filters.owner || '').trigger('change.select2');
                $('#filterInvestor').val(filters.investor || '').trigger('change.select2');
                $('#filterConfinement').val(filters.confinement || '').trigger('change.select2');
                $('#filterLot').val(filters.lot || '').trigger('change.select2');
                $('#filterStatus').val(filters.status || 'active').trigger('change.select2');
                $('#filterType').val(filters.type || 'all').trigger('change.select2');
            } catch (e) {
                try {
                    sessionStorage.removeItem(partnershipFilterStorageKey);
                } catch (ignored) {}
            }
        }

        $(document).ready(function () {
            $('.filter-select').select2({
                placeholder: 'Todos',
                allowClear: true,
                minimumResultsForSearch: 5,
                language: {
                    noResults: function () { return 'Nenhum resultado'; },
                    searching: function () { return 'Buscando...'; }
                }
            });

            restorePartnershipFilters();

            // Bind change event
            $('.filter-select').on('change', function () {
                savePartnershipFilters();
                applyFilters();
            });

            applyFilters();
        });

        function applyFilters() {
            const ownerFilter = ($('#filterOwner').val() || '').trim();
            const investorFilter = ($('#filterInvestor').val() || '').trim();
            const confinementFilter = ($('#filterConfinement').val() || '').trim();
            const lotFilter = ($('#filterLot').val() || '').trim();
            const statusFilter = $('#filterStatus').val();
            const typeFilter = $('#filterType').val();

            // Update title
            const title = document.getElementById('pageTitle');
            let titleText = 'Parcerias';
            if (statusFilter === 'active') titleText = 'Parcerias Ativas';
            else if (statusFilter === 'closed') titleText = 'Parcerias Encerradas';
            else titleText = 'Todas as Parcerias';

            if (typeFilter === 'propria') titleText += ' (Próprias)';
            else if (typeFilter === 'parceria') titleText += ' (Parcerias)';
            
            title.innerText = titleText;

            document.querySelectorAll('.partnership-row').forEach(row => {
                const status = row.getAttribute('data-status');
                const type = row.getAttribute('data-type');
                const owner = (row.getAttribute('data-owner') || '').trim();
                const investor = (row.getAttribute('data-investor') || '').trim();
                const confinement = (row.getAttribute('data-confinement') || '').trim();
                let rowLots = [];
                try {
                    rowLots = JSON.parse(row.getAttribute('data-lots') || '[]');
                } catch (e) {
                    rowLots = [];
                }

                let visible = true;

                // Status filter
                if (statusFilter === 'active' && status !== 'active') visible = false;
                if (statusFilter === 'closed' && status !== 'closed') visible = false;

                // Type filter
                if (typeFilter !== 'all' && type !== typeFilter) visible = false;

                // Dropdown filters
                if (ownerFilter && owner !== ownerFilter) visible = false;
                if (investorFilter && investor !== investorFilter) visible = false;
                if (confinementFilter && confinement !== confinementFilter) visible = false;
                if (lotFilter && !rowLots.includes(lotFilter)) visible = false;

                row.style.display = visible ? '' : 'none';

                // Also hide/show the lots sub-row
                const lotsRow = document.getElementById('lots-row-' + row.getAttribute('data-id'));
                if (lotsRow && !visible) {
                    lotsRow.style.display = 'none';
                }
            });

            recalculateTotals();
        }

        function clearAllFilters() {
            $('#filterOwner').val('').trigger('change');
            $('#filterInvestor').val('').trigger('change');
            $('#filterConfinement').val('').trigger('change');
            $('#filterLot').val('').trigger('change');
            $('#filterStatus').val('active').trigger('change');
            $('#filterType').val('all').trigger('change');
        }

        // toggleClosedPartnerships removed as it was replaced by filterStatus dropdown

        // ── Dynamic Totals Calculation ──
        function formatBRL(value) {
            return 'R$ ' + value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recalculateTotals() {
            let sumInicial = 0, sumAtual = 0, sumPrevisto = 0, sumSaldoAtual = 0, sumSaldoPrevisto = 0, sumAnimais = 0;
            let visibleCount = 0;

            document.querySelectorAll('.partnership-row').forEach(row => {
                if (row.style.display === 'none') return;

                visibleCount++;
                sumInicial     += parseFloat(row.dataset.valInicial    || 0);
                sumAtual       += parseFloat(row.dataset.valAtual      || 0);
                sumPrevisto    += parseFloat(row.dataset.valPrevisto   || 0);
                sumSaldoAtual  += parseFloat(row.dataset.saldoAtual    || 0);
                sumSaldoPrevisto += parseFloat(row.dataset.saldoPrevisto || 0);
                sumAnimais     += parseInt(row.dataset.animais       || 0);
            });

            document.getElementById('totalValInicial').textContent    = formatBRL(sumInicial);
            document.getElementById('totalValAtual').textContent      = formatBRL(sumAtual);
            document.getElementById('totalValPrevisto').textContent   = formatBRL(sumPrevisto);
            document.getElementById('totalSaldoAtual').textContent    = formatBRL(sumSaldoAtual);
            document.getElementById('totalSaldoPrevisto').textContent = formatBRL(sumSaldoPrevisto);
            document.getElementById('totalAnimais').textContent       = sumAnimais;
            document.getElementById('totalsCount').textContent        = `(${visibleCount} parceria${visibleCount !== 1 ? 's' : ''})`;
        }

        // Calculate totals on page load
        document.addEventListener('DOMContentLoaded', recalculateTotals);

        function generatePartnershipReport() {
            const titleText = document.getElementById('pageTitle').innerText;
            const logoUrl = new URL('assets/logo.png', window.location.href).href;
            const timestamp = new Date().toLocaleString('pt-BR');
            
            // Get totals
            const totalValInicial = document.getElementById('totalValInicial').innerText;
            const totalValAtual = document.getElementById('totalValAtual').innerText;
            const totalValPrevisto = document.getElementById('totalValPrevisto').innerText;
            const totalSaldoAtual = document.getElementById('totalSaldoAtual').innerText;
            const totalSaldoPrevisto = document.getElementById('totalSaldoPrevisto').innerText;
            const totalsCount = document.getElementById('totalsCount').innerText;

            const escapeReportHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char]);

            const reportFormatLotDisplay = (lotNumber) => {
                if (typeof formatLotDisplay === 'function') {
                    return formatLotDisplay(lotNumber);
                }
                if (!lotNumber) return '-';
                return String(lotNumber).split(',').map(lot => lot.trim()).filter(Boolean).map(lot => `#${lot}`).join(', ');
            };

            const reportFormatCurrency = (value) => parseFloat(value || 0).toLocaleString('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            });

            const buildReportLiquidationsHtml = (memory) => {
                if (!memory || !memory.liquidations || memory.liquidations.length === 0) return '';

                const liquidations = typeof getSummarizedLiquidations === 'function'
                    ? getSummarizedLiquidations(memory.liquidations)
                    : memory.liquidations;
                if (!liquidations || liquidations.length === 0) return '';

                let liquidationRows = '';
                let lastLiqDateStr = memory.start_date;

                liquidations.forEach(liq => {
                    const dateParts = String(liq.date || '').split('-');
                    const liqDate = new Date(Date.UTC(dateParts[0], dateParts[1] - 1, dateParts[2]));
                    const d = String(liqDate.getUTCDate()).padStart(2, '0');
                    const m = String(liqDate.getUTCMonth() + 1).padStart(2, '0');
                    const y = liqDate.getUTCFullYear();

                    const lastParts = String(lastLiqDateStr || memory.start_date).split('-');
                    const lastDate = new Date(Date.UTC(lastParts[0], lastParts[1] - 1, lastParts[2]));
                    const diffDays = Math.max(0, Math.ceil((liqDate - lastDate) / (1000 * 60 * 60 * 24)));
                    const displayElapsed = `${(diffDays / 30).toFixed(1)}m (${diffDays}d)`;

                    let displayQuantity = '-';
                    if (liq.quantity) {
                        displayQuantity = liq.quantity_display || liq.quantity;
                    } else if (parseInt(liq.estimated_quantity || 0) > 0) {
                        displayQuantity = liq.quantity_display || `~${parseInt(liq.estimated_quantity || 0)}`;
                    }

                    liquidationRows += `
                        <tr>
                            <td>${d}/${m}/${y}</td>
                            <td style="text-align: center;">${escapeReportHtml(displayElapsed)}</td>
                            <td style="text-align: center; color: #92400e; font-weight: 600;">${escapeReportHtml(reportFormatLotDisplay(liq.lot_number))}</td>
                            <td style="text-align: center;">${escapeReportHtml(displayQuantity)}</td>
                            <td class="number" style="text-align: right;">${parseFloat(liq.rate || 0).toFixed(2)}%</td>
                            <td class="number" style="text-align: right;">${reportFormatCurrency(liq.amount_principal)}</td>
                            <td class="number" style="text-align: right;">${reportFormatCurrency(liq.amount_interest)}</td>
                            <td class="number" style="text-align: right; font-weight: 600;">${reportFormatCurrency(liq.amount_total)}</td>
                            <td class="number" style="text-align: right; font-weight: 600;">${reportFormatCurrency(liq.balance_after || 0)}</td>
                        </tr>
                    `;

                    lastLiqDateStr = liq.date;
                });

                return `
                    <tr class="liquidations-row">
                        <td colspan="11">
                            <div class="liquidations-box">
                                <div class="liquidations-title">Histórico de Liquidações</div>
                                <table class="liquidations-table">
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th style="text-align: center;">Tempo</th>
                                            <th style="text-align: center;">Lote</th>
                                            <th style="text-align: center;">Cab.</th>
                                            <th style="text-align: right;">Taxa</th>
                                            <th style="text-align: right;">Principal</th>
                                            <th style="text-align: right;">Valor da Engorda</th>
                                            <th style="text-align: right;">Total</th>
                                            <th style="text-align: right;">Saldo Após</th>
                                        </tr>
                                    </thead>
                                    <tbody>${liquidationRows}</tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                `;
            };

            // Get visible rows data
            let rowsHtml = '';
            document.querySelectorAll('.partnership-row').forEach(row => {
                if (row.style.display === 'none') return;
                
                const cells = row.querySelectorAll('td');
                const memory = partnershipMemories[row.dataset.id];
                // Columns: ID (1), Proprietário (2), Investidor (3), Confinamento (4), Data (5), Lotes (6), ValInicial (7), ValAtual (8), ValPrevisto (9), SaldoAtual (10), SaldoPrevisto (11)
                rowsHtml += `
                    <tr>
                        <td>${cells[1].innerText}</td>
                        <td>${cells[2].innerText}</td>
                        <td>${cells[3].innerText}</td>
                        <td>${cells[4].innerText}</td>
                        <td>${cells[5].innerText}</td>
                        <td>${cells[6].innerText}</td>
                        <td align="right" class="number">${cells[7].innerText}</td>
                        <td align="right" class="number">${cells[8].innerText}</td>
                        <td align="right" class="number">${cells[9].innerText}</td>
                        <td align="right" class="number" style="font-weight: 600;">${cells[10].innerText}</td>
                        <td align="right" class="number" style="font-weight: 600;">${cells[11].innerText}</td>
                    </tr>
                    ${buildReportLiquidationsHtml(memory)}
                `;
            });

            const reportHtml = `
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="UTF-8">
                <title>Relatório de Parcerias</title>
                <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
                <style>
                    :root { --primary: #0f172a; --secondary: #64748b; --border: #e2e8f0; --bg-light: #f8fafc; }
                    body { font-family: 'Inter', sans-serif; color: var(--primary); line-height: 1.4; margin: 0; padding: 30px; background: white; font-size: 11px; }
                    .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--primary); padding-bottom: 15px; margin-bottom: 20px; }
                    .logo { height: 50px; }
                    .title-block h1 { margin: 0; font-size: 18px; text-transform: uppercase; text-align: right; }
                    .title-block p { margin: 2px 0 0; color: var(--secondary); text-align: right; font-size: 11px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
                    th, td { text-align: left; padding: 6px 4px; border-bottom: 1px solid var(--border); overflow: hidden; text-overflow: ellipsis; }
                    th { background: var(--bg-light); font-weight: 700; color: var(--secondary); text-transform: uppercase; font-size: 9px; }
                    .number { font-family: 'Courier New', monospace; font-size: 10px; }
                    .liquidations-row > td { padding: 0 4px 10px 34px; background: #fff7ed; border-bottom: 1px solid #fed7aa; overflow: visible; }
                    .liquidations-box { border-left: 3px solid #f59e0b; padding: 8px 0 8px 10px; }
                    .liquidations-title { color: #92400e; font-weight: 700; font-size: 10px; text-transform: uppercase; margin-bottom: 4px; }
                    .liquidations-table { margin-top: 4px; table-layout: auto; background: white; }
                    .liquidations-table th { background: #fffbeb; color: #92400e; font-size: 8px; }
                    .liquidations-table td { font-size: 9px; padding: 4px; }
                    tfoot { display: table-row-group; }
                    tfoot tr { background: var(--bg-light); font-weight: 700; }
                    .footer { margin-top: 30px; text-align: center; font-size: 10px; color: var(--secondary); border-top: 1px solid var(--border); padding-top: 15px; }
                    .btn-print { display: inline-flex; align-items: center; gap: 8px; background: #0f172a; color: white; border: none; padding: 10px 24px; font-size: 13px; font-family: 'Inter', sans-serif; font-weight: 600; border-radius: 8px; cursor: pointer; margin-bottom: 20px; }
                    .btn-print:hover { background: #1e293b; }
                    @media print { body { padding: 0; } .no-print { display: none; } }
                </style>
            </head>
            <body>
                <div class="header">
                    <img src="${logoUrl}" alt="Logo" class="logo">
                    <div class="title-block">
                        <h1>${titleText}</h1>
                        <p>Emitido em: ${timestamp}</p>
                    </div>
                </div>
                <div style="margin-bottom: 10px; color: var(--secondary); font-weight: 600;">
                    Exibindo ${totalsCount}
                </div>
                <table>
                    <thead>
                        <tr>
                            <th width="30">ID</th>
                            <th width="100">Proprietário</th>
                            <th width="100">Investidor</th>
                            <th width="80">Confinam.</th>
                            <th width="60">Data</th>
                            <th width="80">Lotes</th>
                            <th width="80" style="text-align: right;">Inicial</th>
                            <th width="80" style="text-align: right;">Atual</th>
                            <th width="80" style="text-align: right;">Previsto</th>
                            <th width="90" style="text-align: right;">Saldo At.</th>
                            <th width="90" style="text-align: right;">Saldo Pr.</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rowsHtml}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" align="right">TOTAIS:</td>
                            <td align="right" class="number">${totalValInicial}</td>
                            <td align="right" class="number">${totalValAtual}</td>
                            <td align="right" class="number">${totalValPrevisto}</td>
                            <td align="right" class="number">${totalSaldoAtual}</td>
                            <td align="right" class="number">${totalSaldoPrevisto}</td>
                        </tr>
                    </tfoot>
                </table>
                <div class="footer">
                    Cattle Invest - Sistema de Gestão de Parcerias Pecuárias
                </div>
                <div class="no-print" style="margin-bottom: 16px;">
                    <button class="btn-print" onclick="window.print()">&#128438; Imprimir / Salvar PDF</button>
                </div>
            </body>
            </html>
            `;

            const blob = new Blob([reportHtml], { type: 'text/html;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const printWindow = window.open(url, '_blank');
            if (!printWindow) {
                alert("O bloqueador de pop-ups impediu a abertura do relatório. Por favor, permita pop-ups para este site.");
            }
        }

        function runSimulation() {
            const initialValue = parseFloat(document.getElementById('sim-value').value);
            const rate = parseFloat(document.getElementById('sim-rate').value);
            const days = parseInt(document.getElementById('sim-days').value);

            if (isNaN(initialValue) || isNaN(rate) || isNaN(days)) {
                alert('Por favor, preencha todos os campos corretamente.');
                return;
            }

            const months = days / 30;
            const factor = Math.pow((1 + rate / 100), months);
            const finalValue = initialValue * factor;
            const interest = finalValue - initialValue;

            // Get selected lot details if any
            const lotSelect = document.getElementById('sim-lot');
            const hasLot = lotSelect.value !== "";
            const selectedLotText = hasLot ? lotSelect.options[lotSelect.selectedIndex].text : "";

            // Format period description
            const periodType = document.querySelector('input[name="sim-period-type"]:checked').value;
            let periodText = `${days} dias (${months.toFixed(2)} meses)`;
            if (periodType === 'dates') {
                const startStr = document.getElementById('sim-start-date').value;
                const endStr = document.getElementById('sim-end-date').value;
                if (startStr && endStr) {
                    const startFormatted = startStr.split('-').reverse().join('/');
                    const endFormatted = endStr.split('-').reverse().join('/');
                    periodText = `${days} dias (${months.toFixed(2)} meses)<br><span style="font-size: 11px; color: var(--secondary); font-weight: normal;">De ${startFormatted} até ${endFormatted}</span>`;
                }
            }

            const logoUrl = new URL('assets/logo.png', window.location.href).href;
            const timestamp = new Date().toLocaleString('pt-BR');

            const formatBRL = (val) => val.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

            let lotHtml = "";
            if (hasLot) {
                lotHtml = `
                <div class="summary-item" style="grid-column: span 2; border-bottom: 1px dashed var(--border); padding-bottom: 10px; margin-bottom: 10px;">
                    <span class="label">Lote Selecionado</span>
                    <span class="value" style="color: #38bdf8;">${selectedLotText}</span>
                </div>
                `;
            }

            const reportHtml = `
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="UTF-8">
                <title>Relatório de Simulação de Rendimento</title>
                <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
                <style>
                    :root { --primary: #0f172a; --secondary: #64748b; --border: #e2e8f0; --bg-light: #f8fafc; }
                    body { font-family: 'Inter', sans-serif; color: var(--primary); line-height: 1.5; margin: 0; padding: 40px; background: white; font-size: 13px; }
                    .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--primary); padding-bottom: 15px; margin-bottom: 30px; }
                    .logo { height: 60px; }
                    .title-block h1 { margin: 0; font-size: 22px; text-transform: uppercase; text-align: right; color: var(--primary); }
                    .title-block p { margin: 2px 0 0; color: var(--secondary); text-align: right; font-size: 12px; }
                    .summary-card { background: var(--bg-light); border: 1px solid var(--border); border-radius: 12px; padding: 25px; margin-bottom: 30px; }
                    .summary-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
                    .summary-item { margin-bottom: 10px; }
                    .label { color: var(--secondary); text-transform: uppercase; font-size: 11px; font-weight: 700; display: block; margin-bottom: 4px; }
                    .value { font-size: 16px; font-weight: 600; color: var(--primary); }
                    .highlight { font-size: 20px; color: #10b981; }
                    .footer { margin-top: 50px; text-align: center; font-size: 11px; color: var(--secondary); border-top: 1px solid var(--border); padding-top: 20px; }
                    .btn-print { display: inline-flex; align-items: center; gap: 8px; background: #0f172a; color: white; border: none; padding: 10px 24px; font-size: 13px; font-family: 'Inter', sans-serif; font-weight: 600; border-radius: 8px; cursor: pointer; margin-bottom: 20px; }
                    .btn-print:hover { background: #1e293b; }
                    @media print { body { padding: 0; } .no-print { display: none; } }
                </style>
            </head>
            <body>
                <div class="header">
                    <img src="${logoUrl}" alt="Logo" class="logo">
                    <div class="title-block">
                        <h1>Simulação de Rendimento</h1>
                        <p>Emitido em: ${timestamp}</p>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-grid">
                        ${lotHtml}
                        <div class="summary-item">
                            <span class="label">Valor Inicial</span>
                            <span class="value">${formatBRL(initialValue)}</span>
                        </div>
                        <div class="summary-item">
                            <span class="label">Taxa de Engorda Mensal</span>
                            <span class="value">${rate.toFixed(2)}%</span>
                        </div>
                        <div class="summary-item">
                            <span class="label">Período</span>
                            <span class="value">${periodText}</span>
                        </div>
                        <div class="summary-item">
                            <span class="label">Valor Calculado</span>
                            <span class="value highlight">+ ${formatBRL(interest)}</span>
                        </div>
                    </div>
                    <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border); text-align: center;">
                        <span class="label">Montante Final Estimado</span>
                        <div style="font-size: 32px; font-weight: 700; color: var(--primary);">${formatBRL(finalValue)}</div>
                    </div>
                </div>

                <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 4px; margin-top: 20px;">
                    <strong style="color: #92400e; display: block; margin-bottom: 5px;">Aviso:</strong>
                    <p style="margin: 0; color: #92400e; font-size: 12px;">
                        Esta simulação é baseada em juros compostos mensais, calculados pro-rata die (por dia), considerando ${days} dias. 
                        Os valores apresentados são projeções estimadas e podem variar de acordo com as condições específicas do contrato.
                    </p>
                </div>

                <div class="footer">
                    Cattle Invest - Sistema de Gestão de Parcerias Pecuárias<br>
                    Simulador Financeiro Interno
                </div>
                <div class="no-print" style="margin-bottom: 16px;">
                    <button class="btn-print" onclick="window.print()">&#128438; Imprimir / Salvar PDF</button>
                </div>
            </body>
            </html>
            `;

            const blob = new Blob([reportHtml], { type: 'text/html;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const printWindow = window.open(url, '_blank');
            if (!printWindow) {
                alert("O bloqueador de pop-ups impediu a abertura do relatório. Por favor, permita pop-ups para este site.");
            }
            closeSimulationModal();
        }
        // Update form submission to check for difference
        document.getElementById('partnershipForm').addEventListener('submit', function(e) {
            const totalValue = parseFloat(document.getElementById('total_value').value) || 0;
            const allocatedInputs = document.querySelectorAll('.allocated-input');
            let sumAllocated = 0;
            allocatedInputs.forEach(input => sumAllocated += parseFloat(input.value) || 0);

            const diff = totalValue - sumAllocated;

            if (Math.abs(diff) > 0.05) {
                e.preventDefault();
                const msg = diff > 0 
                    ? `Existe uma diferença positiva de ${Math.abs(diff).toLocaleString('pt-BR', {style:'currency', currency:'BRL'})} (valor não alocado). Deseja distribuir essa diferença proporcionalmente entre os lotes, ignorando o limite disponível?`
                    : `Existe uma diferença negativa de ${Math.abs(diff).toLocaleString('pt-BR', {style:'currency', currency:'BRL'})} (alocação superior ao total). Deseja ajustar os valores proporcionalmente para igualar ao Valor Total?`;
                
                if (confirm(msg)) {
                    distributeDifference();
                    // Optional: delay a bit to let the user see the change
                    setTimeout(() => {
                        this.submit();
                    }, 500);
                } else {
                    if (confirm("Deseja gravar mesmo com a diferença? (O cálculo financeiro usará os valores alocados individuais)")) {
                        this.submit();
                    }
                }
            }
        });
    </script>
    <?php include 'attachments_modal.html'; ?>
    <?php include 'liquidations_modal.html'; ?>
    <?php include 'memory_modal.html'; ?>
    <?php include 'simulation_modal.html'; ?>
</body>

</html>
