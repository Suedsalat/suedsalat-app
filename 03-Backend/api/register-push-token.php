<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$deviceToken = trim((string) ($input['device_token'] ?? ''));
$platform = trim((string) ($input['platform'] ?? ''));
$action = trim((string) ($input['action'] ?? 'register'));

if ($deviceToken === '') {
    http_response_code(422);
    echo json_encode(['error' => 'device_token erforderlich.']);
    exit;
}

$pdo = Database::connection();

if ($action === 'unregister') {
    $pdo->prepare('DELETE FROM push_tokens WHERE device_token = :token')->execute([':token' => $deviceToken]);
    echo json_encode(['status' => 'ok']);
    exit;
}

if (!in_array($platform, ['ios', 'android'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'platform (ios|android) erforderlich.']);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO push_tokens (device_token, platform) VALUES (:token, :platform)
     ON DUPLICATE KEY UPDATE platform = VALUES(platform)'
);
$stmt->execute([':token' => $deviceToken, ':platform' => $platform]);

echo json_encode(['status' => 'ok']);
