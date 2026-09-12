<?php
declare(strict_types=1);

// Rein anonyme Zaehlung, wie oft eine Folge abgespielt wurde (fuer die
// Statistik im Admin-Dashboard). Wie api/track-view.php wird bewusst NICHTS
// gespeichert, das Rueckschluesse auf einzelne Nutzer zulaesst - keine IP,
// kein Geraete-Token, keine Sitzungs-ID, nur ein taeglicher Zaehler pro Folge.

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

ApiAuth::requireDeviceToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.']);
    exit;
}

$ip = ApiAuth::clientIp();
if (RateLimiter::tooMany('track_episode_play', $ip, 60, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Zu viele Anfragen.']);
    exit;
}
RateLimiter::record('track_episode_play', $ip);

$episodeGuid = trim((string) ($_POST['episode_guid'] ?? ''));
if ($episodeGuid === '') {
    http_response_code(422);
    echo json_encode(['error' => 'episode_guid fehlt.']);
    exit;
}

$pdo = Database::connection();

// Nur zaehlen, wenn die Folge tatsaechlich bekannt ist - verhindert, dass
// beliebige Zeichenketten den Fremdschluessel-Constraint verletzen bzw. Muell
// in die Tabelle wandert.
$existsStmt = $pdo->prepare('SELECT 1 FROM episodes_cache WHERE guid = :guid');
$existsStmt->execute([':guid' => $episodeGuid]);
if ($existsStmt->fetchColumn() === false) {
    http_response_code(422);
    echo json_encode(['error' => 'Unbekannte Folge.']);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO episode_play_counts (episode_guid, day, count) VALUES (:guid, CURDATE(), 1)
     ON DUPLICATE KEY UPDATE count = count + 1'
);
$stmt->execute([':guid' => $episodeGuid]);

echo json_encode(['status' => 'ok']);
