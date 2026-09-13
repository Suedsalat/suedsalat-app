<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt episode_guid in feedback_messages, damit
// Nutzer ihr Feedback optional einer Folge zuordnen koennen - Grundlage fuer die
// "Meistgehörte Folgen & ausgelöste Inhalte"-Auswertung in admin/statistics.php
// (bisher nur ueber Filmtipps/Locationtipps/Veranstaltungen mit Folgenbezug
// angenaehert, jetzt auch echtes Feedback).
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-feedbackepisodeguid-2026-temp';
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
        "ALTER TABLE feedback_messages
         ADD COLUMN episode_guid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE $guidCollation NULL AFTER type,
         ADD CONSTRAINT fk_feedback_messages_episode_guid FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE SET NULL"
    );
    echo "OK: episode_guid in feedback_messages ergaenzt.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
