<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: erweitert feedback_messages.type um 'frage'
// und legt die Tabelle feedback_media fuer Mehrfach-Foto-Feedback an.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-feedbackmedia-2026-temp';
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
         MODIFY COLUMN type ENUM('allgemein','termin_tipp','foto_vorschlag','kino_tipp','sprachnachricht','frage')
         NOT NULL DEFAULT 'allgemein'"
    );
    echo "OK: feedback_messages.type um 'frage' erweitert.\n";

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS feedback_media (
          id INT PRIMARY KEY AUTO_INCREMENT,
          feedback_message_id INT NOT NULL,
          image_path VARCHAR(500) NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (feedback_message_id) REFERENCES feedback_messages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "OK: Tabelle feedback_media existiert.\n";

    $columns = $pdo->query('SHOW COLUMNS FROM feedback_media')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten feedback_media: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
