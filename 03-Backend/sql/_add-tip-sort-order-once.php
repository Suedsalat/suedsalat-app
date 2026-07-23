<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt sort_order in movie_tips und location_tips,
// damit Filmtipps/Locationtipps im Adminbereich manuell sortiert werden koennen.
// Initialisiert die Reihenfolge so, dass sie der bisherigen Anzeige (neueste zuerst)
// entspricht - nichts aendert sich sichtbar, bis der Admin aktiv umsortiert.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-tipsortorder-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $pdo->exec("ALTER TABLE movie_tips ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER created_via_feedback_id");
    $pdo->exec("ALTER TABLE location_tips ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER created_via_feedback_id");

    $pdo->exec("SET @rownum := 0");
    $pdo->exec("UPDATE movie_tips SET sort_order = (@rownum := @rownum + 1) ORDER BY created_at DESC");

    $pdo->exec("SET @rownum := 0");
    $pdo->exec("UPDATE location_tips SET sort_order = (@rownum := @rownum + 1) ORDER BY created_at DESC");

    echo "OK: sort_order ergaenzt und initialisiert (movie_tips, location_tips).\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
