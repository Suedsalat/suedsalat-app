<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt newsletter_send_photos an, damit ein Newsletter
// mehrere Fotos (statt nur eines) enthalten kann, jedes mit eigener Breite/Ausrichtung.
// Die alten Einzel-Foto-Spalten in newsletter_sends (photo_url/photo_width/photo_align)
// bleiben unangetastet, damit bereits verschickte alte Newsletter weiter korrekt in
// der "Ansehen"-Vorschau angezeigt werden koennen.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-newsletterphotos-2026-temp';
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
        "CREATE TABLE newsletter_send_photos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            newsletter_send_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            photo_url VARCHAR(500) NOT NULL,
            photo_width INT NOT NULL DEFAULT 560,
            photo_align VARCHAR(10) NOT NULL DEFAULT 'center',
            FOREIGN KEY (newsletter_send_id) REFERENCES newsletter_sends(id) ON DELETE CASCADE
        )"
    );
    echo "OK: newsletter_send_photos angelegt.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
