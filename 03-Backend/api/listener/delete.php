<?php
declare(strict_types=1);

// Konto loeschen.
//  mode = "grace": sofort stilllegen, 30 Tage Rueckkehrfrist, danach loescht der Cronjob.
//  mode = "now":   sofort endgueltig - aber erst nach Bestaetigung per Code (delete-confirm.php),
//                  damit niemand mit einem fremden, entsperrten Handy ein Konto unwiderruflich loescht.
// delete_texts / delete_photos: die beiden Haekchen im Loesch-Dialog.

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

$mode = (string) ($input['mode'] ?? '');
$deleteTexts = ($input['delete_texts'] ?? false) === true;
$deletePhotos = ($input['delete_photos'] ?? false) === true;

if ($mode === 'grace') {
    Listener::requestDeletion($pdo, (int) $listener['id'], $deleteTexts, $deletePhotos);
    ListenerContent::hideForDeletion($pdo, (int) $listener['id'], $deleteTexts, $deletePhotos);
    ListenerMail::deletionConfirmation(Listener::findById($pdo, (int) $listener['id']), false, $deleteTexts, $deletePhotos);
    Api::json(200, ['ok' => true, 'mode' => 'grace']);
}

if ($mode === 'now') {
    $code = Listener::issueCode($pdo, (string) $listener['email'], 'delete_now',
        ['delete_texts' => $deleteTexts, 'delete_photos' => $deletePhotos], $deviceId);
    ListenerMail::code((string) $listener['email'], (string) $listener['first_name'], $code, 'delete_now');
    Api::json(200, ['ok' => true, 'mode' => 'now', 'code_sent' => true, 'email' => mask_email_for_display((string) $listener['email'])]);
}

Api::fail(422, 'Unbekannte Löschart.');
