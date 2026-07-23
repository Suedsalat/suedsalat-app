<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt photo_align in newsletter_sends (links/
// zentriert/rechtsbuendig), damit die gewaehlte Ausrichtung mitprotokolliert wird
// (fuer eine originalgetreue "Ansehen"-Vorschau alter Newsletter).
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-newsletterphotoalign-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    $pdo->exec("ALTER TABLE newsletter_sends ADD COLUMN photo_align VARCHAR(10) NULL AFTER photo_width");
    echo "OK: photo_align ergaenzt (newsletter_sends).\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
