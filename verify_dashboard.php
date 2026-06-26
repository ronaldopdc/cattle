<?php
require_once 'src/config.php';
require_once 'src/dashboard_stats.php';

echo "Total Active Partnerships: " . $total_active_partnerships . "\n";
echo "Total Invested: " . $total_invested . "\n";
echo "Total Current Balance: " . $total_current_balance . "\n";
echo "Total Projected Balance: " . $total_projected_balance . "\n";
echo "Total Yield: " . $total_yield . "\n";
?>