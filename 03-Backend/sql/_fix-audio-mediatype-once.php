<?php
declare(strict_types=1);

// Einmaliges Korrektur-Skript: die erste Sprachnachricht (id 14) wurde faelschlich
// als media_type='video' gespeichert, weil mime_content_type() das M4A/AAC-Aufnahme
// als video/mp4 statt audio/mp4 erkannt hat. Korrigiert nur diesen einen Datensatz.
// Nach Gebrauch UNBEDINGT loeschen.

$secret = 'suedsalat-fixaudio-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

$pdo = Database::connection();
$stmt = $pdo->prepare(
    "UPDATE feedback_messages SET media_type = 'audio' WHERE type = 'sprachnachricht' AND media_type = 'video'"
);
$stmt->execute();
echo "OK: " . $stmt->rowCount() . " Datensatz/Datensätze korrigiert.\n";

$stmt2 = $pdo->prepare(
    "UPDATE feedback_messages SET image_path = REPLACE(image_path, '.mp4', '.m4a')
     WHERE type = 'sprachnachricht' AND image_path LIKE '%.mp4'"
);
$stmt2->execute();
echo "OK: " . $stmt2->rowCount() . " Pfad(e) von .mp4 auf .m4a umgestellt.\n";

$rows = $pdo->query(
    "SELECT id, type, image_path, media_type FROM feedback_messages WHERE type = 'sprachnachricht'"
)->fetchAll();
foreach ($rows as $row) {
    echo json_encode($row) . "\n";
}
