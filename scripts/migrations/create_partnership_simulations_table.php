<?php
require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/simulation_rates.php';

try {
    sim_ensure_tables($pdo);
    echo "Table 'partnership_simulations' created successfully!\n";
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
?>
