<?php
require 'src/config.php';

try {
    // $pdo is instantiated in config.php
    
    // Create lot_simulations table
    $sql_lot_simulations = "
    CREATE TABLE IF NOT EXISTS lot_simulations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lot_id INT NOT NULL,
        purchase_arroba_price DECIMAL(10,2),
        sale_arroba_price DECIMAL(10,2),
        expected_yield DECIMAL(5,4),
        freight_distance DECIMAL(10,2),
        freight_price_per_km DECIMAL(10,2),
        animals_per_truck INT,
        commission_percent DECIMAL(5,2),
        is_noventena BOOLEAN DEFAULT 0,
        vaccination_cost DECIMAL(10,2),
        eras_cost DECIMAL(10,2),
        other_extras_cost DECIMAL(10,2),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $pdo->exec($sql_lot_simulations);
    echo "Tabela lot_simulations criada.\n";

    // Create simulation_daily_costs table
    $sql_daily_costs = "
    CREATE TABLE IF NOT EXISTS simulation_daily_costs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        weight_start DECIMAL(10,2) NOT NULL,
        weight_end DECIMAL(10,2) NOT NULL,
        cost_per_day DECIMAL(10,2) NOT NULL,
        projected_gpd DECIMAL(10,2) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $pdo->exec($sql_daily_costs);
    echo "Tabela simulation_daily_costs criada.\n";
    
    // Inserir dados padrão da tabela de diárias
    $stmt = $pdo->query("SELECT COUNT(*) FROM simulation_daily_costs");
    if ($stmt->fetchColumn() == 0) {
        $insert_data = [
            [255, 270, 3.739316, 1.35],
            [270, 285, 3.903332, 1.35],
            [285, 300, 4.081016, 1.40],
            [300, 315, 4.245032, 1.40],
            [315, 330, 4.409048, 1.40],
            [330, 345, 4.573064, 1.40],
            [345, 360, 4.750748, 1.45],
            [360, 375, 4.914764, 1.45],
            [375, 390, 5.092448, 1.50],
            [390, 405,  5.256464, 1.50]
        ];
        
        $insert_sql = "INSERT INTO simulation_daily_costs (weight_start, weight_end, cost_per_day, projected_gpd) VALUES (?, ?, ?, ?)";
        $stmt_insert = $pdo->prepare($insert_sql);
        foreach ($insert_data as $row) {
            $stmt_insert->execute($row);
        }
        echo "Dados da tabela de diárias inseridos.\n";
    }

    echo "Update complete.\n";

} catch (PDOException $e) {
    die("Error updating database: " . $e->getMessage() . "\n");
}
