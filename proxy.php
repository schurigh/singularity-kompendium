<?php
// Einfacher Same-Origin Proxy für Substack RSS, API und Artikel
// Verhindert alle CORS-Probleme auf singularity-kompendium.hannes-schurig.de

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (!$url || !preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    echo "Ungültige URL";
    exit;
}

// Nur erlaubte Domains (Sicherheit)
$parsed = parse_url($url);
$host = isset($parsed['host']) ? $parsed['host'] : '';
if (!preg_match('#(substack\.com|substackcdn\.com)$#i', $host)) {
    http_response_code(403);
    echo "Nur Substack-URLs sind über diesen Proxy erlaubt.";
    exit;
}

$response = false;
$httpCode = 0;
$contentType = '';

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
} else {
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n",
            'timeout' => 25,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    if ($response !== false) {
        $httpCode = 200;
        $contentType = 'application/json; charset=utf-8';
    }
}

if ($httpCode >= 200 && $httpCode < 300 && $response !== false) {
    if ($contentType) {
        header("Content-Type: " . $contentType);
    } else {
        header("Content-Type: text/plain; charset=utf-8");
    }
    echo $response;
} else {
    http_response_code($httpCode ?: 500);
    echo "Fehler beim Abrufen der URL ($httpCode)";
}
