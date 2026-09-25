<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\Listener;
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

// App 2.0: Rezensionen gibt es nur mit Konto, und der Name kommt immer aus dem Konto
// (Spitzname) - nie aus dem Formular. Gaeste und gesperrte Hoerer koennen nicht bewerten.
$pdo = Database::connection();
$listener = Listener::forDevice($pdo, isset($claims['sub']) ? (int) $claims['sub'] : null);
if ($listener === null) {
    http_response_code(403);
    echo json_encode(['error' => 'Bewertungen kannst du als registrierter Hörer abgeben. Registriere dich kostenlos in den Einstellungen.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($listener['blocked_at'] !== null) {
    http_response_code(403);
    echo json_encode(['error' => 'Dein Konto ist für Beiträge gesperrt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Rezensionen gibt es bewusst nur fuer Filmtipps und Locationtipps, nicht fuer
// Veranstaltungen - dort ergibt eine Bewertung inhaltlich keinen Sinn.
$tipTypeTables = [
    'movie_tip' => 'movie_tips',
    'location_tip' => 'location_tips',
];

$tipType = (string) ($_POST['tip_type'] ?? '');
$tipId = (int) ($_POST['tip_id'] ?? 0);
$rating = (int) ($_POST['rating'] ?? 0);
$reviewText = trim((string) ($_POST['review_text'] ?? ''));
$reviewerName = (string) $listener['nickname'];

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
if ($reviewText === '') {
    http_response_code(422);
    echo json_encode(['error' => 'review_text ist erforderlich.']);
    exit;
}
if (mb_strlen($reviewText) > 1000) {
    http_response_code(422);
    echo json_encode(['error' => 'Rezensionstext ist zu lang (max. 1000 Zeichen).']);
    exit;
}
if ($reviewerName === '') {
    http_response_code(422);
    echo json_encode(['error' => 'reviewer_name ist erforderlich.']);
    exit;
}
if (mb_strlen($reviewerName) > 100) {
    $reviewerName = mb_substr($reviewerName, 0, 100);
}

$existsStmt = $pdo->prepare('SELECT id FROM ' . $tipTypeTables[$tipType] . ' WHERE id = :id');
$existsStmt->execute([':id' => $tipId]);
if ($existsStmt->fetchColumn() === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Der bewertete Eintrag existiert nicht.']);
    exit;
}

// Seit 2.0 gibt es Rezensionen nur mit Konto, also immer mit gueltigem Geraete-Token.
$deviceId = (int) $claims['sub'];

// Rezensionen erscheinen ab sofort direkt live in der App, ohne Admin-
// Freigabe - Admins koennen sie im Nachhinein im Admin-Bereich bearbeiten
// oder loeschen (siehe admin/tip-reviews.php, admin/movie-tips.php,
// admin/location-tips.php). approved bleibt als Spalte erhalten, damit
// alte, noch nicht freigegebene Rezensionen aus der Zeit vor dieser
// Umstellung weiter korrekt behandelt werden.
$stmt = $pdo->prepare(
    'INSERT INTO tip_reviews (tip_type, tip_id, rating, review_text, reviewer_name, device_id, listener_id, approved, approved_at)
     VALUES (:tip_type, :tip_id, :rating, :review_text, :reviewer_name, :device_id, :listener_id, 1, NOW())'
);
$stmt->execute([
    ':tip_type' => $tipType,
    ':tip_id' => $tipId,
    ':rating' => $rating,
    ':review_text' => $reviewText,
    ':reviewer_name' => $reviewerName,
    ':device_id' => $deviceId,
    ':listener_id' => $listener['id'],
]);

echo json_encode(['status' => 'ok']);
