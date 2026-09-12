<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt from_email in newsletter_sends, damit die
// im Formular gewaehlte Absenderadresse (z.B. newsletter@ vs. testphase@) mitprotokolliert
// wird - fuer eine originalgetreue "Ansehen"-Vorschau und die Newsletter-Historie.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-newsletterfromemail-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    $pdo->exec("ALTER TABLE newsletter_sends ADD COLUMN from_email VARCHAR(255) NULL AFTER recipient_list_name");
    echo "OK: from_email in newsletter_sends ergaenzt.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
