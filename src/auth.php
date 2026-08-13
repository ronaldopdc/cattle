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
        $_SESSION['partner_ids'] = fetch_user_partner_ids($user['id'], $user['partner_id']);
        // Legacy single-partner slot: kept in sync with the first linked partner
        // so any older code path still resolves to a valid partner.
        $_SESSION['partner_id'] = $_SESSION['partner_ids'][0] ?? null;
        return true;
    }
    return false;
}

// Every partner a user is linked to. Reads the user_partners many-to-many
// table and falls back to the legacy users.partner_id column when the table
// does not exist yet or holds no row for this user.
function fetch_user_partner_ids($userId, $legacyPartnerId = null)
{
    global $pdo;

    $ids = [];
    try {
        $stmt = $pdo->prepare("
            SELECT up.partner_id
            FROM user_partners up
            JOIN partners p ON p.id = up.partner_id
            WHERE up.user_id = ?
            ORDER BY p.name ASC
        ");
        $stmt->execute([$userId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        // user_partners not migrated yet: fall back to the legacy column below.
        $ids = [];
    }

    if (empty($ids)) {
        if ($legacyPartnerId === null) {
            $stmt = $pdo->prepare("SELECT partner_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $legacyPartnerId = $stmt->fetchColumn();
        }
        if (!empty($legacyPartnerId)) {
            $ids = [intval($legacyPartnerId)];
        }
    }

    return array_values(array_unique($ids));
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

    if (!isset($_SESSION['user_id'])) {
        return;
    }

    if (!isset($_SESSION['role'])) {
        $stmt = $pdo->prepare("SELECT role, partner_id FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['role'] = $user['role'];
            $_SESSION['partner_id'] = $user['partner_id'];
        }
    }

    // Sessions created before multi-partner support (or by the branch above)
    // only carry the single partner_id. Load the full list so the access scope
    // covers every partner linked to this user.
    if (!isset($_SESSION['partner_ids'])) {
        $_SESSION['partner_ids'] = fetch_user_partner_ids(
            $_SESSION['user_id'],
            $_SESSION['partner_id'] ?? null
        );
        $_SESSION['partner_id'] = $_SESSION['partner_ids'][0] ?? null;
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

// All partners the logged-in user has access to (empty array when none).
function get_current_user_partner_ids()
{
    if (is_logged_in()) {
        ensure_session_hydrated();
    }
    $ids = $_SESSION['partner_ids'] ?? null;
    if (is_array($ids)) {
        return $ids;
    }
    // Defensive fallback for sessions that only carry the legacy slot.
    return !empty($_SESSION['partner_id']) ? [intval($_SESSION['partner_id'])] : [];
}

// True when the given partner is one of the user's linked partners.
function user_has_partner($partnerId)
{
    if (empty($partnerId)) {
        return false;
    }
    return in_array(intval($partnerId), get_current_user_partner_ids(), true);
}

// Builds the WHERE fragment restricting partnerships to a set of partners.
// Returns [clause, params]; an empty partner set yields "1=0" so a user with no
// linked partner never falls back to seeing everything.
function partner_scope_clause(array $partnerIds, $alias = 'p')
{
    if (empty($partnerIds)) {
        return ['1=0', []];
    }

    $placeholders = implode(', ', array_fill(0, count($partnerIds), '?'));
    $clause = "($alias.owner_id IN ($placeholders)"
        . " OR $alias.investor_id IN ($placeholders)"
        . " OR $alias.confinamento_id IN ($placeholders))";
    $params = array_merge($partnerIds, $partnerIds, $partnerIds);

    return [$clause, $params];
}

// Distinct types (owner / investor / confinamento) of every partner linked to
// the user. Cached per set of partners, so repeated calls within a request cost
// one query without ever returning another user's types.
function get_current_user_partner_types()
{
    global $pdo;
    static $cache = [];

    $ids = get_current_user_partner_ids();
    if (empty($ids)) {
        return [];
    }

    $key = implode(',', $ids);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT type
        FROM partner_type_assignments
        WHERE partner_id IN ($placeholders)
        ORDER BY type ASC
    ");
    $stmt->execute($ids);
    $cache[$key] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $cache[$key];
}

// True when every partner linked to the user is a confinamento (and there is at
// least one). These users get a reduced interface: only the partnerships list,
// restricted to the contracts their confinements take part in, where the
// attachments can be downloaded but never deleted.
function is_confinement_only_user()
{
    if (!is_logged_in()) {
        return false;
    }
    ensure_session_hydrated();

    if (($_SESSION['role'] ?? null) === 'admin') {
        return false;
    }
    if (empty(get_current_user_partner_ids())) {
        return false;
    }

    return get_current_user_partner_types() === ['confinamento'];
}

// True when the given partnership falls inside the user's partner scope.
// Admins always pass.
function user_can_access_partnership($partnershipId)
{
    global $pdo;

    if (!is_logged_in() || empty($partnershipId)) {
        return false;
    }
    ensure_session_hydrated();

    if (($_SESSION['role'] ?? null) === 'admin') {
        return true;
    }

    [$clause, $params] = partner_scope_clause(get_current_user_partner_ids());
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM partnerships p WHERE p.id = ? AND $clause");
    $stmt->execute(array_merge([$partnershipId], $params));

    return intval($stmt->fetchColumn()) > 0;
}

// The only pages a confinamento-only user may open. Everything else bounces
// back to the partnerships list.
function confinement_only_allowed_pages()
{
    return [
        'partnerships.php',
        'generate_receipt.php',
        'generate_total_receipt.php',
        'logout.php',
        'login.php',
    ];
}

// Applied from require_login(), so a page added later is restricted by default
// instead of silently becoming reachable.
function enforce_confinement_only_scope()
{
    if (!is_confinement_only_user()) {
        return;
    }

    $page = basename($_SERVER['PHP_SELF'] ?? '');
    if (in_array($page, confinement_only_allowed_pages(), true)) {
        return;
    }

    header('Location: partnerships.php');
    exit;
}

// Write access rule: admins can change anything, everyone else only the records
// they created themselves. Being linked to a partner grants visibility over
// that partner's data, never the right to edit records created by others.
function can_edit_record($createdBy)
{
    if (!is_logged_in()) {
        return false;
    }
    ensure_session_hydrated();

    if (($_SESSION['role'] ?? null) === 'admin') {
        return true;
    }

    return !empty($createdBy) && $createdBy == $_SESSION['user_id'];
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
    enforce_confinement_only_scope();
}

function require_role($role)
{
    require_login();
    if (!has_role($role) && $_SESSION['role'] !== 'admin') {
        die("Acesso negado. Você não tem permissão para acessar esta página.");
    }
}
?>