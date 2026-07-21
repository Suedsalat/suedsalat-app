<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;

header('Content-Type: application/json; charset=utf-8');

ApiAuth::requireDeviceToken();

$pdo = Database::connection();
$stmt = $pdo->query(
    'SELECT mt.id, mt.title, mt.description, mt.link, mt.episode_guid, mt.episode_timestamp_seconds,
        mt.image_path, mt.created_at,
        (SELECT AVG(rating) FROM tip_reviews WHERE tip_type = "movie_tip" AND tip_id = mt.id AND approved = 1) AS avg_rating,
        (SELECT COUNT(*) FROM tip_reviews WHERE tip_type = "movie_tip" AND tip_id = mt.id AND approved = 1) AS review_count
     FROM movie_tips mt
     ORDER BY mt.created_at DESC'
);

// PDO liefert AVG()/COUNT() standardmaessig als String statt als Zahl - ohne diesen
// Cast wuerde die App beim JSON-Parsen scheitern, sobald ein Eintrag eine Bewertung hat.
$tips = $stmt->fetchAll();
foreach ($tips as &$tip) {
    $tip['avg_rating'] = $tip['avg_rating'] !== null ? round((float) $tip['avg_rating'], 1) : null;
    $tip['review_count'] = (int) $tip['review_count'];
}
unset($tip);

echo json_encode($tips, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
