<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: erweitert feedback_media um imported_at,
// damit jedes Foto einer Mehrfach-Einreichung einzeln in die Galerie
// uebernommen werden kann (nicht nur das erste).
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-feedbackmediaimported-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    $pdo->exec("ALTER TABLE feedback_media ADD COLUMN imported_at DATETIME NULL AFTER image_path");
    echo "OK: feedback_media.imported_at ergaenzt.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
