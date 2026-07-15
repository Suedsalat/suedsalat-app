<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt die Tabelle screen_views an
// (anonyme Aufruf-Zaehler pro App-Bereich, ohne Personenbezug).
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-screenviews-2026-temp';
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
        "CREATE TABLE IF NOT EXISTS screen_views (
          id INT PRIMARY KEY AUTO_INCREMENT,
          screen VARCHAR(20) NOT NULL,
          day DATE NOT NULL,
          count INT NOT NULL DEFAULT 0,
          UNIQUE KEY screen_day (screen, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "OK: Tabelle screen_views existiert.\n";

    $columns = $pdo->query('SHOW COLUMNS FROM screen_views')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
