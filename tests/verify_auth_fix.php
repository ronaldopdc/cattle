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
// $userPartnerIds is the full list of partners linked to the user.
function computeWhere($userRole, array $userPartnerIds, $getPartnerId = null) {
    $whereClause = "1=1";
    $params = [];
    if ($userRole === 'admin') {
        $defaultPartnerId = count($userPartnerIds) === 1 ? $userPartnerIds[0] : 'all';
        $selectedPartnerId = $getPartnerId !== null ? $getPartnerId : $defaultPartnerId;
        if ($selectedPartnerId !== 'all') {
            [$whereClause, $params] = partner_scope_clause([intval($selectedPartnerId)]);
        }
    } else {
        [$whereClause, $params] = partner_scope_clause($userPartnerIds);
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
    $sessionPartnerIds = $_SESSION['partner_ids'] ?? [];
    line("  linked partners: " . (empty($sessionPartnerIds) ? '(none)' : implode(', ', $sessionPartnerIds)));
    check("partner_ids hydrated as an array", is_array($_SESSION['partner_ids'] ?? null));

    [$w, $p] = computeWhere($_SESSION['role'] ?? null, $sessionPartnerIds, null);
    if (count($sessionPartnerIds) === 1) {
        $pid = $sessionPartnerIds[0];
        check("admin default scoped to linked partner ($pid)", $p === [$pid, $pid, $pid]);
    } else {
        check("admin with none/multiple partners defaults to all (1=1)", $w === "1=1");
    }
    // And the admin can always switch to "Todos os Parceiros".
    [$wAll, $pAll] = computeWhere($_SESSION['role'] ?? null, $sessionPartnerIds, 'all');
    check("admin can select 'all' -> 1=1", $wAll === "1=1");
} else {
    line("  (no admin user in DB to test hydration)");
}
line();

line("=== TEST 2: unrecognized/empty role no longer leaks all partnerships ===");
// Before the fix, role=null fell through to the default 1=1. Now it is restricted.
[$w, $p] = computeWhere(null, [5], null); // null role, has a partner
check("null role with partner -> restricted clause (NOT 1=1)", $w !== "1=1");
check("null role with partner -> filtered by partner", strpos($w, "owner_id") !== false);

[$w2, $p2] = computeWhere(null, [], null); // null role, no partner
check("null role, no partner -> 1=0 (sees nothing)", $w2 === "1=0");
line();

line("=== TEST 3: normal 'user' role still restricted to own partner ===");
[$w3, $p3] = computeWhere('user', [7], null);
check("user role -> filtered clause", strpos($w3, "owner_id") !== false && $p3 === [7,7,7]);
line();

line("=== TEST 4: admin explicitly filtering by a partner ===");
[$w4, $p4] = computeWhere('admin', [], '3');
check("admin + ?partner_id=3 -> filtered by 3", $p4 === [3,3,3]);
line();

line("=== TEST 5: user linked to SEVERAL partners sees all of them ===");
[$w5, $p5] = computeWhere('user', [4, 9, 12], null);
check("multi-partner clause uses IN (...)", substr_count($w5, "IN (?, ?, ?)") === 3);
check("multi-partner params cover owner/investor/confinamento", $p5 === [4,9,12,4,9,12,4,9,12]);

// The scope must never widen into "see everything" when the list is empty.
[$w5b, $p5b] = computeWhere('user', [], null);
check("user with no partner -> 1=0", $w5b === "1=0" && $p5b === []);
line();

line("=== TEST 6: editing is limited to records the user created ===");
$_SESSION = ['user_id' => 42, 'role' => 'user', 'partner_ids' => [4, 9], 'partner_id' => 4];
check("can edit a record it created", can_edit_record(42) === true);
check("cannot edit a record created by someone else", can_edit_record(7) === false);
check("cannot edit a record with no creator", can_edit_record(null) === false);
check("linked partner is recognized", user_has_partner(9) === true);
check("unlinked partner is rejected", user_has_partner(3) === false);

$_SESSION = ['user_id' => 42, 'role' => 'admin', 'partner_ids' => [], 'partner_id' => null];
check("admin can edit records created by others", can_edit_record(7) === true);
line();

line("=== TEST 7: DB sanity - all users have a non-null role ===");
$badRoles = $pdo->query("SELECT COUNT(*) c FROM users WHERE role IS NULL OR role = ''")->fetch();
check("no users with null/empty role in DB", intval($badRoles['c']) === 0);
if (intval($badRoles['c']) > 0) {
    line("  WARNING: {$badRoles['c']} user(s) have no role - those sessions could not be hydrated.");
}
line();

line("RESULT: $pass passed, $fail failed");
exit($fail === 0 ? 0 : 1);
