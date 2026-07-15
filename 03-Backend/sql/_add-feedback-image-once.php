<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt image_path in feedback_messages.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-feedback-img-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $column = $pdo->query("SHOW COLUMNS FROM feedback_messages LIKE 'image_path'")->fetch();
    if ($column) {
        echo "OK: Spalte image_path existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE feedback_messages ADD COLUMN image_path VARCHAR(500) NULL AFTER message");
        echo "OK: Spalte image_path hinzugefuegt.\n";
    }

    $columns = $pdo->query('SHOW COLUMNS FROM feedback_messages')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten jetzt: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
