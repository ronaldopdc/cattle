<?php
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método inválido.']);
    exit;
}

// Trim all POST inputs recursively to avoid trailing/leading spaces
array_walk_recursive($_POST, function (&$val) {
    if (is_string($val)) {
        $val = trim($val);
    }
});

function onlyDigits($value)
{
    return preg_replace('/\D/', '', $value ?? '');
}

function ocrDigits($value)
{
    $value = strtr($value ?? '', [
        'o' => '0', 'O' => '0',
        'i' => '1', 'I' => '1', 'l' => '1', 'L' => '1', '|' => '1',
        's' => '5', 'S' => '5',
        'b' => '8', 'B' => '8'
    ]);
    return preg_replace('/\D/', '', $value);
}

function validateCPF($cpf)
{
    $cpf = onlyDigits($cpf);
    if (strlen($cpf) !== 11 || preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }

    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ((int) $cpf[$t] !== $d) {
            return false;
        }
    }

    return true;
}

function validateCNPJ($cnpj)
{
    $cnpj = onlyDigits($cnpj);
    if (strlen($cnpj) !== 14 || preg_match('/(\d)\1{13}/', $cnpj)) {
        return false;
    }

    for ($t = 12; $t < 14; $t++) {
        $d = 0;
        $m = $t - 7;
        for ($i = 0; $i < $t; $i++) {
            $d += $cnpj[$i] * $m;
            $m = ($m === 2) ? 9 : $m - 1;
        }
        $d = ((10 * $d) % 11) % 10;
        if ((int) $cnpj[$t] !== $d) {
            return false;
        }
    }

    return true;
}

function validateIdentityDocumentText($text, $cpf)
{
    $cpf = onlyDigits($cpf);
    $documentDigits = ocrDigits($text);
    $hasCpf = strlen($cpf) === 11 && strpos($documentDigits, $cpf) !== false;
    return $hasCpf;
}

function requireUploadedFile($field, $label)
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("O arquivo '{$label}' é obrigatório.");
    }
}

function validateUploadedFile($file, $label)
{
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowedTypes, true)) {
        throw new Exception("O arquivo '{$label}' deve ser PDF ou imagem.");
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception("O arquivo '{$label}' deve ter no máximo 10MB.");
    }
}

$token = $_POST['token'] ?? '';

if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Token não fornecido.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Validate Token
    $stmtToken = $pdo->prepare("SELECT * FROM registration_tokens WHERE token = ? AND used_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) FOR UPDATE");
    $stmtToken->execute([$token]);
    $tokenData = $stmtToken->fetch();

    if (!$tokenData) {
        throw new Exception("O link de convite expirou ou já foi utilizado.");
    }

    $personType = $_POST['person_type'] ?? 'PF';
    if (!in_array($personType, ['PF', 'PJ'], true)) {
        throw new Exception("Tipo de pessoa inválido.");
    }

    $requiredFields = [
        'name' => $personType === 'PF' ? 'Nome Completo' : 'Razão Social',
        'email' => 'E-mail',
        'phone' => 'Telefone / WhatsApp',
        'cpf' => $personType === 'PF' ? 'CPF' : 'CNPJ',
        'identity' => $personType === 'PF' ? 'RG' : 'Inscrição Estadual',
        'zip' => 'CEP',
        'address' => 'Endereço Completo',
        'city' => 'Cidade',
        'state' => 'Estado',
        'bank_code' => 'Código do Banco',
        'agency' => 'Agência',
        'account_number' => 'Conta Corrente / Operação',
        'pix_type' => 'Tipo de Chave PIX',
        'pix' => 'Chave PIX'
    ];

    if ($personType === 'PF') {
        $requiredFields['nationality'] = 'Nacionalidade';
        $requiredFields['profession'] = 'Profissão';
        $requiredFields['marital_status'] = 'Estado Civil';
    }

    foreach ($requiredFields as $field => $label) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            throw new Exception("O campo '{$label}' é obrigatório.");
        }
    }

    $documentDigits = onlyDigits($_POST['cpf']);
    if ($personType === 'PF' && !validateCPF($documentDigits)) {
        throw new Exception("CPF inválido.");
    }
    if ($personType === 'PJ' && !validateCNPJ($documentDigits)) {
        throw new Exception("CNPJ inválido.");
    }

    $stmtDuplicate = $pdo->prepare("SELECT id FROM partners WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), '/', '') = ? LIMIT 1");
    $stmtDuplicate->execute([$documentDigits]);
    if ($stmtDuplicate->fetch()) {
        throw new Exception(($personType === 'PF' ? 'CPF' : 'CNPJ') . " já cadastrado.");
    }

    requireUploadedFile('doc_identity', $personType === 'PF' ? 'Identidade com CPF' : 'Último Contrato Social');
    validateUploadedFile($_FILES['doc_identity'], $personType === 'PF' ? 'Identidade com CPF' : 'Último Contrato Social');

    if (isset($_FILES['doc_identity_back']) && $_FILES['doc_identity_back']['error'] === UPLOAD_ERR_OK) {
        validateUploadedFile($_FILES['doc_identity_back'], 'Verso da Identidade');
    }

    if (isset($_FILES['doc_residence']) && $_FILES['doc_residence']['error'] === UPLOAD_ERR_OK) {
        validateUploadedFile($_FILES['doc_residence'], $personType === 'PF' ? 'Comprovante de Residência' : 'Comprovante de Domicílio');
    }

    $identityDocumentText = ($_POST['identity_document_text'] ?? '') . ' ' . ($_POST['identity_document_back_text'] ?? '');
    if ($personType === 'PF' && !validateIdentityDocumentText($identityDocumentText, $documentDigits)) {
        throw new Exception("O CPF informado não foi encontrado na identidade anexada.");
    }

    // 2. Insert Partner
    $pixType = !empty($_POST['pix_type']) ? $_POST['pix_type'] : null;
    $sql = "INSERT INTO partners (person_type, name, email, phone, nationality, marital_status, profession, cpf, identity, address, city, state, zip, bank_code, agency, account_number, pix_type, pix, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $personType,
        $_POST['name'],
        $_POST['email'],
        $_POST['phone'],
        $personType === 'PF' ? $_POST['nationality'] : null,
        $personType === 'PF' ? $_POST['marital_status'] : null,
        $personType === 'PF' ? $_POST['profession'] : null,
        $_POST['cpf'],
        $_POST['identity'] ?? null,
        $_POST['address'] ?? null,
        $_POST['city'] ?? null,
        $_POST['state'] ?? null,
        $_POST['zip'] ?? null,
        $_POST['bank_code'] ?? null,
        $_POST['agency'] ?? null,
        $_POST['account_number'] ?? null,
        $pixType,
        $_POST['pix'] ?? null,
        $tokenData['created_by']
    ]);
    
    $partnerId = $pdo->lastInsertId();

    // 3. Handle File Uploads
    $filesToProcess = [
        'doc_identity' => $personType === 'PF' ? 'Documento de Identidade com CPF' : 'Último Contrato Social',
        'doc_identity_back' => 'Verso da Identidade',
        'doc_residence' => $personType === 'PF' ? 'Comprovante de Residência' : 'Comprovante de Domicílio'
    ];

    foreach ($filesToProcess as $inputName => $desc) {
        if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES[$inputName];
            
            // Basic validation
            $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'];
            if (in_array($file['type'], $allowed_types) && $file['size'] <= 10 * 1024 * 1024) {
                $content = file_get_contents($file['tmp_name']);
                $stmtAtt = $pdo->prepare("INSERT INTO partner_attachments (partner_id, filename, file_content, file_type, file_size, description) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtAtt->execute([
                    $partnerId,
                    $file['name'],
                    $content,
                    $file['type'],
                    $file['size'],
                    $desc
                ]);
            }
        }
    }

    // 4. Mark Token as used
    $stmtUpdateToken = $pdo->prepare("UPDATE registration_tokens SET used_at = NOW(), partner_id = ? WHERE token = ?");
    $stmtUpdateToken->execute([$partnerId, $token]);

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
