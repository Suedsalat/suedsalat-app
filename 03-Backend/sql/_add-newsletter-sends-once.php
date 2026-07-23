<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt die Tabelle newsletter_sends an, damit jeder
// Versand protokolliert wird (fuer eine Liste bisheriger Newsletter + die
// Moeglichkeit, einen alten Newsletter als Vorlage fuer einen neuen zu uebernehmen).
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-newslettersends-2026-temp';
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
        "CREATE TABLE IF NOT EXISTS newsletter_sends (
            id INT PRIMARY KEY AUTO_INCREMENT,
            subject VARCHAR(255) NOT NULL,
            headline VARCHAR(255) NULL,
            episode_link VARCHAR(500) NULL,
            body_text TEXT NOT NULL,
            photo_url VARCHAR(500) NULL,
            recipient_count INT NOT NULL DEFAULT 0,
            sent_by INT NOT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sent_by) REFERENCES admins(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    echo "OK: newsletter_sends angelegt.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
