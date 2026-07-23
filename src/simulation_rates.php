<?php
/**
 * Regras de taxa e cálculo da Simulação de Parceria (Rialma / Cattle Invest).
 *
 * Tabela de participação MENSAL por prazo (quanto menor o prazo, maior a taxa,
 * incentivando o proprietário a girar o capital mais vezes por ano):
 *   - até  30 dias -> 2,9% ao mês
 *   - até  60 dias -> 2,8% ao mês
 *   - até  90 dias -> 2,7% ao mês
 *   - 120 dias ou mais -> 2,6% ao mês
 *
 * O cálculo usa juros compostos com a mesma regra oficial do sistema:
 *   meses = dias / 30  e  valor_final = base * (1 + taxa/100)^meses
 * (ver src/financial_calculations.php e a skill cattle-system).
 */

if (!function_exists('sim_rate_for_days')) {

    /** Retorna a taxa mensal (%) de acordo com o número de dias. */
    function sim_rate_for_days($days)
    {
        $days = (float) $days;
        if ($days <= 30) {
            return 2.9;
        }
        if ($days <= 60) {
            return 2.8;
        }
        if ($days <= 90) {
            return 2.7;
        }
        return 2.6; // 120 dias ou mais
    }

    /** Faixas da tabela, para exibição na tela e no PDF. */
    function sim_rate_table()
    {
        return [
            ['days' => 120, 'rate' => 2.6, 'label' => '120 dias'],
            ['days' => 90,  'rate' => 2.7, 'label' => '90 dias'],
            ['days' => 60,  'rate' => 2.8, 'label' => '60 dias'],
            ['days' => 30,  'rate' => 2.9, 'label' => '30 dias'],
        ];
    }

    /**
     * Calcula a simulação completa.
     *
     * @param float $numAnimals    Número de bois.
     * @param float $entryWeightKg Peso médio de entrada por animal (kg).
     * @param float $arrobaPrice   Preço da arroba (R$).
     * @param float $days          Prazo de participação (dias).
     * @return array
     */
    function sim_calculate($numAnimals, $entryWeightKg, $arrobaPrice, $days)
    {
        $numAnimals    = max(0.0, (float) $numAnimals);
        $entryWeightKg = max(0.0, (float) $entryWeightKg);
        $arrobaPrice   = max(0.0, (float) $arrobaPrice);
        $days          = max(0.0, (float) $days);

        $rate = sim_rate_for_days($days);

        // Regra do sistema: arrobas = (peso_kg * cabeças) / 30 (arroba de 30 kg).
        $arrobasPerAnimal = $entryWeightKg / 30.0;
        $totalArrobas     = $arrobasPerAnimal * $numAnimals;
        $base             = $totalArrobas * $arrobaPrice;

        $months = $days > 0 ? $days / 30.0 : 0.0;

        // Juros compostos (regra oficial).
        $final = $months > 0 ? $base * pow(1 + $rate / 100, $months) : $base;
        $totalParticipation = $final - $base;

        // Cronograma mês a mês (capitalização composta).
        $schedule   = [];
        $balance    = $base;
        $fullMonths = (int) floor($months);
        for ($m = 1; $m <= $fullMonths; $m++) {
            $interest = $balance * ($rate / 100);
            $balance += $interest;
            $schedule[] = [
                'month'         => $m,
                'participation' => $interest,
                'balance'       => $balance,
                'partial'       => false,
                'days'          => 30,
            ];
        }
        $frac = $months - $fullMonths;
        if ($frac > 0.0001) {
            $interest = $balance * (pow(1 + $rate / 100, $frac) - 1);
            $balance += $interest;
            $schedule[] = [
                'month'         => $fullMonths + 1,
                'participation' => $interest,
                'balance'       => $balance,
                'partial'       => true,
                'days'          => (int) round($frac * 30),
            ];
        }

        $avgMonthly = $months > 0 ? $totalParticipation / $months : 0.0;

        return [
            'rate'                 => $rate,
            'arrobas_per_animal'   => $arrobasPerAnimal,
            'total_arrobas'        => $totalArrobas,
            'base'                 => $base,
            'months'               => $months,
            'days'                 => $days,
            'final'                => $final,
            'total_participation'  => $totalParticipation,
            'avg_monthly'          => $avgMonthly,
            'schedule'             => $schedule,
        ];
    }

    /**
     * Garante a existência da tabela de simulações. Idempotente.
     * Evita a necessidade de rodar a migração manualmente.
     */
    function sim_ensure_tables(PDO $pdo)
    {
        $sql = "CREATE TABLE IF NOT EXISTS partnership_simulations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            num_animals INT NOT NULL,
            entry_weight DECIMAL(10,2) NOT NULL,
            arroba_price DECIMAL(10,2) NOT NULL,
            days INT NOT NULL,
            monthly_rate DECIMAL(5,2) NOT NULL,
            total_arrobas DECIMAL(15,2) NOT NULL,
            base_value DECIMAL(15,2) NOT NULL,
            avg_monthly_cost DECIMAL(15,2) NOT NULL,
            total_participation DECIMAL(15,2) NOT NULL,
            final_value DECIMAL(15,2) NOT NULL,
            client_name VARCHAR(255) NULL,
            client_phone VARCHAR(60) NULL,
            client_email VARCHAR(255) NULL,
            token VARCHAR(128) NULL,
            partner_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_partner (partner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);
    }

    /** Formata valor monetário em Real (R$ 1.234,56). */
    function sim_money($value)
    {
        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }

    /**
     * Monta a URL base pública (mesma lógica de api_generate_token.php) para
     * gerar links absolutos de simulação/cadastro que funcionam no QR Code.
     */
    function sim_base_url()
    {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = rtrim(dirname($uri), '/\\');

        if (basename($path) === 'src') {
            $baseUrl = "$protocol://$host" . $path;
        } else {
            $baseUrl = "$protocol://$host" . $path . "/src";
        }
        $baseUrl = preg_replace('/([^:])\/\//', '$1/', $baseUrl);
        return rtrim($baseUrl, '/');
    }
}
