<?php
require_once 'config.php';
require_once 'simulation_rates.php';

// Parâmetros (com defaults do exemplo comercial: 100 bois, 450 kg, 90 dias).
$animals = (int) ($_GET['animals'] ?? 100);
$weight  = (float) str_replace(',', '.', $_GET['weight'] ?? 450);
$price   = (float) str_replace(',', '.', $_GET['price'] ?? 315);
$days    = (int) ($_GET['days'] ?? 90);

if ($animals <= 0) $animals = 100;
if ($weight <= 0)  $weight = 450;
if ($price <= 0)   $price = 315;
if ($days <= 0)    $days = 90;

$calc      = sim_calculate($animals, $weight, $price, $days);
$rateTable = sim_rate_table();
$simUrl    = sim_base_url() . '/simulacao.php';

$rialmaLogoExists = file_exists(__DIR__ . '/assets/rialma.png');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Proposta de Parceria — Rialma | Cattle Invest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        @page {
            size: A4;
            margin: 1.4cm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', Arial, sans-serif;
            color: #1e293b;
            background: #fff;
            margin: 0;
            font-size: 11pt;
            line-height: 1.5;
        }

        .sheet {
            max-width: 21cm;
            margin: 0 auto;
            padding: 0.4cm;
        }

        /* Header / logos */
        .pdf-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 3px solid #10b981;
            padding-bottom: 0.6rem;
            margin-bottom: 1.2rem;
        }

        .pdf-header .logos {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .pdf-header img.cattle {
            height: 52px;
        }

        .pdf-header img.rialma-img {
            height: 48px;
            padding-left: 1rem;
            border-left: 2px solid #e2e8f0;
        }

        .rialma-wordmark {
            display: flex;
            flex-direction: column;
            line-height: 1;
            padding-left: 1rem;
            border-left: 2px solid #e2e8f0;
        }

        .rialma-wordmark .r-name {
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            color: #15803d;
        }

        .rialma-wordmark .r-sub {
            font-size: 0.55rem;
            font-weight: 600;
            letter-spacing: 0.26em;
            color: #64748b;
            text-transform: uppercase;
            margin-top: 0.2rem;
        }

        .pdf-header .doc-tag {
            text-align: right;
            font-size: 0.72rem;
            color: #64748b;
        }

        h1.title {
            font-size: 1.7rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 0.3rem;
        }

        .subtitle {
            color: #475569;
            margin: 0 0 1.2rem;
            font-size: 0.98rem;
        }

        .lead {
            background: linear-gradient(135deg, #ecfdf5, #eff6ff);
            border: 1px solid #a7f3d0;
            border-radius: 12px;
            padding: 1rem 1.2rem;
            margin-bottom: 1.3rem;
            font-size: 0.95rem;
            color: #334155;
        }

        .lead strong {
            color: #047857;
        }

        /* Rate table */
        .section-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            margin: 1.2rem 0 0.6rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title::before {
            content: "";
            width: 6px;
            height: 18px;
            background: #10b981;
            border-radius: 3px;
            display: inline-block;
        }

        table.rates {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }

        table.rates th,
        table.rates td {
            border: 1px solid #cbd5e1;
            padding: 0.55rem 0.7rem;
            text-align: center;
            font-size: 0.9rem;
        }

        table.rates th {
            background: #0f172a;
            color: #fff;
            font-weight: 600;
        }

        table.rates td.rate {
            font-weight: 800;
            color: #047857;
            font-size: 1.05rem;
        }

        /* Simulation summary */
        .sim-box {
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .sim-box .sim-top {
            background: #0f172a;
            color: #fff;
            padding: 0.7rem 1rem;
            font-weight: 700;
            font-size: 0.95rem;
        }

        .sim-inputs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            padding: 0.8rem 1rem;
            background: #f8fafc;
            font-size: 0.85rem;
            color: #475569;
        }

        .sim-inputs span {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 0.25rem 0.8rem;
        }

        .sim-results {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1px;
            background: #e2e8f0;
        }

        .sim-results .cell {
            background: #fff;
            padding: 0.8rem 1rem;
            text-align: center;
        }

        .sim-results .cell .l {
            font-size: 0.7rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .sim-results .cell .v {
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
            margin-top: 0.25rem;
        }

        .sim-results .cell.highlight .v {
            color: #047857;
        }

        table.schedule {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }

        table.schedule th,
        table.schedule td {
            border-bottom: 1px solid #e2e8f0;
            padding: 0.45rem 0.6rem;
            text-align: right;
        }

        table.schedule th {
            color: #64748b;
            font-weight: 600;
            border-bottom: 2px solid #cbd5e1;
        }

        table.schedule th:first-child,
        table.schedule td:first-child {
            text-align: left;
        }

        table.schedule tr.total td {
            font-weight: 800;
            color: #047857;
            border-top: 2px solid #cbd5e1;
        }

        /* Footer / CTA + QR */
        .cta {
            display: flex;
            gap: 1.2rem;
            align-items: center;
            border: 1px solid #a7f3d0;
            background: #ecfdf5;
            border-radius: 12px;
            padding: 1rem 1.2rem;
            margin-top: 1rem;
        }

        .cta .qr {
            background: #fff;
            padding: 8px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            flex: 0 0 auto;
        }

        .cta .cta-text {
            font-size: 0.9rem;
            color: #334155;
        }

        .cta .cta-text b {
            color: #047857;
        }

        .cta .cta-text .link {
            word-break: break-all;
            color: #2563eb;
            font-size: 0.82rem;
            margin-top: 0.35rem;
        }

        .disclaimer {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 1rem;
            text-align: justify;
        }

        .print-bar {
            text-align: center;
            padding: 1rem;
            background: #0f172a;
        }

        .print-bar button {
            background: #10b981;
            color: #fff;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: 1rem;
        }

        @media print {
            .print-bar {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="print-bar">
        <button onclick="window.print()">🖨️ Imprimir / Salvar em PDF</button>
    </div>

    <div class="sheet">
        <div class="pdf-header">
            <div class="logos">
                <img src="logo.png" alt="Cattle Invest" class="cattle">
                <?php if ($rialmaLogoExists): ?>
                    <img src="assets/rialma.png" alt="Rialma Agropecuária" class="rialma-img">
                <?php else: ?>
                    <div class="rialma-wordmark">
                        <span class="r-name">RIALMA</span>
                        <span class="r-sub">Agropecuária</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="doc-tag">
                Proposta de Parceria de Engorda<br>
                Emitida em <?= date('d/m/Y') ?>
            </div>
        </div>

        <h1 class="title">Proposta de Parceria de Engorda</h1>
        <p class="subtitle">Você entrega a boiada. A Rialma paga à vista, em até 24 horas.</p>

        <div class="lead">
            <strong>Como funciona:</strong> você entra com a boiada e recebe o valor à vista. Sobre esse valor há uma
            <strong>participação mensal</strong>, definida pelo prazo até o abate. <strong>Prazo menor, taxa
                maior.</strong> Garantia Rialma.
        </div>

        <div class="section-title">Participação mensal por prazo</div>
        <table class="rates">
            <thead>
                <tr>
                    <?php foreach ($rateTable as $tier): ?>
                        <th><?= $tier['days'] ?> dias</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach ($rateTable as $tier): ?>
                        <td class="rate"><?= number_format($tier['rate'], 1, ',', '.') ?>% a.m.</td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>

        <div class="section-title">Sua Simulação</div>
        <div class="sim-box">
            <div class="sim-top">
                Boiada de <?= number_format($animals, 0, ',', '.') ?> bois •
                <?= $days ?> dias • participação de <?= number_format($calc['rate'], 1, ',', '.') ?>% ao mês
            </div>
            <div class="sim-inputs">
                <span><?= number_format($animals, 0, ',', '.') ?> bois</span>
                <span><?= number_format($weight, 0, ',', '.') ?> kg/animal (entrada)</span>
                <span>Arroba: <?= sim_money($price) ?></span>
                <span>Total: <?= number_format($calc['total_arrobas'], 0, ',', '.') ?> @</span>
            </div>
            <div class="sim-results">
                <div class="cell">
                    <div class="l">Valor base</div>
                    <div class="v"><?= sim_money($calc['base']) ?></div>
                </div>
                <div class="cell highlight">
                    <div class="l">Participação total</div>
                    <div class="v"><?= sim_money($calc['total_participation']) ?></div>
                </div>
                <div class="cell">
                    <div class="l">Custo médio/mês</div>
                    <div class="v"><?= sim_money($calc['avg_monthly']) ?></div>
                </div>
                <div class="cell highlight">
                    <div class="l">Total a receber</div>
                    <div class="v"><?= sim_money($calc['final']) ?></div>
                </div>
            </div>
        </div>

        <table class="schedule">
            <thead>
                <tr>
                    <th>Período</th>
                    <th>Participação do mês</th>
                    <th>Valor acumulado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($calc['schedule'] as $s): ?>
                    <tr>
                        <td><?= $s['partial'] ? ($s['month'] . 'º (' . $s['days'] . ' dias)') : ($s['month'] . 'º mês') ?></td>
                        <td><?= sim_money($s['participation']) ?></td>
                        <td><?= sim_money($s['balance']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total">
                    <td>Total (<?= $days ?> dias)</td>
                    <td><?= sim_money($calc['total_participation']) ?></td>
                    <td><?= sim_money($calc['final']) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="cta">
            <div class="qr" id="qrcode"></div>
            <div class="cta-text">
                <b>Simule e cadastre-se.</b><br>
                Aponte a câmera para o QR Code ou acesse:
                <div class="link"><?= htmlspecialchars($simUrl) ?></div>
            </div>
        </div>

        <div class="disclaimer">
            Simulação baseada nos dados informados e na tabela vigente (juros compostos, meses = dias ÷ 30). Valores
            podem variar conforme a cotação do boi gordo e as condições finais da parceria. Não constitui oferta
            vinculante.
        </div>
    </div>

    <script>
        new QRCode(document.getElementById('qrcode'), {
            text: <?= json_encode($simUrl) ?>,
            width: 96,
            height: 96,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
        });
        // Aguarda o QR renderizar antes de abrir o diálogo de impressão.
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 700);
        });
    </script>
</body>

</html>
