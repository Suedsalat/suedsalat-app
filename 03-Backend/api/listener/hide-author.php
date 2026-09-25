<?php
declare(strict_types=1);

// "Diesen Nutzer ausblenden": Beitraege des Verfassers sieht der angemeldete Hoerer nicht mehr.
// Die App schickt den Beitrag (content_type, content_id), nie eine Nutzernummer - welche
// Konten hinter Beitraegen stehen, verraet die Schnittstelle nicht.

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\ListenerApi as Api;
use Suedsalat\Moderation;

Api::requireMethod('POST');
$deviceId = Api::requireDeviceId();
Api::limit('listener_hide', 30);

$pdo = Database::connection();
$listener = Api::requireListener($pdo, $deviceId);
$input = Api::input();
$type = (string) ($input['content_type'] ?? '');
$id = (int) ($input['content_id'] ?? 0);

if (!Moderation::isKnownType($type) || $id <= 0) {
    Api::fail(422, 'Unbekannter Beitrag.');
}
$problem = Moderation::hideAuthor($pdo, (int) $listener['id'], $type, $id);
if ($problem !== null) {
    Api::fail(422, $problem);
}
Api::json(200, ['ok' => true]);
