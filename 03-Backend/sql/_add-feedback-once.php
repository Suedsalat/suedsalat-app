<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt die feedback_messages-Tabelle live an.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-feedback-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

$sql = "CREATE TABLE IF NOT EXISTS feedback_messages (
  id INT PRIMARY KEY AUTO_INCREMENT,
  sender_name VARCHAR(100) NULL,
  type ENUM('allgemein','termin_tipp','foto_vorschlag') NOT NULL DEFAULT 'allgemein',
  message TEXT NOT NULL,
  status ENUM('offen','erledigt') NOT NULL DEFAULT 'offen',
  handled_by INT NULL,
  handled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (handled_by) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

try {
    $pdo = Database::connection();
    $pdo->exec($sql);
    echo "OK: feedback_messages Tabelle angelegt/vorhanden.\n";
    echo "Vorhandene Tabellen: " . implode(', ', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
