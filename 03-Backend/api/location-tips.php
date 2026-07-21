<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;

header('Content-Type: application/json; charset=utf-8');

ApiAuth::requireDeviceToken();

$pdo = Database::connection();
$stmt = $pdo->query(
    'SELECT lt.id, lt.name, lt.location, lt.description, lt.link, lt.episode_guid, lt.episode_timestamp_seconds,
        lt.image_path, lt.created_at,
        (SELECT AVG(rating) FROM tip_reviews WHERE tip_type = "location_tip" AND tip_id = lt.id AND approved = 1) AS avg_rating,
        (SELECT COUNT(*) FROM tip_reviews WHERE tip_type = "location_tip" AND tip_id = lt.id AND approved = 1) AS review_count
     FROM location_tips lt
     ORDER BY lt.created_at DESC'
);

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
