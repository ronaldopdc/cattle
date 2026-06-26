<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once __DIR__ . '/financial_calculations.php';

function valorPorExtenso($valor = 0)
{
    if ($valor <= 0)
        return "zero reais";

    $singular = ["centavo", "real", "mil", "milhão", "bilhão", "trilhão", "quatrilhão"];
    $plural = ["centavos", "reais", "mil", "milhões", "bilhões", "trilhões", "quatrilhões"];

    $c = ["", "cem", "duzentos", "trezentos", "quatrocentos", "quinhentos", "seiscentos", "setecentos", "oitocentos", "novecentos"];
    $d = ["", "dez", "vinte", "trinta", "quarenta", "cinquenta", "sessenta", "setenta", "oitenta", "noventa"];
    $d10 = ["dez", "onze", "doze", "treze", "quatorze", "quinze", "dezesseis", "dezessete", "dezoito", "dezenove"];
    $u = ["", "um", "dois", "três", "quatro", "cinco", "seis", "sete", "oito", "nove"];

    $z = 0;

    $valor = number_format($valor, 2, ".", ".");
    $inteiro = explode(".", $valor);
    for ($i = 0; $i < count($inteiro); $i++) {
        for ($ii = strlen($inteiro[$i]); $ii < 3; $ii++) {
            $inteiro[$i] = "0" . $inteiro[$i];
        }
    }

    $rt = "";
    $fim = count($inteiro) - ($inteiro[count($inteiro) - 1] > 0 ? 1 : 2);
    for ($i = 0; $i < count($inteiro); $i++) {
        $valor = $inteiro[$i];
        $rc = (($valor > 100) && ($valor < 200)) ? "cento" : $c[$valor[0]];
        $rd = ($valor[1] < 2) ? "" : $d[$valor[1]];
        $ru = ($valor[1] == 1) ? $d10[$valor[2]] : $u[$valor[2]];

        $clean = $rc . (($rc && ($rd || $ru)) ? " e " : "") . $rd . (($rd && $ru) ? " e " : "") . $ru;
        $t = count($inteiro) - 1 - $i;

        if ($clean) {
            $rt .= ($rt ? " " : "") . $clean . " " . ($valor > 1 ? $plural[$t] : $singular[$t]);
        }

        if ($valor == "000")
            $z++;
        elseif ($z > 0)
            $z--;

        if (($t == 1) && ($z > 0) && ($inteiro[0] > 0)) {
            $rt .= " " . (($z > 1) ? " de " : "") . $plural[$t];
        }

        if ($i < $fim && $clean && isset($inteiro[$i + 1]) && $inteiro[$i + 1] != "000") {
            // Determine if we need "e" or ","
            $proximos = 0;
            for ($j = $i + 1; $j <= $fim; $j++)
                if ($inteiro[$j] != "000")
                    $proximos++;
            $rt .= ($proximos > 1) ? ", " : " e ";
        }
    }

    return ($rt ? $rt : "zero reais");
}

function dataPorExtenso($data)
{
    if (!$data)
        return "";
    $meses = [
        1 => 'janeiro',
        'fevereiro',
        'março',
        'abril',
        'maio',
        'junho',
        'julho',
        'agosto',
        'setembro',
        'outubro',
        'novembro',
        'dezembro'
    ];
    $timestamp = strtotime($data);
    $dia = date('d', $timestamp);
    $mes = $meses[(int) date('m', $timestamp)];
    $ano = date('Y', $timestamp);
    return "$dia de $mes de $ano";
}

$message = '';
$generated_contract = '';
$selected_partnership_id = $_POST['partnership_id'] ?? null;
$selected_template_id = $_POST['template_id'] ?? null;
$include_attachments_val = isset($_POST['include_attachments']) ? 1 : 0;

// Handle Template Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_template') {
    try {
        $sql = "INSERT INTO contracts (name, template_text, created_by) VALUES (?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_POST['name'], $_POST['template_text'], $_SESSION['user_id']]);
        $message = "Modelo de contrato salvo com sucesso!";
    } catch (PDOException $e) {
        $message = "Erro ao salvar modelo: " . $e->getMessage();
    }
}

// Handle Template Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_template') {
    try {
        // Verify ownership
        $stmtOwner = $pdo->prepare("SELECT created_by FROM contracts WHERE id = ?");
        $stmtOwner->execute([$_POST['template_id']]);
        $owner = $stmtOwner->fetchColumn();
        if ($owner != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
            throw new Exception("Você não tem permissão para editar este modelo.");
        }

        $sql = "UPDATE contracts SET name = ?, template_text = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_POST['name'], $_POST['template_text'], $_POST['template_id']]);
        $message = "Modelo atualizado com sucesso!";
    } catch (Exception $e) {
        $message = "Erro ao atualizar modelo: " . $e->getMessage();
    }
}

// Handle Template Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_template') {
    try {
        // Verify ownership
        $stmtOwner = $pdo->prepare("SELECT created_by FROM contracts WHERE id = ?");
        $stmtOwner->execute([$_POST['template_id']]);
        $owner = $stmtOwner->fetchColumn();
        if ($owner != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
            throw new Exception("Você não tem permissão para excluir este modelo.");
        }

        $sql = "DELETE FROM contracts WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_POST['template_id']]);
        $message = "Modelo excluído com sucesso!";
    } catch (Exception $e) {
        $message = "Erro ao excluir modelo: " . $e->getMessage();
    }
}

// Handle Contract Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    $partnership_id = $_POST['partnership_id'];
    $template_id = $_POST['template_id'];

    // Encode logo for contract
    $logoPath = 'assets/logo.png';
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $logoData = file_get_contents($logoPath);
        $logoType = pathinfo($logoPath, PATHINFO_EXTENSION);
        $logoBase64 = 'data:image/' . $logoType . ';base64,' . base64_encode($logoData);
    }
    $logoHtml = $logoBase64 ? '<img src="' . $logoBase64 . '" style="height: 80px; margin-bottom: 20px; vertical-align: middle;">' : '';

    // Fetch Partnership Data
    $sql = "SELECT p.*, 
                   own.name as owner_name, own.cpf as owner_cpf, own.address as owner_address,
                   own.nationality as owner_nationality, own.marital_status as owner_marital_status,
                   own.profession as owner_profession, own.identity as owner_identity,
                   own.city as owner_city, own.state as owner_state, own.zip as owner_zip,
                   own.bank_code as owner_bank_code, own.agency as owner_agency,
                   own.account_number as owner_account_number, own.pix as owner_pix,
                   inv.name as investor_name, inv.cpf as investor_cpf, inv.address as investor_address,
                   inv.nationality as investor_nationality, inv.marital_status as investor_marital_status,
                   inv.profession as investor_profession, inv.identity as investor_identity,
                   inv.city as investor_city, inv.state as investor_state, inv.zip as investor_zip,
                   inv.bank_code as investor_bank_code, inv.agency as investor_agency,
                   inv.account_number as investor_account_number, inv.pix as investor_pix,
                   conf.name as confinement_name, conf.cpf as confinement_cpf, conf.address as confinement_address,
                   conf.nationality as confinement_nationality, conf.marital_status as confinement_marital_status,
                   conf.profession as confinement_profession, conf.identity as confinement_identity,
                   conf.city as confinement_city, conf.state as confinement_state, conf.zip as confinement_zip,
                   conf.bank_code as confinement_bank_code, conf.agency as confinement_agency,
                   conf.account_number as confinement_account_number, conf.pix as confinement_pix
            FROM partnerships p
            JOIN partners own ON p.owner_id = own.id
            JOIN partners inv ON p.investor_id = inv.id
            LEFT JOIN partners conf ON p.confinamento_id = conf.id
            WHERE p.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$partnership_id]);
    $data = $stmt->fetch();

    // Fetch Lots Data
    $sqlLots = "SELECT l.*, pl.projected_value, pl.monthly_rate as pl_monthly_rate, pl.slaughter_date as pl_slaughter_date
                FROM partnership_lots pl 
                JOIN lots l ON pl.lot_id = l.id 
                WHERE pl.partnership_id = ?";
    $stmtLots = $pdo->prepare($sqlLots);
    $stmtLots->execute([$partnership_id]);
    $lots = $stmtLots->fetchAll();

    // Fetch Template
    $stmtTemplate = $pdo->prepare("SELECT template_text FROM contracts WHERE id = ?");
    $stmtTemplate->execute([$template_id]);
    $template = $stmtTemplate->fetchColumn();

    if ($data && $template) {
        // Prepare Lots String (HTML format)
        $lotsString = "";
        $lotsTable = "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
        $lotsTable .= "<thead><tr><th>Lote</th><th>Raça</th><th>Categoria</th><th>Qtd</th><th>Peso (kg)</th><th>Data</th><th>Preço (@)</th><th>Abate</th></tr></thead><tbody>";

        foreach ($lots as $l) {
            $lotsString .= "Lote {$l['lot_number']} ({$l['breed']}), Valor Projetado: R$ " . number_format($l['projected_value'], 2, ',', '.') . "<br>";

            $lotsTable .= "<tr>";
            $lotsTable .= "<td>{$l['lot_number']}</td>";
            $lotsTable .= "<td>{$l['breed']}</td>";
            $lotsTable .= "<td>{$l['category']}</td>";
            $lotsTable .= "<td>{$l['animal_count']}</td>";
            $lotsTable .= "<td>" . number_format($l['protocol_weight'], 2, ',', '.') . "</td>";
            $lotsTable .= "<td>" . date('d/m/Y', strtotime($l['protocol_date'])) . "</td>";
            $lotsTable .= "<td>" . number_format($l['indexed_price'], 2, ',', '.') . "</td>";
            $lotsTable .= "<td>" . date('d/m/Y', strtotime($l['exit_forecast_date'])) . "</td>";
            $lotsTable .= "</tr>";
        }
        $lotsTable .= "</tbody></table>";

        // Prepare Value Formation Table
        $formationTable = "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
        $formationTable .= "<thead><tr><th>Lote</th><th>Valor Aporte</th><th>Engorda (%)</th><th>Dias</th><th>Valor Projetado</th></tr></thead><tbody>";

        foreach ($lots as $l) {
            // Calculate Aporte (Principal)
            // Months between Partnership Start and Slaughter Date
            $start = new DateTime($data['start_date']);
            $end = new DateTime($l['pl_slaughter_date'] ?? $l['exit_forecast_date']);
            // Official rule: months = total days / 30 (calculateMonthsBetween)
            $months = calculateMonthsBetween($data['start_date'], ($l['pl_slaughter_date'] ?? $l['exit_forecast_date']));
            if ($months <= 0)
                $months = 0.0001;

            $rate = floatval($l['pl_monthly_rate'] ?? 0);
            $projected = floatval($l['projected_value']);
            $aporte = $projected / pow((1 + $rate / 100), $months);

            // Calculate Days between Partnership Start and Slaughter Date
            $diffDays = $start->diff($end)->days;

            $formationTable .= "<tr>";
            $formationTable .= "<td>{$l['lot_number']}</td>";
            $formationTable .= "<td>R$ " . number_format($aporte, 2, ',', '.') . "</td>";
            $formationTable .= "<td>" . number_format($rate, 2, ',', '.') . "%</td>";
            $formationTable .= "<td>{$diffDays}</td>";
            $formationTable .= "<td>R$ " . number_format($projected, 2, ',', '.') . "</td>";
            $formationTable .= "</tr>";
        }
        $formationTable .= "</tbody></table>";

        // Prepare Liquidations Table
        $liquidationsTable = "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
        $liquidationsTable .= "<thead><tr><th>Lote</th><th>Valor Projetado</th><th>Previsão de Abate</th></tr></thead><tbody>";

        foreach ($lots as $l) {
            $slaughterDate = !empty($l['pl_slaughter_date']) ? $l['pl_slaughter_date'] : $l['exit_forecast_date'];

            $liquidationsTable .= "<tr>";
            $liquidationsTable .= "<td>{$l['lot_number']}</td>";
            $liquidationsTable .= "<td>R$ " . number_format($l['projected_value'], 2, ',', '.') . "</td>";
            $liquidationsTable .= "<td>" . ($slaughterDate ? date('d/m/Y', strtotime($slaughterDate)) : '-') . "</td>";
            $liquidationsTable .= "</tr>";
        }
        $liquidationsTable .= "</tbody></table>";

        // Calculate Total Projected Value
        $totalProjected = 0;
        foreach ($lots as $l) {
            $totalProjected += floatval($l['projected_value']);
        }

        // Replacements
        $replacements = [
            '{{LOGO}}' => $logoHtml,
            // Proprietário
            '{{PARCEIRO_PROPRIETARIO}}' => $data['owner_name'],
            '{{CPF_PROPRIETARIO}}' => $data['owner_cpf'],
            '{{RG_PROPRIETARIO}}' => $data['owner_identity'],
            '{{ENDERECO_PROPRIETARIO}}' => $data['owner_address'],
            '{{CIDADE_PROPRIETARIO}}' => $data['owner_city'],
            '{{ESTADO_PROPRIETARIO}}' => $data['owner_state'],
            '{{CEP_PROPRIETARIO}}' => $data['owner_zip'],
            '{{NACIONALIDADE_PROPRIETARIO}}' => $data['owner_nationality'],
            '{{ESTADO_CIVIL_PROPRIETARIO}}' => $data['owner_marital_status'],
            '{{PROFISSAO_PROPRIETARIO}}' => $data['owner_profession'],
            '{{BANCO_PROPRIETARIO}}' => $data['owner_bank_code'],
            '{{AGENCIA_PROPRIETARIO}}' => $data['owner_agency'],
            '{{CONTA_PROPRIETARIO}}' => $data['owner_account_number'],
            '{{PIX_PROPRIETARIO}}' => $data['owner_pix'],

            // Investidor
            '{{PARCEIRO_INVESTIDOR}}' => $data['investor_name'],
            '{{CPF_INVESTIDOR}}' => $data['investor_cpf'],
            '{{RG_INVESTIDOR}}' => $data['investor_identity'],
            '{{ENDERECO_INVESTIDOR}}' => $data['investor_address'],
            '{{CIDADE_INVESTIDOR}}' => $data['investor_city'],
            '{{ESTADO_INVESTIDOR}}' => $data['investor_state'],
            '{{CEP_INVESTIDOR}}' => $data['investor_zip'],
            '{{NACIONALIDADE_INVESTIDOR}}' => $data['investor_nationality'],
            '{{ESTADO_CIVIL_INVESTIDOR}}' => $data['investor_marital_status'],
            '{{PROFISSAO_INVESTIDOR}}' => $data['investor_profession'],
            '{{BANCO_INVESTIDOR}}' => $data['investor_bank_code'],
            '{{AGENCIA_INVESTIDOR}}' => $data['investor_agency'],
            '{{CONTA_INVESTIDOR}}' => $data['investor_account_number'],
            '{{PIX_INVESTIDOR}}' => $data['investor_pix'],

            // Confinamento
            '{{CONFINAMENTO}}' => $data['confinement_name'] ?? '',
            '{{CPF_CONFINAMENTO}}' => $data['confinement_cpf'] ?? '',
            '{{RG_CONFINAMENTO}}' => $data['confinement_identity'] ?? '',
            '{{ENDERECO_CONFINAMENTO}}' => $data['confinement_address'] ?? '',
            '{{CIDADE_CONFINAMENTO}}' => $data['confinement_city'] ?? '',
            '{{ESTADO_CONFINAMENTO}}' => $data['confinement_state'] ?? '',
            '{{CEP_CONFINAMENTO}}' => $data['confinement_zip'] ?? '',
            '{{NACIONALIDADE_CONFINAMENTO}}' => $data['confinement_nationality'] ?? '',
            '{{ESTADO_CIVIL_CONFINAMENTO}}' => $data['confinement_marital_status'] ?? '',
            '{{PROFISSAO_CONFINAMENTO}}' => $data['confinement_profession'] ?? '',
            '{{BANCO_CONFINAMENTO}}' => $data['confinement_bank_code'] ?? '',
            '{{AGENCIA_CONFINAMENTO}}' => $data['confinement_agency'] ?? '',
            '{{CONTA_CONFINAMENTO}}' => $data['confinement_account_number'] ?? '',
            '{{PIX_CONFINAMENTO}}' => $data['confinement_pix'] ?? '',

            // Parceria
            '{{VALOR_PARCERIA}}' => number_format($data['total_value'], 2, ',', '.'),
            '{{VALOR_PARCERIA_EXTENSO}}' => valorPorExtenso($data['total_value']),
            '{{VALOR_PROJETADO_TOTAL}}' => number_format($totalProjected, 2, ',', '.'),
            '{{VALOR_PROJETADO_TOTAL_EXTENSO}}' => valorPorExtenso($totalProjected),
            '{{DATA_INICIO}}' => date('d/m/Y', strtotime($data['start_date'])),
            '{{DATA_INICIO_EXTENSO}}' => dataPorExtenso($data['start_date']),

            // Lotes
            '{{LOTES}}' => $lotsString,
            '{{LISTA_LOTES_DETALHADA}}' => $lotsTable,
            '{{TABELA_FORMACAO_VALOR}}' => $formationTable,
            '{{TABELA_LIQUIDACOES}}' => $liquidationsTable
        ];

        $generated_contract = str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}

// Fetch Templates and Partnerships for Dropdowns
$templates = $pdo->query("SELECT * FROM contracts ORDER BY created_at DESC")->fetchAll();

require_once __DIR__ . '/financial_calculations.php';

// Fetch all partnerships and filter by active status
$raw_partners = $pdo->query("
    SELECT p.*, own.name as owner_name, inv.name as investor_name,
           (SELECT SUM(projected_value) FROM partnership_lots WHERE partnership_id = p.id) as total_projected_value
    FROM partnerships p
    JOIN partners own ON p.owner_id = own.id
    JOIN partners inv ON p.investor_id = inv.id
    ORDER BY p.created_at DESC
")->fetchAll();

// Pre-fetch lots and liquidations for filtering
$allLots = [];
$lotRes = $pdo->query("SELECT * FROM partnership_lots");
while($r = $lotRes->fetch(PDO::FETCH_ASSOC)) { $allLots[$r['partnership_id']][] = $r; }

$allLiqs = [];
$liqRes = $pdo->query("SELECT * FROM partnership_liquidations");
while($r = $liqRes->fetch(PDO::FETCH_ASSOC)) { $allLiqs[$r['partnership_id']][] = $r; }

$partners = [];
foreach ($raw_partners as $p) {
    $lots = $allLots[$p['id']] ?? [];
    $liqs = $allLiqs[$p['id']] ?? [];
    $calcState = calculatePartnershipState($p, $lots, $liqs);
    
    // Calculate Projected Balance using furthest lot rate (same as partnerships.php)
    $furthestLotRate = 0;
    if (!empty($lots)) {
        usort($lots, function ($a, $b) {
            return strtotime($a['slaughter_date']) - strtotime($b['slaughter_date']);
        });
        $furthestLotRate = floatval($lots[count($lots) - 1]['monthly_rate']);
    }
    $projected_balance = calculateProjectedBalance($calcState['current_balance'], date('Y-m-d'), $furthestLotRate, $lots, $liqs);

    // Active if current or projected balance > 0
    if ($calcState['current_balance'] >= 0.01 || $projected_balance >= 0.01) {
        $partners[] = $p;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contratos - Cattle Invest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <!-- Quill.js CSS -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        /* Customize Quill for dark theme */
        .ql-toolbar.ql-snow {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px 8px 0 0;
        }

        .ql-container.ql-snow {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-top: none;
            border-radius: 0 0 8px 8px;
            color: #fff;
            min-height: 400px;
        }

        .ql-editor {
            color: #fff;
            font-size: 12pt;
        }

        .ql-snow .ql-stroke {
            stroke: #94a3b8;
        }

        .ql-snow .ql-fill {
            fill: #94a3b8;
        }

        .ql-snow .ql-picker-label {
            color: #94a3b8;
        }

        .ql-snow .ql-picker-options {
            background: #1a1a2e;
            border-color: rgba(255, 255, 255, 0.1);
        }

        .ql-snow .ql-picker-item:hover {
            color: #8b5cf6;
        }

        .ql-toolbar.ql-snow .ql-picker-label:hover,
        .ql-toolbar.ql-snow button:hover {
            color: #8b5cf6;
        }

        .ql-toolbar.ql-snow .ql-picker-label:hover .ql-stroke,
        .ql-toolbar.ql-snow button:hover .ql-stroke {
            stroke: #8b5cf6;
        }

        #editor-container {
            margin-bottom: 1rem;
        }

        /* Select2 Dark Theme Overrides */
        .select2-container--default .select2-selection--single {
            background-color: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            height: 46px;
            color: #fff;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #fff;
            line-height: 44px;
            padding-left: 12px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 44px;
        }
        .select2-dropdown {
            background-color: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .select2-search__field {
            background-color: rgba(15, 23, 42, 0.6) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #fff !important;
        }
        .select2-results__option--highlighted[aria-selected] {
            background-color: var(--primary-color) !important;
        }
        .select2-results__option[aria-selected=true] {
            background-color: rgba(255, 255, 255, 0.05);
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
            <!-- Create Template -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2 id="form-title" style="margin: 0;">Novo Modelo</h2>
                    <button id="cancel-edit-btn" type="button" class="btn btn-secondary" onclick="resetForm()"
                        style="display: none; padding: 0.25rem 0.5rem; font-size: 0.8rem;">Cancelar Edição</button>
                </div>


                <!-- Variable Insertion Toolbar -->
                <div
                    style="background: rgba(255,255,255,0.05); padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid rgba(255,255,255,0.1);">
                    <h3 style="margin-top: 0; font-size: 0.9rem; color: #94a3b8; margin-bottom: 0.5rem;">Inserir
                        Variáveis</h3>

                    <div class="grid">
                        <!-- Partners -->
                        <div style="grid-column: span 2;">
                            <label style="font-size: 0.8rem;">Parceiros (Proprietário / Investidor)</label>
                            <div style="display: flex; gap: 0.25rem;">
                                <select id="var_partners" style="font-size: 0.8rem; padding: 0.25rem; width: 100%;">
                                    <optgroup label="Proprietário">
                                        <option value="{{PARCEIRO_PROPRIETARIO}}">Nome</option>
                                        <option value="{{CPF_PROPRIETARIO}}">CPF</option>
                                        <option value="{{RG_PROPRIETARIO}}">RG</option>
                                        <option value="{{NACIONALIDADE_PROPRIETARIO}}">Nacionalidade</option>
                                        <option value="{{ESTADO_CIVIL_PROPRIETARIO}}">Estado Civil</option>
                                        <option value="{{PROFISSAO_PROPRIETARIO}}">Profissão</option>
                                        <option value="{{ENDERECO_PROPRIETARIO}}">Endereço</option>
                                        <option value="{{CIDADE_PROPRIETARIO}}">Cidade</option>
                                        <option value="{{ESTADO_PROPRIETARIO}}">Estado</option>
                                        <option value="{{CEP_PROPRIETARIO}}">CEP</option>
                                        <option value="{{BANCO_PROPRIETARIO}}">Banco</option>
                                        <option value="{{AGENCIA_PROPRIETARIO}}">Agência</option>
                                        <option value="{{CONTA_PROPRIETARIO}}">Conta</option>
                                        <option value="{{PIX_PROPRIETARIO}}">PIX</option>
                                    </optgroup>
                                    <optgroup label="Investidor">
                                        <option value="{{PARCEIRO_INVESTIDOR}}">Nome</option>
                                        <option value="{{CPF_INVESTIDOR}}">CPF</option>
                                        <option value="{{RG_INVESTIDOR}}">RG</option>
                                        <option value="{{NACIONALIDADE_INVESTIDOR}}">Nacionalidade</option>
                                        <option value="{{ESTADO_CIVIL_INVESTIDOR}}">Estado Civil</option>
                                        <option value="{{PROFISSAO_INVESTIDOR}}">Profissão</option>
                                        <option value="{{ENDERECO_INVESTIDOR}}">Endereço</option>
                                        <option value="{{CIDADE_INVESTIDOR}}">Cidade</option>
                                        <option value="{{ESTADO_INVESTIDOR}}">Estado</option>
                                        <option value="{{CEP_INVESTIDOR}}">CEP</option>
                                        <option value="{{BANCO_INVESTIDOR}}">Banco</option>
                                        <option value="{{AGENCIA_INVESTIDOR}}">Agência</option>
                                        <option value="{{CONTA_INVESTIDOR}}">Conta</option>
                                        <option value="{{PIX_INVESTIDOR}}">PIX</option>
                                    </optgroup>
                                    <optgroup label="Confinamento">
                                        <option value="{{CONFINAMENTO}}">Nome</option>
                                        <option value="{{CPF_CONFINAMENTO}}">CPF</option>
                                        <option value="{{RG_CONFINAMENTO}}">RG</option>
                                        <option value="{{NACIONALIDADE_CONFINAMENTO}}">Nacionalidade</option>
                                        <option value="{{ESTADO_CIVIL_CONFINAMENTO}}">Estado Civil</option>
                                        <option value="{{PROFISSAO_CONFINAMENTO}}">Profissão</option>
                                        <option value="{{ENDERECO_CONFINAMENTO}}">Endereço</option>
                                        <option value="{{CIDADE_CONFINAMENTO}}">Cidade</option>
                                        <option value="{{ESTADO_CONFINAMENTO}}">Estado</option>
                                        <option value="{{CEP_CONFINAMENTO}}">CEP</option>
                                        <option value="{{BANCO_CONFINAMENTO}}">Banco</option>
                                        <option value="{{AGENCIA_CONFINAMENTO}}">Agência</option>
                                        <option value="{{CONTA_CONFINAMENTO}}">Conta</option>
                                        <option value="{{PIX_CONFINAMENTO}}">PIX</option>
                                    </optgroup>
                                </select>
                                <button type="button" class="btn btn-secondary" onclick="insertVariable('var_partners')"
                                    style="padding: 0.25rem 0.5rem;">+</button>
                            </div>
                        </div>

                        <!-- Partnerships -->
                        <div>
                            <label style="font-size: 0.8rem;">Parcerias</label>
                            <div style="display: flex; gap: 0.25rem;">
                                <select id="var_partnerships" style="font-size: 0.8rem; padding: 0.25rem; width: 100%;">
                                    <option value="{{VALOR_PARCERIA}}">Valor Total</option>
                                    <option value="{{VALOR_PARCERIA_EXTENSO}}">Valor Total (Extenso)</option>
                                    <option value="{{VALOR_PROJETADO_TOTAL}}">Valor Projetado Total</option>
                                    <option value="{{VALOR_PROJETADO_TOTAL_EXTENSO}}">Valor Projetado Total (Extenso)
                                    </option>
                                    <option value="{{DATA_INICIO}}">Data Início</option>
                                    <option value="{{DATA_INICIO_EXTENSO}}">Data Início (Extenso)</option>
                                </select>
                                <button type="button" class="btn btn-secondary"
                                    onclick="insertVariable('var_partnerships')"
                                    style="padding: 0.25rem 0.5rem;">+</button>
                            </div>
                        </div>

                        <!-- Lots -->
                        <div>
                            <label style="font-size: 0.8rem;">Lotes</label>
                            <div style="display: flex; gap: 0.25rem;">
                                <select id="var_lots" style="font-size: 0.8rem; padding: 0.25rem; width: 100%;">
                                    <option value="{{LISTA_LOTES_DETALHADA}}">Tabela Detalhada</option>
                                    <option value="{{TABELA_FORMACAO_VALOR}}">Formação do Valor</option>
                                    <option value="{{TABELA_LIQUIDACOES}}">Liquidações</option>
                                    <option value="{{LOTES}}">Lista Simples</option>
                                </select>
                                <button type="button" class="btn btn-secondary" onclick="insertVariable('var_lots')"
                                    style="padding: 0.25rem 0.5rem;">+</button>
                            </div>
                        </div>

                        <!-- General -->
                        <div>
                            <label style="font-size: 0.8rem;">Geral</label>
                            <div style="display: flex; gap: 0.25rem;">
                                <select id="var_general" style="font-size: 0.8rem; padding: 0.25rem; width: 100%;">
                                    <option value="{{LOGO}}">Logo do Aplicativo</option>
                                </select>
                                <button type="button" class="btn btn-secondary" onclick="insertVariable('var_general')"
                                    style="padding: 0.25rem 0.5rem;">+</button>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="POST" action="" onsubmit="return saveTemplate()" id="template-form">
                    <input type="hidden" name="action" id="form-action" value="create_template">
                    <input type="hidden" name="template_id" id="template_id">

                    <div class="form-group">
                        <label>Nome do Modelo</label>
                        <input type="text" name="name" id="template_name" required
                            placeholder="Ex: Contrato Padrão 2024">
                    </div>

                    <div class="form-group">
                        <label>Texto do Contrato</label>
                        <div id="editor-container"></div>
                        <input type="hidden" name="template_text" id="template_text">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%" id="submit-btn">Salvar
                        Modelo</button>
                </form>

            </div>

            <!-- Generate Contract -->
            <div class="card">
                <h2>Gerar Contrato</h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="generate">
                    <div class="form-group">
                        <label>Selecione a Parceria</label>
                        <select name="partnership_id" id="select_partnership_id" class="select2" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($partners as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $p['id'] == $selected_partnership_id ? 'selected' : '' ?>>#<?= $p['id'] ?> -
                                    <?= date('d/m/Y', strtotime($p['start_date'])) ?>
                                    (<?= dataPorExtenso($p['start_date']) ?>) -
                                    <?= htmlspecialchars($p['owner_name']) ?>
                                    & <?= htmlspecialchars($p['investor_name']) ?>
                                    - Proj: R$ <?= number_format($p['total_projected_value'] ?? 0, 2, ',', '.') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Selecione o Modelo</label>
                        <div style="display: flex; gap: 0.5rem;">
                            <select name="template_id" id="select_template_id" required style="flex: 1;">
                                <option value="">Selecione...</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= $t['id'] == $selected_template_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-icon btn-edit" onclick="editSelectedTemplate()"
                                title="Editar Modelo Selecionado">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button type="button" class="btn btn-icon btn-delete" onclick="deleteSelectedTemplate()"
                                title="Excluir Modelo Selecionado">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem; margin-top: 1rem;">
                        <input type="checkbox" id="include_attachments" name="include_attachments" style="width: auto; margin: 0;" <?= $include_attachments_val ? 'checked' : '' ?>>
                        <label for="include_attachments" style="margin: 0; cursor: pointer;">Incluir anexos da parceria no PDF/DOCX</label>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%">Gerar Contrato</button>
                </form>

                <?php if ($generated_contract): ?>
                    <div style="margin-top: 2rem; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 1rem;">
                        <h3>Contrato Gerado</h3>
                        <div id="generated_contract"
                            style="background: rgba(255,255,255,0.05); padding: 1.5rem; border-radius: 8px; min-height: 300px; max-height: 500px; overflow-y: auto;">
                            <?= $generated_contract ?>
                        </div>
                        <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                            <button onclick="window.print()" class="btn btn-secondary" style="flex: 1;">Imprimir</button>
                            <button onclick="exportPDF()" class="btn btn-primary" style="flex: 1;">Exportar PDF</button>
                            <button onclick="exportDOCX()" class="btn btn-primary" style="flex: 1;">Exportar DOCX</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quill.js JavaScript -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script>
        // Pass PHP templates data to JS
        const templatesData = <?= json_encode($templates) ?>;
        const currentUserId = <?= json_encode($_SESSION['user_id']) ?>;
        const currentUserRole = <?= json_encode($_SESSION['role']) ?>;

        // Initialize Quill editor
        var quill = new Quill('#editor-container', {
            theme: 'snow',
            placeholder: 'Digite o texto do contrato aqui...',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    [{ 'font': [] }],
                    [{ 'size': ['small', false, 'large', 'huge'] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'color': [] }, { 'background': [] }],
                    [{ 'align': [] }],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    [{ 'indent': '-1' }, { 'indent': '+1' }],
                    ['link'],
                    ['clean']
                ]
            }
        });

        function insertVariable(selectId) {
            const select = document.getElementById(selectId);
            const variable = select.value;

            // Get current selection index
            let range = quill.getSelection(true);

            // If lost focus, assume end of document
            if (!range) {
                range = { index: quill.getLength() };
            }

            // Insert text
            quill.insertText(range.index, variable);

            // Move cursor after inserted text
            quill.setSelection(range.index + variable.length);
        }

        // Save template content before form submission
        function saveTemplate() {
            document.getElementById('template_text').value = quill.root.innerHTML;
            return true;
        }

        function exportPDF() {
            const content = document.getElementById('generated_contract').innerHTML;
            const partnershipId = document.getElementById('select_partnership_id').value;
            const includeAttachments = document.getElementById('include_attachments').checked ? 1 : 0;
            
            if (!partnershipId) {
                alert('ID da parceria não encontrado.');
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_pdf.php';
            form.target = '_blank';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'content';
            input.value = content;
            form.appendChild(input);

            const inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'partnership_id';
            inputId.value = partnershipId;
            form.appendChild(inputId);

            const inputAttach = document.createElement('input');
            inputAttach.type = 'hidden';
            inputAttach.name = 'include_attachments';
            inputAttach.value = includeAttachments;
            form.appendChild(inputAttach);

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        function exportDOCX() {
            const content = document.getElementById('generated_contract').innerHTML;
            const partnershipId = document.getElementById('select_partnership_id').value;
            const includeAttachments = document.getElementById('include_attachments').checked ? 1 : 0;

            if (!partnershipId) {
                alert('ID da parceria não encontrado.');
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_docx.php';
            form.target = '_blank';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'content';
            input.value = content;
            form.appendChild(input);

            const inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'partnership_id';
            inputId.value = partnershipId;
            form.appendChild(inputId);

            const inputAttach = document.createElement('input');
            inputAttach.type = 'hidden';
            inputAttach.name = 'include_attachments';
            inputAttach.value = includeAttachments;
            form.appendChild(inputAttach);

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        function editSelectedTemplate() {
            const select = document.getElementById('select_template_id');
            const templateId = select.value;

            if (!templateId) {
                alert('Por favor, selecione um modelo para editar.');
                return;
            }

            const template = templatesData.find(t => t.id == templateId);
            if (template) {
                if (template.created_by != currentUserId && currentUserRole !== 'admin') {
                    alert('Você não tem permissão para editar este modelo.');
                    return;
                }
                
                // Populate form
                document.getElementById('template_id').value = template.id;
                document.getElementById('template_name').value = template.name;
                quill.root.innerHTML = template.template_text;

                // Update UI state
                document.getElementById('form-action').value = 'update_template';
                document.getElementById('form-title').innerText = 'Editar Modelo';
                document.getElementById('submit-btn').innerText = 'Atualizar Modelo';
                document.getElementById('cancel-edit-btn').style.display = 'block';

                // Scroll to form
                document.getElementById('template-form').scrollIntoView({ behavior: 'smooth' });
            }
        }

        function deleteSelectedTemplate() {
            const select = document.getElementById('select_template_id');
            const templateId = select.value;

            if (!templateId) {
                alert('Por favor, selecione um modelo para excluir.');
                return;
            }

            const template = templatesData.find(t => t.id == templateId);
            if (template) {
                if (template.created_by != currentUserId && currentUserRole !== 'admin') {
                    alert('Você não tem permissão para excluir este modelo.');
                    return;
                }
                
                if (confirm(`Tem certeza que deseja excluir o modelo "${template.name}"? Esta ação não pode ser desfeita.`)) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';

                    const inputAction = document.createElement('input');
                    inputAction.type = 'hidden';
                    inputAction.name = 'action';
                    inputAction.value = 'delete_template';

                    const inputId = document.createElement('input');
                    inputId.type = 'hidden';
                    inputId.name = 'template_id';
                    inputId.value = templateId;

                    form.appendChild(inputAction);
                    form.appendChild(inputId);
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }

        function resetForm() {
            document.getElementById('template_id').value = '';
            document.getElementById('template_name').value = '';
            quill.root.innerHTML = '';

            document.getElementById('form-action').value = 'create_template';
            document.getElementById('form-title').innerText = 'Novo Modelo';
            document.getElementById('submit-btn').innerText = 'Salvar Modelo';
            document.getElementById('cancel-edit-btn').style.display = 'none';
        }
    </script>

    <!-- jQuery (Required for Select2) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#select_partnership_id').select2({
                placeholder: "Selecione a Parceria...",
                allowClear: true,
                width: '100%'
            });
        });
    </script>
</body>

</html>