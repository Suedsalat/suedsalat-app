<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: erfasst anonym, ob eine Wiedergabe ueber
// Android Auto oder CarPlay lief (kein Geraete-/Fahrzeug-Identifier, nur ein
// taeglicher Zaehler pro Folge und Kontext) - Grundlage fuer die neue
// "Android Auto / CarPlay"-Auswertung in admin/statistics.php.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-carcontext-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $guidCollation = $pdo->query(
        "SELECT COLLATION_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'episodes_cache' AND COLUMN_NAME = 'guid'"
    )->fetchColumn();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS episode_play_car_context (
            episode_guid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE $guidCollation NOT NULL,
            day DATE NOT NULL,
            context ENUM('android_auto','carplay') NOT NULL,
            count INT NOT NULL DEFAULT 0,
            PRIMARY KEY (episode_guid, day, context),
            CONSTRAINT fk_episode_play_car_context_guid FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE CASCADE
        )"
    );
    echo "OK: episode_play_car_context angelegt (oder bereits vorhanden).\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
