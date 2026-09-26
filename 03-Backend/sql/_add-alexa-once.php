<?php
declare(strict_types=1);

// Vorab-Migration fuer den Alexa-Skill (alexa/index.php), falls er vor dem 2.0-Release live geht:
// nur die Tabelle alexa_positions. Die 2.0-Migration legt sie ebenfalls an und ueberspringt sie dann.
// Beliebig oft ausfuehrbar. Nach Gebrauch UNBEDINGT vom Server loeschen.

$secret = 'suedsalat-alexa-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = \Suedsalat\Database::connection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS alexa_positions (
        user_hash CHAR(64) PRIMARY KEY,
        episode_number INT NOT NULL,
        offset_ms INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: alexa_positions vorhanden.\nFertig.\n";
} catch (\Throwable $e) {
    echo 'FEHLER: ' . $e->getMessage() . "\n";
}
