<?php
require_once 'auth.php';
require_login();
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método inválido.']);
    exit;
}

try {
    $token = bin2hex(random_bytes(32));
    $createdBy = $_SESSION['user_id'];
    $expiresAt = date('Y-m-d H:i:s', strtotime('+48 hours'));

    $stmt = $pdo->prepare("INSERT INTO registration_tokens (token, created_by, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$token, $createdBy, $expiresAt]);

    // Generate full URL
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $uri = $_SERVER['REQUEST_URI'];
    
    // Get the base path (everything before the current filename)
    $path = rtrim(dirname($uri), '/\\');
    
    // If we are already inside /src, just use the path. Otherwise append /src
    if (basename($path) === 'src') {
        $baseUrl = "$protocol://$host" . $path;
    } else {
        $baseUrl = "$protocol://$host" . $path . "/src";
    }
    
    // Replace any accidental double slashes (except the one after http:)
    $baseUrl = preg_replace('/([^:])\/\//', '$1/', $baseUrl);
    
    $registrationUrl = "$baseUrl/register_partner.php?token=$token";

    echo json_encode([
        'success' => true,
        'token' => $token,
        'url' => $registrationUrl,
        'expires_at' => date('d/m/Y H:i', strtotime($expiresAt))
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao gerar token: ' . $e->getMessage()]);
}
?>
