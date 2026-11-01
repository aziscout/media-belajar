<?php
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');

$url = trim($_GET['url'] ?? '');
if (!$url || !preg_match('/^https?:\/\//i', $url)) {
    jsonResponse(['embeddable' => false, 'reason' => 'Invalid URL'], 400);
}

function checkEmbeddableUrl(string $url): array {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

    // 1. Known embeddable platforms
    if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) {
        return ['embeddable' => true, 'reason' => 'YouTube embed supported'];
    }
    if (strpos($host, 'geogebra.org') !== false) {
        return ['embeddable' => true, 'reason' => 'GeoGebra embed supported'];
    }
    if (strpos($host, 'phet.colorado.edu') !== false) {
        return ['embeddable' => true, 'reason' => 'PhET embed supported'];
    }
    if (strpos($host, 'wordwall.net') !== false) {
        return ['embeddable' => true, 'reason' => 'Wordwall embed supported'];
    }
    if (strpos($host, 'quizizz.com') !== false) {
        return ['embeddable' => true, 'reason' => 'Quizizz embed supported'];
    }
    if (strpos($host, 'learningapps.org') !== false) {
        return ['embeddable' => true, 'reason' => 'LearningApps embed supported'];
    }
    if (strpos($host, 'liveworksheets.com') !== false) {
        return ['embeddable' => true, 'reason' => 'Liveworksheets embed supported'];
    }
    if (strpos($host, 'drive.google.com') !== false || strpos($host, 'docs.google.com') !== false) {
        return ['embeddable' => true, 'reason' => 'Google Docs/Drive embed supported'];
    }
    if (strpos($host, 'canva.com') !== false) {
        return ['embeddable' => true, 'reason' => 'Canva embed supported'];
    }
    if (strpos($host, 'scratch.mit.edu') !== false) {
        return ['embeddable' => true, 'reason' => 'Scratch embed supported'];
    }

    // 2. Fetch headers via stream_context or cURL
    $headersStr = '';
    
    // Method A: get_headers
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nRange: bytes=0-500\r\n",
            'timeout' => 5,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ];
    $context = stream_context_create($opts);
    $hdrArray = @get_headers($url, false, $context);

    if ($hdrArray && is_array($hdrArray)) {
        $headersStr = implode("\n", $hdrArray);
    } else {
        // Method B: cURL fallback
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => ['Range: bytes=0-500'],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res !== false) {
            $headersStr = $res;
        }
    }

    $lowerHeaders = strtolower($headersStr);
    
    if (strpos($lowerHeaders, 'x-frame-options') !== false) {
        if (strpos($lowerHeaders, 'sameorigin') !== false || strpos($lowerHeaders, 'deny') !== false) {
            return ['embeddable' => false, 'reason' => 'X-Frame-Options SAMEORIGIN/DENY detected'];
        }
    }

    if (strpos($lowerHeaders, 'frame-ancestors') !== false) {
        if (strpos($lowerHeaders, "frame-ancestors 'none'") !== false || strpos($lowerHeaders, "frame-ancestors 'self'") !== false) {
            return ['embeddable' => false, 'reason' => 'CSP frame-ancestors detected'];
        }
    }

    return ['embeddable' => true, 'reason' => 'OK'];
}

jsonResponse(checkEmbeddableUrl($url));
