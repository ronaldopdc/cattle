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

/**
 * Improved DOCX export using HTML-to-Word compatible format
 * This resolves charset issues and preserves formatting/tables.
 */

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
                // Word uses a specific style for page breaks in HTML: br style="page-break-before:always" or div style="page-break-before:always"
                $attachments_html .= '<div class="attachment-section" style="page-break-before: always; clear: both;">';
                $attachments_html .= '<h2 class="attachment-title" style="text-align: center; border-bottom: 1px solid #000; padding-bottom: 10pt; margin-bottom: 20pt;">' . htmlspecialchars($attach['description'] ?: $attach['filename']) . '</h2>';

                if (strpos($attach['file_type'], 'image/') === 0) {
                    $base64 = base64_encode($attach['file_data']);
                    // Note: Word sometimes struggles with large data URIs in HTML imports, but it's the most compatible way here
                    $attachments_html .= '<div style="text-align: center;"><img src="data:' . $attach['file_type'] . ';base64,' . $base64 . '" style="max-width: 100%; height: auto;"></div>';
                } elseif ($attach['file_type'] === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' || $attach['file_type'] === 'application/vnd.ms-excel') {
                    // Handle Excel using PhpSpreadsheet if available
                    if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                        try {
                            $tmpFile = tempnam(sys_get_temp_dir(), 'xls');
                            file_put_contents($tmpFile, $attach['file_data']);
                            
                            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile);
                            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Html($spreadsheet);
                            
                            ob_start();
                            $writer->save('php://output');
                            $excelHtml = ob_get_clean();
                            
                            if (preg_match_all('/<table[^>]*>.*?<\/table>/is', $excelHtml, $matches)) {
                                $attachments_html .= $matches[0][0];
                            } else {
                                $attachments_html .= $excelHtml;
                            }
                            
                            unlink($tmpFile);
                        } catch (Exception $e) {
                            $attachments_html .= '<p style="color: red;">Erro ao processar planilha: ' . htmlspecialchars($e->getMessage()) . '</p>';
                        }
                    } else {
                        $attachments_html .= '<p><i>Anexo em formato Excel: ' . htmlspecialchars($attach['filename']) . '</i></p>';
                    }
                } elseif ($attach['file_type'] === 'application/pdf') {
                    $attachments_html .= '<p><i>Anexo em formato PDF: ' . htmlspecialchars($attach['filename']) . '. (Arquivos PDF não podem ser mesclados diretamente em documentos Word via HTML).</i></p>';
                } else {
                    $attachments_html .= '<p><i>Anexo: ' . htmlspecialchars($attach['filename']) . ' (Tipo: ' . htmlspecialchars($attach['file_type']) . ')</i></p>';
                }

                $attachments_html .= '</div>';
            }
        } catch (Exception $e) {
            $attachments_html .= '<p style="color: red;">Erro ao carregar anexos: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    // Construct a Word-compatible HTML document
    $html = '
    <html xmlns:o="urn:schemas-microsoft-com:office:office" 
          xmlns:w="urn:schemas-microsoft-com:office:word" 
          xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta charset="UTF-8">
        <title>Contrato</title>
        <!--[if gte mso 9]>
        <xml>
            <w:WordDocument>
                <w:View>Print</w:View>
                <w:Zoom>100</w:Zoom>
                <w:DoNotOptimizeForBrowser/>
            </w:WordDocument>
        </xml>
        <![endif]-->
        <style>
            @page {
                size: 8.5in 11in;
                margin: 1in 1in 1in 1in;
                mso-header-margin: .5in;
                mso-footer-margin: .5in;
                mso-paper-source: 0;
            }
            body {
                font-family: "Times New Roman", Times, serif;
                font-size: 12pt;
                line-height: 1.2;
            }
            p {
                margin: 0;
                padding: 0;
                margin-bottom: 6pt;
            }
            .ql-align-justify {
                text-align: justify;
                text-justify: inter-word;
            }
            .ql-align-center {
                text-align: center;
            }
            .ql-align-right {
                text-align: right;
            }
            table {
                border-collapse: collapse;
                width: 100%;
                mso-table-lspace: 0pt;
                mso-table-rspace: 0pt;
                margin-bottom: 10pt;
            }
            th, td {
                border: 1px solid #000;
                padding: 4pt;
                vertical-align: top;
            }
            th {
                background-color: #f3f4f6;
                font-weight: bold;
            }
        </style>
    </head>
    <body>
        <div class="content">
            ' . $content . '
        </div>
        ' . $attachments_html . '
    </body>
    </html>';

    // Set headers for download as .doc (Word opens this HTML as a document)
    header('Content-Type: application/vnd.ms-word');
    header('Content-Disposition: attachment; filename="contrato_' . date('Y-m-d_H-i-s') . '.doc"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    // Output UTF-8 BOM to force correct encoding detection in Word/Excel
    echo "\xef\xbb\xbf";
    echo $html;
    exit;
} else {
    echo "Erro: Conteúdo não fornecido.";
}
?>