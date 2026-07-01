<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

function login($username, $password)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT id, username, password_hash, role, partner_id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['partner_id'] = $user['partner_id'];
        return true;
    }
    return false;
}

function logout()
{
    session_unset();
    session_destroy();
}

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

// Guarantee the session carries the authoritative role/partner_id for the
// logged-in user. If a session ever ends up with a user_id but no role
// (e.g. a session created by an older code path, a partially written
// session, or a session from a different origin), downstream checks like
// the admin partner filter and the dashboard's WHERE clause silently treat
// the user as "non-admin with unknown role", which hid the filter and
// leaked every partnership. Reload from the database in that case so the
// state is always consistent.
function ensure_session_hydrated()
{
    global $pdo;

    if (isset($_SESSION['user_id']) && !isset($_SESSION['role'])) {
        $stmt = $pdo->prepare("SELECT role, partner_id FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['role'] = $user['role'];
            $_SESSION['partner_id'] = $user['partner_id'];
        }
    }
}

function get_current_user_role()
{
    return $_SESSION['role'] ?? null;
}

function get_current_user_partner_id()
{
    return $_SESSION['partner_id'] ?? null;
}

function has_role($role)
{
    if (!is_logged_in())
        return false;
    ensure_session_hydrated();
    // Admin has all roles
    if (($_SESSION['role'] ?? null) === 'admin')
        return true;
    return ($_SESSION['role'] ?? null) === $role;
}

function require_login()
{
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
    ensure_session_hydrated();
}

function require_role($role)
{
    require_login();
    if (!has_role($role) && $_SESSION['role'] !== 'admin') {
        die("Acesso negado. Você não tem permissão para acessar esta página.");
    }
}
?>