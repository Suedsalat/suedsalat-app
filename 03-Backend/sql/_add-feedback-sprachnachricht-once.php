<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt 'sprachnachricht' im type-Enum,
// 'audio' im media_type-Enum sowie die Spalte consent_publish in feedback_messages.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-feedbacksprach-2026-temp';
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
         MODIFY type ENUM('allgemein','termin_tipp','foto_vorschlag','kino_tipp','sprachnachricht') NOT NULL DEFAULT 'allgemein'"
    );
    echo "OK: type-Enum um sprachnachricht erweitert.\n";

    $pdo->exec(
        "ALTER TABLE feedback_messages
         MODIFY media_type ENUM('image','video','audio') NOT NULL DEFAULT 'image'"
    );
    echo "OK: media_type-Enum um audio erweitert.\n";

    $column = $pdo->query("SHOW COLUMNS FROM feedback_messages LIKE 'consent_publish'")->fetch();
    if ($column) {
        echo "OK: Spalte consent_publish existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE feedback_messages ADD COLUMN consent_publish TINYINT(1) NOT NULL DEFAULT 0 AFTER media_type");
        echo "OK: Spalte consent_publish hinzugefuegt.\n";
    }

    $columns = $pdo->query('SHOW COLUMNS FROM feedback_messages')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten jetzt: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
