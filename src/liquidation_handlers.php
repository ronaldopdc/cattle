<?php
// This file should be included in partnerships.php

require_once __DIR__ . '/financial_calculations.php';

/**
 * Recalculates a SINGLE partnership monthly_rate and per-lot projected_value from
 * the slaughtered head, applied equally to every lot of the partnership.
 *
 * value_per_head      = sum(amount_total of liquidations WITH head) / sum(head)
 * principal_per_head  = partnership total_value / total head of the partnership
 * months              = average( days(start -> slaughter)/30 ) across lots
 * rate (single)       = (value_per_head / principal_per_head)^(1/months) - 1
 *
 * Each lot that has an informed head count AND a remaining head balance gets its
 * projected_value set to value_per_head * remaining_head. Every lot receives the
 * single rate. No-op when there is no informed head count.
 */
function recalcPartnershipSingleRate($pdo, $partnership, $partnership_id, $allLots, $date)
{
    // All liquidations of this partnership, including the ones just inserted.
    $stmtLiqAll = $pdo->prepare("SELECT lot_id, date, amount_total, amount_principal, quantity FROM partnership_liquidations WHERE partnership_id = ?");
    $stmtLiqAll->execute([$partnership_id]);
    $allLiquidationsNow = $stmtLiqAll->fetchAll(PDO::FETCH_ASSOC);

    // Partnership-wide value/head from liquidations WITH an informed head count.
    $totalLiqValueWithHead = 0;
    $totalLiqHead = 0;
    $lotHasHead = [];
    foreach ($allLiquidationsNow as $liqRow) {
        $lid = intval($liqRow['lot_id']);
        $qtyRow = intval($liqRow['quantity'] ?? 0);
        if ($qtyRow <= 0) {
            continue; // ignore liquidations without an informed head count
        }
        $totalLiqValueWithHead += floatval($liqRow['amount_total']);
        $totalLiqHead += $qtyRow;
        if ($lid > 0) {
            $lotHasHead[$lid] = true;
        }
    }

    if ($totalLiqHead <= 0 || empty($lotHasHead)) {
        return;
    }

    $valuePerHead = $totalLiqValueWithHead / $totalLiqHead;

    // Partnership average principal per head.
    $totalPartnershipHead = 0;
    foreach ($allLots as $bl) {
        $totalPartnershipHead += intval($bl['animal_count']);
    }
    $totalInitialPrincipal = floatval($partnership['total_value']);

    // Average slaughter horizon (in months) across lots.
    $sumMonths = 0;
    $lotCountForMonths = 0;
    foreach ($allLots as $bl) {
        $mLot = calculateMonthsBetween($partnership['start_date'], $bl['slaughter_date']);
        if ($mLot <= 0) {
            $mLot = 0.0001;
        }
        $sumMonths += $mLot;
        $lotCountForMonths++;
    }
    $avgMonths = $lotCountForMonths > 0 ? ($sumMonths / $lotCountForMonths) : 0.0001;
    if ($avgMonths <= 0) {
        $avgMonths = 0.0001;
    }

    // Single monthly rate applied to every lot of the partnership.
    $newMonthlyRate = null;
    if ($totalPartnershipHead > 0 && $totalInitialPrincipal > 0 && $valuePerHead > 0) {
        $principalPerHead = $totalInitialPrincipal / $totalPartnershipHead;
        if ($principalPerHead > 0) {
            $newMonthlyRate = (pow(($valuePerHead / $principalPerHead), (1 / $avgMonths)) - 1) * 100;
        }
    }

    if ($newMonthlyRate === null) {
        return;
    }

    // Per-lot remaining head balances from ALL current liquidations.
    $balanceLotsForHead = [];
    foreach ($allLots as $bl) {
        $monthsLotH = calculateMonthsBetween($partnership['start_date'], $bl['slaughter_date']);
        $allocatedH = calculateAllocatedAmount(floatval($bl['projected_value']), floatval($bl['monthly_rate']), $monthsLotH);
        $balanceLotsForHead[] = [
            'lot_id' => $bl['lot_id'],
            'monthly_rate' => $bl['monthly_rate'],
            'slaughter_date' => $bl['slaughter_date'],
            'allocated_amount' => $allocatedH,
            'allocated_animals' => intval($bl['animal_count']),
        ];
    }
    $headBalanceTargetDate = $date;
    foreach ($allLiquidationsNow as $liqRow) {
        if (!empty($liqRow['date']) && $liqRow['date'] > $headBalanceTargetDate) {
            $headBalanceTargetDate = $liqRow['date'];
        }
    }
    $headBalanceMap = computeLotBalances($balanceLotsForHead, $allLiquidationsNow, $partnership['start_date'], $headBalanceTargetDate);

    $stmtUpdateLotRateOnly = $pdo->prepare("UPDATE partnership_lots SET monthly_rate = ? WHERE partnership_id = ? AND lot_id = ?");
    $stmtUpdateLotRateProj = $pdo->prepare("UPDATE partnership_lots SET monthly_rate = ?, projected_value = ? WHERE partnership_id = ? AND lot_id = ?");

    foreach ($allLots as $lot) {
        $lid = intval($lot['lot_id']);

        $remainingHead = isset($headBalanceMap[$lid])
            ? intval($headBalanceMap[$lid]['balance_animals'])
            : 0;

        if (isset($lotHasHead[$lid]) && $remainingHead > 0) {
            $newProjectedValue = $valuePerHead * $remainingHead;
            $stmtUpdateLotRateProj->execute([
                $newMonthlyRate,
                $newProjectedValue,
                $partnership_id,
                $lid
            ]);
        } else {
            $stmtUpdateLotRateOnly->execute([
                $newMonthlyRate,
                $partnership_id,
                $lid
            ]);
        }
    }
}

// API endpoint for adding liquidation
if (isset($_GET['action']) && $_GET['action'] === 'add_liquidation') {
    header('Content-Type: application/json');

    try {
        if (!isset($_POST['partnership_id']) || !isset($_POST['date']) || !isset($_POST['amount_total'])) {
            echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
            exit;
        }

        $partnership_id = $_POST['partnership_id'];
        $date = $_POST['date'];
        $amount_total = floatval($_POST['amount_total']);
        $is_settlement = isset($_POST['is_settlement']) ? intval($_POST['is_settlement']) : 0;
        $quantity = !empty($_POST['quantity']) ? intval($_POST['quantity']) : null;
        $lot_quantities = [];
        if (!empty($_POST['lot_quantities'])) {
            $decodedQuantities = json_decode($_POST['lot_quantities'], true);
            if (is_array($decodedQuantities)) {
                foreach ($decodedQuantities as $lotId => $qty) {
                    $lotId = intval($lotId);
                    $qty = intval($qty);
                    if ($lotId > 0 && $qty > 0) {
                        $lot_quantities[$lotId] = $qty;
                    }
                }
            }
        }
        if (!empty($lot_quantities)) {
            $quantity = array_sum($lot_quantities);
        }

        // Parse lot_ids from frontend (JSON array or legacy single lot_id)
        $lot_ids = [];
        if (!empty($_POST['lot_ids'])) {
            $decoded = json_decode($_POST['lot_ids'], true);
            if (is_array($decoded)) {
                $lot_ids = array_map('intval', array_filter($decoded));
            }
        }
        // Legacy fallback: single lot_id field
        if (empty($lot_ids) && !empty($_POST['lot_id'])) {
            $lot_ids = [intval($_POST['lot_id'])];
        }
        if (!empty($lot_quantities) && !empty($lot_ids)) {
            $selectedLotIds = array_flip($lot_ids);
            foreach (array_keys($lot_quantities) as $lotId) {
                if (!isset($selectedLotIds[$lotId])) {
                    unset($lot_quantities[$lotId]);
                }
            }
            $quantity = !empty($lot_quantities) ? array_sum($lot_quantities) : null;
        }

        // Fetch partnership details
        $stmt = $pdo->prepare("SELECT created_by, investor_id, start_date, total_value FROM partnerships WHERE id = ?");
        $stmt->execute([$partnership_id]);
        $partnership = $stmt->fetch();

        if (!$partnership) {
            echo json_encode(['success' => false, 'message' => 'Parceria não encontrada']);
            exit;
        }

        // Permission check
        $isInvestor = ($_SESSION['partner_id'] && $_SESSION['partner_id'] == $partnership['investor_id']);
        if ($partnership['created_by'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin' && !$isInvestor) {
            echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para esta ação.']);
            exit;
        }

        require_once __DIR__ . '/financial_calculations.php';

        // Fetch ALL lots for this partnership ordered by slaughter_date ASC
        $stmtLots = $pdo->prepare("SELECT pl.lot_id, pl.monthly_rate, pl.slaughter_date, pl.projected_value, l.animal_count 
                                    FROM partnership_lots pl 
                                    JOIN lots l ON pl.lot_id = l.id
                                    WHERE pl.partnership_id = ? 
                                    ORDER BY pl.slaughter_date ASC");
        $stmtLots->execute([$partnership_id]);
        $allLots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

        // Fetch existing liquidations (full columns so we can compute per-lot
        // remaining balances, not just the partnership rolling balance).
        $stmtAll = $pdo->prepare("SELECT lot_id, date, amount_total, amount_principal, quantity, is_settlement FROM partnership_liquidations WHERE partnership_id = ?");
        $stmtAll->execute([$partnership_id]);
        $existingLiquidations = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

        // Calculate state just BEFORE this new payment
        $state = calculatePartnershipState($partnership, $allLots, $existingLiquidations, $date);

        // Check if payment covers entire balance (auto-settlement)
        if ($amount_total >= (floatval($state['current_balance']) - 0.01)) {
            $is_settlement = 1;
        }

        // --- Dedicated path: explicit head count PER LOT ---
        // When the user informed a head count per lot (lot_quantities), the
        // liquidation must be written EXACTLY to those lots, each carrying its own
        // informed head count, splitting the total amount across them in proportion
        // to the informed head. It must NOT spill over to other lots by value
        // capacity (that overflow logic is what dropped the head count when the
        // selected lots had a zero value-balance under the old per-value model).
        if (!$is_settlement && !empty($lot_quantities)) {
            // Keep only lots that belong to this partnership.
            $lotInfoById = [];
            foreach ($allLots as $bl) {
                $lotInfoById[intval($bl['lot_id'])] = $bl;
            }
            $qtyLots = [];
            $totalQtyHead = 0;
            foreach ($lot_quantities as $qLotId => $qHead) {
                $qLotId = intval($qLotId);
                $qHead = intval($qHead);
                if ($qHead > 0 && isset($lotInfoById[$qLotId])) {
                    $qtyLots[$qLotId] = $qHead;
                    $totalQtyHead += $qHead;
                }
            }

            if ($totalQtyHead > 0) {
                // Accrued interest of the current period (to split as interest part).
                $accruedSinceLast = 0;
                if (!empty($state['events'])) {
                    $lastEvent = end($state['events']);
                    $accruedSinceLast = $lastEvent['interest_accrued'] ?? 0;
                }

                $sqlInsertQ = "INSERT INTO partnership_liquidations (partnership_id, lot_id, date, amount_principal, amount_interest, amount_total, balance_after, is_settlement, quantity)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmtInsertQ = $pdo->prepare($sqlInsertQ);

                $remainingAmountQ = $amount_total;
                $allocatedAmountQ = 0;
                $insertedCountQ = 0;
                $lotIdsQ = array_keys($qtyLots);
                $lastLotIdQ = end($lotIdsQ);

                foreach ($qtyLots as $qLotId => $qHead) {
                    // Split total amount proportional to informed head; last lot
                    // absorbs the rounding remainder so the sum matches exactly.
                    if ($qLotId === $lastLotIdQ) {
                        $lotAmount = max(0, $amount_total - $allocatedAmountQ);
                    } else {
                        $lotAmount = round($amount_total * ($qHead / $totalQtyHead), 2);
                        $allocatedAmountQ += $lotAmount;
                    }

                    // Interest part proportional to head; principal is the remainder.
                    $lotInterest = $accruedSinceLast * ($qHead / $totalQtyHead);
                    $lotPrincipal = $lotAmount - $lotInterest;

                    $remainingAmountQ -= $lotAmount;
                    $balanceAfterQ = max(0, floatval($state['current_balance']) - ($amount_total - $remainingAmountQ));

                    $stmtInsertQ->execute([
                        $partnership_id,
                        $qLotId,
                        $date,
                        $lotPrincipal,
                        $lotInterest,
                        $lotAmount,
                        $balanceAfterQ,
                        0,
                        $qHead
                    ]);
                    $insertedCountQ++;
                }

                // Reuse the single-rate recalculation that runs for normal
                // liquidations by jumping to it via the shared code below. We
                // replicate that block here because we exit early.
                recalcPartnershipSingleRate($pdo, $partnership, $partnership_id, $allLots, $date);

                $lotWord = $insertedCountQ > 1
                    ? "liquidações registradas em {$insertedCountQ} lotes"
                    : "Liquidação registrada com sucesso";
                echo json_encode(['success' => true, 'message' => $lotWord]);
                exit;
            }
        }

        // Determine which lots to distribute across
        $targetLots = [];
        if (!empty($lot_ids)) {
            // User selected specific lots - filter and keep order by slaughter_date.
            // These define WHERE the liquidation starts.
            foreach ($allLots as $lot) {
                if (in_array(intval($lot['lot_id']), $lot_ids)) {
                    $targetLots[] = $lot;
                }
            }
            // Overflow handling: if the amount exceeds the selected lots, the
            // remaining value must spill over into the OTHER lots of the
            // partnership, in slaughter_date order (nearest first). We append
            // the non-selected lots after the selected ones. The distribution
            // loop stops once the amount is exhausted, so these extra lots only
            // receive a record when there is leftover value to absorb.
            foreach ($allLots as $lot) {
                if (!in_array(intval($lot['lot_id']), $lot_ids)) {
                    $targetLots[] = $lot;
                }
            }
        } else {
            // No lots selected: use ALL lots ordered by slaughter_date ASC
            $targetLots = $allLots;
        }

        // Compute the REMAINING balance of each lot at the liquidation date,
        // discounting liquidations already made on that lot. This is essential
        // for the overflow logic: a lot that is already (over)liquidated has
        // little or no remaining capacity, so a new payment must spill over to
        // the next lot by due date instead of piling up on an exhausted lot.
        $balanceLots = [];
        foreach ($allLots as $bl) {
            $monthsLotB = calculateMonthsBetween($partnership['start_date'], $bl['slaughter_date']);
            $allocatedB = calculateAllocatedAmount(floatval($bl['projected_value']), floatval($bl['monthly_rate']), $monthsLotB);
            $balanceLots[] = [
                'lot_id' => $bl['lot_id'],
                'monthly_rate' => $bl['monthly_rate'],
                'allocated_amount' => $allocatedB,
                'allocated_animals' => intval($bl['animal_count']),
            ];
        }
        $lotBalanceMap = computeLotBalances($balanceLots, $existingLiquidations, $partnership['start_date'], $date);

        // For each target lot determine how much it can still absorb (remaining
        // balance) and its allocated principal at the liquidation date.
        foreach ($targetLots as &$tl) {
            $monthsLot = calculateMonthsBetween($partnership['start_date'], $date);
            $allocated = calculateAllocatedAmount(floatval($tl['projected_value']), floatval($tl['monthly_rate']), 
                         calculateMonthsBetween($partnership['start_date'], $tl['slaughter_date']));
            $tl['allocated_principal'] = $allocated;
            // Full projected value at date (kept for reference)
            $tl['projected_at_date'] = $allocated * pow((1 + floatval($tl['monthly_rate']) / 100), $monthsLot);
            // Remaining capacity = value still owed on this lot at the date.
            $tl['remaining_at_date'] = isset($lotBalanceMap[$tl['lot_id']])
                ? floatval($lotBalanceMap[$tl['lot_id']]['balance_value'])
                : $tl['projected_at_date'];
        }
        unset($tl);

        $lotAmountOverrides = [];
        $quantifiedCapacity = 0;
        $quantifiedCapByLot = [];
        if (!empty($lot_quantities)) {
            foreach ($targetLots as $tl) {
                $tlLotId = intval($tl['lot_id']);
                if (!isset($lot_quantities[$tlLotId])) {
                    continue;
                }
                $cap = max(0, floatval($tl['remaining_at_date']));
                if ($cap <= 0.01) {
                    continue;
                }
                $quantifiedCapByLot[$tlLotId] = $cap;
                $quantifiedCapacity += $cap;
            }

            if ($quantifiedCapacity > 0) {
                $amountForQuantifiedLots = min($amount_total, $quantifiedCapacity);
                $allocatedOverride = 0;
                $quantifiedLotIds = array_keys($quantifiedCapByLot);
                $lastQuantifiedLotId = end($quantifiedLotIds);
                foreach ($quantifiedCapByLot as $tlLotId => $cap) {
                    if ($tlLotId === $lastQuantifiedLotId) {
                        $lotAmountOverrides[$tlLotId] = max(0, $amountForQuantifiedLots - $allocatedOverride);
                    } else {
                        $overrideAmount = round($amountForQuantifiedLots * ($cap / $quantifiedCapacity), 2);
                        $lotAmountOverrides[$tlLotId] = $overrideAmount;
                        $allocatedOverride += $overrideAmount;
                    }
                }
            }
        }

        // Calculate total remaining value across the lots that will ACTUALLY be
        // reached by this payment. Because targetLots may include overflow lots
        // that the amount never reaches (the distribution loop stops once the
        // value is exhausted), summing every lot here would distort the interest
        // proportion. We only accumulate lots until the running amount is
        // covered, mirroring the distribution loop below.
        $totalProjectedTarget = 0;
        $remainingForBase = $amount_total;
        foreach ($targetLots as $tl) {
            if ($remainingForBase <= 0.01) break;
            $cap = $tl['remaining_at_date'];
            $totalProjectedTarget += $cap;
            $remainingForBase -= $cap;
        }
        if (!empty($lotAmountOverrides) && $amount_total <= $quantifiedCapacity + 0.01) {
            $totalProjectedTarget = $quantifiedCapacity;
        }

        // Get paid principal so far (for settlement calculation)
        $stmtTotals = $pdo->prepare("SELECT SUM(amount_principal) as paid_principal FROM partnership_liquidations WHERE partnership_id = ?");
        $stmtTotals->execute([$partnership_id]);
        $paidData = $stmtTotals->fetch();
        $paidPrincipalSoFar = floatval($paidData['paid_principal'] ?? 0);
        $remainingPrincipal = floatval($partnership['total_value']) - $paidPrincipalSoFar;

        // Distribute the amount across lots by slaughter_date order
        $remaining = $amount_total;
        $remainingQty = $quantity;
        $insertedCount = 0;

        $sqlInsert = "INSERT INTO partnership_liquidations (partnership_id, lot_id, date, amount_principal, amount_interest, amount_total, balance_after, is_settlement, quantity) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtInsert = $pdo->prepare($sqlInsert);

        for ($i = 0; $i < count($targetLots); $i++) {
            $lot = $targetLots[$i];
            $isLastLot = ($i === count($targetLots) - 1);
            $lotId = intval($lot['lot_id']);
            $hasAmountOverride = isset($lotAmountOverrides[$lotId]);

            if ($remaining <= 0.01 && !$hasAmountOverride) break;

            // Capacity of this lot = value still owed on it at the date.
            $lotCapacity = $lot['remaining_at_date'];

            // Skip lots that are already fully liquidated (no capacity left),
            // unless this is the very last lot and there is still value to place
            // (in that case the last lot must absorb the leftover so the payment
            // is never lost).
            if ($lotCapacity <= 0.01 && !$isLastLot && !$hasAmountOverride) {
                continue;
            }

            // Determine how much this lot absorbs
            if ($hasAmountOverride) {
                $lotAmount = min($remaining, $lotAmountOverrides[$lotId]);
                if ($lotAmount <= 0.01) {
                    continue;
                }
            } else if ($isLastLot || $remaining <= $lotCapacity) {
                // Last lot or remaining fits: absorb everything left
                $lotAmount = $remaining;
            } else {
                // Consume this lot's remaining capacity, overflow continues
                $lotAmount = $lotCapacity;
            }

            // Determine quantity for this lot
            $lotQty = null;
            if (isset($lot_quantities[$lotId])) {
                $lotQty = $lot_quantities[$lotId];
            } else if (empty($lot_quantities) && $quantity !== null && $quantity > 0) {
                $lotAnimals = intval($lot['animal_count']);
                if ($isLastLot || $remaining <= $lotCapacity) {
                    // Last lot gets remaining quantity
                    $lotQty = $remainingQty;
                } else {
                    // Full lot: use lot's animal count (capped by remaining)
                    $lotQty = min($lotAnimals, $remainingQty ?? $lotAnimals);
                }
                if ($remainingQty !== null) {
                    $remainingQty = max(0, $remainingQty - ($lotQty ?? 0));
                }
            }

            // Calculate principal/interest split
            if ($is_settlement && $isLastLot) {
                // Final lot of settlement: absorb remaining principal
                $lotPrincipal = max(0, $remainingPrincipal);
                $lotInterest = $lotAmount - $lotPrincipal;
            } else {
                // Calculate accrued interest proportionally
                $accruedSinceLast = 0;
                if (!empty($state['events'])) {
                    $lastEvent = end($state['events']);
                    $accruedSinceLast = $lastEvent['interest_accrued'] ?? 0;
                }
                // Proportion of this lot relative to total remaining capacity
                $proportion = ($totalProjectedTarget > 0) ? ($lotCapacity / $totalProjectedTarget) : 0;
                $lotInterest = $accruedSinceLast * $proportion;
                $lotPrincipal = $lotAmount - $lotInterest;
            }

            // Balance after
            $remaining -= $lotAmount;
            $lotIsSettlement = ($is_settlement && $remaining < 0.01) ? 1 : 0;
            $balanceAfter = $lotIsSettlement ? 0 : max(0, $state['current_balance'] - ($amount_total - $remaining));

            // Track principal consumed
            $remainingPrincipal -= $lotPrincipal;

            $stmtInsert->execute([
                $partnership_id,
                $lot['lot_id'],
                $date,
                $lotPrincipal,
                $lotInterest,
                $lotAmount,
                $balanceAfter,
                $lotIsSettlement,
                $lotQty
            ]);
            $insertedCount++;
        }

        // Recalculate a SINGLE partnership monthly_rate + per-lot projected_value.
        if (!$is_settlement) {
            recalcPartnershipSingleRate($pdo, $partnership, $partnership_id, $allLots, $date);
        }

        // Settlement: update lot rates and projected values
        if ($is_settlement) {
            $stmtPaid = $pdo->prepare("SELECT SUM(amount_total) AS total_paid FROM partnership_liquidations WHERE partnership_id = ?");
            $stmtPaid->execute([$partnership_id]);
            $paidData = $stmtPaid->fetch();
            $totalPaid = floatval($paidData['total_paid'] ?? 0);

            $monthsSettled = calculateMonthsBetween($partnership['start_date'], $date);
            if ($monthsSettled > 0 && floatval($partnership['total_value']) > 0 && $totalPaid > 0) {
                $effectiveRate = (pow(($totalPaid / floatval($partnership['total_value'])), (1 / $monthsSettled)) - 1) * 100;

                $totalOriginalPrincipal = 0;
                foreach ($allLots as &$lot) {
                    $monthsLot = calculateMonthsBetween($partnership['start_date'], $lot['slaughter_date']);
                    $originalPrincipal = calculateAllocatedAmount(floatval($lot['projected_value']), floatval($lot['monthly_rate']), $monthsLot);
                    $lot['original_principal'] = $originalPrincipal;
                    $totalOriginalPrincipal += $originalPrincipal;
                }
                unset($lot);

                if ($totalOriginalPrincipal > 0) {
                    $stmtUpdateLot = $pdo->prepare("UPDATE partnership_lots SET monthly_rate = ?, slaughter_date = ?, projected_value = ? WHERE partnership_id = ? AND lot_id = ?");
                    foreach ($allLots as $lot) {
                        $proportion = floatval($lot['original_principal']) / $totalOriginalPrincipal;
                        $settledProjectedValue = $totalPaid * $proportion;
                        $stmtUpdateLot->execute([
                            $effectiveRate,
                            $date,
                            $settledProjectedValue,
                            $partnership_id,
                            $lot['lot_id']
                        ]);
                    }
                }
            }
        }

        $lotWord = $insertedCount > 1 ? "liquidações registradas em {$insertedCount} lotes" : "Liquidação registrada com sucesso";
        echo json_encode(['success' => true, 'message' => $lotWord]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao registrar liquidação: ' . $e->getMessage()]);
    }
    exit;
}

// API endpoint for deleting liquidation
if (isset($_GET['action']) && $_GET['action'] === 'delete_liquidation') {
    header('Content-Type: application/json');
    $id = $_GET['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID não fornecido']);
        exit;
    }

    try {
        // Fetch partnership details from liquidation
        $stmtLiq = $pdo->prepare("SELECT p.created_by, p.investor_id FROM partnership_liquidations pl JOIN partnerships p ON pl.partnership_id = p.id WHERE pl.id = ?");
        $stmtLiq->execute([$id]);
        $pData = $stmtLiq->fetch();

        if (!$pData) {
            echo json_encode(['success' => false, 'message' => 'Liquidação não encontrada']);
            exit;
        }

        // Permission check
        $isInvestor = ($_SESSION['partner_id'] && $_SESSION['partner_id'] == $pData['investor_id']);
        if ($pData['created_by'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin' && !$isInvestor) {
            echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para esta ação.']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM partnership_liquidations WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Liquidação excluída']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao excluir: ' . $e->getMessage()]);
    }
    exit;
}

// API endpoint for getting liquidations
if (isset($_GET['action']) && $_GET['action'] === 'get_liquidations') {
    header('Content-Type: application/json');
    $partnership_id = $_GET['partnership_id'] ?? null;

    if (!$partnership_id) {
        echo json_encode([]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM partnership_liquidations WHERE partnership_id = ? ORDER BY date DESC");
        $stmt->execute([$partnership_id]);
        $liquidations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($liquidations);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}
