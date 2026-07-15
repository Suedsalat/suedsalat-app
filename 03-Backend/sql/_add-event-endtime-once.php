<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt event_end_time in events.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-eventendtime-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $column = $pdo->query("SHOW COLUMNS FROM events LIKE 'event_end_time'")->fetch();
    if ($column) {
        echo "OK: Spalte event_end_time existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE events ADD COLUMN event_end_time TIME NULL AFTER event_time");
        echo "OK: Spalte event_end_time hinzugefuegt.\n";
    }

    $columns = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten jetzt: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
