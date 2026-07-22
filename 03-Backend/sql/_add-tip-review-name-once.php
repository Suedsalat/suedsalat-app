<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: erweitert tip_reviews um reviewer_name.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-reviewname-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    $pdo->exec("ALTER TABLE tip_reviews ADD COLUMN reviewer_name VARCHAR(100) NULL AFTER review_text");
    echo "OK: tip_reviews.reviewer_name ergaenzt.\n";

    $columns = $pdo->query('SHOW COLUMNS FROM tip_reviews')->fetchAll(PDO::FETCH_COLUMN);
    echo "Spalten: " . implode(', ', $columns) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
