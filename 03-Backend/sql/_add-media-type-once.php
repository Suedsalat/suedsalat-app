<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt media_type in photos und feedback_messages,
// zur Unterscheidung Foto/Video.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-mediatype-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $column = $pdo->query("SHOW COLUMNS FROM photos LIKE 'media_type'")->fetch();
    if ($column) {
        echo "OK: Spalte photos.media_type existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE photos ADD COLUMN media_type ENUM('photo','video') NOT NULL DEFAULT 'photo' AFTER image_path");
        echo "OK: Spalte photos.media_type hinzugefuegt.\n";
    }

    $column = $pdo->query("SHOW COLUMNS FROM feedback_messages LIKE 'media_type'")->fetch();
    if ($column) {
        echo "OK: Spalte feedback_messages.media_type existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE feedback_messages ADD COLUMN media_type ENUM('image','video') NOT NULL DEFAULT 'image' AFTER image_path");
        echo "OK: Spalte feedback_messages.media_type hinzugefuegt.\n";
    }

    echo "photos-Spalten: " . implode(', ', $pdo->query('SHOW COLUMNS FROM photos')->fetchAll(PDO::FETCH_COLUMN)) . "\n";
    echo "feedback_messages-Spalten: " . implode(', ', $pdo->query('SHOW COLUMNS FROM feedback_messages')->fetchAll(PDO::FETCH_COLUMN)) . "\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
