<?php
require_once 'auth.php';
require_login();
require_once 'config.php';

$company_id = $_GET['company_id'] ?? 0;

if ($company_id) {
    $stmt = $pdo->prepare("SELECT representative_id FROM partner_representatives WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $reps = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($reps);
} else {
    echo json_encode([]);
}
