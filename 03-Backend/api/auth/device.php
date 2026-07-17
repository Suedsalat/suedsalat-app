<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
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
if (RateLimiter::tooMany('device_register', $ip, 10, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Zu viele Anfragen. Bitte spaeter erneut versuchen.']);
    exit;
}
RateLimiter::record('device_register', $ip);

$providedSecret = $_SERVER['HTTP_X_APP_SECRET'] ?? '';
if (empty(APP_SECRET) || !hash_equals(APP_SECRET, $providedSecret)) {
    http_response_code(403);
    echo json_encode(['error' => 'Ungueltiges App-Secret.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$deviceUuid = trim((string) ($input['device_uuid'] ?? ''));
$platform = trim((string) ($input['platform'] ?? ''));

if ($deviceUuid === '' || strlen($deviceUuid) > 64) {
    http_response_code(422);
    echo json_encode(['error' => 'device_uuid erforderlich (max. 64 Zeichen).']);
    exit;
}
if (!in_array($platform, ['ios', 'android'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'platform (ios|android) erforderlich.']);
    exit;
}

$pdo = Database::connection();

// ON DUPLICATE KEY UPDATE mit id = LAST_INSERT_ID(id) sorgt dafuer, dass
// lastInsertId() auch beim Update-Pfad die bestehende Zeilen-ID liefert
// (sonst gibt MySQL dort 0 zurueck).
$stmt = $pdo->prepare(
    'INSERT INTO devices (device_uuid, platform, last_seen_at)
     VALUES (:uuid, :platform, NOW())
     ON DUPLICATE KEY UPDATE platform = VALUES(platform), last_seen_at = NOW(), id = LAST_INSERT_ID(id)'
);
$stmt->execute([':uuid' => $deviceUuid, ':platform' => $platform]);
$deviceId = (int) $pdo->lastInsertId();

$accessToken = Jwt::issue(['sub' => $deviceId, 'typ' => 'device'], JWT_ACCESS_TTL_MINUTES * 60);
$refreshToken = RefreshToken::issue('device', $deviceId, JWT_REFRESH_TTL_DAYS);

echo json_encode([
    'access_token' => $accessToken,
    'refresh_token' => $refreshToken,
    'expires_in' => JWT_ACCESS_TTL_MINUTES * 60,
]);
