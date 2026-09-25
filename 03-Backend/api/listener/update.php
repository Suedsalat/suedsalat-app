<?php
declare(strict_types=1);

// Profil aendern: Spitzname (gleiche Regeln wie bei der Registrierung), Vor- und Nachname.
// Nur mitgeschickte Felder werden geaendert. Die E-Mail-Adresse laesst sich hier nicht aendern.

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerApi as Api;

Api::requireMethod('POST');
$deviceId = Api::requireDeviceId();
Api::limit('listener_update', 30);

$pdo = Database::connection();
$listener = Api::requireListener($pdo, $deviceId);
$input = Api::input();

$fields = [];
$params = [':id' => $listener['id']];

if (array_key_exists('nickname', $input)) {
    $nickname = trim(normalize_input((string) $input['nickname']));
    $problem = Listener::nicknameProblem($pdo, $nickname, (int) $listener['id']);
    if ($problem !== null) {
        Api::fail(422, $problem);
    }
    $fields[] = 'nickname = :n, nickname_key = :k';
    $params[':n'] = $nickname;
    $params[':k'] = Listener::nicknameKey($nickname);
}
if (array_key_exists('first_name', $input)) {
    $fields[] = 'first_name = :fn';
    $params[':fn'] = Api::personName($input, 'first_name', 'Vornamen');
}
if (array_key_exists('last_name', $input)) {
    $fields[] = 'last_name = :ln';
    $params[':ln'] = Api::personName($input, 'last_name', 'Nachnamen');
}

if ($fields !== []) {
    try {
        $pdo->prepare('UPDATE listeners SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id')->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            Api::fail(409, 'Dieser Spitzname wurde gerade vergeben. Bitte wähle einen anderen.');
        }
        throw $e;
    }
}

Api::json(200, ['ok' => true, 'listener' => Listener::publicProfile(Listener::findById($pdo, (int) $listener['id']))]);
