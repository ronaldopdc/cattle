<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
// Include vendor autoload if it exists (for PhpSpreadsheet)
if (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
} elseif (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
    $content = $_POST['content'];
    $partnership_id = $_POST['partnership_id'] ?? null;
    $include_attachments = isset($_POST['include_attachments']) && $_POST['include_attachments'] == 1;

    $attachments_html = "";

    if ($include_attachments && $partnership_id) {
        try {
            $sql = "SELECT filename, file_data, file_type, description FROM partnership_attachments WHERE partnership_id = ? ORDER BY uploaded_at ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$partnership_id]);
            $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($attachments as $attach) {
                $attachments_html .= '<div class="attachment-section" style="page-break-before: always;">';
                $attachments_html .= '<h2 class="attachment-title">' . htmlspecialchars($attach['description'] ?: $attach['filename']) . '</h2>';

                if (strpos($attach['file_type'], 'image/') === 0) {
                    $base64 = base64_encode($attach['file_data']);
                    $attachments_html .= '<div style="text-align: center;"><img src="data:' . $attach['file_type'] . ';base64,' . $base64 . '" style="max-width: 100%; max-height: 18cm; object-fit: contain;"></div>';
                } elseif ($attach['file_type'] === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' || $attach['file_type'] === 'application/vnd.ms-excel') {
                    $base64 = base64_encode($attach['file_data']);
                    $attachments_html .= '<div class="excel-attachment" data-excel-base64="' . $base64 . '" data-filename="' . htmlspecialchars($attach['filename']) . '">';
                    $attachments_html .= '<div class="excel-loading-message" style="text-align: center; padding: 20px; border: 1px dashed #ccc; margin: 10px 0;">Carregando Planilha: ' . htmlspecialchars($attach['filename']) . '...</div>';
                    $attachments_html .= '</div>';
                } elseif ($attach['file_type'] === 'application/pdf') {
                    $base64 = base64_encode($attach['file_data']);
                    $attachments_html .= '<div class="pdf-attachment" data-pdf-base64="' . $base64 . '" data-filename="' . htmlspecialchars($attach['filename']) . '">';
                    $attachments_html .= '<div class="pdf-loading-message" style="text-align: center; padding: 20px; border: 1px dashed #ccc; margin: 10px 0;">Carregando PDF: ' . htmlspecialchars($attach['filename']) . '...</div>';
                    $attachments_html .= '</div>';
                } else {
                    $attachments_html .= '<p><i>Anexo: ' . htmlspecialchars($attach['filename']) . ' (Tipo: ' . htmlspecialchars($attach['file_type']) . ')</i></p>';
                }

                $attachments_html .= '</div>';
            }
        } catch (Exception $e) {
            $attachments_html .= '<p style="color: red;">Erro ao carregar anexos: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">

    <head>
        <meta charset="UTF-8">
        <title>Contrato - Cattle Invest</title>
        <!-- PDF.js Library -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
        <!-- SheetJS Library for Excel -->
        <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
        <script>
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        </script>
        <style>
            @page {
                margin: 2cm;
            }

            body {
                font-family: 'Times New Roman', Times, serif;
                font-size: 12pt;
                line-height: 1.6;
                color: #000;
                max-width: 21cm;
                margin: 0 auto;
                padding: 1cm;
            }

            h1,
            h2,
            h3 {
                color: #000;
            }
            
            .attachment-title {
                text-align: center;
                margin-bottom: 2rem;
                border-bottom: 1px solid #000;
                padding-bottom: 0.5rem;
            }

            p {
                margin: 0;
                margin-bottom: 1em;
                text-align: justify;
            }

            /* Quill Alignment Classes */
            .ql-align-center {
                text-align: center;
            }

            .ql-align-right {
                text-align: right;
            }

            .ql-align-justify {
                text-align: justify;
            }

            /* Quill Size Classes */
            .ql-size-small {
                font-size: 0.75em;
            }

            .ql-size-large {
                font-size: 1.5em;
            }

            .ql-size-huge {
                font-size: 2.5em;
            }

            /* Quill Indent Classes */
            .ql-indent-1 {
                padding-left: 3em;
            }

            .ql-indent-2 {
                padding-left: 6em;
            }

            .ql-indent-3 {
                padding-left: 9em;
            }

            .ql-indent-4 {
                padding-left: 12em;
            }

            .ql-indent-5 {
                padding-left: 15em;
            }

            .ql-indent-6 {
                padding-left: 18em;
            }

            .ql-indent-7 {
                padding-left: 21em;
            }

            .ql-indent-8 {
                padding-left: 24em;
            }

            /* Table Styles */
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 1em 0;
            }

            th,
            td {
                border: 1px solid #000;
                padding: 8px;
                text-align: left;
            }

            th {
                background-color: #f2f2f2;
                font-weight: bold;
            }
            
            /* Styles for Excel Tables */
            .attachment-section table {
                font-size: 10pt;
            }
        </style>
    </head>

    <body>
        <div class="contract-content">
            <?= $content ?>
        </div>
        
        <?= $attachments_html ?>

        <script>
            async function renderAttachments() {
                const pdfContainers = document.querySelectorAll('.pdf-attachment');
                const excelContainers = document.querySelectorAll('.excel-attachment');
                
                if (pdfContainers.length === 0 && excelContainers.length === 0) {
                    window.print();
                    return;
                }

                // Render PDFs
                for (const container of pdfContainers) {
                    const base64 = container.getAttribute('data-pdf-base64');
                    try {
                        const binary = atob(base64);
                        const uint8Array = new Uint8Array(binary.length);
                        for (let i = 0; i < binary.length; i++) {
                            uint8Array[i] = binary.charCodeAt(i);
                        }

                        const loadingTask = pdfjsLib.getDocument({data: uint8Array});
                        const pdf = await loadingTask.promise;
                        container.innerHTML = '';

                        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                            const page = await pdf.getPage(pageNum);
                            const viewport = page.getViewport({scale: 2.0});
                            const canvas = document.createElement('canvas');
                            const context = canvas.getContext('2d');
                            canvas.height = viewport.height;
                            canvas.width = viewport.width;
                            canvas.style.maxWidth = '100%';
                            canvas.style.height = 'auto';
                            canvas.style.display = 'block';
                            canvas.style.margin = '0 auto 10px auto';
                            canvas.style.pageBreakInside = 'avoid';

                            await page.render({canvasContext: context, viewport: viewport}).promise;
                            container.appendChild(canvas);
                        }
                    } catch (error) {
                        console.error('Error rendering PDF:', error);
                        container.innerHTML = '<p style="color: red;">Erro ao renderizar PDF (' + container.getAttribute('data-filename') + '): ' + error.message + '</p>';
                    }
                }

                // Render Excel files
                for (const container of excelContainers) {
                    const base64 = container.getAttribute('data-excel-base64');
                    try {
                        const binary = atob(base64);
                        const uint8Array = new Uint8Array(binary.length);
                        for (let i = 0; i < binary.length; i++) {
                            uint8Array[i] = binary.charCodeAt(i);
                        }

                        const workbook = XLSX.read(uint8Array, {type: 'array'});
                        container.innerHTML = '';

                        // Render each sheet
                        workbook.SheetNames.forEach(sheetName => {
                            const worksheet = workbook.Sheets[sheetName];
                            const html = XLSX.utils.sheet_to_html(worksheet);
                            
                            const sheetDiv = document.createElement('div');
                            sheetDiv.className = 'excel-sheet-content';
                            sheetDiv.style.marginBottom = '20px';
                            sheetDiv.style.overflowX = 'auto';
                            
                            if (workbook.SheetNames.length > 1) {
                                const h3 = document.createElement('h3');
                                h3.innerText = 'Planilha: ' + sheetName;
                                sheetDiv.appendChild(h3);
                            }
                            
                            const tableContainer = document.createElement('div');
                            tableContainer.innerHTML = html;
                            // Basic styling for the generated table
                            const table = tableContainer.querySelector('table');
                            if (table) {
                                table.style.width = '100%';
                                table.style.borderCollapse = 'collapse';
                                table.querySelectorAll('td').forEach(td => {
                                    td.style.border = '1px solid #000';
                                    td.style.padding = '4px';
                                });
                            }
                            
                            sheetDiv.appendChild(tableContainer);
                            container.appendChild(sheetDiv);
                        });
                    } catch (error) {
                        console.error('Error rendering Excel:', error);
                        container.innerHTML = '<p style="color: red;">Erro ao renderizar Planilha (' + container.getAttribute('data-filename') + '): ' + error.message + '</p>';
                    }
                }

                // Small delay to ensure browser layout is stable
                setTimeout(() => {
                    window.print();
                }, 800);
            }

            window.onload = renderAttachments;
        </script>
    </body>

    </html>
    <?php
} else {
    echo "Erro: Conteúdo não fornecido.";
}
?>