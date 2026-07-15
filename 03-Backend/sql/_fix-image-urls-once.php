<?php
declare(strict_types=1);

// Einmaliges Reparatur-Skript: ersetzt die faelschlich mit Unicode-Domain
// gespeicherten Bild-URLs (www.südsalat.eu) durch die korrekte Punycode-Form
// (www.xn--sdsalat-n2a.eu). Grund: APP_URL in .env stand zeitweise falsch.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-fiximg-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $photos = $pdo->exec(
        "UPDATE photos SET image_path = REPLACE(image_path, 'www.südsalat.eu', 'www.xn--sdsalat-n2a.eu')
         WHERE image_path LIKE '%www.südsalat.eu%'"
    );
    echo "photos aktualisiert: $photos\n";

    $feedback = $pdo->exec(
        "UPDATE feedback_messages SET image_path = REPLACE(image_path, 'www.südsalat.eu', 'www.xn--sdsalat-n2a.eu')
         WHERE image_path LIKE '%www.südsalat.eu%'"
    );
    echo "feedback_messages aktualisiert: $feedback\n";

    $events = $pdo->exec(
        "UPDATE events SET image_path = REPLACE(image_path, 'www.südsalat.eu', 'www.xn--sdsalat-n2a.eu')
         WHERE image_path LIKE '%www.südsalat.eu%'"
    );
    echo "events aktualisiert: $events\n";

    echo "\nFERTIG.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
