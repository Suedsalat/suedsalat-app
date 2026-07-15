<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::connection();
$stmt = $pdo->query('SELECT id, title, description, link, episode_guid, episode_timestamp_seconds, image_path, created_at
                      FROM movie_tips
                      ORDER BY created_at DESC');

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
