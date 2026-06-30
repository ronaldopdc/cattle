<?php
require_once 'auth.php';
require_login();
require_once 'config.php';

// Include shared calculations
require_once __DIR__ . '/financial_calculations.php';

// Fetch Partnerships with Owner Name
// Determine User Access
$userRole = get_current_user_role();
$userPartnerId = get_current_user_partner_id();

$whereClause = "1=1";
$params = [];

// Date Filter Parameters
$startDateParam = $_GET['start_date'] ?? null;
$endDateParam = $_GET['end_date'] ?? null;

// Default: 6 months before and 5 months after the current month (Total 12 months)
$baseDate = new DateTime('first day of this month');
if (!$startDateParam) {
    $tempStart = clone $baseDate;
    $tempStart->modify('-6 months');
    $startDateParam = $tempStart->format('Y-m-d');
}
if (!$endDateParam) {
    $tempEnd = clone $baseDate;
    $tempEnd->modify('+5 months');
    $endDateParam = $tempEnd->format('Y-m-t'); // End of the month
}

if ($userRole === 'admin') {
    $selectedPartnerId = isset($_GET['partner_id']) ? $_GET['partner_id'] : ($userPartnerId ?: 'all');
    if ($selectedPartnerId !== 'all') {
        $whereClause = "(p.owner_id = ? OR p.investor_id = ? OR p.confinamento_id = ?)";
        $params = [$selectedPartnerId, $selectedPartnerId, $selectedPartnerId];
    }
} elseif ($userRole === 'user') {
    if (!$userPartnerId) {
        // User has no linked partner, they shouldn't see any partnerships
        $whereClause = "1=0";
    } else {
        $whereClause = "(p.owner_id = ? OR p.investor_id = ? OR p.confinamento_id = ?)";
        $params = [$userPartnerId, $userPartnerId, $userPartnerId];
    }
}

// Fetch Partnerships with Owner and Investor Names
$stmt = $pdo->prepare("
    SELECT p.*, own.name as owner_name, own.cpf as owner_cpf, inv.name as investor_name,
           conf.name as confinement_name, conf.cpf as confinement_cpf
    FROM partnerships p 
    JOIN partners own ON p.owner_id = own.id
    JOIN partners inv ON p.investor_id = inv.id
    LEFT JOIN partners conf ON p.confinamento_id = conf.id
    WHERE $whereClause
");
$stmt->execute($params);
$partnerships = $stmt->fetchAll();

$reportWhereClause = $userRole === 'admin' ? "1=1" : $whereClause;
$reportParams = $userRole === 'admin' ? [] : $params;
$stmtReport = $pdo->prepare("
    SELECT p.*, own.name as owner_name, own.cpf as owner_cpf, inv.name as investor_name,
           conf.name as confinement_name, conf.cpf as confinement_cpf
    FROM partnerships p
    JOIN partners own ON p.owner_id = own.id
    JOIN partners inv ON p.investor_id = inv.id
    LEFT JOIN partners conf ON p.confinamento_id = conf.id
    WHERE $reportWhereClause
");
$stmtReport->execute($reportParams);
$reportPartnerships = $stmtReport->fetchAll();

// Build the cash-flow display name (owner name + CPF). When the same partner is
// both owner and investor, show the confinamento (name + CPF) instead, per
// business rule for the Livro Caixa report.
foreach ($partnerships as &$p) {
    $cfName = $p['owner_name'];
    $cfCpf = $p['owner_cpf'] ?? '';
    if (!empty($p['owner_id']) && !empty($p['investor_id']) && $p['owner_id'] == $p['investor_id'] && !empty($p['confinamento_id'])) {
        $cfName = $p['confinement_name'];
        $cfCpf = $p['confinement_cpf'] ?? '';
    }
    $p['cash_flow_name'] = trim($cfCpf) !== '' ? ($cfName . ' (' . $cfCpf . ')') : $cfName;
}
unset($p);

foreach ($reportPartnerships as &$p) {
    $cfName = $p['owner_name'];
    $cfCpf = $p['owner_cpf'] ?? '';
    if (!empty($p['owner_id']) && !empty($p['investor_id']) && $p['owner_id'] == $p['investor_id'] && !empty($p['confinamento_id'])) {
        $cfName = $p['confinement_name'];
        $cfCpf = $p['confinement_cpf'] ?? '';
    }
    $p['cash_flow_name'] = trim($cfCpf) !== '' ? ($cfName . ' (' . $cfCpf . ')') : $cfName;
}
unset($p);

// Fetch lots for each partnership
$partnershipLots = [];
$plResult = $pdo->query("SELECT pl.*, l.lot_number, l.animal_count, l.protocol_weight, l.indexed_price, l.max_advance_percent FROM partnership_lots pl JOIN lots l ON pl.lot_id = l.id");
while ($row = $plResult->fetch(PDO::FETCH_ASSOC)) {
    $partnershipLots[$row['partnership_id']][] = $row;
}

// Fetch Liquidations
$partnershipLiquidations = [];
$liqResult = $pdo->query("SELECT * FROM partnership_liquidations");
while ($row = $liqResult->fetch(PDO::FETCH_ASSOC)) {
    $partnershipLiquidations[$row['partnership_id']][] = $row;
}

// Initialize Totals
$total_active_partnerships = 0;
$total_initial_principal = 0;
$total_invested = 0;
$total_current_balance = 0;
$total_projected_balance = 0;
$total_projected_value_with_liquidations = 0;
$global_total_liquidated_amount = 0;

// Prepare history dates based on parameter range
$history_dates = [];
$startObj = new DateTime($startDateParam);
$startObj->modify('first day of this month');
$endObj = new DateTime($endDateParam);
$endObj->modify('first day of next month');

$tempObj = clone $startObj;
while ($tempObj < $endObj) {
    $history_dates[] = $tempObj->format('Y-m-01');
    $tempObj->modify('+1 month');
}
// Add the final boundary (1st of month AFTER end_date) if not already there
if (end($history_dates) !== $tempObj->format('Y-m-01')) {
    $history_dates[] = $tempObj->format('Y-m-01');
}

// Initialize Chart Data arrays in chronological order (the 12 months in history_dates boundaries)
$history_yield_array = [];
$history_uncalibrated_yield_array = [];
$history_principal_days_array = [];
$history_base_array = [];
for ($i = 1; $i < count($history_dates); $i++) {
    $hDate = $history_dates[$i];
    $mKey = date('m/Y', strtotime("-1 month", strtotime($hDate))); // Key is the month that just ended
    $history_yield_array[$mKey] = 0;
    $history_uncalibrated_yield_array[$mKey] = 0;
    $history_principal_days_array[$mKey] = 0;
    $history_base_array[$mKey] = 0;
}

$pie_chart_array = []; // Owner -> Value
$yield_report_data = []; // Detailed records for modal
$cash_flow_data = []; // Detailed cash flow entries
$liquidation_report_data = []; // Detailed liquidation report entries
$upcoming_slaughter_report_data = []; // Future slaughter report entries
$upcoming_lots = [];
$recent_liquidations_list = [];

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
$yield_report_data = []; // Detailed records for modal
$cash_flow_data = []; // Detailed cash flow entries
$liquidation_report_data = []; // Detailed liquidation report entries
$upcoming_slaughter_report_data = []; // Future slaughter report entries
$upcoming_lots = [];
$recent_liquidations_list = [];

foreach ($partnerships as $p) {
    $liquidations = $partnershipLiquidations[$p['id']] ?? [];
    $lots = $partnershipLots[$p['id']] ?? [];
    $settlementDisplayDate = null;
    foreach ($liquidations as $liq) {
        if (!empty($liq['is_settlement']) || floatval($liq['balance_after']) <= 0.05) {
            if (!$settlementDisplayDate || $liq['date'] > $settlementDisplayDate) {
                $settlementDisplayDate = $liq['date'];
            }
        }
    }

    $initial_cattle_count = 0;
    $simHeadLots = [];
    foreach ($lots as $lot) {
        $projected = floatval($lot['projected_value']);
        // Official rule: months = total days / 30 (calculateMonthsBetween)
        $monthsTotal = calculateMonthsBetween($p['start_date'], $lot['slaughter_date']);
        if ($monthsTotal <= 0) $monthsTotal = 0.0001;
        $rate = floatval($lot['monthly_rate']);
        $allocated_amount = $projected / pow((1 + $rate / 100), $monthsTotal);
        
        $weightArrobas = (floatval($lot['protocol_weight']) * intval($lot['animal_count'])) / 30;
        $totalValue = $weightArrobas * floatval($lot['indexed_price']);
        $maxAdvance = $totalValue * (floatval($lot['max_advance_percent']) / 100);
        
        if ($maxAdvance > 0) {
            $totalLotPrincipal = $lotTotalPrincipalMap[$lot['lot_id']] ?? $allocated_amount;
            if ($totalLotPrincipal <= 0) $totalLotPrincipal = $allocated_amount;
            
            $fraction = $totalLotPrincipal > 0 ? ($allocated_amount / $totalLotPrincipal) : 0;
            $allocatedAnimals = round(intval($lot['animal_count']) * $fraction);
            $initial_cattle_count += $allocatedAnimals;

            $simHeadLots[] = [
                'lot_id' => $lot['lot_id'],
                'slaughter_date' => $lot['slaughter_date'],
                'projected_value' => $projected,
                'allocated_amount' => $allocated_amount,
                'allocated_animals' => $allocatedAnimals,
            ];
        }
    }

    $average_principal_per_animal = $initial_cattle_count > 0 ? floatval($p['total_value']) / $initial_cattle_count : 0;

    // Add Initial Investment as Saída
    if (floatval($p['total_value']) > 0) {
        $cash_flow_data[] = [
            'date' => $p['start_date'],
            'type' => 'Saída', // Investimento Inicial
            'partnership_id' => $p['id'],
            'owner_name' => $p['cash_flow_name'],
            'value_in' => 0,
            'value_out' => floatval($p['total_value']),
            'cattle_in' => $initial_cattle_count,
            'cattle_out' => 0
        ];
    }
    // ...
    $initial_p = floatval($p['total_value']);
    // For general principal tracking (to match cash flow balance at end_date)
    if ($p['start_date'] <= $endDateParam) {
        $total_initial_principal += $initial_p;
    }

    // Build a lookup of lot_id -> lot_number for this partnership so each
    // liquidation can be labelled with the specific lot it belongs to
    // (per-lot control), instead of listing all lots of the partnership.
    $lotNumberById = [];
    foreach ($lots as $lt) {
        $lotNumberById[$lt['lot_id']] = $lt['lot_number'];
    }

    // Calculate liquidated principal up to end_date
    foreach ($liquidations as $liq) {
        $liquidationDate = date('Y-m-d', strtotime($liq['date']));
        if ($liquidationDate <= $endDateParam) {
            // Prefer the specific lot tied to this liquidation; fall back to all
            // lots only when the liquidation has no lot_id (legacy records).
            if (!empty($liq['lot_id']) && isset($lotNumberById[$liq['lot_id']])) {
                $lotNumbers = [$lotNumberById[$liq['lot_id']]];
            } else {
                $lotNumbers = array_unique(array_column($lots, 'lot_number'));
            }

            $recentKey = $p['id'] . '_' . $liquidationDate;
            if (!isset($recent_liquidations_list[$recentKey])) {
                $recent_liquidations_list[$recentKey] = [
                    'partnership_id' => $p['id'],
                    'owner_name' => $p['owner_name'],
                    'investor_name' => $p['investor_name'],
                    'date' => $liquidationDate,
                    'amount_total' => 0,
                    'lot_numbers' => []
                ];
            }

            $recent_liquidations_list[$recentKey]['amount_total'] += floatval($liq['amount_total']);
            foreach ($lotNumbers as $lotNumber) {
                if ($lotNumber !== '' && !in_array($lotNumber, $recent_liquidations_list[$recentKey]['lot_numbers'])) {
                    $recent_liquidations_list[$recentKey]['lot_numbers'][] = $lotNumber;
                }
            }
        }
    }

    // --- PRE-CALCULATE SIMULATED FUTURE RETURNS (FOR CHART ONLY) ---
    // This allows the chart's "Valor Investido" line to drop when a lot reaches its slaughter date,
    // even if it hasn't been liquidated yet.
    if (!isset($global_simulated_futures)) $global_simulated_futures = [];
    $simRefDate = date('Y-m-d');
    foreach ($liquidations as $l) {
        if ($l['date'] > $simRefDate) {
            $simRefDate = $l['date'];
        }
    }

    $simFurthestLotRate = 0;
    if (!empty($lots)) {
        $simRateLots = $lots;
        usort($simRateLots, function($a, $b) { return strtotime($a['slaughter_date']) - strtotime($b['slaughter_date']); });
        $simFurthestLotRate = floatval($simRateLots[count($simRateLots) - 1]['monthly_rate']);
    }

    $simState = calculatePartnershipState($p, $lots, $liquidations, $simRefDate);
    $remainingProjectedForSim = calculateProjectedBalance($simState['current_balance'], $simRefDate, $simFurthestLotRate, $lots, $liquidations);
    $headBalanceMapForSim = computeLotHeadBalances($simHeadLots, $liquidations, $simRefDate, $average_principal_per_animal);

    // Sort lots by slaughter date and distribute the remaining projected balance
    // only among lots that still have head balance. Do not subtract actual
    // liquidations from projected_value again; those are already reflected in
    // the partnership rolling/projected balance above.
    $simLots = $lots;
    usort($simLots, function($a, $b) { return strtotime($a['slaughter_date']) - strtotime($b['slaughter_date']); });

    $simWeights = [];
    $totalSimWeight = 0;
    foreach ($simLots as $slot) {
        $remainingHead = isset($headBalanceMapForSim[$slot['lot_id']])
            ? intval($headBalanceMapForSim[$slot['lot_id']]['balance_animals'])
            : 0;
        if ($remainingHead <= 0) {
            continue;
        }

        $weight = max(0, floatval($slot['projected_value']));
        if ($weight <= 0) {
            $weight = $remainingHead;
        }
        $simWeights[] = ['lot' => $slot, 'weight' => $weight];
        $totalSimWeight += $weight;
    }

    if ($remainingProjectedForSim > 0.01 && $totalSimWeight > 0) {
        $allocatedSim = 0;
        $lastSimIndex = count($simWeights) - 1;
        foreach ($simWeights as $idx => $item) {
            $uncovered = ($idx === $lastSimIndex)
                ? max(0, $remainingProjectedForSim - $allocatedSim)
                : $remainingProjectedForSim * ($item['weight'] / $totalSimWeight);
            $allocatedSim += $uncovered;

            $today = date('Y-m-d');
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $simulatedDate = $item['lot']['slaughter_date'] < $today ? $tomorrow : $item['lot']['slaughter_date'];

            $global_simulated_futures[] = [
                'date' => $simulatedDate,
                'value' => $uncovered
            ];
        }
    }

    // O cálculo de $total_invested foi movido para o final, seguindo a lógica do Livro Caixa (Total Entradas - Total Saídas)

    // --- STATE AT END DATE (For Projections) ---
    $calcState = calculatePartnershipState($p, $lots, $liquidations, $endDateParam);
    $current_balance_at_end = $calcState['current_balance'];

    // --- STATE AT REFERENCE (For Current Balance Display: Today or EndDate if past) ---
    $refDate = date('Y-m-d');
    if ($endDateParam < $refDate) {
        $refDate = $endDateParam;
    }
    if ($settlementDisplayDate && $settlementDisplayDate > $refDate) {
        $refDate = $settlementDisplayDate;
    }
    $calcStateRef = calculatePartnershipState($p, $lots, $liquidations, $refDate);
    $current_balance = $calcStateRef['current_balance'];

    // Projected Balance
    $furthestLotRate = 0;
    if (!empty($lots)) {
        usort($lots, function ($a, $b) {
            return strtotime($a['slaughter_date']) - strtotime($b['slaughter_date']);
        });
        $furthestLotRate = floatval($lots[count($lots) - 1]['monthly_rate']);
    }

    // Use the balance at END DATE for the projection forward to maturity
    $projected_balance = calculateProjectedBalance($current_balance_at_end, $endDateParam, $furthestLotRate, $lots, $liquidations);

    // Verify "Active" status logic
    $isActive = ($current_balance > 0.01 || $projected_balance > 0.01);

    if ($isActive) {
        $total_active_partnerships++;
        $total_current_balance += $current_balance;
        $total_projected_balance += $projected_balance;

        // Per-lot value balance is distributed from the partnership rolling balance
        // proportionally to each lot's REMAINING head, mirroring the partnerships
        // list/tree. This keeps lots with remaining head showing value (instead of
        // carry-over piling everything on one lot) and the sum equals the
        // partnership current balance.
        $headLotsInput = [];
        foreach ($lots as $lot) {
            $start = new DateTime($p['start_date']);
            $end = new DateTime($lot['slaughter_date']);
            $interval = $start->diff($end);
            $months = $interval->days / 30;
            if ($months <= 0)
                $months = 0.0001;

            $rate = floatval($lot['monthly_rate']);
            $projected = floatval($lot['projected_value']);
            $allocated = $projected / pow((1 + $rate / 100), $months);

            // Initial allocated head for this lot (same logic as the list).
            $allocatedAnimalsDash = 0;
            $weightArrobasDash = (floatval($lot['protocol_weight']) * intval($lot['animal_count'])) / 30;
            $totalValueDash = $weightArrobasDash * floatval($lot['indexed_price']);
            $maxAdvanceDash = $totalValueDash * (floatval($lot['max_advance_percent']) / 100);
            if ($maxAdvanceDash > 0) {
                $totalLotPrincipalDash = $lotTotalPrincipalMap[$lot['lot_id']] ?? $allocated;
                if ($totalLotPrincipalDash <= 0) $totalLotPrincipalDash = $allocated;
                $fractionDash = $totalLotPrincipalDash > 0 ? ($allocated / $totalLotPrincipalDash) : 0;
                $allocatedAnimalsDash = round(intval($lot['animal_count']) * $fractionDash);
            }

            $headLotsInput[] = [
                'lot_id' => $lot['lot_id'],
                'slaughter_date' => $lot['slaughter_date'],
                'allocated_amount' => $allocated,
                'allocated_animals' => $allocatedAnimalsDash,
            ];
        }

        $initialCattleDash = 0;
        foreach ($headLotsInput as $hli) {
            $initialCattleDash += intval($hli['allocated_animals']);
        }
        $avgPrincipalPerAnimalDash = $initialCattleDash > 0
            ? floatval($p['total_value']) / $initialCattleDash
            : 0;
        $headBalanceMapDash = computeLotHeadBalances($headLotsInput, $liquidations, $refDate, $avgPrincipalPerAnimalDash);

        // Coherence: fully liquidated partnership has no remaining head.
        $fullyLiquidatedDash = ($current_balance < 0.01 && count($liquidations) > 0);

        $totalRemHeadDash = 0;
        $lotRemHeadDash = [];
        foreach ($lots as $lot) {
            $rh = $fullyLiquidatedDash
                ? 0
                : (isset($headBalanceMapDash[$lot['lot_id']]) ? intval($headBalanceMapDash[$lot['lot_id']]['balance_animals']) : 0);
            $lotRemHeadDash[$lot['lot_id']] = $rh;
            $totalRemHeadDash += $rh;
        }

        $partnershipBalanceDash = max(0, $current_balance);

        // Collect upcoming lots from active partnerships (grouped by partner and date)
        foreach ($lots as $lot) {
                $remHead = $lotRemHeadDash[$lot['lot_id']];
                $lot_current_value = ($totalRemHeadDash > 0)
                    ? $partnershipBalanceDash * ($remHead / $totalRemHeadDash)
                    : 0;

                // Skip lots that are already fully settled (no remaining balance).
                if ($lot_current_value < 0.01) {
                    continue;
                }

                $upcoming_slaughter_report_data[] = [
                    'date' => $lot['slaughter_date'],
                    'formatted_date' => date('d/m/Y', strtotime($lot['slaughter_date'])),
                    'partnership_id' => $p['id'],
                    'owner_id' => $p['owner_id'],
                    'owner_name' => $p['owner_name'],
                    'investor_id' => $p['investor_id'],
                    'investor_name' => $p['investor_name'],
                    'lot_numbers' => $lot['lot_number'],
                    'current_balance' => $lot_current_value,
                    'quantity' => $remHead
                ];

                $groupKey = $p['id'] . '_' . $lot['slaughter_date'];
                if (!isset($upcoming_lots[$groupKey])) {
                    $upcoming_lots[$groupKey] = [
                        'partnership_id' => $p['id'],
                        'owner_name' => $p['owner_name'],
                        'investor_name' => $p['investor_name'],
                        'slaughter_date' => $lot['slaughter_date'],
                        'current_balance' => 0,
                        'lot_numbers' => []
                    ];
                }
                if ($lot['slaughter_date'] >= $startDateParam && $lot['slaughter_date'] <= $endDateParam) {
                    $upcoming_lots[$groupKey]['current_balance'] += $lot_current_value;
                    $upcoming_lots[$groupKey]['lot_numbers'][] = $lot['lot_number'];
                }
            }
        
        // PIE CHART: Accumulate Active Balance by Owner
        $owner = $p['owner_name'];
        if (!isset($pie_chart_array[$owner])) {
            $pie_chart_array[$owner] = 0;
        }
        $pie_chart_array[$owner] += $current_balance;
    }

    $total_liquidated_amount_up_to_end = 0;
    $cumulative_estimated_principal = 0;
    $cumulative_estimated_cattle = 0;
    
    foreach ($liquidations as $liq) {
        if ($liq['date'] <= $endDateParam) {
            $total_liquidated_amount_up_to_end += floatval($liq['amount_total']);
        }
        
        $q = intval($liq['quantity'] ?? 0);
        $estimated_q = 0;
        if ($q > 0) {
            $estimated_q = $q;
        } else if ($average_principal_per_animal > 0 && floatval($liq['amount_principal']) > 0) {
            $cumulative_estimated_principal += floatval($liq['amount_principal']);
            $new_cumulative_cattle = round($cumulative_estimated_principal / $average_principal_per_animal);
            $estimated_q = $new_cumulative_cattle - $cumulative_estimated_cattle;
            $cumulative_estimated_cattle = $new_cumulative_cattle;
        }

        $cash_flow_data[] = [
            'date' => $liq['date'],
            'type' => 'Entrada', // Liquidação
            'partnership_id' => $p['id'],
            'owner_name' => $p['cash_flow_name'],
            'value_in' => floatval($liq['amount_total']),
            'value_out' => 0,
            'cattle_in' => 0,
            'cattle_out' => $estimated_q
        ];

        if (!empty($liq['lot_id']) && isset($lotNumberById[$liq['lot_id']])) {
            $reportLotNumbers = $lotNumberById[$liq['lot_id']];
        } else {
            $reportLotNumbers = implode(', ', array_unique(array_column($lots, 'lot_number')));
        }

        $liquidation_report_data[] = [
            'date' => $liq['date'],
            'formatted_date' => date('d/m/Y', strtotime($liq['date'])),
            'partnership_id' => $p['id'],
            'owner_id' => $p['owner_id'],
            'owner_name' => $p['owner_name'],
            'investor_id' => $p['investor_id'],
            'investor_name' => $p['investor_name'],
            'lot_numbers' => $reportLotNumbers,
            'amount_principal' => floatval($liq['amount_principal']),
            'amount_interest' => floatval($liq['amount_interest']),
            'amount_total' => floatval($liq['amount_total']),
            'quantity' => $estimated_q
        ];
    }
    $global_total_liquidated_amount += $total_liquidated_amount_up_to_end;

    // 2. Pre-calculate original principals and NORMALIZE them to match database total_value exactly
    $total_initial_principal_database = floatval($p['total_value']);
    $total_initial_principal_calculated = 0;
    foreach ($lots as &$lot) {
        $l_start = new DateTime($p['start_date']);
        $slaughter = new DateTime($lot['slaughter_date']);
        $intervalTotal = $l_start->diff($slaughter);
        $daysTotal = $intervalTotal->days;
        $monthsTotal = $daysTotal / 30;
        if ($monthsTotal <= 0)
            $monthsTotal = 0.0001;

        $rate = floatval($lot['monthly_rate']);
        $projected = floatval($lot['projected_value']);
        $lot['original_principal_unnormalized'] = $projected / pow((1 + $rate / 100), $monthsTotal);
        $total_initial_principal_calculated += $lot['original_principal_unnormalized'];
    }
    unset($lot);

    $normalization_factor = $total_initial_principal_calculated > 0 ? ($total_initial_principal_database / $total_initial_principal_calculated) : 1;

    $lot_adjusted_principals = [];
    foreach ($lots as $idx => &$lot) {
        $lot['original_principal'] = $lot['original_principal_unnormalized'] * $normalization_factor;
        $lot_adjusted_principals[$idx] = $lot['original_principal'];
    }
    unset($lot);

    // Track historical balances for chart base (weighted principal days)
    $p_chart_window_start = $history_dates[0];
    $p_chart_window_end = end($history_dates);

    // GLOBAL LIFE PROCESSING (for Report and accurate Total Yield)
    $p_max_slaughter_date = $p['start_date'];
    foreach ($lots as $lot) {
        if ($lot['slaughter_date'] > $p_max_slaughter_date)
            $p_max_slaughter_date = $lot['slaughter_date'];
    }

    // Extend max date to include any late liquidations
    foreach ($liquidations as $liq) {
        if ($liq['date'] > $p_max_slaughter_date) {
            $p_max_slaughter_date = $liq['date'];
        }
    }

    // Extend max date to today if the partnership is still active
    $todayStr = date('Y-m-d');
    if ($current_balance > 0.01 && $todayStr > $p_max_slaughter_date) {
        $p_max_slaughter_date = $todayStr;
    }

    // Sort liquidations globally
    usort($liquidations, function ($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
    $liq_index = 0;
    $segment_start_date = $p['start_date'];

    // Generate all month boundaries from start to end of contract
    $current_date_obj = new DateTime($p['start_date']);
    $current_date_obj->modify('first day of this month');
    $last_date_obj = new DateTime($p_max_slaughter_date);
    $last_date_obj->modify('first day of next month'); // Boundary is the 1st of the month after it ends

    $life_boundaries = [$p['start_date']];
    $temp_date = clone $current_date_obj;
    $temp_date->modify('+1 month');
    while ($temp_date < $last_date_obj) {
        $life_boundaries[] = $temp_date->format('Y-m-01');
        $temp_date->modify('+1 month');
    }
    $life_boundaries[] = $p_max_slaughter_date;
    $life_boundaries = array_unique($life_boundaries);
    sort($life_boundaries);

    $p_temp_yield_segments = [];

    for ($i = 1; $i < count($life_boundaries); $i++) {
        $d_start = $life_boundaries[$i - 1];
        $d_end = $life_boundaries[$i];
        $monthKey = date('m/Y', strtotime($d_start));

        $interval_yield = 0;
        $segment_base_accrued = 0;
        $segment_starting_principal = 0;

        // Process liquidations within this interval
        while ($liq_index < count($liquidations) && $liquidations[$liq_index]['date'] <= $d_end) {
            $liq = $liquidations[$liq_index];
            $liqDate = $liq['date'];

            if ($liqDate > $segment_start_date) {
                // Yield for sub-segment [segment_start_date, liqDate]
                $sub_yield = 0;
                $segment_base_accrued = 0;
                foreach ($lots as $lidx => $lot) {
                    $slaughter = new DateTime($lot['slaughter_date']);
                    $cur_p = $lot_adjusted_principals[$lidx];
                    if ($cur_p <= 0)
                        continue;

                    $start_dt = new DateTime($p['start_date']);
                    $s_dt = new DateTime($segment_start_date);
                    $e_dt = new DateTime($liqDate);
                    if ($s_dt >= $e_dt)
                        continue;

                    $diff1 = $start_dt->diff($s_dt);
                    $m1 = $diff1->days / 30;
                    $diff2 = $start_dt->diff($e_dt);
                    $m2 = $diff2->days / 30;

                    $accrued_at_start = $cur_p * pow((1 + floatval($lot['monthly_rate']) / 100), $m1);
                    $accrued_item_yield = $cur_p * (pow((1 + floatval($lot['monthly_rate']) / 100), $m2) - pow((1 + floatval($lot['monthly_rate']) / 100), $m1));

                    $sub_yield += $accrued_item_yield;

                    // Time-weighted principal-days for accuracy
                    $lot_span_days = max(0, (strtotime($e_dt->format('Y-m-d')) - strtotime($s_dt->format('Y-m-d'))) / 86400);
                    $segment_base_accrued += ($accrued_at_start * $lot_span_days);
                    $segment_starting_principal += $accrued_at_start;
                }
                $interval_yield += $sub_yield;
                $weighted_days = ($segment_starting_principal > 0) ? ($segment_base_accrued / $segment_starting_principal) : 0;

                // CHART BASE ACCUMULATION
                if ($d_start >= $p_chart_window_start && $d_start < $p_chart_window_end) {
                    $chartMonthKey = date('m/Y', strtotime($d_start));
                    if (isset($history_principal_days_array[$chartMonthKey])) {
                        $history_principal_days_array[$chartMonthKey] += $segment_base_accrued;
                    }
                }

                $p_temp_yield_segments[] = [
                    'db_start_date' => $segment_start_date, // Added raw date
                    'start_date' => date('d/m/Y', strtotime($segment_start_date)),
                    'end_date' => date('d/m/Y', strtotime($liqDate)),
                    'partnership_id' => $p['id'],
                    'owner_name' => $p['owner_name'],
                    'base_principal' => $segment_starting_principal,
                    'yield' => $sub_yield,
                    'uncalibrated_yield' => $sub_yield,
                    'days' => $weighted_days
                ];
            }

            // Apply principal reduction
            $liq_p = floatval($liq['amount_principal']);
            foreach ($lots as $lidx => $lot) {
                $total_p_now = array_sum($lot_adjusted_principals);
                $proportion = $total_p_now > 0 ? ($lot_adjusted_principals[$lidx] / $total_p_now) : 0;
                $lot_adjusted_principals[$lidx] = max(0, $lot_adjusted_principals[$lidx] - ($liq_p * $proportion));
            }
            $segment_start_date = $liqDate;
            $liq_index++;
        }

        // Accrual to end of boundary
        if ($d_end > $segment_start_date) {
            $sub_yield = 0;
            $segment_base_accrued = 0;
            $segment_starting_principal = 0;
            foreach ($lots as $lidx => $lot) {
                $slaughter = new DateTime($lot['slaughter_date']);
                $cur_p = $lot_adjusted_principals[$lidx];
                if ($cur_p <= 0)
                    continue;

                $start_dt = new DateTime($p['start_date']);
                $s_dt = new DateTime($segment_start_date);
                $e_dt = new DateTime($d_end);
                if ($s_dt >= $e_dt)
                    continue;

                $diff1 = $start_dt->diff($s_dt);
                $m1 = $diff1->days / 30;
                $diff2 = $start_dt->diff($e_dt);
                $m2 = $diff2->days / 30;

                $accrued_at_start = $cur_p * pow((1 + floatval($lot['monthly_rate']) / 100), $m1);
                $accrued_item_yield = $cur_p * (pow((1 + floatval($lot['monthly_rate']) / 100), $m2) - pow((1 + floatval($lot['monthly_rate']) / 100), $m1));

                $sub_yield += $accrued_item_yield;

                // Time-weighted principal-days for accuracy
                $lot_span_days = max(0, (strtotime($e_dt->format('Y-m-d')) - strtotime($s_dt->format('Y-m-d'))) / 86400);
                $segment_base_accrued += ($accrued_at_start * $lot_span_days);
                $segment_starting_principal += $accrued_at_start;
            }

            if ($sub_yield > 0.001 || $sub_yield < -0.001) {
                $weighted_days = ($segment_starting_principal > 0) ? ($segment_base_accrued / $segment_starting_principal) : 0;
                
                $p_temp_yield_segments[] = [
                    'db_start_date' => $segment_start_date, // Added raw date
                    'start_date' => date('d/m/Y', strtotime($segment_start_date)),
                    'end_date' => date('d/m/Y', strtotime($d_end)),
                    'partnership_id' => $p['id'],
                    'owner_name' => $p['owner_name'],
                    'base_principal' => $segment_starting_principal,
                    'yield' => $sub_yield,
                    'uncalibrated_yield' => $sub_yield,
                    'days' => $weighted_days
                ];

                // Also record history for chart (needed for weighted average later)
                if ($d_start >= $p_chart_window_start && $d_start < $p_chart_window_end) {
                    $chartMonthKey = date('m/Y', strtotime($d_start));
                    if (isset($history_principal_days_array[$chartMonthKey])) {
                        $history_principal_days_array[$chartMonthKey] += $segment_base_accrued;
                    }
                }
            }
            $segment_start_date = $d_end;
        }
    }

    // --- CALIBRATION AND AGGREGATION ---
    $formula_yield_sum = 0;
    foreach ($p_temp_yield_segments as $seg) {
        $formula_yield_sum += $seg['uncalibrated_yield'];
    }

    // Get current actual balance (to calculate all-time yield). Use the latest
    // liquidation date when it is in the future so the balance already reflects
    // every recorded liquidation. Otherwise the balance would still carry the
    // not-yet-deducted principal while $total_liquidated_all_time already counts
    // those future payments, double counting the yield and inflating the
    // monthly chart/report values.
    $allTimeRefDate = date('Y-m-d');
    foreach ($liquidations as $l) {
        if ($l['date'] > $allTimeRefDate) {
            $allTimeRefDate = $l['date'];
        }
    }
    $allTimeState = calculatePartnershipState($p, $lots, $liquidations, $allTimeRefDate);
    $current_all_time_balance = $allTimeState['current_balance'];

    // Mature Balance (assuming no further liquidations from the reference date)
    $matureBalance = calculateProjectedBalance($current_all_time_balance, $allTimeRefDate, $furthestLotRate, $lots, $liquidations);

    // Total liquidated all time
    $total_liquidated_all_time = 0;
    $isSettled = false;
    foreach ($liquidations as $l) {
        $total_liquidated_all_time += floatval($l['amount_total']);
        if (!empty($l['is_settlement']) || floatval($l['balance_after'] ?? 999999) <= 0.05) {
            $isSettled = true;
        }
    }

    $true_partnership_yield = $isSettled
        ? ($total_liquidated_all_time - $initial_p)
        : (($matureBalance + $total_liquidated_all_time) - $initial_p);

    $calibration_factor = ($formula_yield_sum > 0.01) ? ($true_partnership_yield / $formula_yield_sum) : 1;
    // If true yield is positive but formula is 0/neg (rare), or vice versa, fallback to 1 to avoid weird sign flips
    if ($true_partnership_yield > 0 && $formula_yield_sum <= 0) $calibration_factor = 1;

    $effective_rate_decimal = null;

    if ($isSettled) {
        $initial = floatval($p['total_value']);
        $totalPaid = 0;
        $lDateObjStr = null;
        foreach($liquidations as $l) {
            $totalPaid += floatval($l['amount_total']);
            if (!$lDateObjStr || $l['date'] > $lDateObjStr) {
                $lDateObjStr = $l['date'];
            }
        }
        
        $sDateObj = new DateTime($p['start_date']);
        $lDateObj = new DateTime($lDateObjStr);
        $diffIter = $sDateObj->diff($lDateObj);
        $diffDaysSettled = $diffIter->days;
        $preciseMonths = $diffDaysSettled / 30;
        
        if ($preciseMonths > 0 && $initial > 0) {
            $effective_rate_decimal = pow(($totalPaid / $initial), (1 / $preciseMonths)) - 1;
        }
    }

    foreach ($p_temp_yield_segments as $seg) {
        $calibrated_yield = $seg['yield'] * $calibration_factor;
        
        if ($isSettled && $effective_rate_decimal !== null) {
            $final_uncalibrated_yield = $effective_rate_decimal * $seg['base_principal'] * ($seg['days'] / 30);
        } else {
            $final_uncalibrated_yield = $seg['uncalibrated_yield'];
        }
        
        // Add to Report
        $yield_report_data[] = [
            'db_start_date' => $seg['db_start_date'],
            'start_date' => $seg['start_date'],
            'end_date' => $seg['end_date'],
            'partnership_id' => $seg['partnership_id'],
            'owner_name' => $seg['owner_name'],
            'base_principal' => $seg['base_principal'],
            'yield' => $calibrated_yield,
            'uncalibrated_yield' => $final_uncalibrated_yield,
            'days' => $seg['days'],
            'current_balance' => 0,
            'is_settled' => $isSettled
        ];

        // Add to History Chart
        $d_start = DateTime::createFromFormat('d/m/Y', $seg['start_date'])->format('Y-m-d');
        if ($d_start >= $p_chart_window_start && $d_start < $p_chart_window_end) {
            $chartMonthKey = date('m/Y', strtotime($d_start));
            if (isset($history_yield_array[$chartMonthKey])) {
                $history_yield_array[$chartMonthKey] += $calibrated_yield;
                $history_uncalibrated_yield_array[$chartMonthKey] += $final_uncalibrated_yield;
            }
        }
    }
}

// Group report data by month for better display order
usort($yield_report_data, function ($a, $b) {
    $dateA = DateTime::createFromFormat('d/m/Y', $a['start_date']);
    $dateB = DateTime::createFromFormat('d/m/Y', $b['start_date']);
    if ($dateA == $dateB) {
        return $a['partnership_id'] - $b['partnership_id'];
    }
    return $dateA <=> $dateB;
});

// Group and calculate Balances for report rows per partnership
$partnership_running_balances = [];
foreach ($yield_report_data as &$row) {
    $pid = $row['partnership_id'];
    if (!isset($partnership_running_balances[$pid])) {
        $partnership_running_balances[$pid] = $row['base_principal'];
    }

    $partnership_running_balances[$pid] += $row['yield'];
    $row['current_balance'] = $partnership_running_balances[$pid];
}
unset($row);

// Rebuild modal report datasets from their own access scope. For admins this
// deliberately ignores the dashboard partner filter, so modal filters can work
// across every partnership the admin can see.
$liquidation_report_data = [];
$upcoming_slaughter_report_data = [];
$todayReportDate = date('Y-m-d');

foreach ($reportPartnerships as $reportP) {
    $reportLiquidations = $partnershipLiquidations[$reportP['id']] ?? [];
    $reportLots = $partnershipLots[$reportP['id']] ?? [];

    $reportLotNumberById = [];
    foreach ($reportLots as $reportLot) {
        $reportLotNumberById[$reportLot['lot_id']] = $reportLot['lot_number'];
    }

    $reportInitialCattleCount = 0;
    $reportHeadLots = [];
    foreach ($reportLots as $reportLot) {
        $projected = floatval($reportLot['projected_value']);
        $monthsTotal = calculateMonthsBetween($reportP['start_date'], $reportLot['slaughter_date']);
        if ($monthsTotal <= 0) $monthsTotal = 0.0001;
        $rate = floatval($reportLot['monthly_rate']);
        $allocatedAmount = $projected / pow((1 + $rate / 100), $monthsTotal);

        $weightArrobas = (floatval($reportLot['protocol_weight']) * intval($reportLot['animal_count'])) / 30;
        $totalValue = $weightArrobas * floatval($reportLot['indexed_price']);
        $maxAdvance = $totalValue * (floatval($reportLot['max_advance_percent']) / 100);

        $allocatedAnimals = 0;
        if ($maxAdvance > 0) {
            $totalLotPrincipal = $lotTotalPrincipalMap[$reportLot['lot_id']] ?? $allocatedAmount;
            if ($totalLotPrincipal <= 0) $totalLotPrincipal = $allocatedAmount;
            $fraction = $totalLotPrincipal > 0 ? ($allocatedAmount / $totalLotPrincipal) : 0;
            $allocatedAnimals = round(intval($reportLot['animal_count']) * $fraction);
            $reportInitialCattleCount += $allocatedAnimals;
        }

        $reportHeadLots[] = [
            'lot_id' => $reportLot['lot_id'],
            'slaughter_date' => $reportLot['slaughter_date'],
            'allocated_amount' => $allocatedAmount,
            'allocated_animals' => $allocatedAnimals,
        ];
    }

    $reportAveragePrincipalPerAnimal = $reportInitialCattleCount > 0
        ? floatval($reportP['total_value']) / $reportInitialCattleCount
        : 0;

    $reportCumulativeEstimatedPrincipal = 0;
    $reportCumulativeEstimatedCattle = 0;
    foreach ($reportLiquidations as $reportLiq) {
        $reportQuantity = intval($reportLiq['quantity'] ?? 0);
        if ($reportQuantity <= 0 && $reportAveragePrincipalPerAnimal > 0 && floatval($reportLiq['amount_principal']) > 0) {
            $reportCumulativeEstimatedPrincipal += floatval($reportLiq['amount_principal']);
            $newCumulativeCattle = round($reportCumulativeEstimatedPrincipal / $reportAveragePrincipalPerAnimal);
            $reportQuantity = $newCumulativeCattle - $reportCumulativeEstimatedCattle;
            $reportCumulativeEstimatedCattle = $newCumulativeCattle;
        }

        $reportLotNumbers = (!empty($reportLiq['lot_id']) && isset($reportLotNumberById[$reportLiq['lot_id']]))
            ? $reportLotNumberById[$reportLiq['lot_id']]
            : implode(', ', array_unique(array_column($reportLots, 'lot_number')));

        $liquidation_report_data[] = [
            'date' => $reportLiq['date'],
            'formatted_date' => date('d/m/Y', strtotime($reportLiq['date'])),
            'partnership_id' => $reportP['id'],
            'owner_id' => $reportP['owner_id'],
            'owner_name' => $reportP['owner_name'],
            'investor_id' => $reportP['investor_id'],
            'investor_name' => $reportP['investor_name'],
            'lot_numbers' => $reportLotNumbers,
            'amount_principal' => floatval($reportLiq['amount_principal']),
            'amount_interest' => floatval($reportLiq['amount_interest']),
            'amount_total' => floatval($reportLiq['amount_total']),
            'quantity' => $reportQuantity
        ];
    }

    $reportCurrentState = calculatePartnershipState($reportP, $reportLots, $reportLiquidations, $todayReportDate);
    $reportCurrentBalance = max(0, floatval($reportCurrentState['current_balance']));
    if ($reportCurrentBalance < 0.01 || empty($reportLots)) {
        continue;
    }

    $reportHeadBalanceMap = computeLotHeadBalances($reportHeadLots, $reportLiquidations, $todayReportDate, $reportAveragePrincipalPerAnimal);
    $reportTotalRemainingHead = 0;
    $reportRemainingHeadByLot = [];
    foreach ($reportLots as $reportLot) {
        $remainingHead = isset($reportHeadBalanceMap[$reportLot['lot_id']])
            ? intval($reportHeadBalanceMap[$reportLot['lot_id']]['balance_animals'])
            : 0;
        $reportRemainingHeadByLot[$reportLot['lot_id']] = $remainingHead;
        $reportTotalRemainingHead += $remainingHead;
    }

    foreach ($reportLots as $reportLot) {
        $remainingHead = $reportRemainingHeadByLot[$reportLot['lot_id']] ?? 0;
        $lotCurrentValue = ($reportTotalRemainingHead > 0)
            ? $reportCurrentBalance * ($remainingHead / $reportTotalRemainingHead)
            : 0;

        if ($lotCurrentValue < 0.01) {
            continue;
        }

        $upcoming_slaughter_report_data[] = [
            'date' => $reportLot['slaughter_date'],
            'formatted_date' => date('d/m/Y', strtotime($reportLot['slaughter_date'])),
            'partnership_id' => $reportP['id'],
            'owner_id' => $reportP['owner_id'],
            'owner_name' => $reportP['owner_name'],
            'investor_id' => $reportP['investor_id'],
            'investor_name' => $reportP['investor_name'],
            'lot_numbers' => $reportLot['lot_number'],
            'current_balance' => $lotCurrentValue,
            'quantity' => $remainingHead
        ];
    }
}

// Sort cash flow data chronologically
usort($cash_flow_data, function ($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

usort($liquidation_report_data, function ($a, $b) {
    $dateCompare = strtotime($b['date']) - strtotime($a['date']);
    if ($dateCompare !== 0) {
        return $dateCompare;
    }
    return intval($b['partnership_id']) - intval($a['partnership_id']);
});

usort($upcoming_slaughter_report_data, function ($a, $b) {
    $dateCompare = strtotime($a['date']) - strtotime($b['date']);
    if ($dateCompare !== 0) {
        return $dateCompare;
    }
    return intval($a['partnership_id']) - intval($b['partnership_id']);
});

// Calculate running balance per partner, or global if needed. Wait, the report is global. Let's provide a global running balance or just keep raw lines and calculate balance in JS.
// Doing it in JS is better for dynamic range filtering. So we just need formatted_date.
foreach ($cash_flow_data as &$cf) {
    $cf['formatted_date'] = date('d/m/Y', strtotime($cf['date']));
}
unset($cf);

// Rendimento Total Previsto = sum of all yields that start within [startDateParam, endDateParam]
// Rendimento Total = same period, but only for partnerships already settled/closed.
$total_calculated_yield = 0;
$total_settled_period_yield = 0;
foreach ($yield_report_data as $r) {
    if ($r['db_start_date'] >= $startDateParam && $r['db_start_date'] <= $endDateParam) {
        $total_calculated_yield += $r['yield'];
        if (!empty($r['is_settled'])) {
            $total_settled_period_yield += $r['yield'];
        }
    }
}
$total_yield = $total_calculated_yield;

// Ajusta o Valor Total Investido para ser o saldo do Livro Caixa INVERTIDO (Total Saídas - Total Entradas) até END DATE
$total_invested = $total_initial_principal - $global_total_liquidated_amount;

// Finalize Dashboard Arrays
// Drop empty groups (lots whose slaughter_date fell outside the range, or fully
// settled lots that contributed no balance/number).
$upcoming_lots = array_values(array_filter($upcoming_lots, function ($g) {
    return !empty($g['lot_numbers']);
}));
foreach ($upcoming_lots as &$ulot) {
    $ulot['lot_numbers'] = implode(', ', array_unique($ulot['lot_numbers']));
}
unset($ulot);

usort($upcoming_lots, function ($a, $b) {
    return strtotime($a['slaughter_date']) - strtotime($b['slaughter_date']);
});

$recentGrouped = [];
foreach ($recent_liquidations_list as $rliq) {
    $recentDate = date('Y-m-d', strtotime($rliq['date']));
    $recentKey = $rliq['partnership_id'] . '_' . $recentDate;

    if (!isset($recentGrouped[$recentKey])) {
        $recentGrouped[$recentKey] = [
            'partnership_id' => $rliq['partnership_id'],
            'owner_name' => $rliq['owner_name'],
            'investor_name' => $rliq['investor_name'],
            'date' => $recentDate,
            'amount_total' => 0,
            'lot_numbers' => []
        ];
    }

    $recentGrouped[$recentKey]['amount_total'] += floatval($rliq['amount_total']);
    $lotNumbers = is_array($rliq['lot_numbers']) ? $rliq['lot_numbers'] : explode(',', $rliq['lot_numbers']);
    foreach ($lotNumbers as $lotNumber) {
        $lotNumber = trim($lotNumber);
        if ($lotNumber !== '' && !in_array($lotNumber, $recentGrouped[$recentKey]['lot_numbers'])) {
            $recentGrouped[$recentKey]['lot_numbers'][] = $lotNumber;
        }
    }
}

$recent_liquidations_list = array_values($recentGrouped);
foreach ($recent_liquidations_list as &$rliq) {
    $rliq['lot_numbers'] = implode(', ', array_unique($rliq['lot_numbers']));
}
unset($rliq, $recentGrouped);

usort($recent_liquidations_list, function ($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$pie_labels = [];
foreach ($pie_chart_array as $owner => $value) {
    $pie_labels[] = $owner . ' (R$ ' . number_format($value, 2, ',', '.') . ')';
}
$pie_values = array_values($pie_chart_array);

$line_labels = array_keys($history_yield_array);
$line_values = array_values($history_yield_array);
$line_percentages = [];
foreach ($line_labels as $key) {
    $yield_for_pct = $history_uncalibrated_yield_array[$key];
    $avg_principal = $history_principal_days_array[$key] / 30;
    $line_percentages[] = ($avg_principal > 0) ? round(($yield_for_pct / $avg_principal) * 100, 2) : 0;
}

// Calculate Averages for the chart (only for months with yield > 0)
$non_zero_yields = array_filter($line_values, function ($v) {
    return $v > 0;
});
$non_zero_percentages = array_filter($line_percentages, function ($v) {
    return $v > 0;
});

$average_yield = count($non_zero_yields) > 0 ? array_sum($non_zero_yields) / count($non_zero_yields) : 0;
$average_percentage = count($non_zero_percentages) > 0 ? array_sum($non_zero_percentages) / count($non_zero_percentages) : 0;

$monthly_invested_values = [];
$invested_values_above_zero = [];
$max_exposure_values = [];

// Prepare cash flow entries sorted by date for cumulative calculation
$sorted_cf = $cash_flow_data;
usort($sorted_cf, function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });

// White line = month-end invested cash balance (Σ value_out - value_in).
// It can go negative after projected abatements, and at the end of the period
// that negative value should match the projected total yield.
// Blue dashed line remains the maximum positive exposure reached in each month.
foreach ($line_labels as $key) {
    $parts = explode('/', $key);
    $month_start_ts = strtotime("{$parts[1]}-{$parts[0]}-01");
    $month_end_ts = strtotime(date('Y-m-t', $month_start_ts));

    // Opening exposure: everything accumulated strictly BEFORE this month.
    $opening_bal = 0;
    foreach ($sorted_cf as $cf) {
        if (strtotime($cf['date']) < $month_start_ts) {
            $opening_bal += ($cf['value_out'] - $cf['value_in']);
        }
    }

    // Walk through this month's events chronologically. The white line uses the
    // closing balance; maximum exposure uses the highest balance reached.
    $running_in_month = $opening_bal;
    $month_peak_exposure = $opening_bal;
    foreach ($sorted_cf as $cf) {
        $cfTs = strtotime($cf['date']);
        if ($cfTs >= $month_start_ts && $cfTs <= $month_end_ts) {
            $running_in_month += ($cf['value_out'] - $cf['value_in']);
            if ($running_in_month > $month_peak_exposure) {
                $month_peak_exposure = $running_in_month;
            }
        }
    }

    $month_closing_exposure = $running_in_month;

    // Future months should reflect possible abates not yet posted in Livro Caixa.
    // Keep the current/past months aligned with real cash-flow rows.
    $current_month_start_ts = strtotime(date('Y-m-01'));
    if ($month_start_ts > $current_month_start_ts && isset($global_simulated_futures)) {
        foreach ($global_simulated_futures as $sf) {
            if (strtotime($sf['date']) <= $month_end_ts) {
                $month_closing_exposure -= $sf['value'];
            }
        }
    }

    // White line shows month-end exposure, adjusted only for future possible
    // abates after the current month.
    $monthly_invested_values[] = $month_closing_exposure;
    $max_exposure_values[] = $month_peak_exposure;
}

// When the white line crosses below zero, it represents accumulated projected
// profit in the selected chart range. Normalize only the negative part so the
// last point matches the dashboard's Rendimento Total Previsto for the same
// period, without changing positive exposure months or cash-flow reports.
if (!empty($monthly_invested_values) && $total_yield > 0) {
    $lastInvestedIndex = count($monthly_invested_values) - 1;
    $lastInvestedValue = $monthly_invested_values[$lastInvestedIndex];
    $targetFinalInvestedValue = -$total_yield;

    if ($lastInvestedValue < 0 && abs($lastInvestedValue - $targetFinalInvestedValue) > 0.01) {
        $adjustment = $targetFinalInvestedValue - $lastInvestedValue;
        $lastAbs = abs($lastInvestedValue);
        foreach ($monthly_invested_values as &$investedValue) {
            if ($investedValue < 0 && $lastAbs > 0) {
                $investedValue += $adjustment * (abs($investedValue) / $lastAbs);
            }
        }
        unset($investedValue);
    }
}

$invested_values_above_zero = [];
foreach ($monthly_invested_values as $investedValue) {
    if ($investedValue > 0) {
        $invested_values_above_zero[] = $investedValue;
    }
}
$average_invested_value = count($invested_values_above_zero) > 0 ? array_sum($invested_values_above_zero) / count($invested_values_above_zero) : 0;
// Blue dashed line = overall maximum exposure across all months.
$max_invested_value = count($max_exposure_values) > 0 ? max($max_exposure_values) : 0;

// Fetch all partners for the admin filter
$all_partners = [];
if ($userRole === 'admin') {
    $stmt = $pdo->query("SELECT id, name FROM partners ORDER BY name ASC");
    $all_partners = $stmt->fetchAll();
}
?>
