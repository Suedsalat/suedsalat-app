<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt episode_guid + episode_timestamp_seconds in events.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-episodelink-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $column = $pdo->query("SHOW COLUMNS FROM events LIKE 'episode_guid'")->fetch();
    if ($column) {
        echo "OK: Spalte episode_guid existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE events ADD COLUMN episode_guid VARCHAR(255) NULL AFTER link");
        echo "OK: Spalte episode_guid hinzugefuegt.\n";
    }

    $column = $pdo->query("SHOW COLUMNS FROM events LIKE 'episode_timestamp_seconds'")->fetch();
    if ($column) {
        echo "OK: Spalte episode_timestamp_seconds existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE events ADD COLUMN episode_timestamp_seconds INT NULL AFTER episode_guid");
        echo "OK: Spalte episode_timestamp_seconds hinzugefuegt.\n";
    }

    $fkExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND CONSTRAINT_NAME = 'events_episode_guid_fk'"
    )->fetchColumn();
    if ($fkExists) {
        echo "OK: Foreign Key events_episode_guid_fk existiert bereits.\n";
    } else {
        $pdo->exec(
            "ALTER TABLE events ADD CONSTRAINT events_episode_guid_fk
             FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE SET NULL"
        );
        echo "OK: Foreign Key events_episode_guid_fk hinzugefuegt.\n";
    }

    $columns = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten jetzt: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
