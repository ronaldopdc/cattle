<?php
// Debug script to simulate calculating months and interest
$start_date = '2025-11-24';
$calc_date = '2025-12-26';
$rate = 2.9;
$principal = 650000;

// 1. PHP Logic (Partnerships.php updated style)
$start = new DateTime($start_date);
$end = new DateTime($calc_date);
$interval = $start->diff($end);
$days = $interval->days;

echo "Start: $start_date\n";
echo "End: $calc_date\n";
echo "Days (PHP DateTime diff->days): $days\n";
echo "Months (Days/30): " . ($days / 30) . "\n";

$months = $days / 30;
$interest_factor = pow(1 + $rate / 100, $months);
$amount = $principal * $interest_factor;
$interest = $amount - $principal;

echo "Calculated Interest (PHP): " . number_format($interest, 2) . "\n";

// 2. Simulate JS Logic (Manual)
// Start: 2025-11-24
// Target: 2025-12-26
// Diff ms: (32 days) * 86400000
// Days: 32
// Months: 1.06666
// Result should trigger same.

echo "User Target: 20125.51\n";
