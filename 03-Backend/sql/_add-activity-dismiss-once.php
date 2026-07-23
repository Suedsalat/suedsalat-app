<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt dismissed_from_activity_at in events,
// photos, movie_tips und location_tips, damit selbst angelegte Eintraege aus
// der Aktivitaeten-Liste entfernt werden koennen, OHNE den eigentlichen
// Termin/Foto/Filmtipp/Locationtipp zu loeschen.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-activitydismiss-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $pdo->exec("ALTER TABLE events ADD COLUMN dismissed_from_activity_at DATETIME NULL");
    $pdo->exec("ALTER TABLE photos ADD COLUMN dismissed_from_activity_at DATETIME NULL");
    $pdo->exec("ALTER TABLE movie_tips ADD COLUMN dismissed_from_activity_at DATETIME NULL");
    $pdo->exec("ALTER TABLE location_tips ADD COLUMN dismissed_from_activity_at DATETIME NULL");

    echo "OK: dismissed_from_activity_at ergaenzt (events, photos, movie_tips, location_tips).\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
