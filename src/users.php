<?php
require_once 'auth.php';
require_login();
require_role('admin');
require_once 'config.php';

$message = '';

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
            $partner_id = !empty($_POST['partner_id']) ? $_POST['partner_id'] : null;

            if (empty($username)) {
                throw new Exception("O nome de usuário é obrigatório.");
            }

            if (!empty($_POST['id'])) {
                // Update User
                $sql = "UPDATE users SET username = ?, email = ?, role = ?, partner_id = ? WHERE id = ?";
                $params = [$username, $_POST['email'], $role, $partner_id, $_POST['id']];

                // Update password if provided
                if (!empty($_POST['password'])) {
                    $sql = "UPDATE users SET username = ?, email = ?, password_hash = ?, role = ?, partner_id = ? WHERE id = ?";
                    $params = [$username, $_POST['email'], password_hash($_POST['password'], PASSWORD_DEFAULT), $role, $partner_id, $_POST['id']];
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
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

                $sql = "INSERT INTO users (username, email, password_hash, role, partner_id) VALUES (?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$username, $_POST['email'], password_hash($_POST['password'], PASSWORD_DEFAULT), $role, $partner_id]);
                $message = "Usuário criado com sucesso!";
            }
        }
    } catch (Exception $e) {
        $message = "Erro: " . $e->getMessage();
    }
}

// Fetch Users
$stmt = $pdo->query("SELECT u.*, p.name as partner_name FROM users u LEFT JOIN partners p ON u.partner_id = p.id ORDER BY u.username ASC");
$users = $stmt->fetchAll();

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
                            <label>Vincular a Parceiro (Opcional)</label>
                            <select name="partner_id" id="partner_id">
                                <option value="">Nenhum</option>
                                <?php foreach ($partners as $p): ?>
                                    <option value="<?= $p['id'] ?>">
                                        <?= htmlspecialchars($p['name']) ?>
                                        (<?= $p['type'] === 'owner' ? 'Proprietário' : 'Investidor' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color: #94a3b8; display: block; margin-top: 0.25rem;">
                                Vincule se este usuário for um parceiro (proprietário ou investidor).
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
                                <th>Parceiro Vinculado</th>
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
                                    <td data-label="Parceiro Vinculado">
                                        <?= $u['partner_name'] ? htmlspecialchars($u['partner_name']) : '-' ?>
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
            document.getElementById('partner_id').value = user.partner_id || '';
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