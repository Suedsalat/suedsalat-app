<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt episode_play_counts an, um anonym (ohne
// Personenbezug) zu zaehlen, wie oft welche Folge abgespielt wurde - analog zu
// screen_views (siehe api/track-view.php), nur pro Folge statt pro Bereich.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-episodeplaycounts-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    // episodes_cache.guid ist utf8mb4_0900_ai_ci (per Diagnose bestaetigt) - muss hier
    // exakt uebereinstimmen, sonst lehnt MySQL den Fremdschluessel als "incompatible" ab.
    $pdo->exec(
        "CREATE TABLE episode_play_counts (
            episode_guid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
            day DATE NOT NULL,
            count INT NOT NULL DEFAULT 0,
            PRIMARY KEY (episode_guid, day),
            CONSTRAINT fk_episode_play_counts_guid FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE CASCADE
        )"
    );
    echo "OK: episode_play_counts angelegt.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
