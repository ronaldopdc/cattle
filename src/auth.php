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
    // Admin has all roles
    if ($_SESSION['role'] === 'admin')
        return true;
    return $_SESSION['role'] === $role;
}

function require_login()
{
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}

function require_role($role)
{
    require_login();
    if (!has_role($role) && $_SESSION['role'] !== 'admin') {
        die("Acesso negado. Você não tem permissão para acessar esta página.");
    }
}
?>