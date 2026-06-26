<?php
require_once 'auth.php';
require_login();
require_once 'config.php';

// Include attachment handlers
include_once 'partner_attachment_handlers.php';

$message = '';

function validateCPF($cpf)
{
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf))
        return false;
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++)
            $d += $cpf[$c] * (($t + 1) - $c);
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d)
            return false;
    }
    return true;
}

function validateCNPJ($cnpj)
{
    $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
    if (strlen($cnpj) != 14 || preg_match('/(\d)\1{13}/', $cnpj))
        return false;
    for ($t = 12; $t < 14; $t++) {
        for ($d = 0, $m = ($t - 7), $i = 0; $i < $t; $i++) {
            $d += $cnpj[$i] * $m;
            $m = ($m == 2 ? 9 : --$m);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cnpj[$t] != $d)
            return false;
    }
    return true;
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trim all POST inputs recursively to avoid trailing/leading spaces
    array_walk_recursive($_POST, function (&$val) {
        if (is_string($val)) {
            $val = trim($val);
        }
    });

    try {
        if (isset($_POST['action']) && $_POST['action'] === 'delete') {
            // Verify ownership
            $stmtOwner = $pdo->prepare("SELECT created_by FROM partners WHERE id = ?");
            $stmtOwner->execute([$_POST['id']]);
            $owner = $stmtOwner->fetchColumn();
            if ($owner != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
                throw new Exception("Você não tem permissão para excluir este parceiro.");
            }

            // Delete Partner
            $stmt = $pdo->prepare("DELETE FROM partners WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = "Parceiro excluído com sucesso!";
        } else {
            // Sanitize and Validate Document
            $doc = preg_replace('/[^0-9]/', '', $_POST['cpf']);
            $personType = $_POST['person_type'] ?? 'PF';

            if ($personType === 'PF') {
                if (!validateCPF($doc))
                    throw new Exception("CPF inválido.");
            } else {
                if (!validateCNPJ($doc))
                    throw new Exception("CNPJ inválido.");
            }

            // Check for duplicates
            $checkSql = "SELECT id FROM partners WHERE cpf = ?";
            $checkParams = [$_POST['cpf']];
            if (!empty($_POST['id'])) {
                $checkSql .= " AND id != ?";
                $checkParams[] = $_POST['id'];
            }
            $stmtCheck = $pdo->prepare($checkSql);
            $stmtCheck->execute($checkParams);
            if ($stmtCheck->fetch()) {
                throw new Exception(($personType === 'PF' ? "CPF" : "CNPJ") . " já cadastrado para outro parceiro.");
            }

            if (!empty($_POST['id'])) {
                // Verify ownership
                $stmtOwner = $pdo->prepare("SELECT created_by FROM partners WHERE id = ?");
                $stmtOwner->execute([$_POST['id']]);
                $owner = $stmtOwner->fetchColumn();
                if ($owner != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
                    throw new Exception("Você não tem permissão para editar este parceiro.");
                }

                // Update Partner
                $pixType = !empty($_POST['pix_type']) ? $_POST['pix_type'] : null;
                $sql = "UPDATE partners SET person_type = ?, name = ?, email = ?, phone = ?, nationality = ?, marital_status = ?, profession = ?, cpf = ?, identity = ?, address = ?, city = ?, state = ?, zip = ?, bank_code = ?, agency = ?, account_number = ?, pix_type = ?, pix = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_POST['person_type'],
                    $_POST['name'],
                    $_POST['email'],
                    $_POST['phone'],
                    $_POST['nationality'],
                    $_POST['marital_status'],
                    $_POST['profession'],
                    $_POST['cpf'],
                    $_POST['identity'],
                    $_POST['address'],
                    $_POST['city'],
                    $_POST['state'],
                    $_POST['zip'],
                    $_POST['bank_code'],
                    $_POST['agency'],
                    $_POST['account_number'],
                    $pixType,
                    $_POST['pix'],
                    $_POST['id']
                ]);

                // Update Types
                $pdo->prepare("DELETE FROM partner_type_assignments WHERE partner_id = ?")->execute([$_POST['id']]);
                if (!empty($_POST['types'])) {
                    $stmtType = $pdo->prepare("INSERT INTO partner_type_assignments (partner_id, type) VALUES (?, ?)");
                    foreach ($_POST['types'] as $type) {
                        $stmtType->execute([$_POST['id'], $type]);
                    }
                }

                // Update representatives for PJ
                $pdo->prepare("DELETE FROM partner_representatives WHERE company_id = ?")->execute([$_POST['id']]);
                if ($_POST['person_type'] === 'PJ' && !empty($_POST['representatives_ids'])) {
                    $rep_ids = explode(',', $_POST['representatives_ids']);
                    $stmtRep = $pdo->prepare("INSERT INTO partner_representatives (company_id, representative_id) VALUES (?, ?)");
                    foreach ($rep_ids as $rep_id) {
                        if (!empty($rep_id)) {
                            $stmtRep->execute([$_POST['id'], $rep_id]);
                        }
                    }
                }
                $message = "Parceiro atualizado com sucesso!";
            } else {
                // Insert Partner
                $pixType = !empty($_POST['pix_type']) ? $_POST['pix_type'] : null;
                $sql = "INSERT INTO partners (person_type, name, email, phone, nationality, marital_status, profession, cpf, identity, address, city, state, zip, bank_code, agency, account_number, pix_type, pix, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_POST['person_type'],
                    $_POST['name'],
                    $_POST['email'],
                    $_POST['phone'],
                    $_POST['nationality'],
                    $_POST['marital_status'],
                    $_POST['profession'],
                    $_POST['cpf'],
                    $_POST['identity'],
                    $_POST['address'],
                    $_POST['city'],
                    $_POST['state'],
                    $_POST['zip'],
                    $_POST['bank_code'],
                    $_POST['agency'],
                    $_POST['account_number'],
                    $pixType,
                    $_POST['pix'],
                    $_SESSION['user_id']
                ]);
                $partner_id = $pdo->lastInsertId();

                // Handle Types
                if (!empty($_POST['types'])) {
                    $stmtType = $pdo->prepare("INSERT INTO partner_type_assignments (partner_id, type) VALUES (?, ?)");
                    foreach ($_POST['types'] as $type) {
                        $stmtType->execute([$partner_id, $type]);
                    }
                }

                // Handle representatives for PJ
                if ($_POST['person_type'] === 'PJ' && !empty($_POST['representatives_ids'])) {
                    $rep_ids = explode(',', $_POST['representatives_ids']);
                    $stmtRep = $pdo->prepare("INSERT INTO partner_representatives (company_id, representative_id) VALUES (?, ?)");
                    foreach ($rep_ids as $rep_id) {
                        if (!empty($rep_id)) {
                            $stmtRep->execute([$partner_id, $rep_id]);
                        }
                    }
                }
                $message = "Parceiro cadastrado com sucesso!";
            }
        }
    } catch (Exception $e) {
        $message = "Erro ao salvar: " . $e->getMessage();
    }
}

// Fetch ALL existing partners for representatives selection (PF and PJ)
$representatives_list = $pdo->query("SELECT id, name, person_type FROM partners ORDER BY name ASC")->fetchAll();

// Helper to get representatives for a company
function getCompanyRepresentatives($pdo, $company_id)
{
    if (!$company_id)
        return [];
    $stmt = $pdo->prepare("SELECT representative_id FROM partner_representatives WHERE company_id = ?");
    $stmt->execute([$company_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Helper to get types for a partner
function getPartnerTypes($pdo, $partner_id)
{
    if (!$partner_id)
        return [];
    $stmt = $pdo->prepare("SELECT type FROM partner_type_assignments WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch Partners
$stmt = $pdo->query("SELECT p.*, GROUP_CONCAT(pta.type) as types 
                    FROM partners p 
                    LEFT JOIN partner_type_assignments pta ON p.id = pta.partner_id 
                    GROUP BY p.id 
                    ORDER BY p.created_at DESC");
$partners = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parceiros - Cattle Invest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>
        /* Searchable Select Styles */
        .searchable-select {
            position: relative;
            width: 100%;
        }

        .select-trigger {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 46px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .select-trigger:hover {
            border-color: var(--primary-color);
        }

        .selected-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .tag {
            background: var(--primary-color);
            color: #0f172a;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-weight: 500;
        }

        .tag i {
            cursor: pointer;
            font-size: 0.75rem;
        }

        .select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            margin-top: 0.5rem;
            z-index: 1000;
            display: none;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: transparent;
            border: none;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            outline: none;
        }

        .options-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .option-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .option-item:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .option-item.selected {
            color: var(--primary-color);
            background: rgba(56, 189, 248, 0.05);
        }

        .person-badge {
            font-size: 0.7rem;
            padding: 0.1rem 0.3rem;
            border-radius: 0.2rem;
            background: rgba(255, 255, 255, 0.1);
            color: #94a3b8;
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
                    <h2 id="formTitle">Novo Parceiro</h2>
                    <form method="POST" action="" id="partnerForm">
                        <input type="hidden" name="id" id="partner_id">
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Tipos</label>
                                <div style="display: flex; gap: 1rem; padding: 0.75rem 0;">
                                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                        <input type="checkbox" name="types[]" value="owner" class="type-checkbox">
                                        Proprietário
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                        <input type="checkbox" name="types[]" value="investor" class="type-checkbox">
                                        Investidor
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                        <input type="checkbox" name="types[]" value="confinamento"
                                            class="type-checkbox"> Confinamento
                                    </label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Pessoa</label>
                                <select name="person_type" id="person_type" required onchange="togglePersonType()">
                                    <option value="PF">Física (PF)</option>
                                    <option value="PJ">Jurídica (PJ)</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Nome Completo</label>
                            <input type="text" name="name" id="name" required>
                        </div>
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label id="label_cpf_cnpj">CPF</label>
                                <input type="text" name="cpf" id="cpf" required maxlength="18"
                                    onkeyup="maskDocument(this)" onblur="validateDocumentInput(this)">
                                <small id="cpfError" style="color: red; display: none;">Documento Inválido</small>
                            </div>
                            <div class="form-group">
                                <label id="label_identity_im">Identidade</label>
                                <input type="text" name="identity" id="identity">
                            </div>
                        </div>
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>E-mail</label>
                                <input type="email" name="email" id="email">
                            </div>
                            <div class="form-group">
                                <label>Telefone</label>
                                <input type="text" name="phone" id="phone">
                            </div>
                        </div>

                        <div id="pf_fields">
                            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group">
                                    <label>Nacionalidade</label>
                                    <input type="text" name="nationality" id="nationality">
                                </div>
                                <div class="form-group">
                                    <label>Estado Civil</label>
                                    <input type="text" name="marital_status" id="marital_status">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Profissão</label>
                                <input type="text" name="profession" id="profession">
                            </div>
                        </div>

                        <div id="pj_fields" style="display: none;">
                            <div class="form-group">
                                <label>Representantes (Pesquisar Parceiros)</label>
                                <div class="searchable-select" id="repSelect">
                                    <div class="select-trigger" onclick="toggleDropdown()">
                                        <div class="selected-tags" id="selectedTags">
                                            <span style="color: #64748b;">Selecione os representantes...</span>
                                        </div>
                                        <i class="fas fa-chevron-down" style="color: #64748b; font-size: 0.8rem;"></i>
                                    </div>
                                    <div class="select-dropdown" id="dropdown">
                                        <input type="text" class="search-input" placeholder="Pesquisar por nome..."
                                            oninput="filterOptions(this.value)">
                                        <div class="options-list" id="optionsList">
                                            <?php foreach ($representatives_list as $rep): ?>
                                                <div class="option-item" data-id="<?= $rep['id'] ?>"
                                                    onclick="toggleOption(<?= $rep['id'] ?>, '<?= addslashes($rep['name']) ?>')"
                                                    data-name="<?= strtolower(htmlspecialchars($rep['name'])) ?>">
                                                    <span>
                                                        <?= htmlspecialchars($rep['name']) ?>
                                                    </span>
                                                    <span class="person-badge">
                                                        <?= $rep['person_type'] ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <input type="hidden" name="representatives_ids" id="representatives_ids">
                                </div>
                                <small style="color: #94a3b8; margin-top: 0.25rem; display: block;">Você pode selecionar
                                    múltiplos parceiros, incluindo outras empresas.</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Endereço</label>
                            <input type="text" name="address" id="address">
                        </div>
                        <div class="grid" style="grid-template-columns: 2fr 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Cidade</label>
                                <input type="text" name="city" id="city">
                            </div>
                            <div class="form-group">
                                <label>Estado</label>
                                <input type="text" name="state" id="state" maxlength="2">
                            </div>
                            <div class="form-group">
                                <label>CEP</label>
                                <input type="text" name="zip" id="zip">
                            </div>
                        </div>

                        <div class="grid" style="grid-template-columns: 1fr 1fr 2fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Banco (Cód.)</label>
                                <input type="text" name="bank_code" id="bank_code" maxlength="3" placeholder="000">
                            </div>
                            <div class="form-group">
                                <label>Agência</label>
                                <input type="text" name="agency" id="agency" maxlength="10" placeholder="0000-0">
                            </div>
                            <div class="form-group">
                                <label>Conta Corrente</label>
                                <input type="text" name="account_number" id="account_number">
                            </div>
                        </div>
                        <div class="grid" style="grid-template-columns: 1fr 2fr; gap: 1rem;">
                            <div class="form-group">
                                <label>Tipo de Chave PIX</label>
                                <select name="pix_type" id="pix_type">
                                    <option value="">Selecione...</option>
                                    <option value="cpf">CPF</option>
                                    <option value="phone">Telefone</option>
                                    <option value="email">E-mail</option>
                                    <option value="random">Chave Aleatória</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Chave PIX</label>
                                <input type="text" name="pix" id="pix">
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

            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; gap: 1rem; flex-wrap: wrap;">
                    <h2 style="margin: 0;">Lista de Parceiros</h2>
                    <div style="display: flex; gap: 0.75rem; align-items: center;">
                        <div style="position: relative; width: 250px; height: 38px; display: flex; align-items: center; margin: 0;">
                            <i class="fas fa-search" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.8rem; pointer-events: none; z-index: 1;"></i>
                            <input type="text" id="partnerSearch" placeholder="Filtrar parceiros..." autocomplete="off"
                                   style="width: 100%; height: 38px; padding: 0 0.75rem 0 2.2rem; background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: white; font-size: 0.85rem; display: block; margin: 0 !important; box-sizing: border-box;"
                                   onkeyup="filterPartnersTable()">
                        </div>
                        <button class="btn btn-secondary" onclick="generateInviteLink()" style="height: 38px; padding: 0 1rem; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin: 0; box-sizing: border-box;">
                            <i class="fas fa-link"></i> Link
                        </button>
                        <button class="btn btn-primary" onclick="showForm()" style="height: 38px; padding: 0 1rem; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin: 0; box-sizing: border-box;">+ Novo Parceiro</button>
                    </div>
                </div>

                <!-- List -->
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>CPF</th>
                                <th>Email/Telefone</th>
                                <th>Cidade/UF</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($partners as $partner): ?>
                                <tr>
                                    <td data-label="Nome">
                                        <?= htmlspecialchars($partner['name']) ?>
                                    </td>
                                    <td data-label="Tipo">
                                        <div style="display: flex; flex-wrap: wrap; gap: 0.25rem;">
                                            <?php
                                            $types = !empty($partner['types']) ? explode(',', $partner['types']) : [];
                                            foreach ($types as $type):
                                                ?>
                                                <span class="badge badge-<?= $type ?>">
                                                    <?php
                                                    if ($type === 'owner')
                                                        echo 'Proprietário';
                                                    elseif ($type === 'investor')
                                                        echo 'Investidor';
                                                    elseif ($type === 'confinamento')
                                                        echo 'Confinamento';
                                                    ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td data-label="CPF">
                                        <?= htmlspecialchars($partner['cpf']) ?>
                                    </td>
                                    <td data-label="Email/Telefone">
                                        <div>
                                            <?= htmlspecialchars($partner['email'] ?? '') ?>
                                        </div>
                                        <small>
                                            <?= htmlspecialchars($partner['phone'] ?? '') ?>
                                        </small>
                                        <?php if ($partner['person_type'] === 'PJ'): ?>
                                            <div style="font-size: 0.75rem; color: var(--primary-color); margin-top: 0.25rem;">
                                                PJ</div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Cidade/UF">
                                        <?= htmlspecialchars($partner['city'] ?? '') . '/' . htmlspecialchars($partner['state'] ?? '') ?>
                                    </td>
                                    <td data-label="Ações">
                                        <a href="partner_data_report.php?id=<?= $partner['id'] ?>" target="_blank" class="btn btn-icon" title="Relatório"
                                            style="background: rgba(56, 189, 248, 0.1); border-color: rgba(56, 189, 248, 0.2); color: #38bdf8; display: inline-flex; align-items: center; justify-content: center; text-decoration: none;">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        <?php if ($partner['created_by'] == $_SESSION['user_id'] || $_SESSION['role'] === 'admin'): ?>
                                        <button class="btn btn-icon btn-edit"
                                            onclick='editPartner(<?= json_encode($partner) ?>)' title="Editar">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-icon"
                                            onclick="openPartnerAttachmentsModal(<?= $partner['id'] ?>)" title="Anexos"
                                            style="background: rgba(139, 92, 246, 0.1); border-color: rgba(139, 92, 246, 0.2); color: #a78bfa;">
                                            <i class="fas fa-paperclip"></i>
                                        </button>
                                        <?php if ($partner['created_by'] == $_SESSION['user_id'] || $_SESSION['role'] === 'admin'): ?>
                                        <button class="btn btn-icon btn-delete"
                                            onclick="deletePartner(<?= $partner['id'] ?>)" title="Excluir">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
            function filterPartnersTable() {
                const query = document.getElementById('partnerSearch').value.toLowerCase();
                const rows = document.querySelectorAll('table tbody tr');

                rows.forEach(row => {
                    const name = row.querySelector('[data-label="Nome"]').innerText.toLowerCase();
                    const cpf = row.querySelector('[data-label="CPF"]').innerText.toLowerCase();
                    const emailTelefone = row.querySelector('[data-label="Email/Telefone"]').innerText.toLowerCase();
                    const cidadeUf = row.querySelector('[data-label="Cidade/UF"]').innerText.toLowerCase();

                    const match = name.includes(query) || 
                                cpf.includes(query) || 
                                emailTelefone.includes(query) || 
                                cidadeUf.includes(query);
                    
                    row.style.display = match ? '' : 'none';
                });
            }

            function maskDocument(input) {
                let value = input.value.replace(/\D/g, "");
                const type = document.getElementById('person_type').value;

                if (type === 'PF') {
                    if (value.length > 11) value = value.slice(0, 11);
                    value = value.replace(/(\d{3})(\d)/, "$1.$2");
                    value = value.replace(/(\d{3})(\d)/, "$1.$2");
                    value = value.replace(/(\d{3})(\d{1,2})$/, "$1-$2");
                } else {
                    if (value.length > 14) value = value.slice(0, 14);
                    value = value.replace(/^(\d{2})(\d)/, "$1.$2");
                    value = value.replace(/^(\d{2})\.(\d{3})(\d)/, "$1.$2.$3");
                    value = value.replace(/\.(\d{3})(\d)/, ".$1/$2");
                    value = value.replace(/(\d{4})(\d)/, "$1-$2");
                }

                input.value = value;
            }

            function validateCPF(cpf) {
                if (cpf.length !== 11 || /^(\d)\1+$/.test(cpf)) return false;
                let sum = 0, remainder;
                for (let i = 1; i <= 9; i++) sum = sum + parseInt(cpf.substring(i - 1, i)) * (11 - i);
                remainder = (sum * 10) % 11;
                if ((remainder === 10) || (remainder === 11)) remainder = 0;
                if (remainder !== parseInt(cpf.substring(9, 10))) return false;
                sum = 0;
                for (let i = 1; i <= 10; i++) sum = sum + parseInt(cpf.substring(i - 1, i)) * (12 - i);
                remainder = (sum * 10) % 11;
                if ((remainder === 10) || (remainder === 11)) remainder = 0;
                if (remainder !== parseInt(cpf.substring(10, 11))) return false;
                return true;
            }

            function validateCNPJ(cnpj) {
                if (cnpj.length !== 14 || /^(\d)\1+$/.test(cnpj)) return false;
                let size = cnpj.length - 2;
                let numbers = cnpj.substring(0, size);
                let digits = cnpj.substring(size);
                let sum = 0;
                let pos = size - 7;
                for (let i = size; i >= 1; i--) {
                    sum += numbers.charAt(size - i) * pos--;
                    if (pos < 2) pos = 9;
                }
                let result = sum % 11 < 2 ? 0 : 11 - (sum % 11);
                if (result != digits.charAt(0)) return false;
                size = size + 1;
                numbers = cnpj.substring(0, size);
                sum = 0;
                pos = size - 7;
                for (let i = size; i >= 1; i--) {
                    sum += numbers.charAt(size - i) * pos--;
                    if (pos < 2) pos = 9;
                }
                result = sum % 11 < 2 ? 0 : 11 - (sum % 11);
                if (result != digits.charAt(1)) return false;
                return true;
            }

            function validateDocumentInput(input) {
                const doc = input.value.replace(/\D/g, '');
                const type = document.getElementById('person_type').value;
                const errorElement = document.getElementById('cpfError');
                let isValid = false;

                if (type === 'PF') {
                    isValid = validateCPF(doc);
                } else {
                    isValid = validateCNPJ(doc);
                }

                if (!isValid) {
                    errorElement.style.display = 'block';
                    errorElement.innerText = (type === 'PF' ? 'CPF' : 'CNPJ') + ' Inválido';
                    input.setCustomValidity("Documento Inválido");
                } else {
                    errorElement.style.display = 'none';
                    input.setCustomValidity("");
                }

                return isValid;
            }

            function showForm() {
                document.getElementById('formContainer').style.display = 'block';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            function hideForm() {
                document.getElementById('formContainer').style.display = 'none';
                resetForm();
            }

            function togglePersonType() {
                const type = document.getElementById('person_type').value;
                const pfFields = document.getElementById('pf_fields');
                const pjFields = document.getElementById('pj_fields');
                const labelCpfCnpj = document.getElementById('label_cpf_cnpj');
                const labelIdentityIm = document.getElementById('label_identity_im');
                const cpfInput = document.getElementById('cpf');
                const errorElement = document.getElementById('cpfError');

                // Clear input and errors when switching
                cpfInput.value = '';
                errorElement.style.display = 'none';
                cpfInput.setCustomValidity("");

                if (type === 'PF') {
                    pfFields.style.display = 'block';
                    pjFields.style.display = 'none';
                    labelCpfCnpj.innerText = 'CPF';
                    labelIdentityIm.innerText = 'Identidade';
                    cpfInput.placeholder = '000.000.000-00';
                } else {
                    pfFields.style.display = 'none';
                    pjFields.style.display = 'block';
                    labelCpfCnpj.innerText = 'CNPJ';
                    labelIdentityIm.innerText = 'Inscrição Municipal';
                    cpfInput.placeholder = '00.000.000/0000-00';
                }
            }

            // Searchable Select Logic
            const selectedReps = new Set();

            function toggleDropdown() {
                const dropdown = document.getElementById('dropdown');
                dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            }

            function filterOptions(query) {
                const options = document.querySelectorAll('.option-item');
                query = query.toLowerCase();
                options.forEach(opt => {
                    const name = opt.getAttribute('data-name');
                    opt.style.display = name.includes(query) ? 'flex' : 'none';
                });
            }

            function toggleOption(id, name) {
                if (selectedReps.has(id)) {
                    selectedReps.delete(id);
                } else {
                    selectedReps.add(id);
                }
                updateSelectedUI();
            }

            function updateSelectedUI() {
                const tagsContainer = document.getElementById('selectedTags');
                const hiddenInput = document.getElementById('representatives_ids');

                if (selectedReps.size === 0) {
                    tagsContainer.innerHTML = '<span style="color: #64748b;">Selecione os representantes...</span>';
                    hiddenInput.value = '';
                } else {
                    tagsContainer.innerHTML = '';
                    const ids = [];

                    selectedReps.forEach(id => {
                        ids.push(id);
                        // Find matching option for name using data-id
                        const opt = document.querySelector(`.option-item[data-id="${id}"]`);
                        const name = opt ? opt.querySelector('span').innerText : 'ID: ' + id;

                        const tag = document.createElement('div');
                        tag.className = 'tag';
                        tag.innerHTML = `${name} <i class="fas fa-times" onclick="event.stopPropagation(); removeRep(${id})"></i>`;
                        tagsContainer.appendChild(tag);
                    });

                    hiddenInput.value = ids.join(',');
                }

                // Highlight selected in list
                document.querySelectorAll('.option-item').forEach(opt => {
                    const id = parseInt(opt.getAttribute('data-id'));
                    if (selectedReps.has(id)) {
                        opt.classList.add('selected');
                    } else {
                        opt.classList.remove('selected');
                    }
                });
            }

            function removeRep(id) {
                selectedReps.delete(id);
                updateSelectedUI();
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function (e) {
                const select = document.getElementById('repSelect');
                if (select && !select.contains(e.target)) {
                    document.getElementById('dropdown').style.display = 'none';
                }
            });

            function editPartner(partner) {
                showForm();
                document.getElementById('formTitle').innerText = 'Editar Parceiro #' + partner.id;
                document.getElementById('partner_id').value = partner.id;

                // Set checkboxes
                const partnerTypes = partner.types ? partner.types.split(',') : [];
                document.querySelectorAll('.type-checkbox').forEach(cb => {
                    cb.checked = partnerTypes.includes(cb.value);
                });

                document.getElementById('person_type').value = partner.person_type || 'PF';
                togglePersonType();

                document.getElementById('name').value = partner.name;
                document.getElementById('email').value = partner.email || '';
                document.getElementById('phone').value = partner.phone || '';
                document.getElementById('cpf').value = partner.cpf;
                document.getElementById('identity').value = partner.identity;
                document.getElementById('nationality').value = partner.nationality;
                document.getElementById('marital_status').value = partner.marital_status;
                document.getElementById('profession').value = partner.profession;
                document.getElementById('address').value = partner.address;
                document.getElementById('city').value = partner.city;
                document.getElementById('state').value = partner.state;
                document.getElementById('zip').value = partner.zip;

                document.getElementById('bank_code').value = partner.bank_code || '';
                document.getElementById('agency').value = partner.agency || '';
                document.getElementById('account_number').value = partner.account_number || '';
                document.getElementById('pix_type').value = partner.pix_type || '';
                document.getElementById('pix').value = partner.pix || '';

                // Fetch and set representatives
                selectedReps.clear();
                fetch('get_representatives.php?company_id=' + partner.id)
                    .then(response => response.json())
                    .then(data => {
                        data.forEach(id => selectedReps.add(parseInt(id)));
                        updateSelectedUI();
                    });

                document.getElementById('submitBtn').innerText = 'Salvar Alterações';
                document.getElementById('cancelBtn').style.display = 'inline-block';
                document.getElementById('cancelBtn').onclick = hideForm;
            }

            function resetForm() {
                document.getElementById('formTitle').innerText = 'Novo Parceiro';
                document.getElementById('partnerForm').reset();
                document.querySelectorAll('.type-checkbox').forEach(cb => cb.checked = false);
                document.getElementById('partner_id').value = '';
                document.getElementById('submitBtn').innerText = 'Cadastrar';
            }

            function deletePartner(id) {
                if (confirm('Tem certeza que deseja excluir este parceiro? Esta ação não pode ser desfeita.')) {
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
        // --- Invite Link Functions ---
        function generateInviteLink() {
            fetch('api_generate_token.php', { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Constrói a URL dinamicamente baseada na URL atual do navegador
                        const currentUrl = window.location.href;
                        const baseUrl = currentUrl.substring(0, currentUrl.lastIndexOf('/'));
                        const registrationUrl = `${baseUrl}/register_partner.php?token=${data.token}`;
                        
                        document.getElementById('inviteLinkInput').value = registrationUrl;
                        document.getElementById('inviteExpiry').innerText = 'Expira em: ' + data.expires_at;
                        document.getElementById('inviteModal').style.display = 'flex';
                        
                        // Reset button state
                        const copyBtn = document.getElementById('copyBtn');
                        copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copiar';
                        copyBtn.classList.remove('btn-success');
                    } else {
                        alert('Erro ao gerar link: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Erro na comunicação com o servidor.');
                });
        }

        function closeInviteModal() {
            document.getElementById('inviteModal').style.display = 'none';
        }

        function copyInviteLink() {
            const input = document.getElementById('inviteLinkInput');
            input.select();
            input.setSelectionRange(0, 99999); // For mobile
            
            navigator.clipboard.writeText(input.value).then(() => {
                const copyBtn = document.getElementById('copyBtn');
                copyBtn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
                copyBtn.classList.add('btn-success');
                
                setTimeout(() => {
                    copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copiar';
                    copyBtn.classList.remove('btn-success');
                }, 2000);
            });
        }
    </script>
        <?php include 'partner_attachments_modal.html'; ?>

    <!-- Invite Link Modal -->
    <div id="inviteModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); align-items: center; justify-content: center;">
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 2rem; border-radius: 12px; width: 500px; max-width: 90%; border: 1px solid rgba(255,255,255,0.1); position: relative; box-shadow: 0 20px 50px rgba(0,0,0,0.5);">
            <span onclick="closeInviteModal()" style="position: absolute; right: 1.5rem; top: 1rem; font-size: 1.5rem; cursor: pointer; color: #94a3b8;">&times;</span>
            <h3 style="color: #60a5fa; margin-top: 0; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fas fa-link"></i> Link de Convite Gerado
            </h3>
            <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.5;">Envie este link para o parceiro realizar o auto-cadastro. O link é de uso único e expira em 48 horas.</p>
            
            <div style="display: flex; gap: 0.5rem; margin: 1.5rem 0;">
                <input type="text" id="inviteLinkInput" readonly style="flex: 1; padding: 0.75rem; border-radius: 6px; background: #0f172a; border: 1px solid #334155; color: #60a5fa; font-family: monospace; font-size: 0.85rem; outline: none;">
                <button class="btn btn-primary" onclick="copyInviteLink()" id="copyBtn" style="white-space: nowrap; padding: 0 1.2rem;">
                    <i class="fas fa-copy"></i> Copiar
                </button>
            </div>
            
            <div id="inviteExpiry" style="color: #64748b; font-size: 0.8rem; text-align: right;"></div>
        </div>
    </div>
</body>

</html>