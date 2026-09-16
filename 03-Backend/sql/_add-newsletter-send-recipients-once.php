<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt eine Tabelle an, die pro tatsächlichem
// Newsletter-Versand die exakt verwendeten Empfänger-Adressen speichert
// (bisher stand in newsletter_sends nur die Anzahl + der Name der Zielgruppe,
// nicht die einzelnen Adressen - z.B. um im Nachhinein prüfen zu können, ob
// eine bestimmte Adresse tatsächlich angeschrieben wurde). Wird ab jetzt bei
// jedem Versand mit befüllt; ältere Sends bleiben ohne Eintrag hier (Admin
// zeigt dafür einen Best-Effort-Fallback über die aktuelle Listenmitgliedschaft).
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-sendrecipients-2026-temp';
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
        "CREATE TABLE newsletter_send_recipients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            newsletter_send_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            FOREIGN KEY (newsletter_send_id) REFERENCES newsletter_sends(id) ON DELETE CASCADE,
            INDEX idx_send (newsletter_send_id)
        )"
    );
    echo "OK: newsletter_send_recipients angelegt.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
