<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;

header('Content-Type: application/json; charset=utf-8');

ApiAuth::requireDeviceToken();

// Rezensionen gibt es bewusst nur fuer Kino-/Filmtipps und Locationtipps, nicht fuer
// Veranstaltungen - dort ergibt eine Bewertung inhaltlich keinen Sinn.
$allowedTipTypes = ['movie_tip', 'location_tip'];
$tipType = (string) ($_GET['tip_type'] ?? '');
$tipId = (int) ($_GET['tip_id'] ?? 0);

if (!in_array($tipType, $allowedTipTypes, true) || $tipId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'tip_type und tip_id sind erforderlich.']);
    exit;
}

$pdo = Database::connection();

$summaryStmt = $pdo->prepare(
    'SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
     FROM tip_reviews WHERE tip_type = :tip_type AND tip_id = :tip_id AND approved = 1'
);
$summaryStmt->execute([':tip_type' => $tipType, ':tip_id' => $tipId]);
$summary = $summaryStmt->fetch();

$reviewsStmt = $pdo->prepare(
    'SELECT id, rating, review_text, reviewer_name, created_at
     FROM tip_reviews WHERE tip_type = :tip_type AND tip_id = :tip_id AND approved = 1
     ORDER BY created_at DESC'
);
$reviewsStmt->execute([':tip_type' => $tipType, ':tip_id' => $tipId]);

echo json_encode([
    'avg_rating' => $summary['avg_rating'] !== null ? round((float) $summary['avg_rating'], 1) : null,
    'review_count' => (int) $summary['review_count'],
    'reviews' => $reviewsStmt->fetchAll(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
