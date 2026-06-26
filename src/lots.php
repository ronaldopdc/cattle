<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once __DIR__ . '/financial_calculations.php';

// Include attachment handlers
include_once 'lot_attachment_handlers.php';

$message = '';

// Fetch Partners for Dropdown
$stmtPartners = $pdo->query("SELECT p.id, p.name FROM partners p JOIN partner_type_assignments pta ON p.id = pta.partner_id WHERE pta.type = 'owner' ORDER BY p.name ASC");
$partners = $stmtPartners->fetchAll();
$stmtLotNumbers = $pdo->query("SELECT DISTINCT lot_number FROM lots ORDER BY lot_number ASC");
$lotNumbers = $stmtLotNumbers->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action']) && $_POST['action'] === 'delete') {
            // Verify ownership
            $stmtOwner = $pdo->prepare("SELECT created_by FROM lots WHERE id = ?");
            $stmtOwner->execute([$_POST['id']]);
            $owner = $stmtOwner->fetchColumn();
            if ($owner != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
                throw new Exception("Você não tem permissão para excluir este lote.");
            }

            // Delete Lot
            $stmt = $pdo->prepare("DELETE FROM lots WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = "Lote excluído com sucesso!";
        } else {
            if (!empty($_POST['id'])) {
                // Verify ownership
                $stmtOwner = $pdo->prepare("SELECT created_by FROM lots WHERE id = ?");
                $stmtOwner->execute([$_POST['id']]);
                $owner = $stmtOwner->fetchColumn();
                if ($owner != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
                    throw new Exception("Você não tem permissão para editar este lote.");
                }

                // Update Lot
                $sql = "UPDATE lots SET partner_id = ?, category = ?, breed = ?, lot_number = ?, protocol_date = ?, protocol_weight = ?, animal_count = ?, indexed_price = ?, exit_forecast_date = ?, max_advance_percent = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_POST['partner_id'],
                    $_POST['category'],
                    $_POST['breed'],
                    $_POST['lot_number'],
                    $_POST['protocol_date'],
                    $_POST['protocol_weight'],
                    $_POST['animal_count'],
                    $_POST['indexed_price'],
                    $_POST['exit_forecast_date'],
                    $_POST['max_advance_percent'],
                    $_POST['id']
                ]);
                $message = "Lote atualizado com sucesso!";

                if (isset($_POST['lot_type']) && $_POST['lot_type'] === 'engorda') {
                    $stmtCheck = $pdo->prepare("SELECT id FROM lot_simulations WHERE lot_id = ?");
                    $stmtCheck->execute([$_POST['id']]);
                    if ($stmtCheck->fetch()) {
                        $sqlSim = "UPDATE lot_simulations SET purchase_arroba_price=?, sale_arroba_price=?, expected_yield=?, freight_distance=?, freight_price_per_km=?, animals_per_truck=?, commission_percent=?, is_noventena=?, vaccination_cost=?, eras_cost=?, other_extras_cost=? WHERE lot_id=?";
                        $stmtSim = $pdo->prepare($sqlSim);
                        $stmtSim->execute([
                            $_POST['purchase_arroba_price'] ?: 0, $_POST['sale_arroba_price'] ?: 0, $_POST['expected_yield'] ?: 0, $_POST['freight_distance'] ?: 0, $_POST['freight_price_per_km'] ?: 0, $_POST['animals_per_truck'] ?: 0, $_POST['commission_percent'] ?: 0, isset($_POST['is_noventena']) ? 1 : 0, $_POST['vaccination_cost'] ?: 0, $_POST['eras_cost'] ?: 0, $_POST['other_extras_cost'] ?: 0, $_POST['id']
                        ]);
                    } else {
                        $sqlSim = "INSERT INTO lot_simulations (lot_id, purchase_arroba_price, sale_arroba_price, expected_yield, freight_distance, freight_price_per_km, animals_per_truck, commission_percent, is_noventena, vaccination_cost, eras_cost, other_extras_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmtSim = $pdo->prepare($sqlSim);
                        $stmtSim->execute([
                            $_POST['id'], $_POST['purchase_arroba_price'] ?: 0, $_POST['sale_arroba_price'] ?: 0, $_POST['expected_yield'] ?: 0, $_POST['freight_distance'] ?: 0, $_POST['freight_price_per_km'] ?: 0, $_POST['animals_per_truck'] ?: 0, $_POST['commission_percent'] ?: 0, isset($_POST['is_noventena']) ? 1 : 0, $_POST['vaccination_cost'] ?: 0, $_POST['eras_cost'] ?: 0, $_POST['other_extras_cost'] ?: 0
                        ]);
                    }
                } else {
                    $pdo->prepare("DELETE FROM lot_simulations WHERE lot_id = ?")->execute([$_POST['id']]);
                }
            } else {
                // Insert Lot
                $sql = "INSERT INTO lots (partner_id, category, breed, lot_number, protocol_date, protocol_weight, animal_count, indexed_price, exit_forecast_date, max_advance_percent, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_POST['partner_id'],
                    $_POST['category'],
                    $_POST['breed'],
                    $_POST['lot_number'],
                    $_POST['protocol_date'],
                    $_POST['protocol_weight'],
                    $_POST['animal_count'],
                    $_POST['indexed_price'],
                    $_POST['exit_forecast_date'],
                    $_POST['max_advance_percent'],
                    $_SESSION['user_id']
                ]);
                $lot_id = $pdo->lastInsertId();
                if (isset($_POST['lot_type']) && $_POST['lot_type'] === 'engorda') {
                    $sqlSim = "INSERT INTO lot_simulations (lot_id, purchase_arroba_price, sale_arroba_price, expected_yield, freight_distance, freight_price_per_km, animals_per_truck, commission_percent, is_noventena, vaccination_cost, eras_cost, other_extras_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmtSim = $pdo->prepare($sqlSim);
                    $stmtSim->execute([
                        $lot_id, $_POST['purchase_arroba_price'] ?: 0, $_POST['sale_arroba_price'] ?: 0, $_POST['expected_yield'] ?: 0, $_POST['freight_distance'] ?: 0, $_POST['freight_price_per_km'] ?: 0, $_POST['animals_per_truck'] ?: 0, $_POST['commission_percent'] ?: 0, isset($_POST['is_noventena']) ? 1 : 0, $_POST['vaccination_cost'] ?: 0, $_POST['eras_cost'] ?: 0, $_POST['other_extras_cost'] ?: 0
                    ]);
                }
                $message = "Lote cadastrado com sucesso!";
            }
        }
    } catch (PDOException $e) {
        $message = "Erro ao salvar: " . $e->getMessage();
    }
}

// Fetch allocations
$stmtAllocations = $pdo->query("
    SELECT pl.lot_id, pl.projected_value, pl.monthly_rate, pl.slaughter_date, p.start_date 
    FROM partnership_lots pl 
    JOIN partnerships p ON pl.partnership_id = p.id
");
$allocations = $stmtAllocations->fetchAll(PDO::FETCH_ASSOC);

$lotAllocations = [];
foreach ($allocations as $alloc) {
    // Official rule: months = total days / 30 (calculateMonthsBetween)
    $months = calculateMonthsBetween($alloc['start_date'], $alloc['slaughter_date']);

    if ($months <= 0)
        $months = 0.0001;

    $rate = floatval($alloc['monthly_rate']);
    $projected = floatval($alloc['projected_value']);

    $allocated = $projected / (1 + ($rate / 100 * $months));

    if (!isset($lotAllocations[$alloc['lot_id']])) {
        $lotAllocations[$alloc['lot_id']] = 0;
    }
    $lotAllocations[$alloc['lot_id']] += $allocated;
}

if (empty($_GET)) {
    $_GET['filter_status'] = 'ativo';
}

$where = [];
$params = [];
if (!empty($_GET['filter_partner_id'])) {
    $where[] = "l.partner_id = ?";
    $params[] = $_GET['filter_partner_id'];
}
if (!empty($_GET['filter_lot_number'])) {
    $where[] = "l.lot_number = ?";
    $params[] = $_GET['filter_lot_number'];
}
if (!empty($_GET['filter_date_start'])) {
    $where[] = "l.exit_forecast_date >= ?";
    $params[] = $_GET['filter_date_start'];
}
if (!empty($_GET['filter_date_end'])) {
    $where[] = "l.exit_forecast_date <= ?";
    $params[] = $_GET['filter_date_end'];
}
if (!empty($_GET['filter_status'])) {
    if ($_GET['filter_status'] === 'ativo') {
        $where[] = "(
            NOT EXISTS (SELECT 1 FROM partnership_lots pl WHERE pl.lot_id = l.id) OR
            EXISTS (
                SELECT 1 FROM partnership_lots pl
                JOIN partnerships p ON pl.partnership_id = p.id
                WHERE pl.lot_id = l.id AND NOT EXISTS (
                    SELECT 1 FROM partnership_liquidations liq 
                    WHERE liq.partnership_id = p.id AND (liq.is_settlement = 1 OR liq.balance_after <= 0.05)
                )
            )
        )";
    } elseif ($_GET['filter_status'] === 'inativo') {
        $where[] = "(
            EXISTS (SELECT 1 FROM partnership_lots pl WHERE pl.lot_id = l.id) AND
            NOT EXISTS (
                SELECT 1 FROM partnership_lots pl
                JOIN partnerships p ON pl.partnership_id = p.id
                WHERE pl.lot_id = l.id AND NOT EXISTS (
                    SELECT 1 FROM partnership_liquidations liq 
                    WHERE liq.partnership_id = p.id AND (liq.is_settlement = 1 OR liq.balance_after <= 0.05)
                )
            )
        )";
    }
}
$whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
$stmt = $pdo->prepare("SELECT l.*, p.name as partner_name, ls.purchase_arroba_price, ls.sale_arroba_price, ls.expected_yield, ls.freight_distance, ls.freight_price_per_km, ls.animals_per_truck, ls.commission_percent, ls.is_noventena, ls.vaccination_cost, ls.eras_cost, ls.other_extras_cost, IF(ls.id IS NOT NULL, 'engorda', 'parceria') as lot_type,
        (SELECT GROUP_CONCAT(pl.partnership_id) FROM partnership_lots pl WHERE pl.lot_id = l.id) as partnership_ids,
        (CASE 
            WHEN NOT EXISTS (SELECT 1 FROM partnership_lots pl WHERE pl.lot_id = l.id) THEN 'ativo'
            WHEN EXISTS (
                SELECT 1 FROM partnership_lots pl
                JOIN partnerships p ON pl.partnership_id = p.id
                WHERE pl.lot_id = l.id AND NOT EXISTS (
                    SELECT 1 FROM partnership_liquidations liq 
                    WHERE liq.partnership_id = p.id AND (liq.is_settlement = 1 OR liq.balance_after <= 0.05)
                )
            ) THEN 'ativo'
            ELSE 'inativo'
        END) as current_status
        FROM lots l 
        LEFT JOIN partners p ON l.partner_id = p.id 
        LEFT JOIN lot_simulations ls ON l.id = ls.lot_id 
        $whereSql 
        ORDER BY l.created_at DESC");
$stmt->execute($params);
$lots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch simulation variables
$stmtCosts = $pdo->query("SELECT * FROM simulation_daily_costs ORDER BY weight_start ASC");
$dailyCosts = $stmtCosts->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lotes - Cattle Invest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>
        .select2-container--default .select2-selection--single {
            background-color: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            height: 45px;
            padding: 8px 12px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #fff;
            line-height: 29px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 43px;
        }

        .select2-dropdown {
            background-color: #1a1a2e;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .select2-container--default .select2-results__option {
            color: #fff;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: rgba(139, 92, 246, 0.3);
        }

        .select2-container--default .select2-search--dropdown .select2-search__field {
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <div class="container">

        <?php if ($message): ?>
            <div class="card" style="border-left: 4px solid var(--primary-color); padding: 1rem;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="grid">
            <div id="formContainer" style="display: none; margin-bottom: 2rem;">
                <!-- Registration Form -->
                <div class="card">
                    <h2 id="formTitle">Novo Lote</h2>
                    <form method="POST" action="" id="lotForm">
                        <input type="hidden" name="id" id="lot_id">
                        <div class="form-group">
                            <label>Número do Lote</label>
                            <input type="text" name="lot_number" id="lot_number" required>
                        </div>
                        <div class="form-group">
                            <label>Proprietário</label>
                            <select name="partner_id" id="partner_id" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($partners as $partner): ?>
                                    <option value="<?= $partner['id'] ?>"><?= htmlspecialchars($partner['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tipo do Lote</label>
                            <select name="lot_type" id="lot_type" onchange="toggleSimulationFields()" required>
                                <option value="parceria">Parceria</option>
                                <option value="engorda">Engorda Própria (Simulação)</option>
                            </select>
                        </div>
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Categoria</label>
                                <input type="text" name="category" id="category">
                            </div>
                            <div class="form-group">
                                <label>Raça</label>
                                <input type="text" name="breed" id="breed">
                            </div>
                        </div>
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Data Protocolo</label>
                                <input type="date" name="protocol_date" id="protocol_date">
                            </div>
                            <div class="form-group">
                                <label>Previsão Saída</label>
                                <input type="date" name="exit_forecast_date" id="exit_forecast_date">
                            </div>
                        </div>
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Peso Protocolo (kg)</label>
                                <input type="number" step="0.01" name="protocol_weight" id="protocol_weight">
                            </div>
                            <div class="form-group">
                                <label>Qtd. Animais</label>
                                <input type="number" name="animal_count" id="animal_count">
                            </div>
                        </div>
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Preço Indexado</label>
                                <input type="number" step="0.01" name="indexed_price" id="indexed_price">
                            </div>
                            <div class="form-group">
                                <label>% Máx Adiantamento</label>
                                <input type="number" step="0.01" name="max_advance_percent" id="max_advance_percent">
                            </div>
                        </div>

                        <!-- Simulation Fields -->
                        <div id="simulationFields" style="display: none; padding: 1.5rem; background: rgba(139, 92, 246, 0.05); border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 8px; margin-bottom: 1.5rem;">
                            <h3 style="margin-top:0; color: #a78bfa; font-size: 1.1em; margin-bottom: 1rem;">Dados de Simulação de Engorda</h3>
                            
                            <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                                <div class="form-group"><label>Preço @ Compra (R$)</label><input type="number" step="0.01" name="purchase_arroba_price" id="purchase_arroba_price" oninput="calculateSimulation()"></div>
                                <div class="form-group"><label>Preço @ Venda (R$)</label><input type="number" step="0.01" name="sale_arroba_price" id="sale_arroba_price" oninput="calculateSimulation()"></div>
                                <div class="form-group"><label>Rendimento Esperado (%)</label><input type="number" step="0.01" name="expected_yield" id="expected_yield" value="50.5" oninput="calculateSimulation()"></div>
                            </div>

                            <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                                <div class="form-group"><label>Comissão Compra (%)</label><input type="number" step="0.01" name="commission_percent" id="commission_percent" value="0.0" oninput="calculateSimulation()"></div>
                                <div class="form-group"><label>Distância Frete (KM)</label><input type="number" step="0.01" name="freight_distance" id="freight_distance" oninput="calculateSimulation()"></div>
                                <div class="form-group"><label>Preço Frete por KM (R$)</label><input type="number" step="0.01" name="freight_price_per_km" id="freight_price_per_km" oninput="calculateSimulation()"></div>
                            </div>

                            <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; align-items: end;">
                                <div class="form-group"><label>Qtd. Animais / Carreta</label><input type="number" name="animals_per_truck" id="animals_per_truck" oninput="calculateSimulation()"></div>
                                <div class="form-group"><label>Peso Final Estimado (kg)</label><input type="number" step="0.01" id="projected_final_weight" oninput="calculateSimulation()"></div>
                                <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 10px;">
                                    <input type="checkbox" name="is_noventena" id="is_noventena" value="1" onchange="calculateSimulation()" style="width:auto; height:18px;">
                                    <label style="margin:0; cursor:pointer;" for="is_noventena">Rastreamento/Noventena?</label>
                                </div>
                            </div>
                            
                            <h4 style="margin: 0 0 0.5rem; color: #e2e8f0; font-size: 0.95em;">Custos Extras (Ex: Vacinação, Eras...)</h4>
                            <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                                <div class="form-group"><label>Vacinação (R$ / cabeça)</label><input type="number" step="0.01" name="vaccination_cost" id="vaccination_cost" oninput="calculateSimulation()"></div>
                                <div class="form-group"><label>Eras (R$ / cabeça)</label><input type="number" step="0.01" name="eras_cost" id="eras_cost" oninput="calculateSimulation()"></div>
                                <div class="form-group"><label>Outros (R$ / cabeça)</label><input type="number" step="0.01" name="other_extras_cost" id="other_extras_cost" oninput="calculateSimulation()"></div>
                            </div>
                            
                            <div class="analysis-panel" id="analysisPanel" style="margin-top: 1.5rem; border: 1px solid rgba(16, 185, 129, 0.3); background: rgba(16, 185, 129, 0.05); padding: 1.2rem; border-radius: 8px;">
                                <h4 style="margin-top:0; color: #10b981; font-size: 1.05em; margin-bottom: 1rem;"><i class="fas fa-chart-line"></i> Análise de Resultado (Projeção por Animal)</h4>
                                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.8rem; font-size: 0.95em; color: #cbd5e1;">
                                    <div style="display:flex; justify-content: space-between;"><span>Custo Animal (propriedade):</span> <strong id="res_custo_fazenda" style="color:#fff">R$ 0,00</strong></div>
                                    <div style="display:flex; justify-content: space-between;"><span>Custo Animal (+frete+comissão):</span> <strong id="res_custo_final" style="color:#fff">R$ 0,00</strong></div>
                                    <div style="display:flex; justify-content: space-between;"><span>Dias de Cocho Estimado:</span> <strong id="res_dias" style="color:#fff">0</strong></div>
                                    <div style="display:flex; justify-content: space-between;"><span>Custo do Confinamento:</span> <strong id="res_custo_conf" style="color:#fff">R$ 0,00</strong></div>
                                    <div style="display:flex; justify-content: space-between;"><span>Peso Final em Arrobas (@):</span> <strong id="res_peso_final" style="color:#fff">0,00</strong></div>
                                    <div style="display:flex; justify-content: space-between;"><span>Faturamento Projetado:</span> <strong id="res_fat" style="color:#fff">R$ 0,00</strong></div>
                                </div>
                                <hr style="border-color: rgba(255,255,255,0.1); margin: 1rem 0;">
                                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.8rem;">
                                    <div style="display:flex; justify-content: space-between; align-items:center;">
                                        <span>Resultado/Lucro Liquido:</span> 
                                        <strong id="res_resultado" style="font-size:1.25em; color: #10b981;">R$ 0,00</strong>
                                    </div>
                                    <div style="display:flex; justify-content: space-between; align-items:center;">
                                        <span>Lucro por @ produzida:</span> 
                                        <strong id="res_lucro_arroba" style="font-size:1.1em; color: #60a5fa;">R$ 0,00</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <button type="button" class="btn btn-secondary" id="cancelBtn"
                                onclick="hideForm()">Cancelar</button>
                            <button type="submit" class="btn btn-primary" id="submitBtn"
                                style="width: 100%">Cadastrar</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- List -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h2 style="margin: 0;">Lista de Lotes</h2>
                    <div style="display: flex; gap: 1rem; align-items: center;">
                        <button class="btn btn-secondary" onclick="generateLotsReport()">
                            <i class="fas fa-file-pdf"></i> Relatório
                        </button>
                        <button class="btn btn-primary" onclick="showForm()">+ Novo Lote</button>
                    </div>
                </div>

                <!-- Filters -->
                <div style="background: rgba(255, 255, 255, 0.02); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.05); margin-bottom: 2rem;">
                    <form method="GET" action="" class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items: flex-end;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="margin-bottom: 0.5rem; display: block; font-size: 0.85rem;">Proprietário</label>
                            <select name="filter_partner_id" id="filter_partner_id" style="margin-bottom: 0; height: 45px;">
                                <option value="">Todos os Proprietários</option>
                                <?php foreach ($partners as $partner): ?>
                                    <option value="<?= $partner['id'] ?>" <?= (isset($_GET['filter_partner_id']) && $_GET['filter_partner_id'] == $partner['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($partner['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="margin-bottom: 0.5rem; display: block; font-size: 0.85rem;">Número do Lote</label>
                            <select name="filter_lot_number" id="filter_lot_number" style="margin-bottom: 0; height: 45px;">
                                <option value="">Todos os Lotes</option>
                                <?php foreach ($lotNumbers as $num): ?>
                                    <option value="<?= htmlspecialchars($num) ?>" <?= (isset($_GET['filter_lot_number']) && $_GET['filter_lot_number'] == $num) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($num) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="margin-bottom: 0.5rem; display: block; font-size: 0.85rem;">Previsão Inicial</label>
                            <input type="date" name="filter_date_start" value="<?= htmlspecialchars($_GET['filter_date_start'] ?? '') ?>" style="margin-bottom: 0; height: 45px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="margin-bottom: 0.5rem; display: block; font-size: 0.85rem;">Previsão Final</label>
                            <input type="date" name="filter_date_end" value="<?= htmlspecialchars($_GET['filter_date_end'] ?? '') ?>" style="margin-bottom: 0; height: 45px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="margin-bottom: 0.5rem; display: block; font-size: 0.85rem;">Status</label>
                            <select name="filter_status" id="filter_status" style="margin-bottom: 0; height: 45px;">
                                <option value="">Todos os Status</option>
                                <option value="ativo" <?= (isset($_GET['filter_status']) && $_GET['filter_status'] == 'ativo') ? 'selected' : '' ?>>Ativos</option>
                                <option value="inativo" <?= (isset($_GET['filter_status']) && $_GET['filter_status'] == 'inativo') ? 'selected' : '' ?>>Inativos</option>
                            </select>
                        </div>
                        <div style="display: flex; gap: 0.5rem; height: 45px;">
                            <button type="submit" class="btn btn-primary" style="flex: 1; height: 45px; display: flex; align-items: center; justify-content: center; padding: 0;">
                                <i class="fas fa-filter"></i>
                            </button>
                            <a href="lots.php" class="btn btn-secondary" style="flex: 1; height: 45px; display: flex; align-items: center; justify-content: center; text-decoration: none; padding: 0;">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Lote #</th>
                                <th>Parceria</th>
                                <th>Status</th>
                                <th>Proprietário</th>
                                <th>Raça/Cat.</th>
                                <th>Data Prot.</th>
                                <th>Qtd.</th>
                                <th>Peso</th>
                                <th>Preço Ind.</th>
                                <th>% Máx Adiant.</th>
                                <th>Valor Total</th>
                                <th>Disponível</th>
                                <th>Previsão</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sum_qtd = 0;
                            $sum_peso_qtd = 0;
                            $sum_valor_total = 0;
                            $sum_valor_disp = 0;
                            $sum_max_adiant_valor = 0;
                            ?>
                            <?php foreach ($lots as $lot): ?>
                                <tr>
                                    <td data-label="Lote #"><?= htmlspecialchars($lot['lot_number']) ?></td>
                                    <td data-label="Parceria">
                                        <?php if ($lot['partnership_ids']): ?>
                                            <?php 
                                            $pids = explode(',', $lot['partnership_ids']);
                                            foreach($pids as $pid) {
                                                echo '<span style="background: rgba(56, 189, 248, 0.1); color: #38bdf8; padding: 2px 6px; border-radius: 4px; font-size: 0.75rem; border: 1px solid rgba(56, 189, 248, 0.2); margin-right: 4px;">#' . $pid . '</span>';
                                            }
                                            ?>
                                        <?php else: ?>
                                            <span style="color: #64748b">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status">
                                        <?php if ($lot['current_status'] === 'ativo'): ?>
                                            <span style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; border: 1px solid rgba(16, 185, 129, 0.2);">Ativo</span>
                                        <?php else: ?>
                                            <span style="background: rgba(148, 163, 184, 0.1); color: #94a3b8; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; border: 1px solid rgba(148, 163, 184, 0.2);">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Proprietário"><?= htmlspecialchars($lot['partner_name'] ?? '-') ?></td>
                                    <td data-label="Raça/Cat.">
                                        <?= htmlspecialchars($lot['breed']) . ' / ' . htmlspecialchars($lot['category']) ?>
                                    </td>
                                    <td data-label="Data Prot.">
                                        <?= !empty($lot['protocol_date']) ? date('d/m/Y', strtotime($lot['protocol_date'])) : '-' ?>
                                    </td>
                                    <td data-label="Qtd."><?= htmlspecialchars($lot['animal_count']) ?></td>
                                    <td data-label="Peso"><?= number_format((float) $lot['protocol_weight'], 2, ',', '.') ?> kg</td>
                                    <?php
                                    $weightArrobas = ($lot['protocol_weight'] * $lot['animal_count']) / 30;
                                    $totalValue = $weightArrobas * $lot['indexed_price'];
                                    $maxAdvance = $totalValue * ($lot['max_advance_percent'] / 100);
                                    $allocated = $lotAllocations[$lot['id']] ?? 0;
                                    $available = $maxAdvance - $allocated;
                                    
                                    $sum_qtd += $lot['animal_count'];
                                    $sum_peso_qtd += ($lot['protocol_weight'] * $lot['animal_count']);
                                    $sum_valor_total += $totalValue;
                                    $sum_valor_disp += $available;
                                    $sum_max_adiant_valor += ($lot['max_advance_percent'] * $totalValue);
                                    ?>
                                    <td data-label="Preço Ind.">R$ <?= number_format((float) $lot['indexed_price'], 2, ',', '.') ?></td>
                                    <td data-label="% Máx Adiant."><?= number_format((float) $lot['max_advance_percent'], 2, ',', '.') ?>%</td>
                                    <td data-label="Valor Total">R$ <?= number_format($totalValue, 2, ',', '.') ?></td>
                                    <td data-label="Disponível" style="color: <?= $available < 0 ? '#ef4444' : '#10b981' ?>">
                                        R$ <?= number_format($available, 2, ',', '.') ?>
                                    </td>
                                    <td data-label="Previsão">
                                        <?= !empty($lot['exit_forecast_date']) ? date('d/m/Y', strtotime($lot['exit_forecast_date'])) : '-' ?>
                                    </td>
                                    <td data-label="Ações">
                                        <?php if ($lot['created_by'] == $_SESSION['user_id'] || $_SESSION['role'] === 'admin'): ?>
                                        <button class="btn btn-icon btn-edit" onclick='editLot(<?= json_encode($lot) ?>)'
                                            title="Editar">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-icon" onclick="openLotAttachmentsModal(<?= $lot['id'] ?>)"
                                            title="Anexos"
                                            style="background: rgba(139, 92, 246, 0.1); border-color: rgba(139, 92, 246, 0.2); color: #a78bfa;">
                                            <i class="fas fa-paperclip"></i>
                                        </button>
                                        <?php if ($lot['created_by'] == $_SESSION['user_id'] || $_SESSION['role'] === 'admin'): ?>
                                        <button class="btn btn-icon btn-delete" onclick="deleteLot(<?= $lot['id'] ?>)"
                                            title="Excluir">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight: bold; background: rgba(255, 255, 255, 0.05);">
                                <td colspan="5" style="text-align: right;">Totais / Médias:</td>
                                <td data-label="Data Prot.">-</td>
                                <td data-label="Qtd."><?= number_format($sum_qtd, 0, ',', '.') ?></td>
                                <td data-label="Peso"><?= $sum_qtd > 0 ? number_format($sum_peso_qtd / $sum_qtd, 2, ',', '.') : '0,00' ?> kg</td>
                                <td data-label="Preço Ind.">-</td>
                                <td data-label="% Máx Adiant."><?= $sum_valor_total > 0 ? number_format($sum_max_adiant_valor / $sum_valor_total, 2, ',', '.') : '0,00' ?>%</td>
                                <td data-label="Valor Total">R$ <?= number_format($sum_valor_total, 2, ',', '.') ?></td>
                                <td data-label="Disponível" style="color: <?= $sum_valor_disp < 0 ? '#ef4444' : '#10b981' ?>">R$ <?= number_format($sum_valor_disp, 2, ',', '.') ?></td>
                                <td data-label="Previsão">-</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script>
            const dailyCosts = <?= json_encode($dailyCosts) ?>;

            function calculateSimulation() {
                const type = document.getElementById('lot_type').value;
                if (type !== 'engorda') return;

                const pesoInicial = parseFloat(document.getElementById('protocol_weight').value) || 0;
                const priceBuy = parseFloat(document.getElementById('purchase_arroba_price').value) || 0;
                const priceSell = parseFloat(document.getElementById('sale_arroba_price').value) || 0;
                const yieldPerc = parseFloat(document.getElementById('expected_yield').value) || 0;
                const commission = parseFloat(document.getElementById('commission_percent').value) || 0;
                const distance = parseFloat(document.getElementById('freight_distance').value) || 0;
                const freightPrice = parseFloat(document.getElementById('freight_price_per_km').value) || 0;
                const headsPerTruck = parseFloat(document.getElementById('animals_per_truck').value) || 1;
                
                const noventena = document.getElementById('is_noventena').checked;
                const vac = parseFloat(document.getElementById('vaccination_cost').value) || 0;
                const eras = parseFloat(document.getElementById('eras_cost').value) || 0;
                const others = parseFloat(document.getElementById('other_extras_cost').value) || 0;

                const protocolStr = document.getElementById('protocol_date').value;
                const exitStr = document.getElementById('exit_forecast_date').value;

                if (pesoInicial <= 0) return;

                const custoFazenda = (pesoInicial / 30) * priceBuy;
                const frete = headsPerTruck > 0 ? (distance * freightPrice) / headsPerTruck : 0;
                const comissao = custoFazenda * (commission / 100);
                const custoAnimal = custoFazenda + frete + comissao;

                let gpd = 1.4;
                let dailyCost = 4.5;
                for (const row of dailyCosts) {
                    if (pesoInicial >= parseFloat(row.weight_start) && pesoInicial < parseFloat(row.weight_end)) {
                        gpd = parseFloat(row.projected_gpd) || 1.4;
                        dailyCost = parseFloat(row.cost_per_day) || 4.5;
                        break;
                    }
                }

                let diasCocho = 0;
                if (protocolStr && exitStr) {
                    const d1 = new Date(protocolStr);
                    const d2 = new Date(exitStr);
                    const diffTime = d2 - d1;
                    if (diffTime > 0) diasCocho = diffTime / (1000 * 60 * 60 * 24);
                }

                const limitPesoFinal = pesoInicial + (diasCocho * gpd);
                document.getElementById('projected_final_weight').value = limitPesoFinal.toFixed(2);

                const custoAlimentacao = diasCocho * dailyCost;
                const custoExtras = vac + eras + others;
                const custoConfinamento = custoAlimentacao + custoExtras;

                const pesoFinalArrobas = (limitPesoFinal * (yieldPerc / 100)) / 15;
                const faturamento = pesoFinalArrobas * priceSell;
                const resultado = faturamento - (custoAnimal + custoConfinamento);

                const pesoInicialArrobas = (pesoInicial * (yieldPerc / 100)) / 15;
                const arrobasProduzidas = (pesoFinalArrobas - pesoInicialArrobas);
                const lucroArroba = arrobasProduzidas > 0 ? resultado / arrobasProduzidas : 0;

                const fmt = (val) => val.toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
                
                document.getElementById('res_custo_fazenda').innerText = fmt(custoFazenda);
                document.getElementById('res_custo_final').innerText = fmt(custoAnimal);
                document.getElementById('res_dias').innerText = Math.round(diasCocho);
                document.getElementById('res_custo_conf').innerText = fmt(custoConfinamento);
                document.getElementById('res_peso_final').innerText = pesoFinalArrobas.toFixed(2);
                document.getElementById('res_fat').innerText = fmt(faturamento);
                document.getElementById('res_resultado').innerText = fmt(resultado);
                document.getElementById('res_resultado').style.color = resultado >= 0 ? '#10b981' : '#ef4444';
                document.getElementById('res_lucro_arroba').innerText = fmt(lucroArroba);
            }

            function toggleSimulationFields() {
                const type = document.getElementById('lot_type').value;
                document.getElementById('simulationFields').style.display = type === 'engorda' ? 'block' : 'none';
                if (type === 'engorda') calculateSimulation();
            }

            $(document).ready(function () {
                $('#partner_id').select2({
                    placeholder: 'Pesquisar proprietário...',
                    allowClear: true,
                    language: {
                        noResults: function () {
                            return "Nenhum proprietário encontrado";
                        },
                        searching: function () {
                            return "Pesquisando...";
                        }
                    }
                });

                $('#filter_partner_id').select2({
                    placeholder: 'Filtrar por proprietário...',
                    allowClear: true
                });

                $('#filter_lot_number').select2({
                    placeholder: 'Filtrar por número...',
                    allowClear: true
                });
            });

            function showForm() {
                document.getElementById('formContainer').style.display = 'block';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            function hideForm() {
                document.getElementById('formContainer').style.display = 'none';
                resetForm();
            }

            function editLot(lot) {
                showForm();
                document.getElementById('formTitle').innerText = 'Editar Lote #' + lot.lot_number;
                document.getElementById('lot_id').value = lot.id;
                document.getElementById('lot_number').value = lot.lot_number;
                $('#partner_id').val(lot.partner_id).trigger('change');
                document.getElementById('category').value = lot.category;
                document.getElementById('breed').value = lot.breed;
                document.getElementById('protocol_date').value = lot.protocol_date;
                document.getElementById('exit_forecast_date').value = lot.exit_forecast_date;
                document.getElementById('protocol_weight').value = lot.protocol_weight;
                document.getElementById('animal_count').value = lot.animal_count;
                document.getElementById('indexed_price').value = lot.indexed_price;
                document.getElementById('max_advance_percent').value = lot.max_advance_percent;

                document.getElementById('lot_type').value = lot.lot_type || 'parceria';
                if (lot.lot_type === 'engorda') {
                    document.getElementById('purchase_arroba_price').value = lot.purchase_arroba_price;
                    document.getElementById('sale_arroba_price').value = lot.sale_arroba_price;
                    document.getElementById('expected_yield').value = lot.expected_yield;
                    document.getElementById('commission_percent').value = lot.commission_percent;
                    document.getElementById('freight_distance').value = lot.freight_distance;
                    document.getElementById('freight_price_per_km').value = lot.freight_price_per_km;
                    document.getElementById('animals_per_truck').value = lot.animals_per_truck;
                    document.getElementById('vaccination_cost').value = lot.vaccination_cost;
                    document.getElementById('eras_cost').value = lot.eras_cost;
                    document.getElementById('other_extras_cost').value = lot.other_extras_cost;
                    document.getElementById('is_noventena').checked = lot.is_noventena == 1;
                } else {
                    document.getElementById('purchase_arroba_price').value = '';
                    document.getElementById('sale_arroba_price').value = '';
                    document.getElementById('expected_yield').value = '50.5';
                    document.getElementById('commission_percent').value = '0.0';
                    document.getElementById('freight_distance').value = '';
                    document.getElementById('freight_price_per_km').value = '';
                    document.getElementById('animals_per_truck').value = '';
                    document.getElementById('vaccination_cost').value = '';
                    document.getElementById('eras_cost').value = '';
                    document.getElementById('other_extras_cost').value = '';
                    document.getElementById('is_noventena').checked = false;
                }
                toggleSimulationFields();

                document.getElementById('submitBtn').innerText = 'Salvar Alterações';
                document.getElementById('cancelBtn').style.display = 'inline-block';
                document.getElementById('cancelBtn').onclick = hideForm;
            }

            function resetForm() {
                document.getElementById('formTitle').innerText = 'Novo Lote';
                document.getElementById('lotForm').reset();
                document.getElementById('lot_id').value = '';
                document.getElementById('lot_type').value = 'parceria';
                toggleSimulationFields();
                $('#partner_id').val(null).trigger('change');
                document.getElementById('submitBtn').innerText = 'Cadastrar';
            }

            function deleteLot(id) {
                if (confirm('Tem certeza que deseja excluir este lote? Esta ação não pode ser desfeita.')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';

                    const inputId = document.createElement('input');
                    inputId.type = 'hidden';
                    inputId.name = 'id';
                    inputId.value = id;

                    const inputAction = document.createElement('input');
                    inputAction.type = 'hidden';
                    inputAction.name = 'action';
                    inputAction.value = 'delete';

                    form.appendChild(inputId);
                    form.appendChild(inputAction);
                    document.body.appendChild(form);
                    form.submit();
                }
            }

            function generateLotsReport() {
                const logoUrl = new URL('assets/logo.png', window.location.href).href;
                const timestamp = new Date().toLocaleString('pt-BR');
                
                let rowsHtml = '';
                let count = 0;
                
                // Get the table body
                const tbody = document.querySelector('table tbody');
                if (tbody) {
                    const rows = tbody.querySelectorAll('tr');
                    rows.forEach(row => {
                        if (row.style.display !== 'none') {
                            count++;
                            const cells = row.querySelectorAll('td');
                            if (cells.length >= 13) {
                                rowsHtml += `
                                    <tr>
                                        <td>${cells[0].innerText}</td>
                                        <td>${cells[1].innerText}</td>
                                        <td>${cells[2].innerText}</td>
                                        <td>${cells[3].innerText}</td>
                                        <td>${cells[4].innerText}</td>
                                        <td>${cells[5].innerText}</td>
                                        <td align="right" class="number">${cells[6].innerText}</td>
                                        <td align="right" class="number">${cells[7].innerText}</td>
                                        <td align="right" class="number">${cells[8].innerText}</td>
                                        <td align="right" class="number">${cells[9].innerText}</td>
                                        <td align="right" class="number">${cells[10].innerText}</td>
                                        <td align="right" class="number" style="font-weight: 600;">${cells[11].innerText}</td>
                                        <td>${cells[12].innerText}</td>
                                    </tr>
                                `;
                            }
                        }
                    });
                }
                
                // Get the table footer
                let footerHtml = '';
                const tfoot = document.querySelector('table tfoot');
                if (tfoot) {
                    const footRow = tfoot.querySelector('tr');
                    if (footRow) {
                        const cells = footRow.querySelectorAll('td');
                        if (cells.length >= 10) { // Accounting for colspan=5
                             footerHtml = `
                                <tr>
                                    <td colspan="6" align="right">TOTAIS / MÉDIAS:</td>
                                    <td align="right" class="number">${cells[2].innerText}</td>
                                    <td align="right" class="number">${cells[3].innerText}</td>
                                    <td align="right" class="number">${cells[4].innerText}</td>
                                    <td align="right" class="number">${cells[5].innerText}</td>
                                    <td align="right" class="number">${cells[6].innerText}</td>
                                    <td align="right" class="number">${cells[7].innerText}</td>
                                    <td></td>
                                </tr>
                             `;
                        }
                    }
                }

                const reportHtml = `
                <!DOCTYPE html>
                <html lang="pt-BR">
                <head>
                    <meta charset="UTF-8">
                    <title>Relatório de Lotes</title>
                    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
                    <style>
                        :root { --primary: #0f172a; --secondary: #64748b; --border: #e2e8f0; --bg-light: #f8fafc; }
                        body { font-family: 'Inter', sans-serif; color: var(--primary); line-height: 1.4; margin: 0; padding: 30px; background: white; font-size: 11px; }
                        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--primary); padding-bottom: 15px; margin-bottom: 20px; }
                        .logo { height: 50px; }
                        .title-block h1 { margin: 0; font-size: 18px; text-transform: uppercase; text-align: right; }
                        .title-block p { margin: 2px 0 0; color: var(--secondary); text-align: right; font-size: 11px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
                        th, td { text-align: left; padding: 6px 4px; border-bottom: 1px solid var(--border); overflow: hidden; text-overflow: ellipsis; }
                        th { background: var(--bg-light); font-weight: 700; color: var(--secondary); text-transform: uppercase; font-size: 9px; }
                        .number { font-family: 'Courier New', monospace; font-size: 10px; }
                        tfoot { display: table-row-group; }
                        tfoot tr { background: var(--bg-light); font-weight: 700; }
                        .footer { margin-top: 30px; text-align: center; font-size: 10px; color: var(--secondary); border-top: 1px solid var(--border); padding-top: 15px; }
                        .btn-print { display: inline-flex; align-items: center; gap: 8px; background: #0f172a; color: white; border: none; padding: 10px 24px; font-size: 13px; font-family: 'Inter', sans-serif; font-weight: 600; border-radius: 8px; cursor: pointer; margin-bottom: 20px; }
                        .btn-print:hover { background: #1e293b; }
                        @media print { body { padding: 0; } .no-print { display: none; } }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <img src="${logoUrl}" alt="Logo" class="logo">
                        <div class="title-block">
                            <h1>Relatório de Lotes</h1>
                            <p>Emitido em: ${timestamp}</p>
                        </div>
                    </div>
                    <div style="margin-bottom: 10px; color: var(--secondary); font-weight: 600;">
                        Exibindo ${count} lotes
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th width="40">Lote #</th>
                                <th width="60">Parceria</th>
                                <th width="60">Status</th>
                                <th width="100">Proprietário</th>
                                <th width="100">Raça/Cat.</th>
                                <th width="60">Data Prot.</th>
                                <th width="40" style="text-align: right;">Qtd.</th>
                                <th width="60" style="text-align: right;">Peso</th>
                                <th width="60" style="text-align: right;">Preço Ind.</th>
                                <th width="60" style="text-align: right;">% Máx.</th>
                                <th width="80" style="text-align: right;">Valor Total</th>
                                <th width="80" style="text-align: right;">Disponível</th>
                                <th width="60">Previsão</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml}
                        </tbody>
                        <tfoot>
                            ${footerHtml}
                        </tfoot>
                    </table>
                    <div class="footer">
                        Cattle Invest - Sistema de Gestão de Parcerias Pecuárias
                    </div>
                    <div class="no-print" style="margin-bottom: 16px;">
                        <button class="btn-print" onclick="window.print()">&#128438; Imprimir / Salvar PDF</button>
                    </div>
                </body>
                </html>
                `;

                const blob = new Blob([reportHtml], { type: 'text/html;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const printWindow = window.open(url, '_blank');
                if (!printWindow) {
                    alert("O bloqueador de pop-ups impediu a abertura do relatório. Por favor, permita pop-ups para este site.");
                }
            }
        </script>
        <?php include 'lot_attachments_modal.html'; ?>
</body>

</html>