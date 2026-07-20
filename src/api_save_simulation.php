<?php
/**
 * Endpoint PÚBLICO (sem login) usado pela tela de simulação quando o cliente
 * clica em "Quero ser Parceiro / Cadastre-se".
 *
 * Fluxo:
 *   1. Recalcula a simulação no servidor (não confia nos números do cliente).
 *   2. Salva a simulação em partnership_simulations.
 *   3. Gera um token de convite (registration_tokens) e o vincula à simulação.
 *   4. Retorna a URL do cadastro por link (register_partner.php?token=...).
 *
 * Ao concluir o cadastro, api_submit_registration.php amarra a simulação ao
 * parceiro recém-criado (via token).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/simulation_rates.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método inválido.']);
    exit;
}

try {
    $numAnimals  = (int) ($_POST['num_animals'] ?? 0);
    $entryWeight = (float) str_replace(',', '.', $_POST['entry_weight'] ?? 0);
    $arrobaPrice = (float) str_replace(',', '.', $_POST['arroba_price'] ?? 0);
    $days        = (int) ($_POST['days'] ?? 0);

    if ($numAnimals <= 0 || $entryWeight <= 0 || $arrobaPrice <= 0 || $days <= 0) {
        throw new Exception('Preencha número de bois, peso, preço da arroba e prazo para simular.');
    }

    $clientName  = trim($_POST['client_name'] ?? '') ?: null;
    $clientPhone = trim($_POST['client_phone'] ?? '') ?: null;
    $clientEmail = trim($_POST['client_email'] ?? '') ?: null;

    $calc = sim_calculate($numAnimals, $entryWeight, $arrobaPrice, $days);

    $pdo->beginTransaction();

    sim_ensure_tables($pdo);

    // Gera token de convite (uso único, expira em 48h), como no fluxo padrão.
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+48 hours'));

    // created_by referencia um usuário. Em fluxo público usamos um admin como
    // "criador" do convite. Se não houver admin, tenta qualquer usuário.
    $createdBy = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if (!$createdBy) {
        $createdBy = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
    }

    $stmtToken = $pdo->prepare("INSERT INTO registration_tokens (token, created_by, expires_at) VALUES (?, ?, ?)");
    $stmtToken->execute([$token, $createdBy ?: null, $expiresAt]);

    // Salva a simulação já vinculada ao token.
    $stmtSim = $pdo->prepare("INSERT INTO partnership_simulations
        (num_animals, entry_weight, arroba_price, days, monthly_rate,
         total_arrobas, base_value, avg_monthly_cost, total_participation, final_value,
         client_name, client_phone, client_email, token)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtSim->execute([
        $numAnimals,
        $entryWeight,
        $arrobaPrice,
        $days,
        $calc['rate'],
        $calc['total_arrobas'],
        $calc['base'],
        $calc['avg_monthly'],
        $calc['total_participation'],
        $calc['final'],
        $clientName,
        $clientPhone,
        $clientEmail,
        $token,
    ]);
    $simulationId = $pdo->lastInsertId();

    $pdo->commit();

    $registerUrl = sim_base_url() . "/register_partner.php?token=$token";

    echo json_encode([
        'success'       => true,
        'simulation_id' => $simulationId,
        'register_url'  => $registerUrl,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
