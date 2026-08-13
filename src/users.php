<?php
require_once 'auth.php';
require_login();
require_role('admin');
require_once 'config.php';

$message = '';

// Replaces every partner link of a user with the given list. The legacy
// users.partner_id column is kept in sync with the first partner so any code
// path still reading it resolves to a valid partner.
function syncUserPartners($pdo, $userId, array $partnerIds)
{
    $pdo->prepare("DELETE FROM user_partners WHERE user_id = ?")->execute([$userId]);

    if (!empty($partnerIds)) {
        $stmtLink = $pdo->prepare("INSERT IGNORE INTO user_partners (user_id, partner_id) VALUES (?, ?)");
        foreach ($partnerIds as $partnerId) {
            $stmtLink->execute([$userId, $partnerId]);
        }
    }

    $stmtLegacy = $pdo->prepare("UPDATE users SET partner_id = ? WHERE id = ?");
    $stmtLegacy->execute([$partnerIds[0] ?? null, $userId]);
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action']) && $_POST['action'] === 'delete') {
            // Delete User
            if ($_POST['id'] == $_SESSION['user_id']) {
                throw new Exception("Você não pode excluir seu próprio usuário.");
            }
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = "Usuário excluído com sucesso!";
        } else {
            // Validate input
            $username = trim($_POST['username']);
            $role = $_POST['role'];

            // A user can be linked to several partners at once and gets access
            // to everything belonging to all of them.
            $partner_ids = isset($_POST['partner_ids']) && is_array($_POST['partner_ids'])
                ? array_values(array_unique(array_map('intval', array_filter($_POST['partner_ids'], 'strlen'))))
                : [];
            $partner_id = $partner_ids[0] ?? null;

            if (empty($username)) {
                throw new Exception("O nome de usuário é obrigatório.");
            }

            if (!empty($_POST['id'])) {
                // Update User
                $pdo->beginTransaction();

                $sql = "UPDATE users SET username = ?, email = ?, role = ?, partner_id = ? WHERE id = ?";
                $params = [$username, $_POST['email'], $role, $partner_id, $_POST['id']];

                // Update password if provided
                if (!empty($_POST['password'])) {
                    $sql = "UPDATE users SET username = ?, email = ?, password_hash = ?, role = ?, partner_id = ? WHERE id = ?";
                    $params = [$username, $_POST['email'], password_hash($_POST['password'], PASSWORD_DEFAULT), $role, $partner_id, $_POST['id']];
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                syncUserPartners($pdo, $_POST['id'], $partner_ids);

                $pdo->commit();
                $message = "Usuário atualizado com sucesso!";
            } else {
                // Insert User
                if (empty($_POST['password'])) {
                    throw new Exception("A senha é obrigatória para novos usuários.");
                }

                // Check duplicate
                $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmtCheck->execute([$username]);
                if ($stmtCheck->fetch()) {
                    throw new Exception("Nome de usuário já existe.");
                }

                $pdo->beginTransaction();

                $sql = "INSERT INTO users (username, email, password_hash, role, partner_id) VALUES (?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$username, $_POST['email'], password_hash($_POST['password'], PASSWORD_DEFAULT), $role, $partner_id]);
                syncUserPartners($pdo, $pdo->lastInsertId(), $partner_ids);

                $pdo->commit();
                $message = "Usuário criado com sucesso!";
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Erro: " . $e->getMessage();
    }
}

// Fetch Users
// Never expose password_hash to the page: the row is JSON-encoded into the edit button.
$stmt = $pdo->query("SELECT id, username, email, role, partner_id FROM users ORDER BY username ASC");
$users = $stmt->fetchAll();

// Fetch every partner linked to each user (many-to-many)
$userPartnerMap = [];
$linkRows = $pdo->query("
    SELECT up.user_id, up.partner_id, p.name
    FROM user_partners up
    JOIN partners p ON p.id = up.partner_id
    ORDER BY p.name ASC
")->fetchAll();
foreach ($linkRows as $link) {
    $userPartnerMap[$link['user_id']][] = [
        'id' => intval($link['partner_id']),
        'name' => $link['name'],
    ];
}

foreach ($users as &$u) {
    $links = $userPartnerMap[$u['id']] ?? [];
    $u['partner_ids'] = array_column($links, 'id');
    $u['partner_names'] = array_column($links, 'name');
}
unset($u);

// Fetch Partners for Dropdown
$partners = $pdo->query("SELECT id, name, type FROM partners ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuários - Cattle Invest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
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
                    <h2 id="formTitle">Novo Usuário</h2>
                    <form method="POST" action="" id="userForm">
                        <input type="hidden" name="id" id="user_id">

                        <div class="form-group">
                            <label>Usuário (Login)</label>
                            <input type="text" name="username" id="username" required>
                        </div>

                        <div class="form-group">
                            <label>E-mail</label>
                            <input type="email" name="email" id="email">
                        </div>

                        <div class="form-group">
                            <label>Senha</label>
                            <input type="password" name="password" id="password"
                                placeholder="Deixe em branco para manter a atual">
                        </div>

                        <div class="form-group">
                            <label>Função</label>
                            <select name="role" id="role" required>
                                <option value="user">Usuário Comum</option>
                                <option value="manager">Gerente</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Vincular a Parceiros (Opcional)</label>
                            <input type="text" id="partnerSearch" placeholder="Buscar parceiro..."
                                oninput="filterPartners(this.value)" style="margin-bottom: 0.5rem;">
                            <div id="partnerList"
                                style="max-height: 220px; overflow-y: auto; border: 1px solid rgba(148, 163, 184, 0.2); border-radius: 8px; padding: 0.5rem;">
                                <?php foreach ($partners as $p): ?>
                                    <label class="partner-option"
                                        data-name="<?= htmlspecialchars(mb_strtolower($p['name'])) ?>"
                                        style="display: flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0.25rem; cursor: pointer; font-weight: 400;">
                                        <input type="checkbox" name="partner_ids[]" value="<?= $p['id'] ?>"
                                            class="partner-checkbox" style="width: auto; margin: 0;">
                                        <span>
                                            <?= htmlspecialchars($p['name']) ?>
                                            (<?= $p['type'] === 'owner' ? 'Proprietário' : 'Investidor' ?>)
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <small style="color: #94a3b8; display: block; margin-top: 0.25rem;">
                                Selecione um ou mais parceiros. O usuário terá acesso a tudo de todos os parceiros
                                vinculados, mas só poderá editar o que ele mesmo cadastrou.
                            </small>
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
                    <h2 style="margin: 0;">Lista de Usuários</h2>
                    <button class="btn btn-primary" onclick="showForm()">+ Novo Usuário</button>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Usuário</th>
                                <th>E-mail</th>
                                <th>Função</th>
                                <th>Parceiros Vinculados</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td data-label="Usuário"><?= htmlspecialchars($u['username']) ?></td>
                                    <td data-label="E-mail"><?= htmlspecialchars($u['email'] ?? '-') ?></td>
                                    <td data-label="Função">
                                        <?php
                                        $badges = [
                                            'admin' => 'badge-purple',
                                            'manager' => 'badge-blue',
                                            'user' => 'badge-gray'
                                        ];
                                        // Define styles locally if not in CSS
                                        $style = "";
                                        if ($u['role'] == 'admin')
                                            $style = "background: rgba(139, 92, 246, 0.2); color: #a78bfa;";
                                        elseif ($u['role'] == 'manager')
                                            $style = "background: rgba(56, 189, 248, 0.2); color: #38bdf8;";
                                        else
                                            $style = "background: rgba(148, 163, 184, 0.2); color: #cbd5e1;";
                                        ?>
                                        <span class="badge" style="<?= $style ?>">
                                            <?= ucfirst($u['role']) ?>
                                        </span>
                                    </td>
                                    <td data-label="Parceiros Vinculados">
                                        <?php if (empty($u['partner_names'])): ?>
                                            -
                                        <?php else: ?>
                                            <?php foreach ($u['partner_names'] as $partnerName): ?>
                                                <span class="badge"
                                                    style="background: rgba(52, 211, 153, 0.15); color: #34d399; margin: 0 0.25rem 0.25rem 0; display: inline-block;">
                                                    <?= htmlspecialchars($partnerName) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Ações">
                                        <button class="btn btn-icon btn-edit" onclick='editUser(<?= json_encode($u) ?>)'
                                            title="Editar">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-icon btn-delete" onclick="deleteUser(<?= $u['id'] ?>)"
                                                title="Excluir">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-icon" disabled
                                                title="Você não pode excluir seu próprio usuário"
                                                style="opacity: 0.3; cursor: not-allowed;">
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
    </div>

    <script>
        function filterPartners(term) {
            const needle = (term || '').toLowerCase();
            document.querySelectorAll('#partnerList .partner-option').forEach(function (opt) {
                opt.style.display = opt.dataset.name.includes(needle) ? 'flex' : 'none';
            });
        }

        function setSelectedPartners(ids) {
            const selected = (ids || []).map(String);
            document.querySelectorAll('.partner-checkbox').forEach(function (cb) {
                cb.checked = selected.includes(cb.value);
            });
        }

        function showForm() {
            document.getElementById('formContainer').style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function hideForm() {
            document.getElementById('formContainer').style.display = 'none';
            resetForm();
        }

        function editUser(user) {
            showForm();
            document.getElementById('formTitle').innerText = 'Editar Usuário: ' + user.username;
            document.getElementById('user_id').value = user.id;
            document.getElementById('username').value = user.username;
            document.getElementById('email').value = user.email || '';
            document.getElementById('role').value = user.role;
            setSelectedPartners(user.partner_ids);
            document.getElementById('partnerSearch').value = '';
            filterPartners('');
            document.getElementById('password').value = ''; // Clean password field
            document.getElementById('password').placeholder = 'Digite para alterar a senha';

            document.getElementById('submitBtn').innerText = 'Salvar Alterações';
            document.getElementById('cancelBtn').style.display = 'inline-block';
            document.getElementById('cancelBtn').onclick = hideForm;
        }

        function resetForm() {
            document.getElementById('formTitle').innerText = 'Novo Usuário';
            document.getElementById('userForm').reset();
            document.getElementById('user_id').value = '';
            setSelectedPartners([]);
            filterPartners('');
            document.getElementById('password').placeholder = 'Deixe em branco para manter a atual';
            document.getElementById('submitBtn').innerText = 'Cadastrar';
            document.getElementById('cancelBtn').style.display = 'none';
        }

        function deleteUser(id) {
            if (confirm('Tem certeza que deseja excluir este usuário? Esta ação não pode ser desfeita.')) {
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
    </script>
</body>

</html>