<?php
// =========================================================================
// Peter H. Diamandis Kompendium - Server API & Sync Backend
// =========================================================================

// Konfiguration laden
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', '');
}
if (!defined('DATA_FILE_PATH')) {
    define('DATA_FILE_PATH', __DIR__ . '/diamandis-data.js');
}
if (!defined('BACKUP_FILE_PATH')) {
    define('BACKUP_FILE_PATH', __DIR__ . '/diamandis-data.backup.js');
}
if (!defined('LEGAL_NAME')) {
    define('LEGAL_NAME', '');
}
if (!defined('LEGAL_ADDRESS_LINE1')) {
    define('LEGAL_ADDRESS_LINE1', '');
}
if (!defined('LEGAL_ADDRESS_LINE2')) {
    define('LEGAL_ADDRESS_LINE2', '');
}
if (!defined('LEGAL_COUNTRY')) {
    define('LEGAL_COUNTRY', '');
}
if (!defined('LEGAL_EMAIL')) {
    define('LEGAL_EMAIL', '');
}
if (!defined('LEGAL_HOSTING_NAME')) {
    define('LEGAL_HOSTING_NAME', 'Neue Medien Münnich GmbH (All-Inkl.com)');
}
if (!defined('LEGAL_HOSTING_ADDRESS')) {
    define('LEGAL_HOSTING_ADDRESS', 'Hauptstraße 68, 02742 Friedersdorf, Deutschland');
}
if (!defined('LEGAL_HOSTING_URL')) {
    define('LEGAL_HOSTING_URL', 'https://all-inkl.com');
}
if (!defined('GEMINI_FALLBACK_KEYS')) {
    define('GEMINI_FALLBACK_KEYS', []);
}

// CORS & JSON Header
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Admin-Password, Authorization");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=utf-8");

// JSON-Body einlesen
$rawInput = file_get_contents('php://input');
$inputData = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $inputData = $decoded;
    }
}

// Authentifizierung prüfen
function checkAuth($inputData) {
    $provided = '';
    if (isset($_SERVER['HTTP_X_ADMIN_PASSWORD'])) {
        $provided = trim($_SERVER['HTTP_X_ADMIN_PASSWORD']);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $provided = trim($matches[1]);
        }
    } elseif (isset($inputData['password'])) {
        $provided = trim($inputData['password']);
    } elseif (isset($_GET['password'])) {
        $provided = trim($_GET['password']);
    }

    if (empty(ADMIN_PASSWORD) || empty($provided)) {
        return false; // Kein Passwort konfiguriert oder übermittelt -> Schreibzugriff gesperrt
    }

    return hash_equals(ADMIN_PASSWORD, $provided);
}

// =========================================================================
// Rate-Limiting für KI-Anfragen (1 Anfrage / Minute, maximal 5 Anfragen / Tag pro IP-Hash)
// =========================================================================
function getClientIp() {
    $ip = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
}

function getClientIpHash() {
    $ip = getClientIp();
    $salt = defined('ADMIN_PASSWORD') && !empty(ADMIN_PASSWORD) ? ADMIN_PASSWORD : 'singularity_salt_2026';
    return hash('sha256', $ip . '_' . $salt);
}

function checkAiRateLimit($recordRequest = false) {
    $hash = getClientIpHash();
    $file = __DIR__ . '/.rate_limits.json';
    $today = gmdate('Y-m-d');
    $now = time();

    $data = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }

    // Veraltete Einträge (> 48h alt) aufräumen
    foreach ($data as $h => $rec) {
        if (isset($rec['lastRequestTime']) && ($now - $rec['lastRequestTime']) > 172800) {
            unset($data[$h]);
        }
    }

    $record = $data[$hash] ?? [
        'lastRequestTime' => 0,
        'dailyDate' => $today,
        'dailyCount' => 0
    ];

    if (($record['dailyDate'] ?? '') !== $today) {
        $record['dailyDate'] = $today;
        $record['dailyCount'] = 0;
    }

    $lastTime = (int)($record['lastRequestTime'] ?? 0);
    $dailyCount = (int)($record['dailyCount'] ?? 0);

    // 1 Anfrage pro 60s
    $cooldownRemaining = max(0, 60 - ($now - $lastTime));
    // Maximal 5 Anfragen pro Tag
    $dailyRemaining = max(0, 5 - $dailyCount);

    $allowed = ($cooldownRemaining === 0) && ($dailyRemaining > 0);

    if ($recordRequest && $allowed) {
        $record['lastRequestTime'] = $now;
        $record['dailyCount'] = $dailyCount + 1;
        $record['dailyDate'] = $today;
        $data[$hash] = $record;
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);

        $dailyRemaining = max(0, 5 - $record['dailyCount']);
        $cooldownRemaining = 60;
    }

    return [
        'allowed' => $allowed,
        'cooldownRemaining' => $cooldownRemaining,
        'dailyRemaining' => $dailyRemaining,
        'dailyLimit' => 5,
        'dailyCount' => $record['dailyCount']
    ];
}

// Hilfsfunktion: Daten aus diamandis-data.js einlesen
function readDataFile() {
    $filePath = DATA_FILE_PATH;
    if (!file_exists($filePath)) {
        return [];
    }

    $content = file_get_contents($filePath);
    if (empty($content)) {
        return [];
    }

    // Extrahiere den JSON-Teil (nach 'const INITIAL_NEWSLETTERS = ' bis zum Semikolon)
    if (preg_match('/const\s+INITIAL_NEWSLETTERS\s*=\s*(\[[\s\S]*\])\s*;?\s*$/m', $content, $matches)) {
        $json = $matches[1];
        $data = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }
    }

    // Fallback: Suche nach beliebigem JSON Array
    if (preg_match('/(\[[\s\S]*\])/m', $content, $matches)) {
        $data = json_decode($matches[1], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }
    }

    return [];
}

// Hilfsfunktion: Daten formatiert in diamandis-data.js schreiben
function writeDataFile($articles) {
    if (!is_array($articles)) {
        return false;
    }

    $filePath = DATA_FILE_PATH;
    $backupPath = BACKUP_FILE_PATH;

    // Backup anlegen, falls Zieldatei bereits existiert und nicht leer ist
    if (file_exists($filePath) && filesize($filePath) > 0) {
        @copy($filePath, $backupPath);
    }

    // Sortiere nach Datum absteigend (neueste zuerst)
    usort($articles, function($a, $b) {
        $dateA = isset($a['date']) ? $a['date'] : '';
        $dateB = isset($b['date']) ? $b['date'] : '';
        return strcmp($dateB, $dateA);
    });

    $json = json_encode($articles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $fileContent = "/**\n" .
                   " * Peter H. Diamandis Newsletter Kompendium - Datenspeicher\n" .
                   " * Dieses Array enthaelt alle erfassten Artikel/Newsletter auf Deutsch.\n" .
                   " * Zuletzt synchronisiert: " . gmdate('Y-m-d H:i:s') . " UTC\n" .
                   " */\n" .
                   "const INITIAL_NEWSLETTERS = " . $json . ";\n";

    $bytes = @file_put_contents($filePath, $fileContent, LOCK_EX);
    return ($bytes !== false);
}

// Hilfsfunktion: Bild von URL herunterladen und lokal speichern
function downloadRemoteImage($url, $targetDir, $baseFileName) {
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }

    $data = null;
    $contentType = '';
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
    }

    // 2. Stream context if https wrapper is supported
    if (empty($data) && in_array('https', stream_get_wrappers())) {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n",
                'timeout' => 20,
                'follow_location' => 1,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        $context = stream_context_create($opts);
        $data = @file_get_contents($url, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $hdr, $m)) {
                    $httpCode = intval($m[1]);
                } elseif (stripos($hdr, 'Content-Type:') === 0) {
                    $contentType = trim(substr($hdr, 13));
                }
            }
        }
    }

    // 3. PowerShell / CLI fallback for Windows systems without openssl/curl in PHP
    if (empty($data) && (DIRECTORY_SEPARATOR === '\\' || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')) {
        $tempOut = tempnam(sys_get_temp_dir(), 'img_dl_') . '.tmp';
        $safeUrl = addslashes($url);
        $safeOut = addslashes($tempOut);
        $psCmd = 'powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; (New-Object Net.WebClient).DownloadFile(\'' . $safeUrl . '\', \'' . $safeOut . '\')"';
        @exec($psCmd, $psOut, $psRet);
        if ($psRet === 0 && file_exists($tempOut) && filesize($tempOut) > 50) {
            $data = @file_get_contents($tempOut);
            $httpCode = 200;
        }
        if (file_exists($tempOut)) {
            @unlink($tempOut);
        }
    }

    if ($httpCode !== 200 || empty($data)) {
        return null;
    }

    $ext = 'jpg';
    if (stripos($contentType, 'image/png') !== false) {
        $ext = 'png';
    } elseif (stripos($contentType, 'image/webp') !== false) {
        $ext = 'webp';
    } elseif (stripos($contentType, 'image/gif') !== false) {
        $ext = 'gif';
    } elseif (stripos($contentType, 'image/svg') !== false) {
        $ext = 'svg';
    } elseif (preg_match('/\.(jpg|jpeg|png|webp|gif|svg)($|\?)/i', $url, $m)) {
        $ext = strtolower($m[1]);
        if ($ext === 'jpeg') $ext = 'jpg';
    }

    $fileName = $baseFileName . '.' . $ext;
    $filePath = rtrim($targetDir, '/\\') . '/' . $fileName;

    if (@file_put_contents($filePath, $data) !== false) {
        // Mindestgröße prüfen: Breite und Höhe müssen mindestens 300px betragen
        $size = @getimagesize($filePath);
        if ($size && is_array($size)) {
            $w = isset($size[0]) ? intval($size[0]) : 0;
            $h = isset($size[1]) ? intval($size[1]) : 0;
            if ($w < 300 || $h < 300) {
                @unlink($filePath);
                return null; // Verwurf: Bild ist kleiner als 300px
            }
        }
        return $fileName;
    }

    return null;
}

// Serverseitiger Google Gemini Proxy (Fallback-Keys bleiben streng geheim auf dem Server)
function callGeminiServerApi($apiKey, $model, $systemInstruction, $prompt) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":generateContent?key=" . urlencode($apiKey);

    $parts = [];
    if (!empty($systemInstruction)) {
        $parts[] = ['text' => (string)$systemInstruction];
    }
    $parts[] = ['text' => (string)$prompt];

    $postData = json_encode([
        'contents' => [
            ['parts' => $parts]
        ],
        'generationConfig' => [
            'temperature' => 0.3
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $resBody = '';
    $httpCode = 0;

    // 1. Standard: cURL (z. B. auf Linux/Apache Webservern)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($postData)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $resBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    // 2. Fallback: stream_context / file_get_contents
    if (empty($resBody)) {
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json; charset=utf-8\r\n" .
                            "Content-Length: " . strlen($postData) . "\r\n",
                'content' => $postData,
                'timeout' => 60,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        $context = stream_context_create($opts);
        $resBody = @file_get_contents($url, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#i', $header, $matches)) {
                    $httpCode = (int)$matches[1];
                }
            }
        }
    }

    // 3. Fallback auf Windows-Systemen ohne OpenSSL/cURL Extension im PHP
    if (empty($resBody) && (DIRECTORY_SEPARATOR === '\\' || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')) {
        $tempJson = tempnam(sys_get_temp_dir(), 'greq_') . '.json';
        $tempOut = tempnam(sys_get_temp_dir(), 'gres_') . '.json';
        file_put_contents($tempJson, $postData);

        // 3a. Schnelles natives Windows curl.exe (System32)
        $curlExe = 'C:\\Windows\\System32\\curl.exe';
        if (!file_exists($curlExe)) {
            $curlExe = 'curl.exe';
        }
        $curlCmd = escapeshellarg($curlExe) . ' -s -k -X POST ' . escapeshellarg($url) .
                   ' -H "Content-Type: application/json; charset=utf-8"' .
                   ' --data-binary @' . escapeshellarg($tempJson) .
                   ' -o ' . escapeshellarg($tempOut) .
                   ' -w "%{http_code}"';
        $curlHttpCode = @exec($curlCmd, $curlOutput, $curlRet);
        if ($curlRet === 0 && file_exists($tempOut) && filesize($tempOut) > 0) {
            $resBody = @file_get_contents($tempOut);
            $httpCode = (int)$curlHttpCode ?: 200;
        }

        // 3b. Notfall-Fallback: PowerShell falls curl.exe nicht vorhanden
        if (empty($resBody)) {
            $safeJson = addslashes($tempJson);
            $safeOut = addslashes($tempOut);
            $safeUrl = addslashes($url);

            $psCmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command ' .
                     '"[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; ' .
                     '$wc = New-Object Net.WebClient; ' .
                     '$wc.Headers.Add(\'Content-Type\', \'application/json; charset=utf-8\'); ' .
                     '$wc.Encoding = [System.Text.Encoding]::UTF8; ' .
                     'try { ' .
                     '  $body = [IO.File]::ReadAllText(\'' . $safeJson . '\', [System.Text.Encoding]::UTF8); ' .
                     '  $res = $wc.UploadString(\'' . $safeUrl . '\', $body); ' .
                     '  [IO.File]::WriteAllText(\'' . $safeOut . '\', $res, [System.Text.Encoding]::UTF8); ' .
                     '  exit 0; ' .
                     '} catch [System.Net.WebException] { ' .
                     '  if ($_.Response) { ' .
                     '    $sr = New-Object IO.StreamReader($_.Response.GetResponseStream()); ' .
                     '    [IO.File]::WriteAllText(\'' . $safeOut . '\', $sr.ReadToEnd(), [System.Text.Encoding]::UTF8); ' .
                     '  } ' .
                     '  exit 1; ' .
                     '} catch { exit 2; }"';

            @exec($psCmd, $psOutput, $psRet);
            if (file_exists($tempOut) && filesize($tempOut) > 0) {
                $resBody = @file_get_contents($tempOut);
                $httpCode = ($psRet === 0) ? 200 : 400;
            }
        }

        if (file_exists($tempOut)) {
            @unlink($tempOut);
        }
        if (file_exists($tempJson)) {
            @unlink($tempJson);
        }
    }

    $resBody = preg_replace('/^\xEF\xBB\xBF/', '', trim($resBody));
    $parsed = json_decode($resBody, true);
    if ($httpCode === 200 && is_array($parsed)) {
        $answer = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!empty($answer)) {
            return [
                'ok' => true,
                'answer' => $answer,
                'model' => $model
            ];
        }
    }

    $errorMessage = 'Unbekannter API-Fehler';
    $errorStatus = '';
    if (is_array($parsed) && isset($parsed['error'])) {
        $errorMessage = $parsed['error']['message'] ?? $errorMessage;
        $errorStatus = $parsed['error']['status'] ?? '';
    }

    return [
        'ok' => false,
        'code' => $httpCode,
        'status' => $errorStatus,
        'message' => $errorMessage
    ];
}

// Routing & Aktionen
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
if (empty($action) && isset($inputData['action'])) {
    $action = trim($inputData['action']);
}

switch ($action) {
    case 'ask_gemini':
        // Ausführungszeit für KI-Generierung erhöhen (bis zu 3 Minuten)
        @set_time_limit(180);
        @ini_set('max_execution_time', '180');

        // 1. Rate-Limiting & Missbrauchsschutz (1 Anfrage/Minute, max. 5/Tag pro IP-Hash)
        $rateCheck = checkAiRateLimit(false);
        if (!$rateCheck['allowed']) {
            http_response_code(429);
            $errMsg = '';
            if ($rateCheck['dailyRemaining'] <= 0) {
                $errMsg = 'Tageslimit von 5 kostenlosen KI-Fragen erreicht. Bitte versuche es morgen wieder oder hinterlege eigene Gemini API Keys in den Einstellungen.';
            } else {
                $errMsg = 'Bitte warte noch ' . $rateCheck['cooldownRemaining'] . 's vor der nächsten Frage (1 Anfrage pro Minute erlaubt).';
            }
            echo json_encode([
                'status' => 'error',
                'message' => $errMsg,
                'rateLimit' => $rateCheck
            ]);
            exit;
        }

        $prompt = trim($inputData['prompt'] ?? ($inputData['userPrompt'] ?? ''));
        $systemInstruction = trim($inputData['systemInstruction'] ?? '');
        if (empty($prompt)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Keine Frage bzw. kein Prompt übergeben.'
            ]);
            exit;
        }

        $rawKeys = defined('GEMINI_FALLBACK_KEYS') ? GEMINI_FALLBACK_KEYS : [];
        if (is_string($rawKeys)) {
            $rawKeys = array_map('trim', explode(',', $rawKeys));
        }
        $keys = is_array($rawKeys) ? array_values(array_filter($rawKeys)) : [];

        if (empty($keys)) {
            http_response_code(503);
            echo json_encode([
                'status' => 'error',
                'message' => 'Auf dem Server sind keine Gemini Fallback-Keys konfiguriert.'
            ]);
            exit;
        }

        // Standard-Modelle für den Server-Proxy (funktionierende Flash-Modelle)
        $preferredModels = [
            'gemini-flash-lite-latest',
            'gemini-3.1-flash-lite',
            'gemini-3.5-flash-lite',
            'gemini-3.6-flash',
            'gemini-3.7-flash',
            'gemini-3-flash-preview'
        ];
        if (isset($inputData['models']) && is_array($inputData['models']) && !empty($inputData['models'])) {
            $clientModels = array_values(array_filter(array_map('trim', $inputData['models'])));
            if (!empty($clientModels)) {
                $preferredModels = array_unique(array_merge($clientModels, $preferredModels));
            }
        }

        $lastError = 'Alle Fallback-Keys und Modelle sind derzeit ausgelastet.';
        foreach ($preferredModels as $model) {
            foreach ($keys as $idx => $apiKey) {
                $result = callGeminiServerApi($apiKey, $model, $systemInstruction, $prompt);
                if ($result['ok']) {
                    $updatedRate = checkAiRateLimit(true);
                    echo json_encode([
                        'status' => 'ok',
                        'answer' => $result['answer'],
                        'modelUsed' => $result['model'],
                        'rateLimit' => $updatedRate
                    ]);
                    exit;
                }

                $lastError = $result['message'];
                // Bei 404 (Modell existiert nicht): Schleife für dieses Modell sofort abbrechen, nächstes Modell probieren
                if ($result['code'] === 404 || strpos($lastError, 'is not supported') !== false || strpos($lastError, 'is no longer available') !== false) {
                    break;
                }
            }
        }

        http_response_code(503);
        echo json_encode([
            'status' => 'error',
            'message' => 'Die KI-Anfrage konnte derzeit nicht beantwortet werden (alle Server-Kontingente erschöpft oder Modelle überlastet). Bitte versuche es später noch einmal oder hinterlege einen eigenen Key in den Einstellungen.'
        ]);
        break;

    case 'legal_info':
        echo json_encode([
            'status' => 'ok',
            'legal' => [
                'name' => LEGAL_NAME,
                'address1' => LEGAL_ADDRESS_LINE1,
                'address2' => LEGAL_ADDRESS_LINE2,
                'country' => LEGAL_COUNTRY,
                'email' => LEGAL_EMAIL,
                'hostingName' => LEGAL_HOSTING_NAME,
                'hostingAddress' => LEGAL_HOSTING_ADDRESS,
                'hostingUrl' => LEGAL_HOSTING_URL
            ]
        ]);
        break;

    case 'status':
        $articles = readDataFile();
        $fileModTime = file_exists(DATA_FILE_PATH) ? date('c', filemtime(DATA_FILE_PATH)) : null;
        $backupExists = file_exists(BACKUP_FILE_PATH);
        echo json_encode([
            'status' => 'ok',
            'articleCount' => count($articles),
            'dataFile' => basename(DATA_FILE_PATH),
            'lastModified' => $fileModTime,
            'backupExists' => $backupExists,
            'serverTime' => date('c'),
            'hasAiFallback' => (defined('GEMINI_FALLBACK_KEYS') && !empty(GEMINI_FALLBACK_KEYS)),
            'rateLimit' => checkAiRateLimit(false)
        ]);
        break;

    case 'verify_auth':
        if (empty(ADMIN_PASSWORD)) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'authenticated' => false,
                'message' => 'Auf dem Server ist noch kein Admin-Passwort in config.php hinterlegt.'
            ]);
            exit;
        }
        if (!checkAuth($inputData)) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'authenticated' => false,
                'message' => 'Admin-Passwort ist ungültig.'
            ]);
            exit;
        }
        echo json_encode([
            'status' => 'ok',
            'authenticated' => true,
            'message' => 'Autorisierung erfolgreich.'
        ]);
        break;

    case 'list':
        $filePath = DATA_FILE_PATH;
        if (file_exists($filePath)) {
            $mtime = filemtime($filePath);
            $size  = filesize($filePath);
            $etag  = sprintf('"%x-%x"', $mtime, $size);
            $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

            $ifNoneMatch     = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
            $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;

            if ($ifNoneMatch === $etag || ($ifModifiedSince && strtotime($ifModifiedSince) >= $mtime)) {
                http_response_code(304);
                header('ETag: ' . $etag);
                header('Last-Modified: ' . $lastModified);
                header('Cache-Control: no-cache');
                exit; // 0 Bytes Payload
            }

            header('ETag: ' . $etag);
            header('Last-Modified: ' . $lastModified);
            header('Cache-Control: no-cache');
        }

        $articles = readDataFile();
        echo json_encode([
            'status' => 'ok',
            'count' => count($articles),
            'articles' => $articles
        ]);
        break;

    case 'sync_all':
        if (!checkAuth($inputData)) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Nicht autorisiert. Bitte prüfe dein Admin-Passwort in den Einstellungen.'
            ]);
            exit;
        }

        $articles = [];
        if (isset($inputData['articles']) && is_array($inputData['articles'])) {
            $articles = $inputData['articles'];
        } elseif (is_array($inputData) && array_keys($inputData) === range(0, count($inputData) - 1)) {
            $articles = $inputData;
        }

        if (empty($articles) && !isset($inputData['allow_empty'])) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Keine Artikeldaten übermittelt.'
            ]);
            exit;
        }

        $success = writeDataFile($articles);
        if ($success) {
            echo json_encode([
                'status' => 'ok',
                'count' => count($articles),
                'message' => count($articles) . ' Artikel erfolgreich in ' . basename(DATA_FILE_PATH) . ' gespeichert.'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Fehler beim Schreiben der Datei ' . basename(DATA_FILE_PATH) . '. Bitte Dateiberechtigungen (Schreibrechte) auf dem Server prüfen.'
            ]);
        }
        break;

    case 'save_article':
        if (!checkAuth($inputData)) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Nicht autorisiert. Bitte prüfe dein Admin-Passwort in den Einstellungen.'
            ]);
            exit;
        }

        $article = isset($inputData['article']) ? $inputData['article'] : $inputData;
        if (!is_array($article) || empty($article['id']) || empty($article['title'])) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Ungültige Artikeldaten (ID oder Titel fehlt).'
            ]);
            exit;
        }

        $currentList = readDataFile();
        $updated = false;
        foreach ($currentList as $idx => $existing) {
            if (isset($existing['id']) && $existing['id'] === $article['id']) {
                $currentList[$idx] = $article;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            array_unshift($currentList, $article);
        }

        $success = writeDataFile($currentList);
        if ($success) {
            echo json_encode([
                'status' => 'ok',
                'action' => $updated ? 'updated' : 'inserted',
                'count' => count($currentList),
                'article' => $article,
                'message' => 'Artikel erfolgreich auf dem Server gespeichert.'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Fehler beim Speichern des Artikels auf dem Server.'
            ]);
        }
        break;

    case 'download_article_images':
        if (!checkAuth($inputData)) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Nicht autorisiert. Bitte prüfe dein Admin-Passwort in den Einstellungen.'
            ]);
            exit;
        }

        $articleId = isset($inputData['articleId']) ? trim($inputData['articleId']) : '';
        if (empty($articleId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Artikel-ID fehlt.']);
            exit;
        }

        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $articleId);
        $articleDir = __DIR__ . '/images/' . $safeId;

        $heroUrl = isset($inputData['heroImageUrl']) ? trim($inputData['heroImageUrl']) : '';
        $heroLocalPath = null;
        $savedFileNames = [];

        if (!empty($heroUrl)) {
            $savedHero = downloadRemoteImage($heroUrl, $articleDir, 'hero');
            if ($savedHero) {
                $heroLocalPath = 'images/' . $safeId . '/' . $savedHero;
                $savedFileNames[] = $savedHero;
            }
        }

        $contextList = isset($inputData['contextImages']) && is_array($inputData['contextImages']) ? $inputData['contextImages'] : [];
        $savedContextImages = [];
        $imgCounter = 1;
        foreach ($contextList as $imgItem) {
            $imgUrl = is_array($imgItem) ? (isset($imgItem['url']) ? trim($imgItem['url']) : '') : trim($imgItem);
            $alt = is_array($imgItem) && isset($imgItem['alt']) ? trim($imgItem['alt']) : '';
            $caption = is_array($imgItem) && isset($imgItem['caption']) ? trim($imgItem['caption']) : '';

            if (!empty($imgUrl)) {
                $savedName = downloadRemoteImage($imgUrl, $articleDir, 'image-' . $imgCounter);
                if ($savedName) {
                    $savedContextImages[] = [
                        'url' => 'images/' . $safeId . '/' . $savedName,
                        'alt' => $alt,
                        'caption' => $caption
                    ];
                    $savedFileNames[] = $savedName;
                    $imgCounter++;
                }
            }
        }

        // Bereinige alte, nicht mehr genutzte oder verworfene Bilddateien in diesem Artikel-Ordner
        if (is_dir($articleDir)) {
            $existingFiles = @glob($articleDir . '/*');
            if ($existingFiles && is_array($existingFiles)) {
                foreach ($existingFiles as $ef) {
                    if (is_file($ef)) {
                        $basename = basename($ef);
                        if (!in_array($basename, $savedFileNames)) {
                            @unlink($ef);
                        }
                    }
                }
            }
            $remaining = @glob($articleDir . '/*');
            if (empty($remaining)) {
                @rmdir($articleDir);
            }
        }

        // Optional: Aktualisiere diamandis-data.js direkt auf dem Server
        $updateData = isset($inputData['updateDataFile']) ? (bool)$inputData['updateDataFile'] : true;
        if ($updateData) {
            $currentList = readDataFile();
            foreach ($currentList as $idx => $existing) {
                if (isset($existing['id']) && $existing['id'] === $articleId) {
                    $currentList[$idx]['heroImage'] = $heroLocalPath ? $heroLocalPath : null;
                    $currentList[$idx]['images'] = $savedContextImages;
                    $currentList[$idx]['imagesChecked'] = true;
                    break;
                }
            }
            writeDataFile($currentList);
        }

        echo json_encode([
            'status' => 'ok',
            'articleId' => $articleId,
            'heroImage' => $heroLocalPath,
            'images' => $savedContextImages,
            'imagesChecked' => true,
            'message' => 'Bilder erfolgreich auf den Server heruntergeladen und bereinigt.'
        ]);
        break;

    case 'clean_all_images':
        if (!checkAuth($inputData)) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Nicht autorisiert.']);
            exit;
        }

        $currentList = readDataFile();
        $cleanedArticlesCount = 0;
        $deletedFilesCount = 0;
        $knownPromoPatterns = [
            '9200e33c-6732-48bd-9ccd-f2c32c3c198a',
            '13c1862b-8a12-4f62-abe4-fe2db11c2190',
            '720c2af3-3d1b-4d3b-8802-f876a8f4ba43',
            'weareasgods',
            'we-are-as-gods',
            'abundance360',
            'moonshots'
        ];

        foreach ($currentList as $idx => $art) {
            $changed = false;
            $hero = isset($art['heroImage']) ? $art['heroImage'] : '';
            if ($hero) {
                $filePath = __DIR__ . '/' . ltrim($hero, '/\\');
                $shouldDelete = false;
                if (file_exists($filePath)) {
                    $sz = @getimagesize($filePath);
                    if ($sz && ($sz[0] < 300 || $sz[1] < 300)) {
                        $shouldDelete = true;
                    }
                    foreach ($knownPromoPatterns as $pat) {
                        if (stripos($hero, $pat) !== false) $shouldDelete = true;
                    }
                    if ($shouldDelete) {
                        @unlink($filePath);
                        $deletedFilesCount++;
                    }
                }
                if ($shouldDelete || !file_exists($filePath)) {
                    $currentList[$idx]['heroImage'] = null;
                    $changed = true;
                }
            }

            $imgs = isset($art['images']) && is_array($art['images']) ? $art['images'] : [];
            $newImgs = [];
            foreach ($imgs as $cimg) {
                $url = is_array($cimg) ? ($cimg['url'] ?? '') : $cimg;
                $alt = is_array($cimg) ? ($cimg['alt'] ?? '') : '';
                $filePath = __DIR__ . '/' . ltrim($url, '/\\');
                $shouldDelete = false;

                if (file_exists($filePath)) {
                    $sz = @getimagesize($filePath);
                    if ($sz && ($sz[0] < 300 || $sz[1] < 300)) {
                        $shouldDelete = true;
                    }
                    foreach ($knownPromoPatterns as $pat) {
                        if (stripos($url, $pat) !== false || stripos($alt, $pat) !== false) $shouldDelete = true;
                    }
                    if ($shouldDelete) {
                        @unlink($filePath);
                        $deletedFilesCount++;
                    }
                }
                if (!$shouldDelete && file_exists($filePath)) {
                    $newImgs[] = $cimg;
                } else {
                    $changed = true;
                }
            }

            if ($changed) {
                $currentList[$idx]['images'] = $newImgs;
                $cleanedArticlesCount++;
            }
        }

        writeDataFile($currentList);

        echo json_encode([
            'status' => 'ok',
            'cleanedArticles' => $cleanedArticlesCount,
            'deletedFiles' => $deletedFilesCount,
            'message' => "Bereinigung abgeschlossen: {$cleanedArticlesCount} Artikel bereinigt, {$deletedFilesCount} Bilddateien vom Server gelöscht."
        ]);
        break;

    case 'delete_article':
        if (!checkAuth($inputData)) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Nicht autorisiert.'
            ]);
            exit;
        }

        $id = isset($inputData['id']) ? trim($inputData['id']) : (isset($_GET['id']) ? trim($_GET['id']) : '');
        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Artikel-ID fehlt.']);
            exit;
        }

        $currentList = readDataFile();
        $initialCount = count($currentList);
        $currentList = array_values(array_filter($currentList, function($item) use ($id) {
            return !isset($item['id']) || $item['id'] !== $id;
        }));

        $success = writeDataFile($currentList);
        echo json_encode([
            'status' => 'ok',
            'deleted' => ($initialCount > count($currentList)),
            'count' => count($currentList)
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Unbekannte Aktion. Erlaubt: status, verify_auth, list, sync_all, save_article, download_article_images, clean_all_images, delete_article, legal_info, ask_gemini.'
        ]);
        break;
}
