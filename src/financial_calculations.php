<?php
// src/financial_calculations.php

/**
 * Calculates the state of a partnership (rolling balance) at a given date.
 * 
 * @param array $partnership Partnership data (start_date, total_value)
 * @param array $lots List of lots (monthly_rate, slaughter_date)
 * @param array $liquidations List of liquidations (date, amount_total)
 * @param string|null $targetDate Date to calculate up to (default: now)
 * @return array State details
 */
/**
 * Calculates the allocated amount for a lot based on its projected value and rate.
 * Formula: Present Value = Future Value / (1 + r)^n
 */
function calculateAllocatedAmount($projected, $rate, $months)
{
    if ($months <= 0)
        $months = 0.0001;
    return $projected / pow((1 + $rate / 100), $months);
}

/**
 * Calculates the number of months between two dates using the system-wide rule: (Total Days / 30).
 */
function calculateMonthsBetween($start, $end)
{
    $startDate = new DateTime($start);
    $endDate = new DateTime($end);
    $interval = $startDate->diff($endDate);
    $days = $interval->days;
    return $days / 30;
}

/**
 * Calculates the state of a partnership (rolling balance) at a given date.
 * 
 * @param array $partnership Partnership data (start_date, total_value)
 * @param array $lots List of lots (monthly_rate, slaughter_date)
 * @param array $liquidations List of liquidations (date, amount_total)
 * @param string|null $targetDate Date to calculate up to (default: now)
 * @return array State details
 */
function calculatePartnershipState($partnership, $lots, $liquidations, $targetDate = null)
{
    if (!$targetDate) {
        $targetDate = date('Y-m-d');
    }

    $startDate = $partnership['start_date'];
    $initialBalance = floatval($partnership['total_value']);

    // Sort liquidations by date
    usort($liquidations, function ($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });

    // Helper to get rate based on furthest slaughter date
    $getRateForDate = function ($dateStr) use ($lots) {
        $date = new DateTime($dateStr);
        // Sort lots by slaughter_date ASC
        $sortedLots = $lots;
        usort($sortedLots, function ($a, $b) {
            return strtotime($a['slaughter_date']) - strtotime($b['slaughter_date']);
        });

        // Find first lot that covers the date (ends on or after it)
        foreach ($sortedLots as $l) {
            if (new DateTime($l['slaughter_date']) >= $date) {
                return floatval($l['monthly_rate']);
            }
        }
        // Fallback to last lot (furthest date)
        if (count($sortedLots) > 0) {
            return floatval($sortedLots[count($sortedLots) - 1]['monthly_rate']);
        }
        return 0;
    };

    $currentBalance = $initialBalance;
    $lastDate = new DateTime($startDate);
    $targetDateTime = new DateTime($targetDate);

    $events = []; // Track calculation history

    // Process all liquidations that happened BEFORE or ON target date
    foreach ($liquidations as $liq) {
        $liqDate = new DateTime($liq['date']);

        if ($liqDate > $targetDateTime) {
            continue; // Ignore future liquidations relative to target
        }

        // Calculate interest since last event
        $interval = $lastDate->diff($liqDate);
        $days = $interval->days;

        // Ensure we don't go backwards
        if ($liqDate < $lastDate) {
            $days = 0;
        }

        $months = calculateMonthsBetween($lastDate->format('Y-m-d'), $liq['date']);
        $rate = $getRateForDate($liq['date']);

        // Compound Interest
        $factor = pow((1 + $rate / 100), $months);
        $newGrossBalance = $currentBalance * $factor;
        $accruedInterest = $newGrossBalance - $currentBalance;

        $amountTotal = floatval($liq['amount_total']);
        $isSettlement = !empty($liq['is_settlement']);

        if ($isSettlement || $amountTotal >= ($newGrossBalance - 0.01)) {
            // Quitação do contrato: o valor pago é o valor final.
            // O saldo bruto antes do pagamento se ajusta para ser exatamente o valor pago, zerando o saldo.
            // O juros acumulado neste período absorve a diferença (ganho/perda).
            $accruedInterest = $amountTotal - $currentBalance;
            $newGrossBalance = $amountTotal;
            $postLiquidationBalance = 0;
            $principalComponent = $currentBalance;
            $interestComponent = $accruedInterest;
        } else {
            // Breakdown Payment
            $interestComponent = $accruedInterest;
            // If payment > interest, we reduce principal. 
            // If payment < interest, principal grows (negative reduction).
            // Actually, normally: Payment pays Interest first, then Principal.
            // But here we just track the Balance.
            // For reporting:
            // Reported Interest Paid = min(Total Payment, Accrued Interest)?
            // Or just "Interest Portion of this Event"?
            // Let's stick to simple math: 
            $principalComponent = $amountTotal - $interestComponent;
            $postLiquidationBalance = $newGrossBalance - $amountTotal;
        }

        $events[] = [
            'type' => 'liquidation',
            'date' => $liq['date'],
            'days_since_last' => $days,
            'months' => $months,
            'rate' => $rate,
            'balance_before' => $currentBalance,
            'interest_accrued' => $accruedInterest,
            'gross_balance' => $newGrossBalance,
            'payment_total' => $amountTotal,
            // 'payment_interest' => $interestComponent, // This can be confusing if negative principal
            'balance_after' => $postLiquidationBalance
        ];

        $currentBalance = $postLiquidationBalance;
        $lastDate = $liqDate;
    }

    // Now calculate from last event to target date (Accrual Only)
    // Only if target is after start and balance is greater than zero
    if ($targetDateTime >= new DateTime($startDate) && $currentBalance >= 0.01) {
        // Do not limit accrual to latest slaughter date. Yield continues until liquidated/today.
        $effectiveTargetDateTime = $targetDateTime;

        if ($lastDate < $effectiveTargetDateTime) {
            $interval = $lastDate->diff($effectiveTargetDateTime);
            $days = $interval->days;
            $months = calculateMonthsBetween($lastDate->format('Y-m-d'), $effectiveTargetDateTime->format('Y-m-d'));
            $rate = $getRateForDate($effectiveTargetDateTime->format('Y-m-d'));

            $factor = pow((1 + $rate / 100), $months);
            $finalGrossBalance = $currentBalance * $factor;
            $accruedInterest = $finalGrossBalance - $currentBalance;

            $events[] = [
                'type' => 'accrual',
                'date' => $targetDate,
                'days_since_last' => $days,
                'months' => $months,
                'rate' => $rate,
                'balance_before' => $currentBalance,
                'interest_accrued' => $accruedInterest,
                'gross_balance' => $finalGrossBalance,
                'balance_after' => $finalGrossBalance
            ];

            $currentBalance = $finalGrossBalance;
            $lastDate = $targetDateTime;
        }
    }

    return [
        'current_balance' => $currentBalance,
        'events' => $events
    ];
}

/**
 * Computes per-lot balances (value and cattle head count) at a target date.
 *
 * For each lot we capitalize its allocated principal up to the target date and subtract
 * the liquidations attributed to that lot. Liquidations that carry an explicit lot_id are
 * assigned directly; liquidations WITHOUT a lot_id are distributed across lots proportionally
 * to each lot's allocated principal (so the cattle/value balance always decreases when there
 * is any liquidation, even if the user did not inform the slaughtered head count).
 *
 * The slaughtered head count per lot uses the informed quantity when available; otherwise it
 * is estimated proportionally to the liquidated principal vs the lot's allocated principal.
 *
 * @param array  $lots        Each lot must contain: lot_id, monthly_rate (or rate),
 *                            allocated_amount, allocated_animals.
 * @param array  $liquidations Raw liquidation rows (lot_id, amount_total, amount_principal, quantity, date).
 * @param string $startDate   Partnership start date (Y-m-d).
 * @param string|null $targetDate Date to evaluate up to (default: today).
 * @return array Map of lot_id => [
 *                   'balance_value' => float,
 *                   'balance_animals' => int,
 *                   'liquidated_value' => float,
 *                   'liquidated_principal' => float,
 *                   'slaughtered' => int
 *               ]
 */
function computeLotBalances($lots, $liquidations, $startDate, $targetDate = null)
{
    if (!$targetDate) {
        $targetDate = date('Y-m-d');
    }

    // Total allocated principal across all lots (used to distribute unassigned liquidations)
    $totalAllocatedPrincipal = 0;
    foreach ($lots as $lot) {
        $totalAllocatedPrincipal += floatval($lot['allocated_amount'] ?? 0);
    }

    // Accumulators per lot
    $liqAmount = [];      // amount_total assigned to lot (nominal sum, for display)
    $liqPrincipal = [];   // amount_principal assigned to lot
    $liqQty = [];         // explicit quantity assigned to lot
    $liqPrincipalForQtyEstimate = []; // principal from rows without explicit quantity
    $liqEvents = [];      // per-lot list of payments [date, amount] for rolling balance

    foreach ($lots as $lot) {
        $lid = $lot['lot_id'];
        $liqAmount[$lid] = 0;
        $liqPrincipal[$lid] = 0;
        $liqQty[$lid] = 0;
        $liqPrincipalForQtyEstimate[$lid] = 0;
        $liqEvents[$lid] = [];
    }

    foreach ($liquidations as $liq) {
        if (isset($liq['date']) && $liq['date'] > $targetDate) {
            continue; // only effective liquidations
        }

        $amount = floatval($liq['amount_total'] ?? 0);
        $principal = floatval($liq['amount_principal'] ?? 0);
        $qty = intval($liq['quantity'] ?? 0);
        $lid = $liq['lot_id'] ?? null;
        $liqDate = $liq['date'] ?? $startDate;

        if ($lid !== null && isset($liqAmount[$lid])) {
            // Directly attributed to a lot
            $liqAmount[$lid] += $amount;
            $liqPrincipal[$lid] += $principal;
            $liqQty[$lid] += $qty;
            if ($qty <= 0) {
                $liqPrincipalForQtyEstimate[$lid] += $principal;
            }
            $liqEvents[$lid][] = ['date' => $liqDate, 'amount' => $amount];
        } else {
            // No lot_id: distribute proportionally to allocated principal
            if ($totalAllocatedPrincipal > 0) {
                foreach ($lots as $lot) {
                    $l = $lot['lot_id'];
                    $share = floatval($lot['allocated_amount'] ?? 0) / $totalAllocatedPrincipal;
                    $principalShare = $principal * $share;
                    $liqAmount[$l] += $amount * $share;
                    $liqPrincipal[$l] += $principalShare;
                    $liqQty[$l] += $qty * $share; // fractional; rounded later only if used
                    if ($qty <= 0) {
                        $liqPrincipalForQtyEstimate[$l] += $principalShare;
                    }
                    $liqEvents[$l][] = ['date' => $liqDate, 'amount' => $amount * $share];
                }
            }
        }
    }

    $months = calculateMonthsBetween($startDate, $targetDate);
    if ($months < 0) $months = 0;

    // Process lots in slaughter_date order (nearest first) so that an
    // over-liquidated lot (negative balance) can carry its excess over to the
    // next lot, instead of being clipped to zero and lost. This keeps the sum
    // of lot balances aligned with the partnership rolling balance.
    $orderedLots = $lots;
    usort($orderedLots, function ($a, $b) {
        return strtotime($a['slaughter_date'] ?? '9999-12-31') - strtotime($b['slaughter_date'] ?? '9999-12-31');
    });

    $result = [];
    $carryOver = 0; // negative excess carried from an over-liquidated lot
    foreach ($orderedLots as $idx => $lot) {
        $lid = $lot['lot_id'];
        $rate = floatval($lot['monthly_rate'] ?? $lot['rate'] ?? 0);
        $allocated = floatval($lot['allocated_amount'] ?? 0);
        $allocatedAnimals = intval($lot['allocated_animals'] ?? 0);
        $isLastOrdered = ($idx === count($orderedLots) - 1);

        // Value balance using a ROLLING calculation per lot, mirroring
        // calculatePartnershipState: start from the allocated principal,
        // capitalize compound interest between payment events, and subtract
        // each payment on its own date. This keeps the sum of lot balances
        // consistent with the partnership rolling balance (subtracting nominal
        // amounts without respecting payment dates would diverge).
        $events = $liqEvents[$lid];
        usort($events, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        $balance = $allocated;
        $lastDate = $startDate;
        foreach ($events as $ev) {
            $m = calculateMonthsBetween($lastDate, $ev['date']);
            if ($m < 0) $m = 0;
            $balance = $balance * pow((1 + $rate / 100), $m) - $ev['amount'];
            $lastDate = $ev['date'];
        }
        // Capitalize the remaining balance from the last event up to target date
        $mFinal = calculateMonthsBetween($lastDate, $targetDate);
        if ($mFinal < 0) $mFinal = 0;
        $balance = $balance * pow((1 + $rate / 100), $mFinal);

        // Apply any excess carried over from a previous over-liquidated lot.
        $balance += $carryOver;
        $carryOver = 0;

        if ($balance < 0) {
            // This lot is over-liquidated. Carry the negative excess to the next
            // lot (unless this is the last one, where it simply stays clipped).
            if (!$isLastOrdered) {
                $carryOver = $balance;
            }
            $balanceValue = 0;
        } else {
            $balanceValue = $balance;
        }

        // Cattle balance
        $explicitQty = $liqQty[$lid];
        $liquidatedPrincipal = $liqPrincipal[$lid];
        $principalForEstimate = $liqPrincipalForQtyEstimate[$lid];
        $slaughtered = 0;
        if ($explicitQty > 0) {
            $slaughtered += (int) round($explicitQty);
        }
        if ($allocated > 0 && $principalForEstimate > 0) {
            $slaughtered += (int) round($allocatedAnimals * ($principalForEstimate / $allocated));
        }
        // A fully settled lot (no value left) must show zero animals
        if ($balanceValue < 0.01) {
            $slaughtered = $allocatedAnimals;
        }
        $slaughtered = min($allocatedAnimals, $slaughtered);
        $balanceAnimals = max(0, $allocatedAnimals - $slaughtered);

        $result[$lid] = [
            'balance_value' => $balanceValue,
            'balance_animals' => $balanceAnimals,
            'liquidated_value' => $liqAmount[$lid],
            'liquidated_principal' => $liquidatedPrincipal,
            'slaughtered' => $slaughtered
        ];
    }

    return $result;
}

/**
 * Computes the remaining head (cattle) balance per lot independently of the lot's
 * current projected_value / monthly_rate.
 *
 * This mirrors the memory modal logic: start from each lot's INITIAL allocated head
 * count and subtract the slaughtered head. Slaughtered head come from:
 *   - explicit informed quantity on the liquidation (preferred), and
 *   - an estimate (principal / average principal per head) for liquidations without
 *     an informed head count.
 *
 * Because it never derives head from the (possibly updated) projected_value, this
 * stays correct even after a liquidation rewrites a lot's projected_value/rate.
 *
 * @param array  $lots          Each lot: lot_id, allocated_animals (initial head).
 * @param array  $liquidations  Raw rows: lot_id, amount_principal, quantity, date.
 * @param string $targetDate    Evaluate liquidations up to this date (inclusive).
 * @param float  $avgPrincipalPerAnimal Average principal per head for the partnership.
 * @return array Map of lot_id => ['balance_animals' => int, 'slaughtered' => int].
 */
function computeLotHeadBalances($lots, $liquidations, $targetDate, $avgPrincipalPerAnimal)
{
    $slaughteredExplicit = [];
    $slaughteredEstimated = [];
    foreach ($lots as $lot) {
        $lid = $lot['lot_id'];
        $slaughteredExplicit[$lid] = 0;
        $slaughteredEstimated[$lid] = 0;
    }

    // Total initial allocated principal (to distribute liquidations with no lot_id).
    $totalAllocatedPrincipal = 0;
    foreach ($lots as $lot) {
        $totalAllocatedPrincipal += floatval($lot['allocated_amount'] ?? 0);
    }

    foreach ($liquidations as $liq) {
        if (isset($liq['date']) && $targetDate !== null && $liq['date'] > $targetDate) {
            continue;
        }

        $qty = intval($liq['quantity'] ?? 0);
        $principal = floatval($liq['amount_principal'] ?? 0);
        $lid = $liq['lot_id'] ?? null;

        if ($lid !== null && isset($slaughteredExplicit[$lid])) {
            if ($qty > 0) {
                $slaughteredExplicit[$lid] += $qty;
            } else if ($avgPrincipalPerAnimal > 0 && $principal > 0) {
                $slaughteredEstimated[$lid] += ($principal / $avgPrincipalPerAnimal);
            }
        } else {
            // No lot_id: distribute proportionally to allocated principal.
            if ($totalAllocatedPrincipal > 0) {
                foreach ($lots as $lot) {
                    $l = $lot['lot_id'];
                    $share = floatval($lot['allocated_amount'] ?? 0) / $totalAllocatedPrincipal;
                    if ($qty > 0) {
                        $slaughteredExplicit[$l] += $qty * $share;
                    } else if ($avgPrincipalPerAnimal > 0 && $principal > 0) {
                        $slaughteredEstimated[$l] += (($principal * $share) / $avgPrincipalPerAnimal);
                    }
                }
            }
        }
    }

    $result = [];
    $surplusHead = 0;
    foreach ($lots as $lot) {
        $lid = $lot['lot_id'];
        $allocatedAnimals = intval($lot['allocated_animals'] ?? 0);
        $slaughtered = (int) round($slaughteredExplicit[$lid] + $slaughteredEstimated[$lid]);
        if ($slaughtered < 0) $slaughtered = 0;
        if ($slaughtered > $allocatedAnimals) {
            // Head informed beyond this lot's own size must NOT be discarded. A
            // liquidation is written to the lot that still holds VALUE, which is
            // not always the lot that still holds HEAD, so the excess belongs to
            // another lot of the same partnership. Keep it to reallocate below.
            $surplusHead += $slaughtered - $allocatedAnimals;
            $slaughtered = $allocatedAnimals;
        }
        $result[$lid] = [
            'balance_animals' => max(0, $allocatedAnimals - $slaughtered),
            'slaughtered' => $slaughtered
        ];
    }

    // Reallocate the excess head to the lots that still have head left (nearest
    // slaughter date first), mirroring the value carry-over of computeLotBalances().
    // Without this the partnership head balance stays frozen whenever a liquidation
    // lands on an already fully slaughtered lot, and the list disagrees with the
    // calculation memory (which never clipped per lot).
    if ($surplusHead > 0) {
        $orderedLots = $lots;
        usort($orderedLots, function ($a, $b) {
            return strtotime($a['slaughter_date'] ?? '9999-12-31') - strtotime($b['slaughter_date'] ?? '9999-12-31');
        });
        foreach ($orderedLots as $lot) {
            if ($surplusHead <= 0) break;
            $lid = $lot['lot_id'];
            $free = $result[$lid]['balance_animals'];
            if ($free <= 0) continue;
            $take = min($free, $surplusHead);
            $result[$lid]['balance_animals'] -= $take;
            $result[$lid]['slaughtered'] += $take;
            $surplusHead -= $take;
        }
    }

    return $result;
}

/**
 * Calculates the projected future balance of the partnership assuming no further liquidations.
 * 
 * @param float $currentBalance The current rolled balance
 * @param string $currentDate The date of the current balance
 * @param float $rate The weighted monthly rate
 * @param array $lots List of lots to find the furthest end date
 * @return float Projected Balance
 */
function calculateProjectedBalance($currentBalance, $currentDate, $rate, $lots, $liquidations = [])
{
    if (empty($lots))
        return $currentBalance;

    // Find the latest slaughter date (Projected End of Partnership)
    $maxDate = null;
    $totalProjectedValue = 0;
    foreach ($lots as $lot) {
        $totalProjectedValue += floatval($lot['projected_value']);
        $d = new DateTime($lot['slaughter_date']);
        if (!$maxDate || $d > $maxDate) {
            $maxDate = $d;
        }
    }

    // Rule: If no liquidations, the projected balance is exactly the sum of lot projections
    if (empty($liquidations)) {
        return $totalProjectedValue;
    }

    $cDate = new DateTime($currentDate);

    if ($cDate >= $maxDate) {
        return $currentBalance; // Already passed end date
    }

    $months = calculateMonthsBetween($currentDate, $maxDate->format('Y-m-d'));

    // Compound Interest forward
    $factor = pow((1 + $rate / 100), $months);
    return $currentBalance * $factor;
}

/**
 * Solves for the constant monthly rate (internal rate of return) that makes the
 * partnership's rolling balance land on zero at the settlement date, honoring the
 * DATE of every payment.
 *
 * Starting from the initial principal, the balance is capitalized forward at the
 * candidate rate between consecutive payment dates and each payment is subtracted
 * on its own date. Unlike the closed form (totalPaid / principal)^(1/months) - 1,
 * this accounts for the time value of early partial payments, so the recovered
 * rate matches the rate actually charged (i.e. the agreed rate when the interim
 * payments were exact). The closed form ignored payment timing and systematically
 * understated the rate whenever partial payments preceded the settlement.
 *
 * When there is a single payment on the settlement date, this reduces exactly to
 * the old closed form, so it is a strict generalization.
 *
 * @param float  $principal      Initial principal (partnership total_value).
 * @param array  $liquidations   Rows with 'date' and 'amount_total'.
 * @param string $startDate      Partnership start date (Y-m-d).
 * @param string $settlementDate Final settlement date (Y-m-d).
 * @return float|null Monthly rate in percent, or null if it cannot be solved.
 */
function calculateSettlementIRR($principal, $liquidations, $startDate, $settlementDate)
{
    if ($principal <= 0 || empty($liquidations)) {
        return null;
    }

    // Chronological order (earliest payment first).
    $payments = $liquidations;
    usort($payments, function ($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });

    // Residual balance at the settlement date for a candidate monthly rate.
    // Monotonically increasing in $rate: a higher rate accrues more interest and
    // leaves a larger residual, which lets us bracket the root by bisection.
    $residual = function ($rate) use ($principal, $payments, $startDate, $settlementDate) {
        $balance = $principal;
        $last = $startDate;
        foreach ($payments as $pmt) {
            $m = calculateMonthsBetween($last, $pmt['date']);
            if ($m < 0) $m = 0;
            $balance = $balance * pow((1 + $rate / 100), $m) - floatval($pmt['amount_total']);
            $last = $pmt['date'];
        }
        // Capitalize any remainder up to the settlement date (0 months when the
        // last payment is the settlement itself).
        $mFinal = calculateMonthsBetween($last, $settlementDate);
        if ($mFinal < 0) $mFinal = 0;
        return $balance * pow((1 + $rate / 100), $mFinal);
    };

    // (1 + rate/100) must stay positive, so the rate is bounded below by -100%.
    $lo = -99.0;
    $hi = 1000.0;
    $fLo = $residual($lo);
    $fHi = $residual($hi);
    // Cannot bracket a sign change: give up and let the caller fall back.
    if ($fLo > 0 || $fHi < 0) {
        return null;
    }
    for ($i = 0; $i < 200; $i++) {
        $mid = ($lo + $hi) / 2;
        if ($residual($mid) > 0) {
            $hi = $mid;
        } else {
            $lo = $mid;
        }
    }
    return ($lo + $hi) / 2;
}
