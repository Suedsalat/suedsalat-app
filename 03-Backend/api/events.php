<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;

header('Content-Type: application/json; charset=utf-8');

ApiAuth::requireDeviceToken();

$pdo = Database::connection();
$stmt = $pdo->query("SELECT id, title, event_date, event_time, event_end_time, description, link,
                      episode_guid, episode_timestamp_seconds, image_path
                      FROM events
                      WHERE event_date >= CURDATE()
                      ORDER BY event_date ASC, event_time ASC");

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
