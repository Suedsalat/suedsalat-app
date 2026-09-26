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

$response = (new Skill(Database::connection(), $episodes))->handle($request);

// VORUEBERGEHEND zur Fehlersuche (26.09.2026): die letzten 40 Anfragen in Kurzform, ohne Nutzerkennung.
$verlauf = dirname(__DIR__) . '/cron/alexa-verlauf.json';
$liste = is_file($verlauf) ? (json_decode((string) file_get_contents($verlauf), true) ?: []) : [];
$liste[] = [
    'zeit' => date('H:i:s'),
    'typ' => $request['request']['type'] ?? null,
    'intent' => $request['request']['intent']['name'] ?? null,
    'kontext' => $request['context']['AudioPlayer'] ?? null,
    'ereignis_stelle' => $request['request']['offsetInMilliseconds'] ?? null,
    'antwort' => array_map(static fn ($d) => ($d['type'] ?? '') . ' ' . ($d['audioItem']['stream']['token'] ?? '') . ' @' . ($d['audioItem']['stream']['offsetInMilliseconds'] ?? ''),
        is_array($response['response'] ?? null) ? ($response['response']['directives'] ?? []) : []),
];
@file_put_contents($verlauf, json_encode(array_slice($liste, -40), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
