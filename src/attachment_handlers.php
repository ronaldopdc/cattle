<?php
// This file should be included in partnerships.php after the get_available_lots handler

// API endpoint for uploading attachments
if (isset($_GET['action']) && $_GET['action'] === 'upload_attachment') {
    header('Content-Type: application/json');

    try {
        if (!isset($_FILES['file']) || !isset($_POST['partnership_id'])) {
            echo json_encode(['success' => false, 'message' => 'Arquivo ou ID da parceria não fornecido']);
            exit;
        }

        $partnership_id = $_POST['partnership_id'];
        $description = $_POST['description'] ?? '';
        $file = $_FILES['file'];

        // Validate file type
        $allowed_types = [
            'application/pdf',
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip'
        ];

        if (!in_array($file['type'], $allowed_types)) {
            echo json_encode(['success' => false, 'message' => 'Tipo de arquivo não permitido']);
            exit;
        }

        // Validate file size (max 10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Arquivo muito grande (máx 10MB)']);
            exit;
        }

        // Read file content
        $file_data = file_get_contents($file['tmp_name']);

        // Insert into database
        $sql = "INSERT INTO partnership_attachments (partnership_id, filename, file_data, file_type, file_size, description) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $partnership_id,
            $file['name'],
            $file_data,
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

// API endpoint for downloading attachments
if (isset($_GET['action']) && $_GET['action'] === 'download_attachment') {
    $attachment_id = $_GET['id'] ?? null;

    if (!$attachment_id) {
        die('ID do anexo não fornecido');
    }

    try {
        $sql = "SELECT filename, file_data, file_type FROM partnership_attachments WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$attachment_id]);
        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$attachment) {
            die('Anexo não encontrado');
        }

        // Set headers for download
        header('Content-Type: ' . $attachment['file_type']);
        header('Content-Disposition: attachment; filename="' . $attachment['filename'] . '"');
        header('Content-Length: ' . strlen($attachment['file_data']));

        echo $attachment['file_data'];
    } catch (Exception $e) {
        die('Erro ao baixar arquivo: ' . $e->getMessage());
    }
    exit;
}

// API endpoint for deleting attachments
if (isset($_GET['action']) && $_GET['action'] === 'delete_attachment') {
    header('Content-Type: application/json');

    $attachment_id = $_GET['id'] ?? null;

    if (!$attachment_id) {
        echo json_encode(['success' => false, 'message' => 'ID do anexo não fornecido']);
        exit;
    }

    try {
        $sql = "DELETE FROM partnership_attachments WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$attachment_id]);

        echo json_encode(['success' => true, 'message' => 'Anexo excluído com sucesso']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao excluir anexo: ' . $e->getMessage()]);
    }
    exit;
}

// API endpoint for getting attachments
if (isset($_GET['action']) && $_GET['action'] === 'get_attachments') {
    header('Content-Type: application/json');

    $partnership_id = $_GET['partnership_id'] ?? null;

    if (!$partnership_id) {
        echo json_encode([]);
        exit;
    }

    try {
        $sql = "SELECT id, filename, file_type, file_size, description, uploaded_at 
                FROM partnership_attachments 
                WHERE partnership_id = ? 
                ORDER BY uploaded_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$partnership_id]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($attachments);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}
