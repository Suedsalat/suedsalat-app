<?php
declare(strict_types=1);

// Registrierung, Schritt 1: Angaben pruefen und einen Code an die E-Mail-Adresse schicken.
// Das Konto entsteht erst in verify.php, wenn der Code stimmt - bis dahin warten die Angaben
// im Code-Datensatz. So gibt es keine Konten mit unbestaetigter Adresse.

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerApi as Api;
use Suedsalat\ListenerMail;

Api::requireMethod('POST');
$deviceId = Api::requireDeviceId();
$input = Api::input();

$email = Api::email($input);
Api::limit('listener_register', 10, $email);

$firstName = Api::personName($input, 'first_name', 'Vornamen');
$lastName = Api::personName($input, 'last_name', 'Nachnamen');
$nickname = trim(normalize_input((string) ($input['nickname'] ?? '')));

if (($input['accept_terms'] ?? false) !== true) {
    Api::fail(422, 'Bitte akzeptiere die Nutzungsbedingungen.');
}

$pdo = Database::connection();

$existing = Listener::findByEmail($pdo, $email);
if ($existing !== null) {
    Api::fail(409, $existing['deletion_requested_at'] !== null
        ? 'Zu dieser E-Mail-Adresse gibt es ein stillgelegtes Konto. Melde dich an, um es wiederherzustellen.'
        : 'Mit dieser E-Mail-Adresse gibt es schon ein Konto. Bitte melde dich an.');
}

$problem = Listener::nicknameProblem($pdo, $nickname);
if ($problem !== null) {
    Api::fail(422, $problem);
}

$code = Listener::issueCode($pdo, $email, 'register', [
    'first_name' => $firstName,
    'last_name' => $lastName,
    'nickname' => $nickname,
    'terms_version' => Listener::TERMS_VERSION,
], $deviceId);
ListenerMail::code($email, $firstName, $code, 'register');

Api::json(200, ['ok' => true, 'email' => mask_email_for_display($email)]);
