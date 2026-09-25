<?php
declare(strict_types=1);

// "Sofort endgueltig loeschen", Schritt 2: Code aus der Mail pruefen, dann alles loeschen.
// Die Bestaetigungsmail geht VOR dem Loeschen raus - danach gibt es die Adresse nicht mehr.

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerApi as Api;
use Suedsalat\ListenerContent;
use Suedsalat\ListenerMail;

Api::requireMethod('POST');
$deviceId = Api::requireDeviceId();
Api::limit('listener_delete', 10);

$pdo = Database::connection();
$listener = Api::requireListener($pdo, $deviceId);
$input = Api::input();
$code = preg_replace('/\D/', '', (string) ($input['code'] ?? '')) ?? '';

$result = Listener::consumeCode($pdo, (string) $listener['email'], ['delete_now'], $code);
if (!$result['ok']) {
    Api::fail(422, $result['error']);
}
$flags = $result['row']['payload'] ?? [];
$deleteTexts = ($flags['delete_texts'] ?? false) === true;
$deletePhotos = ($flags['delete_photos'] ?? false) === true;

ListenerMail::deletionConfirmation($listener, true, $deleteTexts, $deletePhotos);
ListenerContent::finalizeDeletion($pdo, (int) $listener['id'], $deleteTexts, $deletePhotos);
Listener::deleteFinally($pdo, (int) $listener['id']);

Api::json(200, ['ok' => true, 'deleted' => true]);
