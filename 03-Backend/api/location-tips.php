<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\ListenerContent;

header('Content-Type: application/json; charset=utf-8');

ApiAuth::requireDeviceToken();

$pdo = Database::connection();
$stmt = $pdo->query(
    // Name live aus dem Konto (siehe ListenerContent); ausgeblendete Rezensionen zaehlen nicht mit.
    'SELECT lt.id, lt.name, lt.location, lt.description, lt.link, lt.episode_guid, lt.episode_timestamp_seconds,
        lt.image_path, ' . ListenerContent::displayNameSql('lt', 'submitted_by_name') . ' AS submitted_by_name, lt.created_at,
        (SELECT AVG(rating) FROM tip_reviews WHERE tip_type = "location_tip" AND tip_id = lt.id AND approved = 1 AND hidden_at IS NULL) AS avg_rating,
        (SELECT COUNT(*) FROM tip_reviews WHERE tip_type = "location_tip" AND tip_id = lt.id AND approved = 1 AND hidden_at IS NULL) AS review_count
     FROM location_tips lt ' . ListenerContent::joinSql('lt') . '
     ORDER BY lt.sort_order ASC'
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
