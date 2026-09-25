<?php
declare(strict_types=1);

// Rein anonyme Zaehlung, welcher App-Bereich geoeffnet wurde (fuer die Statistik
// im Admin-Dashboard). Es wird bewusst NICHTS gespeichert, das Rueckschluesse auf
// einzelne Nutzer zulaesst - keine IP, kein Geraete-Token, keine Sitzungs-ID,
// nur ein taeglicher Zaehler pro Bereich.

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\RateLimiter;
use Suedsalat\StatsConsent;

header('Content-Type: application/json; charset=utf-8');

$claims = ApiAuth::requireDeviceToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.']);
    exit;
}

$ip = ApiAuth::clientIp();
if (RateLimiter::tooMany('track_view', $ip, 60, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Zu viele Anfragen.']);
    exit;
}
RateLimiter::record('track_view', $ip);

$allowedScreens = ['start', 'episodes', 'events', 'movie_tips', 'location_tips', 'gallery', 'feedback'];
$screen = trim((string) ($_POST['screen'] ?? ''));

if (!in_array($screen, $allowedScreens, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Unbekannter Bereich.']);
    exit;
}

$pdo = Database::connection();

// App 2.0: nur mit Einwilligung zaehlen (§ 25 TDDDG). Ohne Entscheidung - auch bei alten
// App-Versionen - wird nichts gespeichert; fuer die App sieht die Antwort gleich aus.
if (!StatsConsent::allowed($pdo, isset($claims['sub']) ? (int) $claims['sub'] : null)) {
    echo json_encode(['status' => 'ok', 'counted' => false]);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO screen_views (screen, day, count) VALUES (:screen, CURDATE(), 1)
     ON DUPLICATE KEY UPDATE count = count + 1'
);
$stmt->execute([':screen' => $screen]);

echo json_encode(['status' => 'ok']);
