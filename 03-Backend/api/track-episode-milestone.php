<?php
declare(strict_types=1);

// Rein anonyme Zaehlung, dass eine Folge eine bestimmte Hoerdauer-Stufe
// erreicht hat (5/15/25/35/45 Minuten oder "bis zum Ende") - fuer die
// Trichter-Auswertung im Admin-Bereich. Wie track-episode-play.php ohne
// jeden Personenbezug, nur ein taeglicher Zaehler pro Folge und Stufe.

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
if (RateLimiter::tooMany('track_episode_milestone', $ip, 120, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Zu viele Anfragen.']);
    exit;
}
RateLimiter::record('track_episode_milestone', $ip);

$allowedTiers = ['5min', '15min', '25min', '35min', '45min', 'end'];
$episodeGuid = trim((string) ($_POST['episode_guid'] ?? ''));
$tier = trim((string) ($_POST['tier'] ?? ''));

if ($episodeGuid === '' || !in_array($tier, $allowedTiers, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'episode_guid oder tier ungueltig.']);
    exit;
}

$pdo = Database::connection();

$existsStmt = $pdo->prepare('SELECT 1 FROM episodes_cache WHERE guid = :guid');
$existsStmt->execute([':guid' => $episodeGuid]);
if ($existsStmt->fetchColumn() === false) {
    http_response_code(422);
    echo json_encode(['error' => 'Unbekannte Folge.']);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO episode_play_milestones (episode_guid, day, tier, count) VALUES (:guid, CURDATE(), :tier, 1)
     ON DUPLICATE KEY UPDATE count = count + 1'
);
$stmt->execute([':guid' => $episodeGuid, ':tier' => $tier]);

echo json_encode(['status' => 'ok']);
