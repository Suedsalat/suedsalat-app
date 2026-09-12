<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt eigene, im Admin-Bereich pflegbare
// Newsletter-Empfaengerlisten an (zusaetzlich zur bestehenden oeffentlichen
// Double-Opt-In-Liste in newsletter/emails.txt, die weiterhin die Standard-
// Zielgruppe "Alle Abonnenten" bleibt). Gedacht z.B. fuer eine separate Liste
// der Google-Play-Testergruppe, die man gezielt anschreiben kann, ohne den
// eigentlichen Newsletter-Verteiler zu vermischen.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-newsletterlists-2026-temp';
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
        "CREATE TABLE newsletter_lists (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    echo "OK: newsletter_lists angelegt.\n";

    $pdo->exec(
        "CREATE TABLE newsletter_list_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            list_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            FOREIGN KEY (list_id) REFERENCES newsletter_lists(id) ON DELETE CASCADE
        )"
    );
    echo "OK: newsletter_list_members angelegt.\n";

    $pdo->exec("ALTER TABLE newsletter_sends ADD COLUMN recipient_list_name VARCHAR(150) NULL AFTER recipient_count");
    echo "OK: recipient_list_name in newsletter_sends ergaenzt.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
