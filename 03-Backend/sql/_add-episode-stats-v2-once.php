<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: erweitert die Folgen-Statistik (bisher nur
// Start-Zaehler pro Tag in episode_play_counts) um Hoerdauer-Stufen,
// Tageszeit, Plattform-Aufteilung, Reichweite (eindeutige Geraete) und eine
// Protokollierung, wann eine "Neue Folge"-Push-Benachrichtigung verschickt
// wurde (fuer die Push-Wirksamkeits-Auswertung). Alles bleibt anonym/aggregiert -
// es wird nirgends ein Geraete-Token/UUID direkt gespeichert, nur taegliche
// Zaehler bzw. Einweg-Hashes, die sich nicht ueber Folgen/Tage hinweg
// zusammenfuehren lassen.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-episodestatsv2-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

$pdo = Database::connection();

$guidCollation = $pdo->query(
    "SELECT COLLATION_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'episodes_cache' AND COLUMN_NAME = 'guid'"
)->fetchColumn();

function guidColumn(string $collation): string
{
    return "VARCHAR(255) CHARACTER SET utf8mb4 COLLATE $collation NOT NULL";
}

try {
    // 1. Tageszeit fuer die bestehenden Start-Zaehler ergaenzen. Bestehende Zeilen
    // (vor dieser Migration) bekommen -1 ("unbekannt"), da fuer sie keine Uhrzeit
    // erfasst wurde - kein Datenverlust, nur fehlende Feingranularitaet rueckwirkend.
    $hasHourColumn = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'episode_play_counts' AND COLUMN_NAME = 'hour'"
    )->fetchColumn();
    if ($hasHourColumn === 0) {
        $pdo->exec("ALTER TABLE episode_play_counts ADD COLUMN hour TINYINT NOT NULL DEFAULT -1 AFTER day");
        $pdo->exec("ALTER TABLE episode_play_counts DROP PRIMARY KEY, ADD PRIMARY KEY (episode_guid, day, hour)");
        echo "OK: episode_play_counts um Spalte 'hour' erweitert.\n";
    } else {
        echo "OK: episode_play_counts hatte 'hour' bereits.\n";
    }

    // 2. Hoerdauer-Stufen ("Trichter"): 5/15/25/35/45 Minuten + "bis zum Ende".
    $guidCol = guidColumn($guidCollation);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS episode_play_milestones (
            episode_guid $guidCol,
            day DATE NOT NULL,
            tier VARCHAR(10) NOT NULL,
            count INT NOT NULL DEFAULT 0,
            PRIMARY KEY (episode_guid, day, tier),
            CONSTRAINT fk_episode_play_milestones_guid FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE CASCADE
        )"
    );
    echo "OK: episode_play_milestones angelegt (oder bereits vorhanden).\n";

    // 3. Reichweite: eindeutige Geraete pro Folge/Tag. device_hash wird aus
    // Geraete-ID + Folge + Tag gebildet - dieselbe Geraete-ID ergibt an einem
    // anderen Tag oder bei einer anderen Folge einen komplett anderen Hash,
    // laesst sich also NICHT folgen-/tagesuebergreifend zusammenfuehren.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS episode_unique_devices (
            episode_guid $guidCol,
            day DATE NOT NULL,
            device_hash CHAR(64) NOT NULL,
            PRIMARY KEY (episode_guid, day, device_hash),
            CONSTRAINT fk_episode_unique_devices_guid FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE CASCADE
        )"
    );
    echo "OK: episode_unique_devices angelegt (oder bereits vorhanden).\n";

    // 4. Plattform-Aufteilung (iOS/Android) pro Folge/Tag.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS episode_play_platform (
            episode_guid $guidCol,
            day DATE NOT NULL,
            platform ENUM('ios','android') NOT NULL,
            count INT NOT NULL DEFAULT 0,
            PRIMARY KEY (episode_guid, day, platform),
            CONSTRAINT fk_episode_play_platform_guid FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE CASCADE
        )"
    );
    echo "OK: episode_play_platform angelegt (oder bereits vorhanden).\n";

    // 5. Wann wurde fuer eine Folge die "Neue Folge"-Push-Benachrichtigung verschickt -
    // fuer die Push-Wirksamkeits-Auswertung (Wiedergaben kurz danach vs. spaeter).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS episode_push_sent (
            episode_guid $guidCol,
            sent_at DATETIME NOT NULL,
            PRIMARY KEY (episode_guid),
            CONSTRAINT fk_episode_push_sent_guid FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE CASCADE
        )"
    );
    echo "OK: episode_push_sent angelegt (oder bereits vorhanden).\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
