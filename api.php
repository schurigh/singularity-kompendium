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

// CORS & JSON Header
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Admin-Password, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

// Routing & Aktionen
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
if (empty($action) && isset($inputData['action'])) {
    $action = trim($inputData['action']);
}

switch ($action) {
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
            'serverTime' => date('c')
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
            'message' => 'Unbekannte Aktion. Erlaubt: status, verify_auth, list, sync_all, save_article, download_article_images, clean_all_images, delete_article.'
        ]);
        break;
}
