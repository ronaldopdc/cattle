<?php
require_once 'config.php';

$message = '';
$error = false;

// Fetch ALL existing partners for representatives selection (PF and PJ)
$stmt = $pdo->prepare("SELECT id, name, person_type FROM partners ORDER BY name ASC");
$stmt->execute();
$all_partners = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trim all POST inputs recursively to avoid trailing/leading spaces
    array_walk_recursive($_POST, function (&$val) {
        if (is_string($val)) {
            $val = trim($val);
        }
    });

    try {
        $pdo->beginTransaction();

        // 1. Validate and Create User
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $email = trim($_POST['email']);

        if (empty($username) || empty($password)) {
            throw new Exception("Usuário e senha são obrigatórios.");
        }

        // Check if username exists
        $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmtCheck->execute([$username]);
        if ($stmtCheck->fetch()) {
            throw new Exception("Este nome de usuário já está em uso.");
        }

        // 2. Create Partner
        $person_type = $_POST['person_type']; // PF or PJ
        $name = trim($_POST['name']);
        $cpf_cnpj = preg_replace('/\D/', '', $_POST['cpf_cnpj']);
        $identity_im = trim($_POST['identity_im']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $state = trim($_POST['state']);
        $zip = trim($_POST['zip']);

        if (empty($name) || empty($cpf_cnpj)) {
            throw new Exception("Nome e CPF/CNPJ são obrigatórios para o cadastro de parceiro.");
        }

        // Additional PF fields
        $nationality = $person_type === 'PF' ? trim($_POST['nationality']) : null;
        $marital_status = $person_type === 'PF' ? trim($_POST['marital_status']) : null;
        $profession = $person_type === 'PF' ? trim($_POST['profession']) : null;

        $sqlPartner = "INSERT INTO partners (type, person_type, name, nationality, marital_status, profession, cpf, identity, email, phone, address, city, state, zip) 
                       VALUES ('investor', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtPartner = $pdo->prepare($sqlPartner);
        $stmtPartner->execute([
            $person_type,
            $name,
            $nationality,
            $marital_status,
            $profession,
            $_POST['cpf_cnpj'],
            $identity_im,
            $email,
            $phone,
            $address,
            $city,
            $state,
            $zip
        ]);
        $partner_id = $pdo->lastInsertId();

        // 3. Link Representatives if PJ
        if ($person_type === 'PJ' && !empty($_POST['representatives_ids'])) {
            $rep_ids = explode(',', $_POST['representatives_ids']);
            $stmtRep = $pdo->prepare("INSERT INTO partner_representatives (company_id, representative_id) VALUES (?, ?)");
            foreach ($rep_ids as $rep_id) {
                if (!empty($rep_id)) {
                    $stmtRep->execute([$partner_id, $rep_id]);
                }
            }
        }

        // 4. Create User linked to Partner
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $sqlUser = "INSERT INTO users (username, password_hash, role, partner_id, email) VALUES (?, ?, 'user', ?, ?)";
        $stmtUser = $pdo->prepare($sqlUser);
        $stmtUser->execute([$username, $password_hash, $partner_id, $email]);

        $pdo->commit();
        $message = "Cadastro realizado com sucesso! Você já pode fazer login.";

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Erro ao cadastrar: " . $e->getMessage();
        $error = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Cattle Invest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background-color: var(--background-color);
            padding: 2rem;
        }

        .register-card {
            background-color: var(--card-bg);
            padding: 2.5rem;
            border-radius: 1rem;
            width: 100%;
            max-width: 800px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .register-logo {
            width: 80px;
            height: auto;
            margin-bottom: 1rem;
        }

        .section-title {
            color: var(--primary-color);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 0.5rem;
            margin-top: 2rem;
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-color);
            border-radius: 0.5rem;
            font-size: 1rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
        }

        .btn-register {
            width: 100%;
            padding: 0.75rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            margin-top: 1rem;
        }

        .btn-register:hover {
            background-color: var(--accent-color);
        }

        .message {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 0.95rem;
        }

        .success {
            color: #10b981;
            background-color: rgba(16, 185, 129, 0.1);
        }

        .error {
            color: #ef4444;
            background-color: rgba(239, 68, 68, 0.1);
        }

        /* Searchable Select Styles */
        .searchable-select {
            position: relative;
            width: 100%;
        }

        .select-trigger {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 46px;
        }

        .select-trigger:hover {
            border-color: rgba(255, 255, 255, 0.2);
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
            border-radius: 0.5rem;
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
    <div class="register-card">
        <div class="register-header">
            <img src="logo.png" alt="Cattle Invest" class="register-logo">
            <h1 style="color: var(--primary-color); font-size: 1.5rem; margin: 0;">Cadastro de Usuário</h1>
            <p style="color: #94a3b8; margin-top: 0.5rem;">Crie sua conta para acessar o sistema</p>
        </div>

        <?php if ($message): ?>
            <div class="message <?= $error ? 'error' : 'success' ?>">
                <?= htmlspecialchars($message) ?>
                <?php if (!$error): ?>
                    <br><a href="login.php"
                        style="color: var(--primary-color); text-decoration: none; font-weight: bold; display: block; margin-top: 0.5rem;">Ir
                        para Login</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($error || !$message): ?>
            <form method="POST" id="registerForm">
                <div class="section-title">Dados de Acesso</div>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label>Usuário (Login)</label>
                        <input type="text" name="username" class="form-control" placeholder="Ex: joao.silva" required
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>E-mail</label>
                        <input type="email" name="email" class="form-control" placeholder="Ex: joao@email.com" required
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                </div>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label>Senha</label>
                        <input type="password" name="password" class="form-control" placeholder="Sua senha" required>
                    </div>
                </div>

                <div class="section-title">Dados do Parceiro</div>
                <div class="form-group">
                    <label>Tipo de Pessoa</label>
                    <select name="person_type" id="person_type" class="form-control" onchange="togglePersonType()">
                        <option value="PF" <?= ($_POST['person_type'] ?? '') === 'PF' ? 'selected' : '' ?>>Pessoa Física (PF)
                        </option>
                        <option value="PJ" <?= ($_POST['person_type'] ?? '') === 'PJ' ? 'selected' : '' ?>>Pessoa Jurídica (PJ)
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label id="label_name">Nome Completo</label>
                    <input type="text" name="name" class="form-control" required
                        value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>

                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label id="label_cpf_cnpj">CPF</label>
                        <input type="text" name="cpf_cnpj" id="cpf_cnpj" class="form-control" required
                            value="<?= htmlspecialchars($_POST['cpf_cnpj'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label id="label_identity_im">Identidade</label>
                        <input type="text" name="identity_im" class="form-control"
                            value="<?= htmlspecialchars($_POST['identity_im'] ?? '') ?>">
                    </div>
                </div>

                <div id="pf_fields">
                    <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label>Nacionalidade</label>
                            <input type="text" name="nationality" class="form-control"
                                value="<?= htmlspecialchars($_POST['nationality'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Estado Civil</label>
                            <input type="text" name="marital_status" class="form-control"
                                value="<?= htmlspecialchars($_POST['marital_status'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Profissão</label>
                            <input type="text" name="profession" class="form-control"
                                value="<?= htmlspecialchars($_POST['profession'] ?? '') ?>">
                        </div>
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
                                    <?php foreach ($all_partners as $p): ?>
                                        <div class="option-item" data-id="<?= $p['id'] ?>"
                                            onclick="toggleOption(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>')"
                                            data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>">
                                            <span><?= htmlspecialchars($p['name']) ?></span>
                                            <span class="person-badge"><?= $p['person_type'] ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="hidden" name="representatives_ids" id="representatives_ids">
                        </div>
                        <small style="color: #94a3b8; margin-top: 0.25rem; display: block;">Você pode selecionar múltiplos
                            parceiros, incluindo outras empresas.</small>
                    </div>
                </div>

                <div class="grid" style="grid-template-columns: 2fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label>Telefone</label>
                        <input type="text" name="phone" class="form-control"
                            value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>CEP</label>
                        <input type="text" name="zip" class="form-control"
                            value="<?= htmlspecialchars($_POST['zip'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Endereço</label>
                    <input type="text" name="address" class="form-control"
                        value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                </div>

                <div class="grid" style="grid-template-columns: 2fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label>Cidade</label>
                        <input type="text" name="city" class="form-control"
                            value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Estado (UF)</label>
                        <input type="text" name="state" class="form-control" maxlength="2"
                            value="<?= htmlspecialchars($_POST['state'] ?? '') ?>">
                    </div>
                </div>

                <button type="submit" class="btn-register">Confirmar Cadastro</button>
                <p style="text-align: center; margin-top: 1rem;">
                    <a href="login.php" style="color: #94a3b8; text-decoration: none; font-size: 0.9rem;">Já tem uma conta?
                        Voltar ao login</a>
                </p>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function togglePersonType() {
            const type = document.getElementById('person_type').value;
            const pfFields = document.getElementById('pf_fields');
            const pjFields = document.getElementById('pj_fields');
            const labelName = document.getElementById('label_name');
            const labelCpfCnpj = document.getElementById('label_cpf_cnpj');
            const labelIdentityIm = document.getElementById('label_identity_im');

            if (type === 'PF') {
                pfFields.style.display = 'block';
                pjFields.style.display = 'none';
                labelName.innerText = 'Nome Completo';
                labelCpfCnpj.innerText = 'CPF';
                labelIdentityIm.innerText = 'Identidade';
            } else {
                pfFields.style.display = 'none';
                pjFields.style.display = 'block';
                labelName.innerText = 'Razão Social';
                labelCpfCnpj.innerText = 'CNPJ';
                labelIdentityIm.innerText = 'Inscrição Municipal';
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
                return;
            }

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
            if (!select.contains(e.target)) {
                document.getElementById('dropdown').style.display = 'none';
            }
        });

        // Initialize on load
        window.onload = togglePersonType;
    </script>
</body>

</html>