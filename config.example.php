<?php
// =========================================================================
// Peter H. Diamandis Kompendium - Beispiel-Konfiguration (Vorlage)
// Kopiere diese Datei nach 'config.php' und passe deine Werte an.
// =========================================================================

// 1. Admin-Passwort für Schreibzugriffe (Live-Sync, Scanner & AI-Import)
define('ADMIN_PASSWORD', 'DEIN_SICHERES_PASSWORT_HIER');

// 2. Pfade zu den Datendateien
define('DATA_FILE_PATH', __DIR__ . '/diamandis-data.js');
define('BACKUP_FILE_PATH', __DIR__ . '/diamandis-data.backup.js');

// 3. Persönliche Daten für Impressum & Datenschutzerklärung (§ 5 TMG / DSGVO)
define('LEGAL_NAME', 'Dein Name / Organisation');
define('LEGAL_ADDRESS_LINE1', 'Musterstraße 123');
define('LEGAL_ADDRESS_LINE2', '12345 Musterstadt');
define('LEGAL_COUNTRY', 'Deutschland');
define('LEGAL_EMAIL', 'kontakt@deine-domain.de');

// 4. Hosting-Informationen
define('LEGAL_HOSTING_NAME', 'Hosting-Anbieter Name (z. B. All-Inkl.com)');
define('LEGAL_HOSTING_ADDRESS', 'Musterstraße 1, 12345 Musterstadt, Deutschland');
define('LEGAL_HOSTING_URL', 'https://dein-hoster.de');
