<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$claims = ApiAuth::requireDeviceToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.']);
    exit;
}

$ip = ApiAuth::clientIp();
if (RateLimiter::tooMany('review_submit', $ip, 20, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Zu viele Anfragen. Bitte später erneut versuchen.']);
    exit;
}
RateLimiter::record('review_submit', $ip);

$tipTypeTables = [
    'movie_tip' => 'movie_tips',
    'event' => 'events',
    'location_tip' => 'location_tips',
];

$tipType = (string) ($_POST['tip_type'] ?? '');
$tipId = (int) ($_POST['tip_id'] ?? 0);
$rating = (int) ($_POST['rating'] ?? 0);
$reviewText = trim((string) ($_POST['review_text'] ?? '')) ?: null;

if (!isset($tipTypeTables[$tipType]) || $tipId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'tip_type und tip_id sind erforderlich.']);
    exit;
}
if ($rating < 1 || $rating > 5) {
    http_response_code(422);
    echo json_encode(['error' => 'rating muss zwischen 1 und 5 liegen.']);
    exit;
}
if ($reviewText !== null && mb_strlen($reviewText) > 1000) {
    http_response_code(422);
    echo json_encode(['error' => 'Rezensionstext ist zu lang (max. 1000 Zeichen).']);
    exit;
}

$pdo = Database::connection();

$existsStmt = $pdo->prepare('SELECT id FROM ' . $tipTypeTables[$tipType] . ' WHERE id = :id');
$existsStmt->execute([':id' => $tipId]);
if ($existsStmt->fetchColumn() === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Der bewertete Eintrag existiert nicht.']);
    exit;
}

// deviceId bleibt null im Soft-Auth-Modus (API_AUTH_ENFORCE=false) ohne gueltiges Token -
// die Rezension wird trotzdem angenommen, nur ohne Geraete-Zuordnung.
$deviceId = $claims['sub'] ?? null;

$stmt = $pdo->prepare(
    'INSERT INTO tip_reviews (tip_type, tip_id, rating, review_text, device_id)
     VALUES (:tip_type, :tip_id, :rating, :review_text, :device_id)'
);
$stmt->execute([
    ':tip_type' => $tipType,
    ':tip_id' => $tipId,
    ':rating' => $rating,
    ':review_text' => $reviewText,
    ':device_id' => $deviceId,
]);

echo json_encode(['status' => 'ok']);
