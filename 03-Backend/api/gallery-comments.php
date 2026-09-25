<?php
declare(strict_types=1);

// Kommentare unter Galerie-Fotos (App 2.0).
//   GET  ?photo_id=12                         -> alle sichtbaren Kommentare (auch fuer Gaeste)
//   POST {"photo_id": 12, "text": "..."}      -> kommentieren (nur angemeldet, nicht gesperrt)
//   POST {"action": "delete", "comment_id": 5} -> eigenen Kommentar loeschen
// Der Name kommt immer aus dem Konto (Spitzname), nie aus der Eingabe.

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\GalleryComments;
use Suedsalat\Listener;
use Suedsalat\ListenerApi as Api;

$deviceId = Api::requireDeviceId();
$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $photoId = (int) ($_GET['photo_id'] ?? 0);
    $viewer = Listener::forDevice($pdo, $deviceId);
    $kommentare = GalleryComments::fuerFoto($pdo, $photoId, $viewer !== null ? (int) $viewer['id'] : null);
    Api::json(200, ['comments' => $kommentare, 'count' => count($kommentare)]);
}

Api::requireMethod('POST');
Api::limit('gallery_comment', 30);
$listener = Api::requireListener($pdo, $deviceId);
if ($listener['blocked_at'] !== null) {
    Api::fail(403, 'Dein Konto ist für Beiträge gesperrt.');
}
$input = Api::input();

if (($input['action'] ?? '') === 'delete') {
    if (!GalleryComments::eigenenLoeschen($pdo, (int) $listener['id'], (int) ($input['comment_id'] ?? 0))) {
        Api::fail(404, 'Diesen Kommentar gibt es nicht (mehr) oder er ist nicht von dir.');
    }
    Api::json(200, ['ok' => true]);
}

$photoId = (int) ($input['photo_id'] ?? 0);
$text = trim(normalize_input((string) ($input['text'] ?? '')));
$stmt = $pdo->prepare('SELECT COUNT(*) FROM photos WHERE id = :id AND hidden_at IS NULL');
$stmt->execute([':id' => $photoId]);
if ((int) $stmt->fetchColumn() === 0) {
    Api::fail(404, 'Dieses Foto gibt es nicht mehr.');
}
$problem = GalleryComments::textProblem($text);
if ($problem !== null) {
    Api::fail(422, $problem);
}
$id = GalleryComments::anlegen($pdo, $listener, $photoId, $text);
$alle = GalleryComments::fuerFoto($pdo, $photoId, (int) $listener['id']);
Api::json(200, ['ok' => true, 'id' => $id, 'comments' => $alle, 'count' => count($alle)]);
