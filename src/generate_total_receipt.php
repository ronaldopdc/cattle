<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Acesso inválido.");
}

$investor = $_POST['investor'] ?? '';
$investor_cpf = $_POST['investor_cpf'] ?? '';
$owner = $_POST['owner'] ?? '';
$owner_cpf = $_POST['owner_cpf'] ?? '';
$partnership_id = $_POST['partnership_id'] ?? '';
$partnership_start_date = $_POST['partnership_start_date'] ?? '';
$liquidations_json = $_POST['liquidations'] ?? '[]';

$liquidations = json_decode($liquidations_json, true);
if (!is_array($liquidations)) {
    die("Dados de liquidação inválidos.");
}

// Calculate total
$totalAmount = 0;
foreach ($liquidations as $liq) {
    $totalAmount += floatval($liq['amount']);
}

// --- Helper Functions (copied from contracts.php for standalone usage) ---
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
    // Format: 1.000,00 -> 1000.00
    $valor = str_replace(',', '.', str_replace('.', '', $valor));
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
            $proximos = 0;
            for ($j = $i + 1; $j <= $fim; $j++)
                if ($inteiro[$j] != "000")
                    $proximos++;
            $rt .= ($proximos > 1) ? ", " : " e ";
        }
    }

    // Fix double spaces
    $rt = preg_replace('/\s+/', ' ', $rt);
    return ($rt ? trim($rt) : "zero reais");
}

function dataPorExtenso($data)
{
    if (!$data)
        return "";
    $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $timestamp = strtotime($data);
    $dia = date('d', $timestamp);
    $mes = $meses[(int) date('m', $timestamp)];
    $ano = date('Y', $timestamp);
    return "$dia de $mes de $ano";
}

$amountExtenso = valorPorExtenso(number_format($totalAmount, 2, ',', '.'));
$partnershipDateExtenso = dataPorExtenso($partnership_start_date);

// Current date for signature
$months = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$month = $months[(int) date('m')];
$day = date('d');
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Recibo de Quitação Total - Parceria #
        <?= $partnership_id ?>
    </title>
    <style>
        @page {
            size: A4;
            margin: 2cm;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            margin: 0;
            padding: 2cm;
            color: #000;
            line-height: 1.6;
            font-size: 12pt;
        }

        .header {
            text-align: center;
            margin-bottom: 2rem;
            border-bottom: 2px solid #000;
            padding-bottom: 1rem;
        }

        .logo {
            max-height: 80px;
            margin-bottom: 1rem;
        }

        .title {
            font-size: 18pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .content {
            margin-top: 2rem;
            text-align: justify;
            min-height: 300px;
        }

        .value-box {
            text-align: right;
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 2rem;
            background: #f3f3f3;
            padding: 0.5rem 1rem;
            border: 1px solid #ccc;
            width: fit-content;
            margin-left: auto;
        }

        @media print {
            .value-box {
                background: none !important;
            }
        }

        .signatures {
            margin-top: 4rem;
            display: flex;
            justify-content: space-between;
            gap: 2rem;
        }

        .signature-box {
            flex: 1;
            border-top: 1px solid #000;
            padding-top: 0.5rem;
            text-align: center;
        }

        .role-label {
            font-size: 0.9rem;
            margin-top: 0.2rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .cpf-label {
            font-size: 0.9rem;
            color: #333;
        }

        .liquidation-list {
            margin: 1rem 0;
            padding-left: 2rem;
        }

        .liquidation-item {
            margin-bottom: 0.25rem;
        }
    </style>
</head>

<body>
    <div class="header">
        <img src="assets/logo.png" alt="Cattle Invest" class="logo">
        <div class="title">Recibo de Quitação Total</div>
        <div>Referente à Parceria #
            <?= $partnership_id ?>
        </div>
    </div>

    <div class="value-box">
        Total: R$
        <?= number_format($totalAmount, 2, ',', '.') ?>
    </div>

    <div class="content">
        <p>
            Eu, <strong><?= htmlspecialchars($investor) ?></strong>, CPF
            <strong><?= htmlspecialchars($investor_cpf) ?></strong>, recebi de
            <strong><?= htmlspecialchars($owner) ?></strong>, CPF <strong><?= htmlspecialchars($owner_cpf) ?></strong>,
            a importância TOTAL de <strong>R$ <?= number_format($totalAmount, 2, ',', '.') ?>
                (<?= $amountExtenso ?>)</strong>, referente às liquidações do contrato de parceria para engorda de gado
            firmado entre as partes na data de <strong><?= $partnershipDateExtenso ?></strong>, conforme detalhado
            abaixo:
        </p>

        <div class="liquidation-list">
            <?php
            $count = count($liquidations);
            foreach ($liquidations as $index => $liq):
                $punctuation = ($index === $count - 1) ? '.' : ';';
                ?>
                <div class="liquidation-item">- Data: <strong><?= date('d/m/Y', strtotime($liq['date'])) ?></strong> -
                    Valor: <strong>R$ <?= number_format($liq['amount'], 2, ',', '.') ?></strong><?= $punctuation ?></div>
            <?php endforeach; ?>
        </div>

        <p>
            Dou plena, rasa e geral quitação da referida importância e do contrato de parceria mencionado, para nada
            mais reclamar a qualquer título, seja no presente ou no futuro.
        </p>
        <p style="margin-top: 2rem; text-align: right;">
            <?= $day ?> de
            <?= $month ?> de
            <?= $year ?>.
        </p>
    </div>

    <div class="signatures">
        <div class="signature-box">
            <strong>
                <?= htmlspecialchars($investor) ?>
            </strong><br>
            <span class="cpf-label">CPF:
                <?= htmlspecialchars($investor_cpf) ?>
            </span><br>
            <div class="role-label">Parceiro Investidor</div>
        </div>
        <div class="signature-box">
            <strong>
                <?= htmlspecialchars($owner) ?>
            </strong><br>
            <span class="cpf-label">CPF:
                <?= htmlspecialchars($owner_cpf) ?>
            </span><br>
            <div class="role-label">Parceiro Proprietário</div>
        </div>
    </div>

    <script>
        window.onload = function () { setTimeout(function () { window.print(); }, 500); };
    </script>
</body>

</html>