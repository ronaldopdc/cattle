<?php
require_once 'config.php';

$token = $_GET['token'] ?? '';
$error = '';
$tokenData = null;

if (empty($token)) {
    $error = "Token de acesso não fornecido.";
} else {
    // Validate token
    $stmt = $pdo->prepare("SELECT * FROM registration_tokens WHERE token = ? AND used_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())");
    $stmt->execute([$token]);
    $tokenData = $stmt->fetch();

    if (!$tokenData) {
        $error = "Link de convite inválido, já utilizado ou expirado.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Parceiro - Cattle Invest</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: radial-gradient(circle at top right, #1e293b, #0f172a);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .registration-card {
            width: 100%;
            max-width: 800px;
            background: rgba(30, 41, 59, 0.8);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            overflow: hidden;
        }
        .steps-container {
            display: flex;
            background: rgba(0, 0, 0, 0.2);
            padding: 1.5rem;
            gap: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .step-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            opacity: 0.4;
            transition: all 0.3s ease;
            position: relative;
        }
        .step-item.active { opacity: 1; }
        .step-item.completed { opacity: 0.8; color: var(--primary-color); }
        .step-number {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            border: 2px solid transparent;
        }
        .active .step-number {
            background: var(--primary-color);
            color: #0f172a;
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.4);
        }
        .completed .step-number {
            background: #10b981;
            color: #0f172a;
        }
        .step-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            text-align: center;
        }
        .form-step { display: none; padding: 2.5rem; }
        .form-step.active { display: block; animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .btn-nav {
            display: flex;
            justify-content: space-between;
            padding: 1.5rem 2.5rem 2.5rem;
        }
        .error-container {
            text-align: center;
            padding: 3rem;
        }
        .error-icon {
            font-size: 4rem;
            color: #ef4444;
            margin-bottom: 1.5rem;
        }
        .success-animation {
            text-align: center;
            padding: 4rem 2rem;
        }
        .success-icon {
            font-size: 5rem;
            color: #10b981;
            margin-bottom: 2rem;
            animation: scaleIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        @keyframes scaleIn { from { transform: scale(0); } to { transform: scale(1); } }
        
        .type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .type-option {
            background: rgba(15, 23, 42, 0.4);
            border: 2px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .type-option:hover { border-color: var(--primary-color); background: rgba(56, 189, 248, 0.05); }
        .type-option.selected { border-color: var(--primary-color); background: rgba(56, 189, 248, 0.1); }
        .type-option i { font-size: 2.5rem; margin-bottom: 1rem; color: #64748b; }
        .type-option.selected i { color: var(--primary-color); }
        
        .header-logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .header-logo img { height: 80px; }

        /* File Input Styling */
        .file-upload-container {
            position: relative;
            margin-bottom: 1rem;
        }
        .file-upload-label {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 1rem;
            background: rgba(15, 23, 42, 0.4);
            border: 1px dashed rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .file-upload-label:hover {
            border-color: var(--primary-color);
            background: rgba(56, 189, 248, 0.05);
        }
        .file-upload-label i {
            font-size: 1.25rem;
            color: var(--primary-color);
        }
        .file-name {
            font-size: 0.8rem;
            color: #94a3b8;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        input[type="file"] {
            display: none;
        }
    </style>
</head>
<body>
    <div class="registration-card">
        <?php if ($error): ?>
            <div class="error-container">
                <i class="fas fa-exclamation-triangle error-icon"></i>
                <h2>Ops! Algo deu errado.</h2>
                <p style="color: #94a3b8; margin: 1rem 0 2rem;"><?= $error ?></p>
                <a href="index.php" class="btn btn-secondary">Voltar para Início</a>
            </div>
        <?php else: ?>
            <div class="header-logo" style="padding-top: 2rem;">
                <img src="assets/logo.png" alt="Cattle Invest">
                <h2 style="margin-top: 1rem;">Ficha Cadastral de Parceiro</h2>
            </div>

            <div class="steps-container">
                <div class="step-item active" id="stepIndicator1">
                    <div class="step-number">1</div>
                    <div class="step-label">Tipo</div>
                </div>
                <div class="step-item" id="stepIndicator2">
                    <div class="step-number">2</div>
                    <div class="step-label">Dados</div>
                </div>
                <div class="step-item" id="stepIndicator3">
                    <div class="step-number">3</div>
                    <div class="step-label">Endereço</div>
                </div>
                <div class="step-item" id="stepIndicator4">
                    <div class="step-number">4</div>
                    <div class="step-label">Financeiro</div>
                </div>
                <div class="step-item" id="stepIndicator5">
                    <div class="step-number">5</div>
                    <div class="step-label">Finalizar</div>
                </div>
            </div>

            <form id="registrationForm">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                
                <!-- Step 1: Person Type -->
                <div class="form-step active" id="step1">
                    <h3 style="margin-bottom: 1.5rem; color: #f8fafc;">Como você deseja se cadastrar?</h3>
                    <div class="type-selector">
                        <div class="type-option selected" onclick="selectPersonType('PF')">
                            <i class="fas fa-user"></i>
                            <div style="font-weight: 700;">PESSOA FÍSICA</div>
                            <div style="font-size: 0.8rem; color: #64748b; margin-top: 0.5rem;">Uso de CPF</div>
                        </div>
                        <div class="type-option" onclick="selectPersonType('PJ')">
                            <i class="fas fa-building"></i>
                            <div style="font-weight: 700;">PESSOA JURÍDICA</div>
                            <div style="font-size: 0.8rem; color: #64748b; margin-top: 0.5rem;">Uso de CNPJ</div>
                        </div>
                    </div>
                    <input type="hidden" name="person_type" id="person_type_input" value="PF">
                    
                    <div class="grid">
                        <div class="form-group">
                            <label id="nameLabel">Nome Completo *</label>
                            <input type="text" name="name" required placeholder="Digite seu nome">
                        </div>
                        <div class="form-group">
                            <label>E-mail *</label>
                            <input type="email" name="email" required placeholder="seu@email.com">
                        </div>
                        <div class="form-group">
                            <label>Telefone / WhatsApp *</label>
                            <input type="text" name="phone" id="phone" required placeholder="(00) 00000-0000">
                        </div>
                    </div>
                </div>

                <!-- Step 2: Documents -->
                <div class="form-step" id="step2">
                    <h3 style="margin-bottom: 1.5rem; color: #f8fafc;">Documentação e Informações Pessoais</h3>
                    <div class="grid">
                        <div class="form-group">
                            <label id="docLabel">CPF *</label>
                            <input type="text" name="cpf" id="cpf_cnpj" required>
                        </div>
                        <div class="form-group">
                            <label id="identityLabel">RG *</label>
                            <input type="text" name="identity" id="identity" required>
                        </div>
                        <div class="form-group pf-only">
                            <label>Nacionalidade *</label>
                            <input type="text" name="nationality" id="nationality" required placeholder="Ex: Brasileira">
                        </div>
                        <div class="form-group pf-only">
                            <label>Profissão *</label>
                            <input type="text" name="profession" id="profession" required>
                        </div>
                        <div class="form-group pf-only">
                            <label>Estado Civil *</label>
                            <select name="marital_status" id="marital_status" required>
                                <option value="">Selecione...</option>
                                <option value="Solteiro(a)">Solteiro(a)</option>
                                <option value="Casado(a)">Casado(a)</option>
                                <option value="União Estável">União Estável</option>
                                <option value="Divorciado(a)">Divorciado(a)</option>
                                <option value="Viúvo(a)">Viúvo(a)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="margin-top: 1.5rem;">
                        <label style="margin-bottom: 0.75rem;">Anexar Documentos</label>
                        <div class="grid">
                            <div class="file-upload-container">
                                <label for="doc_identity" class="file-upload-label">
                                    <i class="fas fa-id-card"></i>
                                    <div style="flex: 1;">
                                        <div id="docIdentityTitle" style="font-size: 0.85rem; font-weight: 600; color: #e2e8f0;">Identidade com CPF - Frente ou Arquivo Único *</div>
                                        <div class="file-name" id="name_doc_identity">Nenhum arquivo selecionado</div>
                                    </div>
                                </label>
                                <input type="file" name="doc_identity" id="doc_identity" accept="application/pdf,image/*" required onchange="handleIdentityDocumentChange(this)">
                                <input type="hidden" name="identity_document_text" id="identity_document_text">
                            </div>

                            <div class="file-upload-container" id="doc_identity_back_container">
                                <label for="doc_identity_back" class="file-upload-label">
                                    <i class="fas fa-id-card"></i>
                                    <div style="flex: 1;">
                                        <div id="docIdentityBackTitle" style="font-size: 0.85rem; font-weight: 600; color: #e2e8f0;">Verso da Identidade (caso tenha)</div>
                                        <div class="file-name" id="name_doc_identity_back">Nenhum arquivo selecionado</div>
                                    </div>
                                </label>
                                <input type="file" name="doc_identity_back" id="doc_identity_back" accept="application/pdf,image/*" onchange="handleIdentityDocumentChange(this)">
                                <input type="hidden" name="identity_document_back_text" id="identity_document_back_text">
                            </div>
                             
                            <div class="file-upload-container">
                                <label for="doc_residence" class="file-upload-label">
                                    <i class="fas fa-home"></i>
                                    <div style="flex: 1;">
                                        <div id="docResidenceTitle" style="font-size: 0.85rem; font-weight: 600; color: #e2e8f0;">Comprovante de Residência</div>
                                        <div class="file-name" id="name_doc_residence">Nenhum arquivo selecionado</div>
                                    </div>
                                </label>
                                <input type="file" name="doc_residence" id="doc_residence" accept="application/pdf,image/*" onchange="updateFileName(this, 'name_doc_residence')">
                            </div>
                        </div>
                        <div id="identity_validation_status" style="display: none; margin-top: 0.75rem; padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.85rem; background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); color: #cbd5e1;"></div>
                    </div>
                </div>

                <!-- Step 3: Address -->
                <div class="form-step" id="step3">
                    <h3 style="margin-bottom: 1.5rem; color: #f8fafc;">Endereço de Correspondência</h3>
                    <div class="grid">
                        <div class="form-group">
                            <label>CEP *</label>
                            <input type="text" name="zip" id="zip" required onblur="lookupZip(this.value)">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Endereço Completo *</label>
                            <input type="text" name="address" id="address" required>
                        </div>
                        <div class="form-group">
                            <label>Cidade *</label>
                            <input type="text" name="city" id="city" required>
                        </div>
                        <div class="form-group">
                            <label>Estado (UF) *</label>
                            <input type="text" name="state" id="state" maxlength="2" required>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Financial -->
                <div class="form-step" id="step4">
                    <h3 style="margin-bottom: 1.5rem; color: #f8fafc;">Dados para Pagamentos e Recebimentos</h3>
                    <div class="grid">
                        <div class="form-group">
                            <label>Código do Banco *</label>
                            <input type="text" name="bank_code" required placeholder="Ex: 001, 237, 341">
                        </div>
                        <div class="form-group">
                            <label>Agência *</label>
                            <input type="text" name="agency" required>
                        </div>
                        <div class="form-group">
                            <label>Conta Corrente / Operação *</label>
                            <input type="text" name="account_number" required>
                        </div>
                        <div class="form-group">
                            <label>Tipo de Chave PIX *</label>
                            <select name="pix_type" required>
                                <option value="">Selecione...</option>
                                <option value="cpf">CPF/CNPJ</option>
                                <option value="phone">Telefone</option>
                                <option value="email">E-mail</option>
                                <option value="random">Chave Aleatória</option>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Chave PIX *</label>
                            <input type="text" name="pix" required>
                        </div>
                    </div>
                </div>

                <!-- Step 5: Finalize -->
                <div class="form-step" id="step5">
                    <div style="text-align: center; padding: 2rem;">
                        <i class="fas fa-clipboard-check" style="font-size: 4rem; color: var(--primary-color); margin-bottom: 1.5rem;"></i>
                        <h3>Tudo pronto!</h3>
                        <p style="color: #94a3b8; margin: 1rem 0;">Por favor, revise suas informações antes de enviar. Ao clicar em "Salvar", seu cadastro será processado e o administrador será notificado.</p>
                        
                        <div style="background: rgba(15, 23, 42, 0.4); border-radius: 12px; padding: 1.5rem; text-align: left; margin-top: 1.5rem; border: 1px solid rgba(255,255,255,0.05);">
                            <div id="summaryContent"></div>
                        </div>
                    </div>
                </div>

                <div class="btn-nav">
                    <button type="button" class="btn btn-secondary" id="btnBack" style="visibility: hidden;" onclick="prevStep()">Anterior</button>
                    <button type="button" class="btn btn-primary" id="btnNext" onclick="nextStep()">Próximo</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmit" style="display: none;">Concluir e Salvar</button>
                </div>
            </form>

            <div id="successView" class="success-animation" style="display: none;">
                <i class="fas fa-check-circle success-icon"></i>
                <h2>Cadastro Realizado com Sucesso!</h2>
                <p style="color: #94a3b8; margin: 1.5rem 0 2rem; font-size: 1.1rem;">Seja bem-vindo à Cattle Invest. Seus dados foram salvos com segurança.</p>
                <div style="padding: 1.5rem; background: rgba(16, 185, 129, 0.1); border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.2); color: #34d399; font-size: 0.9rem;">
                    Este link de convite foi desativado.
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        let currentStep = 1;
        const totalSteps = 5;
        let identityDocumentVerified = false;

        $(document).ready(function(){
            $('#phone').mask('(00) 00000-0000');
            $('#zip').mask('00000-000');
            if (window.pdfjsLib) {
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            }
            selectPersonType('PF');
            $('#cpf_cnpj').on('blur', validateDocumentNumberInput);
            $('input[name="name"], #cpf_cnpj').on('input', clearIdentityDocumentVerification);
        });

        function selectPersonType(type) {
            $('.type-option').removeClass('selected');
            if(type === 'PF') {
                $('.type-option').first().addClass('selected');
                $('#nameLabel').text('Nome Completo *');
                $('#docLabel').text('CPF *');
                $('#identityLabel').text('RG *');
                $('#docIdentityTitle').text('Identidade com CPF - Frente ou Arquivo Único *');
                $('#docIdentityBackTitle').text('Verso da Identidade (caso tenha)');
                $('#docResidenceTitle').text('Comprovante de Residência');
                $('#doc_identity_back_container').show();
                $('.pf-only').show();
                $('.pf-only input, .pf-only select').prop('required', true);
            } else {
                $('.type-option').last().addClass('selected');
                $('#nameLabel').text('Razão Social *');
                $('#docLabel').text('CNPJ *');
                $('#identityLabel').text('Inscrição Estadual *');
                $('#docIdentityTitle').text('Último Contrato Social *');
                $('#docResidenceTitle').text('Comprovante de Domicílio');
                $('#doc_identity_back_container').hide();
                $('.pf-only').hide();
                $('.pf-only input, .pf-only select').prop('required', false).val('');
            }
            $('#person_type_input').val(type);
            $('#cpf_cnpj').val('').css('border-color', '');
            $('#doc_identity').val('');
            $('#doc_identity_back').val('');
            $('#identity_document_text').val('');
            $('#identity_document_back_text').val('');
            $('#name_doc_identity').text('Nenhum arquivo selecionado');
            $('#name_doc_identity_back').text('Nenhum arquivo selecionado');
            setIdentityValidationStatus('', 'neutral');
            identityDocumentVerified = type === 'PJ';
            applyCpfCnpjMask();
        }

        function applyCpfCnpjMask() {
            const type = $('#person_type_input').val();
            $('#cpf_cnpj').unmask();
            if(type === 'PF') {
                $('#cpf_cnpj').mask('000.000.000-00');
            } else {
                $('#cpf_cnpj').mask('00.000.000/0000-00');
            }
        }

        function onlyDigits(value) {
            return (value || '').replace(/\D/g, '');
        }

        function ocrDigits(value) {
            return (value || '')
                .replace(/[oO]/g, '0')
                .replace(/[iIlL|]/g, '1')
                .replace(/[sS]/g, '5')
                .replace(/[bB]/g, '8')
                .replace(/\D/g, '');
        }

        function validateCPF(cpf) {
            cpf = onlyDigits(cpf);
            if (cpf.length !== 11 || /^(\d)\1+$/.test(cpf)) return false;
            let sum = 0;
            for (let i = 0; i < 9; i++) sum += parseInt(cpf.charAt(i), 10) * (10 - i);
            let digit = 11 - (sum % 11);
            if (digit >= 10) digit = 0;
            if (digit !== parseInt(cpf.charAt(9), 10)) return false;
            sum = 0;
            for (let i = 0; i < 10; i++) sum += parseInt(cpf.charAt(i), 10) * (11 - i);
            digit = 11 - (sum % 11);
            if (digit >= 10) digit = 0;
            return digit === parseInt(cpf.charAt(10), 10);
        }

        function validateCNPJ(cnpj) {
            cnpj = onlyDigits(cnpj);
            if (cnpj.length !== 14 || /^(\d)\1+$/.test(cnpj)) return false;
            const calcDigit = (base, weights) => {
                const sum = base.split('').reduce((acc, num, idx) => acc + parseInt(num, 10) * weights[idx], 0);
                const remainder = sum % 11;
                return remainder < 2 ? 0 : 11 - remainder;
            };
            const firstDigit = calcDigit(cnpj.substring(0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
            const secondDigit = calcDigit(cnpj.substring(0, 13), [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
            return firstDigit === parseInt(cnpj.charAt(12), 10) && secondDigit === parseInt(cnpj.charAt(13), 10);
        }

        function validateDocumentNumberInput() {
            const type = $('#person_type_input').val();
            const value = $('#cpf_cnpj').val();
            const valid = type === 'PF' ? validateCPF(value) : validateCNPJ(value);
            $('#cpf_cnpj').css('border-color', valid ? '' : '#ef4444');
            return valid;
        }

        function lookupZip(zip) {
            zip = zip.replace(/\D/g, '');
            if(zip.length === 8) {
                fetch(`https://viacep.com.br/ws/${zip}/json/`)
                    .then(r => r.json())
                    .then(data => {
                        if(!data.erro) {
                            $('#address').val(data.logradouro + (data.bairro ? ' - ' + data.bairro : ''));
                            $('#city').val(data.localidade);
                            $('#state').val(data.uf);
                        }
                    });
            }
        }

        function updateSteps() {
            $('.form-step').removeClass('active');
            $(`#step${currentStep}`).addClass('active');
            
            $('.step-item').removeClass('active completed');
            for(let i=1; i<=totalSteps; i++) {
                if(i < currentStep) $(`#stepIndicator${i}`).addClass('completed');
                if(i === currentStep) $(`#stepIndicator${i}`).addClass('active');
            }

            // Button visibility
            $('#btnBack').css('visibility', currentStep === 1 ? 'hidden' : 'visible');
            if(currentStep === totalSteps) {
                $('#btnNext').hide();
                $('#btnSubmit').show();
                generateSummary();
            } else {
                $('#btnNext').show();
                $('#btnSubmit').hide();
            }
        }

        function nextStep() {
            // Basic validation for current step
            const inputs = $(`#step${currentStep} [required]`);
            let valid = true;
            inputs.each(function() {
                if(!$(this).val()) {
                    $(this).css('border-color', '#ef4444');
                    valid = false;
                } else {
                    $(this).css('border-color', '');
                }
            });

            if(!valid) {
                alert('Por favor, preencha todos os campos obrigatórios (*)');
                return;
            }

            if(currentStep === 2 && !validateDocumentNumberInput()) {
                alert($('#person_type_input').val() === 'PF' ? 'CPF inválido.' : 'CNPJ inválido.');
                return;
            }

            if(currentStep === 2 && $('#person_type_input').val() === 'PF' && !identityDocumentVerified) {
                alert('A identidade ainda não foi validada. O CPF informado precisa ser encontrado na frente/arquivo único ou no verso do documento.');
                return;
            }

            if(currentStep < totalSteps) {
                currentStep++;
                updateSteps();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        function prevStep() {
            if(currentStep > 1) {
                currentStep--;
                updateSteps();
            }
        }

        function generateSummary() {
            const formData = new FormData(document.getElementById('registrationForm'));
            const type = formData.get('person_type');
            let html = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.9rem;">';
            html += `<div><strong>${type === 'PF' ? 'Nome Completo' : 'Razão Social'}:</strong> ${formData.get('name')}</div>`;
            html += `<div><strong>Tipo:</strong> ${type}</div>`;
            html += `<div><strong>${type === 'PF' ? 'CPF' : 'CNPJ'}:</strong> ${formData.get('cpf')}</div>`;
            html += `<div><strong>E-mail:</strong> ${formData.get('email')}</div>`;
            html += `<div><strong>Cidade:</strong> ${formData.get('city')}/${formData.get('state')}</div>`;
            
            const doc1 = document.getElementById('doc_identity').files[0];
            const doc1Back = document.getElementById('doc_identity_back').files[0];
            const doc2 = document.getElementById('doc_residence').files[0];
            const docs = [doc1, doc1Back, doc2].filter(Boolean).map(file => file.name);
            if(docs.length) {
                html += `<div style="grid-column: span 2; color: var(--primary-color); border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.5rem; margin-top: 0.5rem;"><strong>Documentos anexados:</strong> ${docs.join(', ')}</div>`;
            }
            
            html += '</div>';
            $('#summaryContent').html(html);
        }

        function updateFileName(input, targetId) {
            const fileName = input.files.length > 0 ? input.files[0].name : 'Nenhum arquivo selecionado';
            document.getElementById(targetId).innerText = fileName;
        }

        function normalizeText(value) {
            return (value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, ' ')
                .trim();
        }

        function getIdentityValidationResult(text) {
            const normalizedText = normalizeText(text);
            const documentDigits = ocrDigits(text);
            const cpf = onlyDigits($('#cpf_cnpj').val());
            const nameTokens = normalizeText($('input[name="name"]').val()).split(' ').filter(token => token.length > 2);
            const hasCpf = cpf.length === 11 && documentDigits.includes(cpf);
            const matchedNameTokens = nameTokens.filter(token => normalizedText.includes(token)).length;
            const hasName = nameTokens.length >= 2 ? matchedNameTokens >= 2 : matchedNameTokens === 1;

            return {
                hasCpf,
                hasName,
                matchedNameTokens,
                expectedCpf: cpf
            };
        }

        function setIdentityValidationStatus(message, type = 'neutral') {
            const el = $('#identity_validation_status');
            if (!message) {
                el.hide().html('');
                return;
            }

            const colors = {
                success: { border: 'rgba(16, 185, 129, 0.35)', bg: 'rgba(16, 185, 129, 0.1)', color: '#34d399' },
                warning: { border: 'rgba(251, 191, 36, 0.35)', bg: 'rgba(251, 191, 36, 0.1)', color: '#fbbf24' },
                error: { border: 'rgba(239, 68, 68, 0.35)', bg: 'rgba(239, 68, 68, 0.1)', color: '#f87171' },
                neutral: { border: 'rgba(255, 255, 255, 0.08)', bg: 'rgba(15, 23, 42, 0.6)', color: '#cbd5e1' }
            };
            const selected = colors[type] || colors.neutral;
            el.css({ borderColor: selected.border, background: selected.bg, color: selected.color }).html(message).show();
        }

        function clearIdentityDocumentVerification() {
            if ($('#person_type_input').val() !== 'PF' || !$('#doc_identity').val()) {
                return;
            }

            identityDocumentVerified = false;
            $('#doc_identity').val('');
            $('#doc_identity_back').val('');
            $('#identity_document_text').val('');
            $('#identity_document_back_text').val('');
            $('#name_doc_identity').text('Nenhum arquivo selecionado');
            $('#name_doc_identity_back').text('Nenhum arquivo selecionado');
            setIdentityValidationStatus('', 'neutral');
        }

        async function handleIdentityDocumentChange(input) {
            const isBackDocument = input.id === 'doc_identity_back';
            const fileNameTarget = isBackDocument ? 'name_doc_identity_back' : 'name_doc_identity';
            const textTarget = isBackDocument ? '#identity_document_back_text' : '#identity_document_text';

            updateFileName(input, fileNameTarget);
            identityDocumentVerified = $('#person_type_input').val() === 'PJ';
            $(textTarget).val('');
            setIdentityValidationStatus('', 'neutral');

            if (!input.files.length || $('#person_type_input').val() === 'PJ') {
                return;
            }

            if (!validateCPF($('#cpf_cnpj').val()) || !$('input[name="name"]').val().trim()) {
                alert('Informe um nome completo e CPF válido antes de anexar a identidade.');
                input.value = '';
                updateFileName(input, fileNameTarget);
                setIdentityValidationStatus('Informe um nome completo e CPF válido antes de anexar a identidade.', 'warning');
                return;
            }

            $('#' + fileNameTarget).text('Conferindo documento... aguarde.');
            setIdentityValidationStatus('Conferindo documento... aguarde.', 'neutral');

            try {
                const text = await extractTextFromFile(input.files[0]);
                $(textTarget).val(text.substring(0, 20000));
                const combinedText = `${$('#identity_document_text').val()} ${$('#identity_document_back_text').val()}`;
                const validation = getIdentityValidationResult(combinedText);

                if (!validation.hasCpf) {
                    identityDocumentVerified = false;
                    $('#' + fileNameTarget).text('Documento anexado, aguardando conferência com a outra face se necessário.');
                    setIdentityValidationStatus(
                        `CPF informado não encontrado no documento até agora.<br>Nome: ${validation.hasName ? 'localizado' : 'não localizado com segurança'}.<br>Anexe o verso da identidade, caso o CPF esteja no verso.`,
                        'warning'
                    );
                    return;
                }

                identityDocumentVerified = true;
                $('#' + fileNameTarget).text('Documento conferido: ' + input.files[0].name);
                setIdentityValidationStatus(
                    `Documento validado.<br>CPF encontrado: ${validation.expectedCpf}.<br>Nome: ${validation.hasName ? 'localizado' : 'não localizado com segurança pelo OCR'}.`,
                    'success'
                );
            } catch (error) {
                console.error(error);
                identityDocumentVerified = false;
                input.value = '';
                $(textTarget).val('');
                $('#' + fileNameTarget).text('Nenhum arquivo selecionado');
                setIdentityValidationStatus('Não foi possível conferir automaticamente o documento. Envie uma imagem ou PDF legível da identidade com CPF.', 'error');
                alert('Não foi possível conferir automaticamente o documento. Envie uma imagem ou PDF legível da identidade com CPF.');
            }
        }

        async function extractTextFromFile(file) {
            if (file.type === 'application/pdf') {
                return extractTextFromPdf(file);
            }

            if (file.type.startsWith('image/')) {
                return extractTextFromImage(file);
            }

            throw new Error('Unsupported file type');
        }

        async function extractTextFromPdf(file) {
            if (!window.pdfjsLib) {
                throw new Error('PDF.js unavailable');
            }

            const buffer = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({ data: buffer }).promise;
            const page = await pdf.getPage(1);
            const nativeTextContent = await page.getTextContent();
            const nativeText = nativeTextContent.items.map(item => item.str).join(' ');
            const viewport = page.getViewport({ scale: 4 });
            const canvas = document.createElement('canvas');
            const context = canvas.getContext('2d');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            await page.render({ canvasContext: context, viewport }).promise;

            // A CNH Digital vem como uma imagem pequena dentro do PDF; focar nessa área melhora muito o OCR.
            const cnhArea = cropCanvas(canvas, 0, canvas.height * 0.08, canvas.width * 0.50, canvas.height * 0.90, 2);
            const cnhDataArea = cropCanvas(canvas, canvas.width * 0.06, canvas.height * 0.12, canvas.width * 0.40, canvas.height * 0.48, 3);

            const results = await Promise.all([
                Tesseract.recognize(canvas, 'por+eng'),
                Tesseract.recognize(cnhArea, 'por+eng'),
                Tesseract.recognize(cnhDataArea, 'por+eng')
            ]);

            return [nativeText, ...results.map(result => result.data.text || '')].join('\n');
        }

        async function extractTextFromImage(file) {
            const image = await loadImageFromFile(file);
            const canvas = document.createElement('canvas');
            const scale = Math.max(1, 2400 / Math.max(image.width, image.height));
            canvas.width = Math.round(image.width * scale);
            canvas.height = Math.round(image.height * scale);
            const context = canvas.getContext('2d');
            context.imageSmoothingEnabled = false;
            context.drawImage(image, 0, 0, canvas.width, canvas.height);

            // CNH física costuma ter CPF no terço superior direito; OCR em recortes reduz ruído da foto/assinatura.
            const topArea = cropCanvas(canvas, 0, 0, canvas.width, canvas.height * 0.48, 2);
            const documentDataArea = cropCanvas(canvas, canvas.width * 0.38, canvas.height * 0.12, canvas.width * 0.57, canvas.height * 0.30, 3);
            const nameArea = cropCanvas(canvas, canvas.width * 0.08, canvas.height * 0.08, canvas.width * 0.82, canvas.height * 0.12, 3);

            const results = await Promise.all([
                Tesseract.recognize(canvas, 'por+eng'),
                Tesseract.recognize(topArea, 'por+eng'),
                Tesseract.recognize(documentDataArea, 'por+eng'),
                Tesseract.recognize(nameArea, 'por+eng')
            ]);

            return results.map(result => result.data.text || '').join('\n');
        }

        function loadImageFromFile(file) {
            return new Promise((resolve, reject) => {
                const image = new Image();
                image.onload = () => resolve(image);
                image.onerror = reject;
                image.src = URL.createObjectURL(file);
            });
        }

        function cropCanvas(sourceCanvas, x, y, width, height, multiplier = 1) {
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(width * multiplier);
            canvas.height = Math.round(height * multiplier);
            const context = canvas.getContext('2d');
            context.imageSmoothingEnabled = false;
            context.drawImage(sourceCanvas, x, y, width, height, 0, 0, canvas.width, canvas.height);
            return canvas;
        }

        $('#registrationForm').on('submit', function(e) {
            e.preventDefault();

            if (!validateDocumentNumberInput()) {
                alert($('#person_type_input').val() === 'PF' ? 'CPF inválido.' : 'CNPJ inválido.');
                return;
            }

            if ($('#person_type_input').val() === 'PF' && !identityDocumentVerified) {
                alert('A identidade ainda não foi validada. O CPF informado precisa ser encontrado no documento antes de concluir.');
                return;
            }

            const btn = $('#btnSubmit');
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Salvando...');

            const formData = new FormData(this);
            $.ajax({
                url: 'api_submit_registration.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(data) {
                    if(data.success) {
                        $('#registrationForm').hide();
                        $('.steps-container').hide();
                        $('.header-logo').hide();
                        $('#successView').fadeIn();
                    } else {
                        alert('Erro ao salvar: ' + data.message);
                        btn.prop('disabled', false).text('Concluir e Salvar');
                    }
                },
                error: function() {
                    alert('Erro de rede ou no servidor.');
                    btn.prop('disabled', false).text('Concluir e Salvar');
                }
            });
        });
    </script>
</body>
</html>
