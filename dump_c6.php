<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['partner_id'] = null;

require_once __DIR__ . '/src/dashboard_stats.php';

echo "--- ROWS FOR CONTRACT #6 ---\n";
$sum = 0;
foreach ($yield_report_data as $row) {
    if ($row['partnership_id'] == 6) {
        printf(
            "%s - %s | Yield: %.2f | Principal: %.2f\n",
            $row['start_date'],
            $row['end_date'],
            $row['yield'],
            $row['base_principal']
        );
        $sum += $row['yield'];
    }
}
echo "TOTAL SUM: " . number_format($sum, 2, '.', '') . "\n";
?>