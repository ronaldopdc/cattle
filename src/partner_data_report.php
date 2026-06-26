<?php
require_once 'auth.php';
require_login();
require_once 'config.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    die("ID do parceiro não fornecido.");
}

// Fetch partner details
$stmt = $pdo->prepare("SELECT * FROM partners WHERE id = ?");
$stmt->execute([$id]);
$partner = $stmt->fetch();

if (!$partner) {
    die("Parceiro não encontrado.");
}

// Fetch partner types
$stmtTypes = $pdo->prepare("SELECT type FROM partner_type_assignments WHERE partner_id = ?");
$stmtTypes->execute([$id]);
$types = $stmtTypes->fetchAll(PDO::FETCH_COLUMN);

// Fetch representatives if PJ
$representatives = [];
if ($partner['person_type'] === 'PJ') {
    $stmtReps = $pdo->prepare("
        SELECT p.name, p.cpf, p.email, p.phone 
        FROM partner_representatives pr 
        JOIN partners p ON pr.representative_id = p.id 
        WHERE pr.company_id = ?
    ");
    $stmtReps->execute([$id]);
    $representatives = $stmtReps->fetchAll();
}

function formatCPF($doc) {
    $doc = preg_replace("/[^0-9]/", "", $doc);
    if (strlen($doc) === 11) {
        return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "$1.$2.$3-$4", $doc);
    } elseif (strlen($doc) === 14) {
        return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "$1.$2.$3/$4-$5", $doc);
    }
    return $doc;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório - <?= htmlspecialchars($partner['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0f172a;
            --secondary: #64748b;
            --border: #e2e8f0;
            --bg-light: #f8fafc;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
        }
        body {
            font-family: 'Inter', sans-serif;
            color: var(--primary);
            line-height: 1.5;
            margin: 0;
            padding: 40px;
            background: white;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .logo {
            height: 50px;
        }
        .title-block h1 {
            margin: 0;
            font-size: 20px;
            text-transform: uppercase;
            text-align: right;
        }
        .title-block p {
            margin: 2px 0 0;
            color: var(--secondary);
            text-align: right;
            font-size: 12px;
        }
        .section {
            margin-bottom: 15px;
        }
        .section-title {
            background: var(--bg-light);
            padding: 5px 12px;
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            border-left: 4px solid var(--primary);
            margin-bottom: 10px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px 15px;
        }
        .field {
            margin-bottom: 5px;
        }
        .field-label {
            font-size: 10px;
            color: var(--secondary);
            text-transform: uppercase;
            font-weight: 600;
            display: block;
        }
        .field-value {
            font-size: 13px;
            font-weight: 500;
        }
        .badge-list {
            display: flex;
            gap: 8px;
            margin-top: 2px;
        }
        .badge {
            background: #e2e8f0;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }
        th, td {
            text-align: left;
            padding: 6px 10px;
            border-bottom: 1px solid var(--border);
            font-size: 12px;
        }
        th {
            background: var(--bg-light);
            font-weight: 600;
            color: var(--secondary);
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            color: var(--secondary);
            border-top: 1px solid var(--border);
            padding-top: 15px;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">Imprimir PDF</button>

    <div class="header">
        <img src="assets/logo.png" alt="Logo" class="logo">
        <div class="title-block">
            <h1>Ficha Cadastral de Parceiro</h1>
            <p>Emitido em: <?= date('d/m/Y H:i') ?></p>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Dados Básicos</div>
        <div class="grid">
            <div class="field">
                <span class="field-label">Nome / Razão Social</span>
                <span class="field-value"><?= htmlspecialchars($partner['name']) ?></span>
            </div>
            <div class="field">
                <span class="field-label"><?= $partner['person_type'] === 'PF' ? 'CPF' : 'CNPJ' ?></span>
                <span class="field-value"><?= formatCPF($partner['cpf']) ?></span>
            </div>
            <div class="field">
                <span class="field-label"><?= $partner['person_type'] === 'PF' ? 'Identidade' : 'Inscrição Municipal' ?></span>
                <span class="field-value"><?= htmlspecialchars($partner['identity'] ?: '-') ?></span>
            </div>
            <div class="field">
                <span class="field-label">Tipo de Pessoa</span>
                <span class="field-value"><?= $partner['person_type'] === 'PF' ? 'Pessoa Física' : 'Pessoa Jurídica' ?></span>
            </div>
            <div class="field">
                <span class="field-label">Funções no Sistema</span>
                <div class="badge-list">
                    <?php foreach($types as $type): ?>
                        <span class="badge">
                            <?= $type === 'owner' ? 'Proprietário' : ($type === 'investor' ? 'Investidor' : ucfirst($type)) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Contato e Localização</div>
        <div class="grid">
            <div class="field">
                <span class="field-label">E-mail</span>
                <span class="field-value"><?= htmlspecialchars($partner['email'] ?: '-') ?></span>
            </div>
            <div class="field">
                <span class="field-label">Telefone</span>
                <span class="field-value"><?= htmlspecialchars($partner['phone'] ?: '-') ?></span>
            </div>
            <div class="field" style="grid-column: span 2;">
                <span class="field-label">Endereço</span>
                <span class="field-value"><?= htmlspecialchars($partner['address'] ?: '-') ?></span>
            </div>
            <div class="field">
                <span class="field-label">Cidade / UF</span>
                <span class="field-value"><?= htmlspecialchars($partner['city'] ?: '-') ?> / <?= htmlspecialchars($partner['state'] ?: '-') ?></span>
            </div>
            <div class="field">
                <span class="field-label">CEP</span>
                <span class="field-value"><?= htmlspecialchars($partner['zip'] ?: '-') ?></span>
            </div>
        </div>
    </div>

    <?php if ($partner['person_type'] === 'PF'): ?>
    <div class="section">
        <div class="section-title">Informações Adicionais</div>
        <div class="grid">
            <div class="field">
                <span class="field-label">Nacionalidade</span>
                <span class="field-value"><?= htmlspecialchars($partner['nationality'] ?: '-') ?></span>
            </div>
            <div class="field">
                <span class="field-label">Estado Civil</span>
                <span class="field-value"><?= htmlspecialchars($partner['marital_status'] ?: '-') ?></span>
            </div>
            <div class="field">
                <span class="field-label">Profissão</span>
                <span class="field-value"><?= htmlspecialchars($partner['profession'] ?: '-') ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($partner['person_type'] === 'PJ' && !empty($representatives)): ?>
    <div class="section">
        <div class="section-title">Representantes Legais</div>
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>CPF</th>
                    <th>E-mail / Telefone</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($representatives as $rep): ?>
                <tr>
                    <td><?= htmlspecialchars($rep['name']) ?></td>
                    <td><?= formatCPF($rep['cpf']) ?></td>
                    <td>
                        <?= htmlspecialchars($rep['email'] ?: '-') ?><br>
                        <small><?= htmlspecialchars($rep['phone'] ?: '-') ?></small>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="section">
        <div class="section-title">Dados Bancários / Pagamento</div>
        <div class="grid">
            <div class="field">
                <span class="field-label">Banco</span>
                <span class="field-value"><?= htmlspecialchars($partner['bank_code'] ?: '-') ?></span>
            </div>
            <div class="field">
                <span class="field-label">Agência</span>
                <span class="field-value"><?= htmlspecialchars($partner['agency'] ?: '-') ?></span>
            </div>
            <div class="field">
                <span class="field-label">Conta Corrente</span>
                <span class="field-value"><?= htmlspecialchars($partner['account_number'] ?: '-') ?></span>
            </div>
            <div class="field">
                <span class="field-label">Tipo Chave PIX</span>
                <span class="field-value"><?= htmlspecialchars($partner['pix_type'] ?: '-') ?></span>
            </div>
            <div class="field" style="grid-column: span 2;">
                <span class="field-label">Chave PIX</span>
                <span class="field-value"><?= htmlspecialchars($partner['pix'] ?: '-') ?></span>
            </div>
        </div>
    </div>

    <div class="footer">
        Cattle Invest - Sistema de Gestão de Parcerias Pecuárias<br>
        Relatório gerado automaticamente pelo sistema.
    </div>

    <script>
        window.onload = function() {
            // setTimeout(function() { window.print(); }, 500);
        };
    </script>
</body>
</html>
