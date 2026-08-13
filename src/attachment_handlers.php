<?php
// This file should be included in partnerships.php after the get_available_lots handler

require_once __DIR__ . '/document_analysis.php';

// Carrega os dados da parceria necessários para validar os documentos anexados
function loadPartnershipForValidation($pdo, $partnership_id)
{
    $sql = "SELECT p.id, p.owner_id, p.investor_id, p.confinamento_id, p.start_date,
                   own.cpf AS owner_doc, own.name AS owner_name,
                   conf.cpf AS conf_doc, conf.name AS conf_name
            FROM partnerships p
            JOIN partners own ON p.owner_id = own.id
            LEFT JOIN partners conf ON p.confinamento_id = conf.id
            WHERE p.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$partnership_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        return null;
    }

    $lotStmt = $pdo->prepare(
        "SELECT l.lot_number, l.animal_count, l.protocol_date
         FROM partnership_lots pl
         JOIN lots l ON pl.lot_id = l.id
         WHERE pl.partnership_id = ?"
    );
    $lotStmt->execute([$partnership_id]);
    $lots = $lotStmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0;
    foreach ($lots as $l) {
        $total += (int) $l['animal_count'];
    }

    return [
        'id' => $p['id'],
        'owner_doc' => $p['owner_doc'],
        'owner_name' => $p['owner_name'],
        'confinamento_doc' => $p['conf_doc'],
        'confinamento_name' => $p['conf_name'],
        // Valida emitente = proprietário apenas quando proprietário != investidor.
        // Em parceria própria (proprietário == investidor) valida-se só o confinamento.
        'validate_owner' => ($p['owner_id'] != $p['investor_id']),
        'lots' => $lots,
        'total_animals' => $total,
    ];
}

// API endpoint: upload de MÚLTIPLOS arquivos com análise e validação automática.
// Classifica cada arquivo (NF / GTA / Pesagem), valida contra a parceria e,
// se todas as validações passarem, grava os anexos com a descrição automática.
// Em caso de qualquer inconsistência, NADA é gravado (bloqueio atômico).
if (isset($_GET['action']) && $_GET['action'] === 'upload_attachments_batch') {
    header('Content-Type: application/json');

    try {
        // Confinamento-only users have read-only attachments: download only.
        if (is_confinement_only_user()) {
            echo json_encode(['success' => false, 'message' => 'Acesso negado. Os anexos podem apenas ser baixados.']);
            exit;
        }

        if (!isset($_FILES['files']) || !isset($_POST['partnership_id'])) {
            echo json_encode(['success' => false, 'message' => 'Arquivos ou ID da parceria não fornecidos']);
            exit;
        }

        $partnership_id = $_POST['partnership_id'];
        $partnership = loadPartnershipForValidation($pdo, $partnership_id);
        if (!$partnership) {
            echo json_encode(['success' => false, 'message' => 'Parceria não encontrada']);
            exit;
        }

        $allowed_types = [
            'application/pdf',
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip'
        ];

        // Normaliza a estrutura de $_FILES['files'] (upload múltiplo)
        $files = [];
        $f = $_FILES['files'];
        $count = is_array($f['name']) ? count($f['name']) : 0;
        for ($i = 0; $i < $count; $i++) {
            if ($f['error'][$i] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Erro no upload do arquivo ' . $f['name'][$i]]);
                exit;
            }
            if (!in_array($f['type'][$i], $allowed_types)) {
                echo json_encode(['success' => false, 'message' => 'Tipo de arquivo não permitido: ' . $f['name'][$i]]);
                exit;
            }
            if ($f['size'][$i] > 10 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Arquivo muito grande (máx 10MB): ' . $f['name'][$i]]);
                exit;
            }
            $files[] = [
                'name' => $f['name'][$i],
                'type' => $f['type'][$i],
                'size' => $f['size'][$i],
                'data' => file_get_contents($f['tmp_name'][$i]),
            ];
        }

        if (empty($files)) {
            echo json_encode(['success' => false, 'message' => 'Nenhum arquivo recebido']);
            exit;
        }

        // Analisa cada documento
        $analyzed = [];
        foreach ($files as $file) {
            $analyzed[] = analyzeSingleDocument($file['name'], $file['type'], $file['data']);
        }

        // Valida o conjunto contra a parceria
        $result = validatePartnershipDocuments($partnership, $analyzed);

        if (!$result['ok']) {
            echo json_encode([
                'success' => false,
                'blocked' => true,
                'message' => 'Anexos bloqueados: inconsistências encontradas.',
                'errors' => $result['errors'],
                'ignored' => $result['ignored'],
                'docs' => $result['docs'],
            ]);
            exit;
        }

        // Validações OK -> grava apenas os documentos RECONHECIDOS (NF/GTA/pesagem).
        // Arquivos não reconhecidos (comprovantes, contratos, imagens) são ignorados.
        $pdo->beginTransaction();
        $sql = "INSERT INTO partnership_attachments (partnership_id, filename, file_data, file_type, file_size, description)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $saved = 0;
        foreach ($files as $idx => $file) {
            $doc = $analyzed[$idx];
            if ($doc['tipo'] === DOC_DESCONHECIDO || empty($doc['readable'])) {
                continue; // ignora não reconhecidos
            }
            $stmt->execute([
                $partnership_id,
                $file['name'],
                $file['data'],
                $file['type'],
                $file['size'],
                descricaoParaTipo($doc),
            ]);
            $saved++;
        }
        $pdo->commit();

        $msg = "$saved anexo(s) enviado(s) e validado(s) com sucesso.";
        if (!empty($result['ignored'])) {
            $msg .= ' ' . count($result['ignored']) . ' arquivo(s) ignorado(s) (não reconhecidos como NF/GTA/pesagem).';
        }
        echo json_encode([
            'success' => true,
            'message' => $msg,
            'ignored' => $result['ignored'],
            'docs' => $result['docs'],
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Erro ao processar anexos: ' . $e->getMessage()]);
    }
    exit;
}

// API endpoint for uploading attachments
if (isset($_GET['action']) && $_GET['action'] === 'upload_attachment') {
    header('Content-Type: application/json');

    try {
        // Confinamento-only users have read-only attachments: download only.
        if (is_confinement_only_user()) {
            echo json_encode(['success' => false, 'message' => 'Acesso negado. Os anexos podem apenas ser baixados.']);
            exit;
        }

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
        $sql = "SELECT partnership_id, filename, file_data, file_type FROM partnership_attachments WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$attachment_id]);
        $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$attachment) {
            die('Anexo não encontrado');
        }

        // A confinamento-only user may only download attachments of the
        // contracts their confinements take part in, so guessing an id does not
        // reach another partner's documents.
        if (is_confinement_only_user() && !user_can_access_partnership($attachment['partnership_id'])) {
            die('Acesso negado. Este anexo não pertence a uma parceria do seu confinamento.');
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

    // Confinamento-only users may download the attachments but never remove them.
    if (is_confinement_only_user()) {
        echo json_encode(['success' => false, 'message' => 'Acesso negado. Os anexos podem ser baixados, mas não excluídos.']);
        exit;
    }

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
