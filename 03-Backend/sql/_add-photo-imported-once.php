<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt photo_imported_at in feedback_messages.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-photoimp-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $column = $pdo->query("SHOW COLUMNS FROM feedback_messages LIKE 'photo_imported_at'")->fetch();
    if ($column) {
        echo "OK: Spalte photo_imported_at existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE feedback_messages ADD COLUMN photo_imported_at DATETIME NULL AFTER image_path");
        echo "OK: Spalte photo_imported_at hinzugefuegt.\n";
    }

    $columns = $pdo->query('SHOW COLUMNS FROM feedback_messages')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten jetzt: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
