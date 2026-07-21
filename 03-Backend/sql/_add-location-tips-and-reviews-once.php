<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt die Tabellen location_tips und tip_reviews an.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-locationtips-2026-temp';
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
        "CREATE TABLE IF NOT EXISTS location_tips (
          id INT PRIMARY KEY AUTO_INCREMENT,
          name VARCHAR(255) NOT NULL,
          location VARCHAR(255) NOT NULL,
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
          CONSTRAINT location_tips_episode_guid_fk FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "OK: Tabelle location_tips existiert.\n";

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tip_reviews (
          id INT PRIMARY KEY AUTO_INCREMENT,
          tip_type ENUM('movie_tip','event','location_tip') NOT NULL,
          tip_id INT NOT NULL,
          rating TINYINT UNSIGNED NOT NULL,
          review_text TEXT NULL,
          device_id INT NULL,
          approved TINYINT(1) NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          approved_at DATETIME NULL,
          approved_by INT NULL,
          FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
          FOREIGN KEY (approved_by) REFERENCES admins(id),
          INDEX tip_reviews_lookup_idx (tip_type, tip_id, approved)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "OK: Tabelle tip_reviews existiert.\n";

    $columns1 = $pdo->query('SHOW COLUMNS FROM location_tips')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten location_tips: " . implode(', ', $columns1) . "\n";
    $columns2 = $pdo->query('SHOW COLUMNS FROM tip_reviews')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten tip_reviews: " . implode(', ', $columns2) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
