<?php
/**
 * Proxy de cotação do boi gordo (arroba).
 *
 * Faz a busca no servidor (evita bloqueio de CORS no navegador) em fontes
 * públicas de cotação. Se todas falharem, retorna um valor padrão para que a
 * simulação continue funcionando — o usuário sempre pode ajustar manualmente.
 *
 * Resposta JSON:
 *   { success, price, source, fetched_at, is_fallback }
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Valor padrão (fallback). Ajuste aqui se a referência de mercado mudar muito.
const BOI_PRICE_FALLBACK = 315.00;

/** Faz o download de uma URL via cURL (ou file_get_contents como reserva). */
function boi_http_get($url, $timeout = 6)
{
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => ['Accept-Language: pt-BR,pt;q=0.9'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 400) {
            return $body;
        }
        return null;
    }

    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'header' => "User-Agent: $ua\r\n"],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? $body : null;
}

/** Converte "315,50" / "R$ 315,50" / "315.50" em float. */
function boi_parse_price($raw)
{
    $raw = trim($raw);
    // Remove milhar (.) e troca decimal (,) por ponto.
    $normalized = str_replace(['.', ' '], ['', ''], $raw);
    $normalized = str_replace(',', '.', $normalized);
    $val = (float) preg_replace('/[^0-9.]/', '', $normalized);
    // Sanidade: arroba do boi gordo hoje fica na casa das centenas.
    if ($val >= 150 && $val <= 700) {
        return round($val, 2);
    }
    return null;
}

/**
 * Cada fonte é uma função que retorna float|null.
 * São tentadas em ordem; a primeira que responder um valor plausível vence.
 */
$sources = [
    // Notícias Agrícolas — indicador ESALQ/B3 boi gordo.
    'Notícias Agrícolas' => function () {
        $html = boi_http_get('https://www.noticiasagricolas.com.br/cotacoes/boi-gordo');
        if (!$html) {
            return null;
        }
        // Procura o primeiro valor no formato de moeda próximo a "boi".
        if (preg_match_all('/R\$\s*([0-9]{3}[.,][0-9]{2})/u', $html, $m)) {
            foreach ($m[1] as $candidate) {
                $p = boi_parse_price($candidate);
                if ($p !== null) {
                    return $p;
                }
            }
        }
        return null;
    },

    // Agrolink — cotações boi gordo.
    'Agrolink' => function () {
        $html = boi_http_get('https://www.agrolink.com.br/cotacoes/pecuaria/boi-gordo');
        if (!$html) {
            return null;
        }
        if (preg_match_all('/([0-9]{3}[.,][0-9]{2})/u', $html, $m)) {
            foreach ($m[1] as $candidate) {
                $p = boi_parse_price($candidate);
                if ($p !== null) {
                    return $p;
                }
            }
        }
        return null;
    },
];

$price = null;
$sourceName = null;

foreach ($sources as $name => $fetch) {
    try {
        $result = $fetch();
    } catch (Throwable $e) {
        $result = null;
    }
    if ($result !== null) {
        $price = $result;
        $sourceName = $name;
        break;
    }
}

if ($price === null) {
    echo json_encode([
        'success'     => true,
        'price'       => BOI_PRICE_FALLBACK,
        'source'      => 'Valor de referência (ajustável)',
        'fetched_at'  => date('d/m/Y H:i'),
        'is_fallback' => true,
    ]);
    exit;
}

echo json_encode([
    'success'     => true,
    'price'       => $price,
    'source'      => $sourceName,
    'fetched_at'  => date('d/m/Y H:i'),
    'is_fallback' => false,
]);
