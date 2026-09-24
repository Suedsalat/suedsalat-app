<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt locationtip_created_at in
// feedback_messages - das Gegenstueck zu event_created_at/movietip_created_at.
// Ohne diese Spalte laesst sich nicht merken, dass aus einem eingereichten
// Locationtipp bereits ein echter Locationtipp angelegt wurde.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-feedbacklocation-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $column = $pdo->query("SHOW COLUMNS FROM feedback_messages LIKE 'locationtip_created_at'")->fetch();
    if ($column) {
        echo "OK: Spalte locationtip_created_at existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE feedback_messages ADD COLUMN locationtip_created_at DATETIME NULL AFTER movietip_created_at");
        echo "OK: Spalte locationtip_created_at hinzugefuegt.\n";
    }

    $columns = $pdo->query('SHOW COLUMNS FROM feedback_messages')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten jetzt: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
