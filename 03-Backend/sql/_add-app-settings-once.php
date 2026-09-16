<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt eine kleine Key-Value-Tabelle fuer
// admin-weite Einstellungen an (siehe get_app_setting()/set_app_setting() in
// config/config.php). Erster Anwendungsfall: "stats_baseline_date" - ein
// Datum, ab dem die Wiedergabe-/Nutzungszaehler in admin/dashboard.php und
// admin/statistics.php standardmaessig zaehlen, damit sich die eigene Nutzung
// waehrend der Google-Play-Testphase aus den "echten" Hoererzahlen rausrechnen
// laesst, ohne die zugrundeliegenden Rohdaten zu loeschen.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-appsettings-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    $pdo->exec(
        "CREATE TABLE app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );
    echo "OK: app_settings angelegt.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
