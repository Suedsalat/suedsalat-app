<?php
declare(strict_types=1);

// Einwilligung in die anonyme Statistik (App 2.0, § 25 TDDDG), pro Installation.
//   GET  -> aktueller Stand; needs_decision = true, wenn die App (erneut) fragen soll
//   POST {"decision": "granted"|"denied", "text_version": "<Fassung des gezeigten Texts>"}
// Gilt fuer Gaeste und Registrierte gleich. Ablehnen und Widerrufen sind gleich einfach
// und wirken sofort: ab dann wird von diesem Geraet nichts mehr gezaehlt.

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerApi as Api;
use Suedsalat\StatsConsent;

$deviceId = Api::requireDeviceId();
$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Api::json(200, StatsConsent::state($pdo, $deviceId));
}

Api::requireMethod('POST');
Api::limit('stats_consent', 30);

$input = Api::input();
$decision = (string) ($input['decision'] ?? '');
$textVersion = trim((string) ($input['text_version'] ?? ''));

if (!in_array($decision, StatsConsent::DECISIONS, true)) {
    Api::fail(422, 'Unbekannte Entscheidung.');
}
if (!preg_match('/^[0-9A-Za-z.-]{1,20}$/', $textVersion)) {
    Api::fail(422, 'Fassung des Einwilligungstexts fehlt.');
}

$listener = Listener::forDevice($pdo, $deviceId);
StatsConsent::record($pdo, $deviceId, $listener !== null ? (int) $listener['id'] : null, $decision, $textVersion);
Api::json(200, StatsConsent::state($pdo, $deviceId));
