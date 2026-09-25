<?php
declare(strict_types=1);

// Einen oeffentlichen Beitrag melden. Duerfen auch Gaeste (Pflicht nach dem EU-Gesetz ueber
// digitale Dienste: jeder kann melden). Gesperrte Hoerer koennen nicht melden.
// Eingabe (JSON): content_type, content_id, category, text (freiwillig).

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerApi as Api;
use Suedsalat\Moderation;

Api::requireMethod('POST');
$deviceId = Api::requireDeviceId();
Api::limit('content_report', 20);

$input = Api::input();
$type = (string) ($input['content_type'] ?? '');
$id = (int) ($input['content_id'] ?? 0);
$category = (string) ($input['category'] ?? '');
$text = trim(normalize_input((string) ($input['text'] ?? ''))) ?: null;

if (!Moderation::isKnownType($type) || $id <= 0) {
    Api::fail(422, 'Unbekannter Beitrag.');
}
if (!isset(Moderation::CATEGORIES[$category])) {
    Api::fail(422, 'Bitte wähle einen Grund für die Meldung.');
}
if ($text !== null && mb_strlen($text) > 1000) {
    Api::fail(422, 'Die Begründung ist zu lang (höchstens 1000 Zeichen).');
}

$pdo = Database::connection();
$listener = Listener::forDevice($pdo, $deviceId);
if ($listener !== null && $listener['blocked_at'] !== null) {
    Api::fail(403, 'Dein Konto ist gesperrt.');
}
if (!Moderation::isVisible($pdo, $type, $id)) {
    Api::fail(404, 'Diesen Beitrag gibt es nicht mehr.');
}

$result = Moderation::report($pdo, $type, $id, $listener !== null ? (int) $listener['id'] : null, $deviceId, $category, $text);
Api::json(200, ['ok' => true, 'already_reported' => $result['duplicate']]);
