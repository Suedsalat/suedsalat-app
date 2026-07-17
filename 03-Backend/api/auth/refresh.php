<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Jwt;
use Suedsalat\RateLimiter;
use Suedsalat\RefreshToken;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.']);
    exit;
}

$ip = ApiAuth::clientIp();
if (RateLimiter::tooMany('token_refresh', $ip, 30, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Zu viele Anfragen. Bitte spaeter erneut versuchen.']);
    exit;
}
RateLimiter::record('token_refresh', $ip);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$rawRefreshToken = trim((string) ($input['refresh_token'] ?? ''));

if ($rawRefreshToken === '') {
    http_response_code(422);
    echo json_encode(['error' => 'refresh_token erforderlich.']);
    exit;
}

$result = RefreshToken::verifyAndRotate($rawRefreshToken, JWT_REFRESH_TTL_DAYS);
if ($result === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Refresh-Token ungueltig oder abgelaufen.']);
    exit;
}

// Aktuell ist 'device' der einzige subject_type - der Zweig bleibt hier bewusst
// explizit, damit ein spaeterer 'user'-Typ (echtes Hoerer-Login) sich sauber
// ergaenzen laesst, ohne diese Verzweigung stillschweigend falsch zu behandeln.
if ($result['subject_type'] !== 'device') {
    http_response_code(500);
    echo json_encode(['error' => 'Unbekannter Token-Typ.']);
    exit;
}

$accessToken = Jwt::issue(['sub' => $result['subject_id'], 'typ' => 'device'], JWT_ACCESS_TTL_MINUTES * 60);

echo json_encode([
    'access_token' => $accessToken,
    'refresh_token' => $result['refresh_token'],
    'expires_in' => JWT_ACCESS_TTL_MINUTES * 60,
]);
