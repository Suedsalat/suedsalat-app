<?php
declare(strict_types=1);

// Anmeldung, Schritt 1: Code an die E-Mail-Adresse schicken. Die Antwort ist immer gleich,
// ob es ein Konto gibt oder nicht - sonst liesse sich abfragen, wer bei Suedsalat angemeldet ist.
// Konten in der Rueckkehrfrist bekommen ebenfalls einen Code; die Anmeldung stellt sie wieder her.

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerApi as Api;
use Suedsalat\ListenerMail;

Api::requireMethod('POST');
$deviceId = Api::requireDeviceId();
$input = Api::input();

$email = Api::email($input);
Api::limit('listener_login', 10, $email);

$pdo = Database::connection();
$listener = Listener::findByEmail($pdo, $email);
if ($listener !== null) {
    $code = Listener::issueCode($pdo, $email, 'login', null, $deviceId);
    ListenerMail::code($email, (string) $listener['first_name'], $code, 'login');
}

Api::json(200, ['ok' => true, 'email' => mask_email_for_display($email)]);
