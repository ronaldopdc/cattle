<?php
/**
 * NOVA LÓGICA DE CÁLCULO DE VALORES COM LIQUIDAÇÕES
 * 
 * Substituir a seção "// Calculate Values" (linhas ~194-257) em partnerships.php
 * 
 * Esta lógica implementa:
 * 1. Subtrai os principals liquidados do valor inicial de cada lote (proporcionalmente)
 * 2. Calcula juros compostos sobre o valor reduzido
 * 3. Soma o valor total de todas as liquidações ao resultado final
 */

// Calculate Values
foreach ($partnerships as &$p) {
    $p['current_value_calc'] = 0;
    $p['projected_value_calc'] = 0;
    $p['current_balance'] = 0;
    $p['projected_balance'] = 0;

    $liquidations = $partnershipLiquidations[$p['id']] ?? [];
    $total_liquidated_principal = 0;
    $total_liquidated_amount = 0;  // NOVO: Armazena o total liquidado (principal + juros)

    // Somar todos os principals e totais das liquidações
    foreach ($liquidations as $liq) {
        $total_liquidated_principal += floatval($liq['amount_principal']);
        $total_liquidated_amount += floatval($liq['amount_total']);  // NOVO
    }

    $lots = $partnershipLots[$p['id']] ?? [];

    // 1. Calculate Total Initial Principal (necessário para proporção)
    $total_initial_principal = 0;

    foreach ($lots as $lot) {
        $start = new DateTime($p['start_date']);
        $slaughter = new DateTime($lot['slaughter_date']);
        $intervalTotal = $start->diff($slaughter);
        $monthsTotal = $intervalTotal->y * 12 + $intervalTotal->m + ($intervalTotal->d / 30);
        if ($monthsTotal <= 0)
            $monthsTotal = 0.0001;

        $rate = floatval($lot['monthly_rate']);
        $projected = floatval($lot['projected_value']);

        // Compound Interest: Principal = Projected / (1 + Rate)^Months
        $principal = $projected / pow((1 + $rate / 100), $monthsTotal);

        $total_initial_principal += $principal;
    }

    // 2. Calculate Values (Current and Projected) with liquidations factored in
    foreach ($lots as $lot) {
        $start = new DateTime($p['start_date']);
        $slaughter = new DateTime($lot['slaughter_date']);
        $now = new DateTime();

        // Total Duration
        $intervalTotal = $start->diff($slaughter);
        $monthsTotal = $intervalTotal->y * 12 + $intervalTotal->m + ($intervalTotal->d / 30);
        if ($monthsTotal <= 0)
            $monthsTotal = 0.0001;

        $rate = floatval($lot['monthly_rate']);
        $projected = floatval($lot['projected_value']);

        // MODIFICADO: Calcular principal original
        $original_principal = $projected / pow((1 + $rate / 100), $monthsTotal);

        // NOVO: Calcular proporção deste lote no total
        $proportion = $total_initial_principal > 0 ? $original_principal / $total_initial_principal : 0;

        // NOVO: Reduzir proporcionalmente pelo principal liquidado
        $principal_reduction = $total_liquidated_principal * $proportion;

        // NOVO: Principal ajustado (após subtrair liquidações)
        $adjusted_principal = max(0, $original_principal - $principal_reduction);

        // Current Duration
        $intervalCurrent = $start->diff($now);
        $monthsCurrent = $intervalCurrent->y * 12 + $intervalCurrent->m + ($intervalCurrent->d / 30);
        if ($monthsCurrent < 0)
            $monthsCurrent = 0;

        // MODIFICADO: Current Value com principal ajustado
        $current = $adjusted_principal * pow((1 + $rate / 100), $monthsCurrent);

        // MODIFICADO: Projected Value com principal ajustado
        $projected_calc = $adjusted_principal * pow((1 + $rate / 100), $monthsTotal);

        $p['current_value_calc'] += $current;
        $p['projected_value_calc'] += $projected_calc;
    }

    // 3. Calculate Balances - MODIFICADO: Adicionar valores liquidados
    $p['current_balance'] = $p['current_value_calc'] + $total_liquidated_amount;
    $p['projected_balance'] = $p['projected_value_calc'] + $total_liquidated_amount;
}
unset($p);
