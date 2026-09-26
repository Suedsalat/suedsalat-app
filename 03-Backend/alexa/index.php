<?php
declare(strict_types=1);

// Endpunkt des Alexa-Skills "Südsalat" - diese Adresse wird in der Alexa-Konsole eingetragen:
//   https://www.xn--sdsalat-n2a.eu/APP/alexa/
// Jede Anfrage wird zuerst auf Echtheit geprueft (lib/Alexa/RequestVerifier.php), dann beantwortet
// (lib/Alexa/Skill.php). Einrichtung: siehe 01-Brainstorming/Alexa-Skill/ANLEITUNG.md.

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Alexa\RequestVerifier;
use Suedsalat\Alexa\Skill;
use Suedsalat\Database;
use Suedsalat\Feed;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST.']);
    exit;
}

$body = (string) file_get_contents('php://input');
// Auch mit einer aelteren config.php (ohne die Konstante) direkt aus der .env.
$skillId = defined('ALEXA_SKILL_ID') ? (string) constant('ALEXA_SKILL_ID') : (string) env('ALEXA_SKILL_ID', '');

// Nur in der lokalen Testumgebung (erkennbar am Mail-Fangordner) darf die Pruefung abgeschaltet
// werden - live gibt es diesen Weg nicht.
$skipCheck = (string) env('ALEXA_SKIP_SIGNATURE', '') === 'true' && (string) env('MAIL_CAPTURE_DIR', '') !== '';
if (!$skipCheck) {
    $problem = (new RequestVerifier())->verify(
        $body,
        (string) ($_SERVER['HTTP_SIGNATURECERTCHAINURL'] ?? ''),
        (string) ($_SERVER['HTTP_SIGNATURE_256'] ?? ''),
        $skillId
    );
    if ($problem !== null) {
        error_log('Alexa: Anfrage abgelehnt - ' . $problem);
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Anfrage.']);
        exit;
    }
}

$request = json_decode($body, true);
if (!is_array($request)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Anfrage.']);
    exit;
}

try {
    $episodes = Feed::path() !== null ? Feed::parse(Feed::read())['items'] : [];
} catch (\RuntimeException $e) {
    error_log('Alexa: RSS-Datei nicht lesbar - ' . $e->getMessage());
    $episodes = [];
}

echo json_encode((new Skill(Database::connection(), $episodes))->handle($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
