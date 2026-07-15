<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt 'kino_tipp' im type-Enum sowie
// movietip_created_at in feedback_messages.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-feedbackkino-2026-temp';
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
         MODIFY type ENUM('allgemein','termin_tipp','foto_vorschlag','kino_tipp') NOT NULL DEFAULT 'allgemein'"
    );
    echo "OK: type-Enum um kino_tipp erweitert.\n";

    $column = $pdo->query("SHOW COLUMNS FROM feedback_messages LIKE 'movietip_created_at'")->fetch();
    if ($column) {
        echo "OK: Spalte movietip_created_at existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE feedback_messages ADD COLUMN movietip_created_at DATETIME NULL AFTER event_created_at");
        echo "OK: Spalte movietip_created_at hinzugefuegt.\n";
    }

    $columns = $pdo->query('SHOW COLUMNS FROM feedback_messages')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten jetzt: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
