<?php
declare(strict_types=1);

// Schritt 2 fuer Registrierung und Anmeldung: Code pruefen, dann Konto anlegen bzw. anmelden
// und dieses Geraet mit dem Konto verknuepfen.

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerApi as Api;
use Suedsalat\ListenerContent;
use Suedsalat\ListenerMail;

Api::requireMethod('POST');
$deviceId = Api::requireDeviceId();
$input = Api::input();

$email = Api::email($input);
Api::limit('listener_verify', 30);
$code = preg_replace('/\D/', '', (string) ($input['code'] ?? '')) ?? '';

$pdo = Database::connection();
$result = Listener::consumeCode($pdo, $email, ['register', 'login'], $code);
if (!$result['ok']) {
    Api::fail(422, $result['error']);
}
$row = $result['row'];

if ($row['purpose'] === 'register') {
    $data = $row['payload'] ?? [];
    // Zwischen Code-Anforderung und Bestaetigung koennte jemand anderes denselben Spitznamen
    // oder dieselbe Adresse registriert haben - deshalb hier noch einmal pruefen.
    if (Listener::findByEmail($pdo, $email) !== null) {
        Api::fail(409, 'Mit dieser E-Mail-Adresse gibt es schon ein Konto. Bitte melde dich an.');
    }
    $problem = Listener::nicknameProblem($pdo, (string) ($data['nickname'] ?? ''));
    if ($problem !== null) {
        Api::fail(422, $problem . ' Bitte registriere dich mit einem anderen Spitznamen.');
    }
    $nickname = trim(normalize_input((string) $data['nickname']));
    try {
        $pdo->prepare(
            'INSERT INTO listeners (first_name, last_name, email, nickname, nickname_key, email_verified_at,
                terms_accepted_at, terms_version)
             VALUES (:fn, :ln, :e, :n, :k, NOW(), :ta, :tv)'
        )->execute([
            ':fn' => $data['first_name'],
            ':ln' => $data['last_name'],
            ':e' => $email,
            ':n' => $nickname,
            ':k' => Listener::nicknameKey($nickname),
            ':ta' => $row['created_at'],
            ':tv' => $data['terms_version'] ?? Listener::TERMS_VERSION,
        ]);
    } catch (PDOException $e) {
        // Eindeutigkeit von E-Mail/Spitzname: im selben Augenblick hat jemand anderes zugegriffen.
        if ($e->getCode() === '23000') {
            Api::fail(409, 'Dieser Spitzname oder diese E-Mail-Adresse wurde gerade vergeben. Bitte versuche es noch einmal.');
        }
        throw $e;
    }
    $listenerId = (int) $pdo->lastInsertId();
    Listener::linkDevice($pdo, $listenerId, $deviceId);
    Api::json(200, ['ok' => true, 'created' => true, 'listener' => Listener::publicProfile(Listener::findById($pdo, $listenerId))]);
}

// Anmeldung
$listener = Listener::findByEmail($pdo, $email);
if ($listener === null) {
    Api::fail(422, 'Zu dieser E-Mail-Adresse gibt es kein Konto.');
}
$restored = false;
if ($listener['deletion_requested_at'] !== null) {
    Listener::restore($pdo, (int) $listener['id']);
    ListenerContent::restoreAfterReturn($pdo, (int) $listener['id']);
    $listener = Listener::findById($pdo, (int) $listener['id']);
    ListenerMail::welcomeBack($listener);
    $restored = true;
}
Listener::linkDevice($pdo, (int) $listener['id'], $deviceId);

Api::json(200, ['ok' => true, 'restored' => $restored, 'listener' => Listener::publicProfile($listener)]);
