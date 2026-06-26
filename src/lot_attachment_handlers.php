<?php
// This file should be included in lots.php

// API endpoint for uploading attachment
if (isset($_GET['action']) && $_GET['action'] === 'upload_lot_attachment') {
    header('Content-Type: application/json');

    try {
        if (!isset($_POST['lot_id']) || !isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'message' => 'Dados incompletos']);
            exit;
        }

        $lot_id = $_POST['lot_id'];
        $description = $_POST['description'] ?? '';
        $file = $_FILES['file'];

        // Validate file
        $allowed_types = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip'
        ];

        if (!in_array($file['type'], $allowed_types)) {
            echo json_encode(['success' => false, 'message' => 'Tipo de arquivo não permitido']);
            exit;
        }

        if ($file['size'] > 10 * 1024 * 1024) { // 10MB max
            echo json_encode(['success' => false, 'message' => 'Arquivo muito grande (máximo 10MB)']);
            exit;
        }

        // Read file content
        $file_content = file_get_contents($file['tmp_name']);

        // Insert into database
        $sql = "INSERT INTO lot_attachments (lot_id, filename, file_content, file_type, file_size, description) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $lot_id,
            $file['name'],
            $file_content,
            $file['type'],
            $file['size'],
            $description
        ]);

        echo json_encode(['success' => true, 'message' => 'Arquivo enviado com sucesso']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao enviar arquivo: ' . $e->getMessage()]);
    }
    exit;
}

// API endpoint for downloading attachment
if (isset($_GET['action']) && $_GET['action'] === 'download_lot_attachment') {
    $id = $_GET['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo 'ID não fornecido';
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT filename, file_content, file_type FROM lot_attachments WHERE id = ?");
        $stmt->execute([$id]);
        $attachment = $stmt->fetch();

        if (!$attachment) {
            http_response_code(404);
            echo 'Arquivo não encontrado';
            exit;
        }

        header('Content-Type: ' . $attachment['file_type']);
        header('Content-Disposition: attachment; filename="' . $attachment['filename'] . '"');
        echo $attachment['file_content'];
    } catch (Exception $e) {
        http_response_code(500);
        echo 'Erro ao baixar arquivo';
    }
    exit;
}

// API endpoint for deleting attachment
if (isset($_GET['action']) && $_GET['action'] === 'delete_lot_attachment') {
    header('Content-Type: application/json');
    $id = $_GET['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID não fornecido']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM lot_attachments WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Anexo excluído']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao excluir: ' . $e->getMessage()]);
    }
    exit;
}

// API endpoint for getting attachments
if (isset($_GET['action']) && $_GET['action'] === 'get_lot_attachments') {
    header('Content-Type: application/json');
    $lot_id = $_GET['lot_id'] ?? null;

    if (!$lot_id) {
        echo json_encode([]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, filename, file_type, file_size, description, created_at FROM lot_attachments WHERE lot_id = ? ORDER BY created_at DESC");
        $stmt->execute([$lot_id]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($attachments);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}
?>