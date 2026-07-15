<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt created_via_feedback_id in events + photos,
// damit erkennbar ist, ob ein Eintrag aus einem Nutzer-Feedback uebernommen wurde
// oder direkt von einem Admin angelegt wurde.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-sourcetrack-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    foreach (['events', 'photos'] as $table) {
        $column = $pdo->query("SHOW COLUMNS FROM $table LIKE 'created_via_feedback_id'")->fetch();
        if ($column) {
            echo "OK: $table.created_via_feedback_id existiert bereits.\n";
        } else {
            $pdo->exec("ALTER TABLE $table ADD COLUMN created_via_feedback_id INT NULL AFTER created_by");
            echo "OK: $table.created_via_feedback_id hinzugefuegt.\n";
        }
    }
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
