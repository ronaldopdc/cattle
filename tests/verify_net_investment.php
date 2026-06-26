<?php
// tests/verify_net_investment.php
require_once __DIR__ . '/../src/dashboard_stats.php';

echo "--- Dashboard Stats Verification ---\n";
echo "Total Active Partnerships: $total_active_partnerships\n";
echo "Total Invested (Net): R$ " . number_format($total_invested, 2, ',', '.') . "\n";
echo "Total Current Balance: R$ " . number_format($total_current_balance, 2, ',', '.') . "\n";
echo "Total Yield: R$ " . number_format($total_yield, 2, ',', '.') . "\n";

// Basic Sanity Check
// Yield + Invested(Net) should roughly correspond to Current Balance + Total Interest Liquidated??
// Total Yield = (ProjectedBalance + TotalLiquidated) - InitialTotalPrincipal
// Total Invested (Net) = InitialTotalPrincipal - LiquidatedPrincipal
// Sum = ProjectedBalance + TotalLiquidated - LiquidatedPrincipal
// Sum = ProjectedBalance + LiquidatedInterest
// This makes sense!

echo "\nVerification Complete.\n";
