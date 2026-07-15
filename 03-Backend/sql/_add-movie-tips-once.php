<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt die Tabelle movie_tips an.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-movietips-2026-temp';
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
        "CREATE TABLE IF NOT EXISTS movie_tips (
          id INT PRIMARY KEY AUTO_INCREMENT,
          title VARCHAR(255) NOT NULL,
          description TEXT NULL,
          link VARCHAR(500) NULL,
          episode_guid VARCHAR(255) NULL,
          episode_timestamp_seconds INT NULL,
          image_path VARCHAR(500) NULL,
          created_by INT NOT NULL,
          created_via_feedback_id INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NULL,
          FOREIGN KEY (created_by) REFERENCES admins(id),
          CONSTRAINT movie_tips_episode_guid_fk FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "OK: Tabelle movie_tips existiert.\n";

    $columns = $pdo->query('SHOW COLUMNS FROM movie_tips')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
