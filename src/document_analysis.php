<?php
// ============================================================================
// Análise de documentos anexados à parceria (NF, GTA, Relatório de Pesagem)
//   - PDFs: texto extraído via pdftotext (poppler-utils)
//   - XLSX: lido via ZipArchive (parse do XML interno, sem biblioteca externa)
//
// Classifica cada documento, extrai os dados relevantes e valida o conteúdo
// contra os dados da parceria. Calibrado com DANFEs, GTAs (Agrodefesa/SIDAGO)
// e relatórios de pesagem/rebanho reais do sistema. Os valores extraídos são
// sempre devolvidos na resposta para conferência.
// ============================================================================

// Janela (em dias) da data da NF em torno das datas de protocolo dos lotes.
// A NF é aceita se emitida de até N dias ANTES do protocolo mais antigo até
// N dias DEPOIS do protocolo mais recente (o gado costuma ser comprado com
// bastante antecedência à entrada no confinamento). Ajuste aqui se necessário.
if (!defined('NF_JANELA_DIAS_PROTOCOLO')) {
    define('NF_JANELA_DIAS_PROTOCOLO', 15);
}

// Tolerância (em cabeças) na conferência de quantidade por lote do relatório
// de pesagem (cobre mortes/ajustes entre o cadastro e a pesagem).
if (!defined('PESAGEM_TOLERANCIA_CABECAS')) {
    define('PESAGEM_TOLERANCIA_CABECAS', 3);
}

// Máximo de páginas de um PDF escaneado que passam por OCR (limita o tempo).
if (!defined('OCR_MAX_PAGINAS')) {
    define('OCR_MAX_PAGINAS', 15);
}

const DOC_NOTA_FISCAL   = 'nota_fiscal';
const DOC_GTA           = 'gta';
const DOC_PESAGEM       = 'pesagem';
const DOC_DESCONHECIDO  = 'desconhecido';

// ----------------------------------------------------------------------------
// Utilitários
// ----------------------------------------------------------------------------
function onlyDigits($s)
{
    return preg_replace('/\D+/', '', (string) $s);
}

// Texto útil? (PDF escaneado costuma devolver só espaços/form-feed pelo pdftotext)
function hasMeaningfulText($text)
{
    return (bool) preg_match('/[A-Za-z0-9]/', (string) $text);
}

// Chave de comparação de lote: só dígitos, sem zeros à esquerda ("044" -> "44")
function normalizeLotKey($s)
{
    $d = onlyDigits($s);
    if ($d !== '') {
        return ltrim($d, '0') !== '' ? ltrim($d, '0') : '0';
    }
    return mb_strtoupper(trim((string) $s));
}

// Remove acentos e coloca em maiúsculas para casamento de palavras-chave/nomes
function normalizeText($text)
{
    $text = (string) $text;
    $from = ['á','à','ã','â','ä','é','ê','è','í','ì','ó','õ','ô','ò','ú','ù','ü','ç',
             'Á','À','Ã','Â','É','Ê','Í','Ó','Õ','Ô','Ú','Ç'];
    $to   = ['a','a','a','a','a','e','e','e','i','i','o','o','o','o','u','u','u','c',
             'A','A','A','A','E','E','I','O','O','O','U','C'];
    $text = str_replace($from, $to, $text);
    return mb_strtoupper($text, 'UTF-8');
}

// Casa nome de pessoa por sobreposição de tokens (tolera abreviações/ordem)
function nameMatches($haystackText, $personName)
{
    $hay = normalizeText($haystackText);
    $tokens = array_filter(preg_split('/\s+/', normalizeText($personName)), function ($t) {
        return mb_strlen($t) > 2; // ignora "de", "da", "e", iniciais
    });
    if (empty($tokens)) {
        return false;
    }
    $hit = 0;
    foreach ($tokens as $t) {
        if (strpos($hay, $t) !== false) {
            $hit++;
        }
    }
    return $hit >= max(2, (int) ceil(count($tokens) * 0.6));
}

// ----------------------------------------------------------------------------
// Localização de binários (pdftotext, pdftoppm, tesseract)
// ----------------------------------------------------------------------------
function findBinary($name)
{
    static $cache = [];
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    foreach ([$name, "/usr/bin/$name", "/usr/local/bin/$name", "/opt/bin/$name"] as $c) {
        $check = @shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null');
        if (!empty(trim((string) $check))) {
            return $cache[$name] = $c;
        }
        if ($c[0] === '/' && @is_executable($c)) {
            return $cache[$name] = $c;
        }
    }
    return $cache[$name] = null;
}

// ----------------------------------------------------------------------------
// Extração de texto de PDF via pdftotext, com OCR de reserva para escaneados
// ----------------------------------------------------------------------------
function extractPdfText($pdfBinaryData)
{
    $text = '';
    $bin = findBinary('pdftotext');
    if ($bin !== null) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cattledoc_') . '.pdf';
        file_put_contents($tmpFile, $pdfBinaryData);
        $output = @shell_exec(escapeshellarg($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($tmpFile) . ' - 2>/dev/null');
        @unlink($tmpFile);
        if ($output !== null) {
            $text = $output;
        }
    }
    // PDF sem camada de texto (escaneado) -> tenta OCR das páginas
    if (!hasMeaningfulText($text)) {
        $text = ocrPdf($pdfBinaryData);
    }
    return $text;
}

// OCR de uma imagem já gravada em arquivo (tesseract, idioma português)
function ocrImageFile($path)
{
    $bin = findBinary('tesseract');
    if ($bin === null) {
        return '';
    }
    // --psm 6: assume um bloco uniforme de texto (bom para formulários/tabelas)
    $out = @shell_exec(escapeshellarg($bin) . ' ' . escapeshellarg($path) . ' stdout -l por --psm 6 2>/dev/null');
    return (string) $out;
}

// OCR de uma imagem recebida em binário (jpg/png/gif)
function ocrImageData($binaryData, $ext = 'png')
{
    $tmp = tempnam(sys_get_temp_dir(), 'cattleimg_') . '.' . $ext;
    file_put_contents($tmp, $binaryData);
    $text = ocrImageFile($tmp);
    @unlink($tmp);
    return $text;
}

// OCR de um PDF escaneado: converte páginas em PNG (pdftoppm) e passa no OCR
function ocrPdf($pdfBinaryData)
{
    $ppm = findBinary('pdftoppm');
    if ($ppm === null || findBinary('tesseract') === null) {
        return '';
    }
    $tmpPdf = tempnam(sys_get_temp_dir(), 'cattleocr_') . '.pdf';
    file_put_contents($tmpPdf, $pdfBinaryData);
    $prefix = tempnam(sys_get_temp_dir(), 'cattlepg_');
    @unlink($prefix); // usado apenas como prefixo dos PNGs

    // 300 dpi dá boa precisão; limita o nº de páginas para não estourar o tempo
    @shell_exec(escapeshellarg($ppm) . ' -png -r 300 -f 1 -l ' . OCR_MAX_PAGINAS
        . ' ' . escapeshellarg($tmpPdf) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null');

    $text = '';
    $pages = glob($prefix . '*.png');
    if ($pages) {
        natsort($pages);
        foreach ($pages as $img) {
            $text .= ocrImageFile($img) . "\n";
            @unlink($img);
        }
    }
    @unlink($tmpPdf);
    return $text;
}

// ----------------------------------------------------------------------------
// Leitura de XLSX (ZipArchive + parse de XML)
// ----------------------------------------------------------------------------
// Retorna [ 'sheetName' => [ [colLetter => valor], ... ] ] para todas as abas.
function xlsxReadSheets($xlsxBinaryData)
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'cattlexlsx_') . '.xlsx';
    file_put_contents($tmp, $xlsxBinaryData);
    $z = new ZipArchive();
    if ($z->open($tmp) !== true) {
        @unlink($tmp);
        return null;
    }

    // Shared strings
    $shared = [];
    $ss = $z->getFromName('xl/sharedStrings.xml');
    if ($ss !== false && $ss !== '') {
        if (preg_match_all('/<si>(.*?)<\/si>/s', $ss, $m)) {
            foreach ($m[1] as $si) {
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                    $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                } else {
                    $shared[] = '';
                }
            }
        }
    }

    $sheetXml = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if (preg_match('#xl/worksheets/sheet\d+\.xml$#', $n)) {
            $sheetXml[$n] = $z->getFromName($n);
        }
    }
    $z->close();
    @unlink($tmp);

    ksort($sheetXml);
    $sheets = [];
    foreach ($sheetXml as $name => $xml) {
        $rows = [];
        if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', (string) $xml, $rm)) {
            foreach ($rm[1] as $rc) {
                $cells = [];
                if (preg_match_all('/<c\s+r="([A-Z]+)\d+"(?:\s+[^>]*?t="([^"]*)")?[^>]*>(.*?)<\/c>/s', $rc, $cm, PREG_SET_ORDER)) {
                    foreach ($cm as $c) {
                        $col = $c[1];
                        $t = $c[2] ?? '';
                        $inner = $c[3];
                        $val = '';
                        if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                            $val = $vm[1];
                        } elseif (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $im)) {
                            $val = $im[1];
                        }
                        if ($t === 's') {
                            $val = $shared[(int) $val] ?? '';
                        } else {
                            $val = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                        $cells[$col] = $val;
                    }
                }
                if ($cells) {
                    $rows[] = $cells;
                }
            }
        }
        $sheets[$name] = $rows;
    }
    return $sheets;
}

// Extrai do XLSX de pesagem a contagem de animais por lote e os nomes de origem.
// Procura a aba com colunas "Lote" + ("Contato Origem"/"Peso 1"/"ID Animal").
function pesagemFromXlsx($xlsxBinaryData)
{
    $sheets = xlsxReadSheets($xlsxBinaryData);
    if ($sheets === null) {
        return null;
    }
    foreach ($sheets as $rows) {
        $hdrIdx = -1;
        $colLote = null;
        $colOrig = null;
        foreach ($rows as $i => $r) {
            $found = false;
            $lote = null;
            $orig = null;
            foreach ($r as $col => $v) {
                $u = mb_strtoupper(trim($v), 'UTF-8');
                if ($u === 'LOTE') {
                    $lote = $col;
                }
                if ($u === 'CONTATO ORIGEM' || $u === 'ORIGEM' || $u === 'PRODUTOR') {
                    $orig = $col;
                }
                if (in_array($u, ['CONTATO ORIGEM', 'PESO 1', 'ID ANIMAL', 'PESO'], true)) {
                    $found = true;
                }
            }
            if ($lote !== null && $found) {
                $hdrIdx = $i;
                $colLote = $lote;
                $colOrig = $orig;
                break;
            }
        }
        if ($colLote === null) {
            continue;
        }
        $counts = [];
        $origens = [];
        for ($i = $hdrIdx + 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $loteVal = trim($r[$colLote] ?? '');
            if ($loteVal === '') {
                continue;
            }
            $key = normalizeLotKey($loteVal);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            if ($colOrig && !empty($r[$colOrig])) {
                $origens[trim($r[$colOrig])] = true;
            }
        }
        if ($counts) {
            return ['counts' => $counts, 'origens' => array_keys($origens)];
        }
    }
    return null;
}

// ----------------------------------------------------------------------------
// Classificação (sempre pelo conteúdo; nome do arquivo só como último recurso)
// ----------------------------------------------------------------------------
function classifyPdfText($text)
{
    $norm = normalizeText($text);
    // GTA — marcadores exclusivos (robustos a OCR; o cabeçalho "GUIA DE TRÂNSITO
    // ANIMAL" às vezes é um logo que o OCR não lê, então usamos também a seção
    // "ANIMAIS TRANSPORTADOS" e o emissor "AGÊNCIA ... DE DEFESA AGROPECUÁRIA").
    if (strpos($norm, 'GUIA DE TRANSITO ANIMAL') !== false
        || strpos($norm, 'ANIMAIS TRANSPORTADOS') !== false
        || strpos($norm, 'AGENCIA GOIANA DE DEFESA') !== false
        || strpos($norm, 'DE DEFESA AGROPECUARIA') !== false) {
        return DOC_GTA;
    }
    if (strpos($norm, 'DANFE') !== false
        || strpos($norm, 'DOCUMENTO AUXILIAR DA NOTA') !== false
        || strpos($norm, 'NOTA FISCAL ELETRONICA') !== false
        || strpos($norm, 'PROTOCOLO DE AUTORIZACAO DE USO') !== false
        || strpos($norm, 'NF-E') !== false) {
        return DOC_NOTA_FISCAL;
    }
    // Pesagem em PDF é a lista por animal (coluna BRINCO + LOTE). Exigimos
    // "BRINCO" para não confundir com contratos que citam "pesagem"/"rebanho".
    if (strpos($norm, 'BRINCO') !== false && strpos($norm, 'LOTE') !== false) {
        return DOC_PESAGEM;
    }
    return DOC_DESCONHECIDO;
}

// ----------------------------------------------------------------------------
// Extratores de dados (NF / GTA) por regex
// ----------------------------------------------------------------------------
function extractDocuments($text)
{
    $docs = [];
    if (preg_match_all('/\d{2}\.?\d{3}\.?\d{3}\/\d{4}-?\d{2}/', $text, $m)) {
        foreach ($m[0] as $d) {
            $docs[] = onlyDigits($d);
        }
    }
    if (preg_match_all('/(?<!\d)\d{3}\.\d{3}\.\d{3}-\d{2}(?!\d)/', $text, $m)) {
        foreach ($m[0] as $d) {
            $docs[] = onlyDigits($d);
        }
    }
    return array_values(array_unique($docs));
}

function documentPresent($text, $docDigits)
{
    $docDigits = onlyDigits($docDigits);
    if ($docDigits === '') {
        return false;
    }
    return strpos(onlyDigits($text), $docDigits) !== false;
}

function extractDates($text)
{
    $dates = [];
    if (preg_match_all('/\b(\d{2})\/(\d{2})\/(\d{4})\b/', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $set) {
            if (checkdate((int) $set[2], (int) $set[1], (int) $set[3])) {
                $dates[] = sprintf('%04d-%02d-%02d', $set[3], $set[2], $set[1]);
            }
        }
    }
    return $dates;
}

function extractNfDate($text)
{
    $norm = normalizeText($text);
    if (preg_match('/EMISSAO[^0-9]{0,40}(\d{2})\/(\d{2})\/(\d{4})/', $norm, $m)) {
        if (checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
    }
    $dates = extractDates($text);
    if (!empty($dates)) {
        sort($dates);
        return $dates[0];
    }
    return null;
}

// Número da NF: extraído da CHAVE DE ACESSO (44 dígitos, posições 26-34).
// É o método mais confiável para as DANFEs do sistema (NFePHP/SIDAGO).
function extractNfNumber($text)
{
    // chave impressa em grupos de 4 dígitos
    if (preg_match('/((?:\d{4}\s+){10}\d{4})/', $text, $m)) {
        $chave = onlyDigits($m[1]);
        if (strlen($chave) === 44) {
            return ltrim(substr($chave, 25, 9), '0');
        }
    }
    // chave em 44 dígitos corridos
    if (preg_match('/\b(\d{44})\b/', onlyDigits($text) === '' ? '' : $text, $m)) {
        return ltrim(substr($m[1], 25, 9), '0');
    }
    // fallback: rótulo "Nº. 027.157.430"
    if (preg_match('/N\S{0,2}\.?\s*(\d{1,3}(?:\.\d{3}){2,3})/u', $text, $m)) {
        return ltrim(onlyDigits($m[1]), '0');
    }
    return null;
}

function extractGtaNumber($text)
{
    $norm = normalizeText($text);
    if (preg_match('/N[UÚ]MERO\s*[:\-]?\s*(\d{4,10})/', $norm, $m)) {
        return ltrim($m[1], '0');
    }
    return null;
}

function extractGtaRefFromNf($text)
{
    $norm = normalizeText($text);
    if (preg_match('/N[UÚ]MERO\s+GTA\s*[:\-]?\s*(\d{4,10})/', $norm, $m)) {
        return ltrim($m[1], '0');
    }
    if (preg_match('/\bGTA\s*[:\-]?\s*(\d{4,10})/', $norm, $m)) {
        return ltrim($m[1], '0');
    }
    return null;
}

// Qtd de animais na NF: soma das quantidades das linhas de produto
// ("<UN> <QUANT>,0000 <VLR_UNIT> <VLR_TOTAL>", UN = CB/CAB/UN)
function extractNfAnimalCount($text)
{
    $total = 0;
    if (preg_match_all('/\b(?:CB|CAB|UN)\s+(\d{1,5})[,\.]\d{3,4}\s+[\d\.]+[,\.]\d{2,4}\s+[\d\.]+[,\.]\d{2}/', $text, $m)) {
        foreach ($m[1] as $q) {
            $total += (int) $q;
        }
    }
    return $total;
}

// Qtd de animais na GTA: "Total" da tabela III (último número da linha)
function extractGtaAnimalCount($text)
{
    $norm = normalizeText($text);
    $pos = strpos($norm, 'ANIMAIS TRANSPORTADOS');
    $scope = $pos !== false ? substr($norm, $pos, 600) : $norm;
    if (preg_match('/((?:\d{1,5}\s+){5,}\d{1,5})/', $scope, $m)) {
        $nums = preg_split('/\s+/', trim($m[1]));
        return (int) end($nums);
    }
    return 0;
}

// Pesagem em PDF (lista por animal): conta linhas por lote (nº no fim da linha)
function pesagemFromPdf($text)
{
    $counts = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        if (preg_match('/^\s*(\d{9,})\b.*?(\S+)\s*$/', $line, $m)) {
            $lote = trim($m[2]);
            if ($lote !== '' && preg_match('/^[A-Za-z0-9\-\/]{1,20}$/', $lote)) {
                $key = normalizeLotKey($lote);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
    }
    return $counts;
}

// ----------------------------------------------------------------------------
// Análise de um único arquivo -> estrutura padronizada
// ----------------------------------------------------------------------------
function analyzeSingleDocument($filename, $fileType, $binaryData)
{
    $isPdf = (strpos((string) $fileType, 'pdf') !== false) || preg_match('/\.pdf$/i', $filename);
    $isXlsx = (strpos((string) $fileType, 'spreadsheet') !== false)
        || preg_match('/\.xlsx$/i', $filename);
    $isImage = (strpos((string) $fileType, 'image') !== false)
        || preg_match('/\.(jpe?g|png|gif)$/i', $filename);

    $doc = [
        'filename'  => $filename,
        'file_type' => $fileType,
        'tipo'      => DOC_DESCONHECIDO,
        'readable'  => false,
        'text'      => '',
    ];

    // PDFs (com texto ou escaneados via OCR) e imagens (via OCR) seguem o mesmo
    // caminho de análise por texto.
    if ($isPdf || $isImage) {
        if ($isPdf) {
            $text = extractPdfText($binaryData);
        } else {
            $ext = preg_match('/\.(jpe?g|png|gif)$/i', $filename, $em) ? strtolower($em[1]) : 'png';
            $text = ocrImageData($binaryData, $ext);
        }
        $doc['text'] = $text;
        $doc['readable'] = hasMeaningfulText($text);
        $doc['tipo'] = $doc['readable'] ? classifyPdfText($text) : DOC_DESCONHECIDO;
        $doc['documentos'] = $doc['readable'] ? extractDocuments($text) : [];

        if ($doc['tipo'] === DOC_NOTA_FISCAL) {
            $doc['nf_numero']   = extractNfNumber($text);
            $doc['nf_data']     = extractNfDate($text);
            $doc['qtd_animais'] = extractNfAnimalCount($text);
            $doc['gta_ref']     = extractGtaRefFromNf($text);
        } elseif ($doc['tipo'] === DOC_GTA) {
            $doc['gta_numero']  = extractGtaNumber($text);
            $doc['qtd_animais'] = extractGtaAnimalCount($text);
        } elseif ($doc['tipo'] === DOC_PESAGEM) {
            $doc['lotes_qtd']   = pesagemFromPdf($text);
            $doc['origens']     = [];
        }
    } elseif ($isXlsx) {
        $pes = pesagemFromXlsx($binaryData);
        if ($pes !== null) {
            $doc['tipo']      = DOC_PESAGEM;
            $doc['readable']  = true;
            $doc['lotes_qtd'] = $pes['counts'];
            $doc['origens']   = $pes['origens'];
            $doc['text']      = implode(' ', $pes['origens']);
        } else {
            // XLSX que não é relatório de pesagem reconhecível
            $doc['readable'] = false;
            $doc['tipo'] = DOC_DESCONHECIDO;
        }
    }

    return $doc;
}

// Descrição automática conforme o tipo (padrão das demais parcerias)
function descricaoParaTipo($doc)
{
    switch ($doc['tipo']) {
        case DOC_NOTA_FISCAL:
            return 'Nota Fiscal' . (!empty($doc['nf_numero']) ? ' nº ' . $doc['nf_numero'] : '');
        case DOC_GTA:
            return 'GTA' . (!empty($doc['gta_numero']) ? ' nº ' . $doc['gta_numero'] : '');
        case DOC_PESAGEM:
            return 'Relatório de Identificação e Pesagem';
        default:
            return $doc['filename'];
    }
}

// ----------------------------------------------------------------------------
// Validação do lote de documentos contra os dados da parceria
//   $partnership = [ 'owner_doc','owner_name','confinamento_doc','confinamento_name',
//                    'validate_owner' (bool: valida emitente=proprietário),
//                    'lots' => [ ['lot_number','animal_count','protocol_date'], ... ],
//                    'total_animals' ]
// ----------------------------------------------------------------------------
function validatePartnershipDocuments($partnership, $docs)
{
    $errors = [];
    $validateOwner = !empty($partnership['validate_owner']);

    $notas = [];
    $gtas = [];
    $pesagens = [];
    foreach ($docs as $d) {
        if ($d['tipo'] === DOC_NOTA_FISCAL) {
            $notas[] = $d;
        } elseif ($d['tipo'] === DOC_GTA) {
            $gtas[] = $d;
        } elseif ($d['tipo'] === DOC_PESAGEM) {
            $pesagens[] = $d;
        }
    }

    // Arquivos não reconhecidos (comprovantes, contratos, imagens, PDF ilegível)
    // são IGNORADOS: não bloqueiam o lote e não são gravados. Apenas informamos.
    $ignored = [];
    foreach ($docs as $d) {
        if ($d['tipo'] === DOC_DESCONHECIDO || !$d['readable']) {
            $ignored[] = $d['filename'];
        }
    }

    // Datas de protocolo dos lotes (janela de aceitação da data da NF)
    $protocolDates = [];
    foreach ($partnership['lots'] as $lot) {
        if (!empty($lot['protocol_date'])) {
            $protocolDates[] = $lot['protocol_date'];
        }
    }
    sort($protocolDates);
    $minProtocol = $protocolDates[0] ?? null;
    $maxProtocol = !empty($protocolDates) ? end($protocolDates) : null;

    // --- Notas Fiscais ---
    $somaBoisNotas = 0;
    foreach ($notas as $d) {
        if (!$d['readable']) {
            continue;
        }
        $tag = $d['nf_numero'] ? "NF nº {$d['nf_numero']}" : "'{$d['filename']}'";

        // Emitida pelo proprietário (só quando proprietário != investidor)
        if ($validateOwner && !documentPresent($d['text'], $partnership['owner_doc'])) {
            $errors[] = "$tag: não foi emitida pelo proprietário da parceria ({$partnership['owner_name']} - documento não encontrado na nota).";
        }
        // Destinada ao confinamento
        if (!empty($partnership['confinamento_doc'])
            && !documentPresent($d['text'], $partnership['confinamento_doc'])) {
            $errors[] = "$tag: não está destinada ao confinamento da parceria ({$partnership['confinamento_name']} - documento não encontrado na nota).";
        }
        // Data da NF dentro da janela em torno das datas de protocolo
        if ($minProtocol && !empty($d['nf_data'])) {
            $inicio = strtotime($minProtocol) - NF_JANELA_DIAS_PROTOCOLO * 86400;
            $fim    = strtotime($maxProtocol) + NF_JANELA_DIAS_PROTOCOLO * 86400;
            $nf     = strtotime($d['nf_data']);
            if ($nf < $inicio || $nf > $fim) {
                $errors[] = "$tag: data de emissão ({$d['nf_data']}) muito distante das datas de protocolo dos lotes "
                    . "($minProtocol a $maxProtocol, tolerância de " . NF_JANELA_DIAS_PROTOCOLO . " dias).";
            }
        } elseif ($minProtocol && empty($d['nf_data'])) {
            $errors[] = "$tag: não foi possível identificar a data de emissão.";
        }

        $somaBoisNotas += (int) ($d['qtd_animais'] ?? 0);

        // Cada NF deve ter GTA correspondente (vínculo = nº da GTA citado na NF)
        $temGta = false;
        foreach ($gtas as $g) {
            if (!empty($d['gta_ref']) && !empty($g['gta_numero'])
                && onlyDigits($d['gta_ref']) === onlyDigits($g['gta_numero'])) {
                $temGta = true;
                break;
            }
            if (!empty($d['nf_numero'])
                && strpos(onlyDigits($g['text']), onlyDigits($d['nf_numero'])) !== false) {
                $temGta = true;
                break;
            }
        }
        if (!$temGta) {
            $errors[] = "$tag: não há GTA correspondente anexada para esta nota.";
        }
    }

    if (!empty($notas) && $somaBoisNotas < (int) $partnership['total_animals']) {
        $errors[] = "Soma de bois nas notas fiscais ($somaBoisNotas) é menor que o total de bois da parceria "
            . "({$partnership['total_animals']}).";
    }

    // --- Relatórios de Pesagem (agrega todos os relatórios do lote) ---
    if (!empty($pesagens)) {
        $mergedCounts = [];
        $ownerConfirmed = false;
        foreach ($pesagens as $d) {
            if (!$d['readable']) {
                continue;
            }
            foreach (($d['lotes_qtd'] ?? []) as $k => $v) {
                $mergedCounts[$k] = ($mergedCounts[$k] ?? 0) + (int) $v;
            }
            // Do proprietário? (por documento ou por nome, no texto ou nas origens)
            if (documentPresent($d['text'], $partnership['owner_doc'])
                || nameMatches($d['text'], $partnership['owner_name'])) {
                $ownerConfirmed = true;
            }
            foreach (($d['origens'] ?? []) as $orig) {
                if (nameMatches($orig, $partnership['owner_name'])) {
                    $ownerConfirmed = true;
                }
            }
        }

        if (!$ownerConfirmed) {
            $errors[] = "Relatório(s) de pesagem: não foi possível confirmar que é do proprietário da parceria ({$partnership['owner_name']}).";
        }

        foreach ($partnership['lots'] as $lot) {
            $key = normalizeLotKey($lot['lot_number']);
            if (!isset($mergedCounts[$key])) {
                $errors[] = "Relatório de pesagem: lote {$lot['lot_number']} da parceria não consta nos relatórios anexados.";
                continue;
            }
            if (abs((int) $mergedCounts[$key] - (int) $lot['animal_count']) > PESAGEM_TOLERANCIA_CABECAS) {
                $errors[] = "Relatório de pesagem: quantidade de bois do lote {$lot['lot_number']} "
                    . "({$mergedCounts[$key]}) difere da cadastrada na parceria ({$lot['animal_count']}) "
                    . "além da tolerância de " . PESAGEM_TOLERANCIA_CABECAS . " cabeças.";
            }
        }
    }

    // Descrição de cada documento (remove o texto bruto da resposta)
    $out = [];
    foreach ($docs as $d) {
        $d['descricao'] = descricaoParaTipo($d);
        unset($d['text']);
        $out[] = $d;
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
        'ignored' => $ignored,
        'docs' => $out,
    ];
}
