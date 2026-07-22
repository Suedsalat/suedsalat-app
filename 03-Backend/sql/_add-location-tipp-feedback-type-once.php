<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: erweitert feedback_messages.type um 'location_tipp'.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-locationtippfeedback-2026-temp';
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
        "ALTER TABLE feedback_messages
         MODIFY COLUMN type ENUM('allgemein','termin_tipp','foto_vorschlag','kino_tipp','sprachnachricht','frage','location_tipp')
         NOT NULL DEFAULT 'allgemein'"
    );
    echo "OK: feedback_messages.type um 'location_tipp' erweitert.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
