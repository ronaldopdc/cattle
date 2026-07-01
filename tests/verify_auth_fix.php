<?php
// Verification harness for the "logged-in but no partner filter / all partners shown" fix.
// Simulates the session hydration + dashboard WHERE-clause selection without a browser.

chdir(__DIR__ . '/../src');
require_once 'auth.php'; // pulls in config.php ($pdo)

function line($s = '') { echo $s . "\n"; }

// --- Inspect users so we can pick realistic ids/roles ---
$users = $pdo->query("SELECT id, username, role, partner_id FROM users ORDER BY id")->fetchAll();
line("Users in DB:");
foreach ($users as $u) {
    line(sprintf("  #%s %-20s role=%-6s partner_id=%s",
        $u['id'], $u['username'], $u['role'], var_export($u['partner_id'], true)));
}
line();

// Helper: replicate the dashboard_stats.php clause selection logic.
function computeWhere($userRole, $userPartnerId, $getPartnerId = null) {
    $whereClause = "1=1";
    $params = [];
    if ($userRole === 'admin') {
        $selectedPartnerId = $getPartnerId !== null ? $getPartnerId : ($userPartnerId ?: 'all');
        if ($selectedPartnerId !== 'all') {
            $whereClause = "(p.owner_id = ? OR p.investor_id = ? OR p.confinamento_id = ?)";
            $params = [$selectedPartnerId, $selectedPartnerId, $selectedPartnerId];
        }
    } else {
        if (!$userPartnerId) {
            $whereClause = "1=0";
        } else {
            $whereClause = "(p.owner_id = ? OR p.investor_id = ? OR p.confinamento_id = ?)";
            $params = [$userPartnerId, $userPartnerId, $userPartnerId];
        }
    }
    return [$whereClause, $params];
}

$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}

// Pick an admin user id from DB for the hydration test.
$adminId = null;
foreach ($users as $u) { if ($u['role'] === 'admin') { $adminId = $u['id']; break; } }

line("=== TEST 1: session with user_id but MISSING role gets hydrated ===");
if ($adminId !== null) {
    $_SESSION = ['user_id' => $adminId]; // simulate the broken partial session (no role)
    ensure_session_hydrated();
    line("  after hydrate: role=" . var_export($_SESSION['role'] ?? null, true)
        . " partner_id=" . var_export($_SESSION['partner_id'] ?? null, true));
    check("role restored to 'admin' (partner filter reappears)", ($_SESSION['role'] ?? null) === 'admin');

    // With role restored, the admin's default view respects their linked
    // partner_id (ronaldo -> partner 1). If the admin has no partner_id it
    // defaults to 'all'. Either way it is a well-defined admin view, and the
    // filter is rendered because role === 'admin'.
    [$w, $p] = computeWhere($_SESSION['role'] ?? null, $_SESSION['partner_id'] ?? null, null);
    $pid = $_SESSION['partner_id'] ?? null;
    if ($pid) {
        check("admin default scoped to linked partner ($pid)", $p === [$pid, $pid, $pid]);
    } else {
        check("admin with no partner defaults to all (1=1)", $w === "1=1");
    }
    // And the admin can always switch to "Todos os Parceiros".
    [$wAll, $pAll] = computeWhere($_SESSION['role'] ?? null, $_SESSION['partner_id'] ?? null, 'all');
    check("admin can select 'all' -> 1=1", $wAll === "1=1");
} else {
    line("  (no admin user in DB to test hydration)");
}
line();

line("=== TEST 2: unrecognized/empty role no longer leaks all partnerships ===");
// Before the fix, role=null fell through to the default 1=1. Now it is restricted.
[$w, $p] = computeWhere(null, 5, null); // null role, has a partner_id
check("null role with partner_id -> restricted clause (NOT 1=1)", $w !== "1=1");
check("null role with partner_id -> filtered by partner", strpos($w, "owner_id") !== false);

[$w2, $p2] = computeWhere(null, null, null); // null role, no partner
check("null role, no partner -> 1=0 (sees nothing)", $w2 === "1=0");
line();

line("=== TEST 3: normal 'user' role still restricted to own partner ===");
[$w3, $p3] = computeWhere('user', 7, null);
check("user role -> filtered clause", strpos($w3, "owner_id") !== false && $p3 === [7,7,7]);
line();

line("=== TEST 4: admin explicitly filtering by a partner ===");
[$w4, $p4] = computeWhere('admin', null, '3');
check("admin + ?partner_id=3 -> filtered by 3", $p4 === ['3','3','3']);
line();

line("=== TEST 5: DB sanity - all users have a non-null role ===");
$badRoles = $pdo->query("SELECT COUNT(*) c FROM users WHERE role IS NULL OR role = ''")->fetch();
check("no users with null/empty role in DB", intval($badRoles['c']) === 0);
if (intval($badRoles['c']) > 0) {
    line("  WARNING: {$badRoles['c']} user(s) have no role - those sessions could not be hydrated.");
}
line();

line("RESULT: $pass passed, $fail failed");
exit($fail === 0 ? 0 : 1);
