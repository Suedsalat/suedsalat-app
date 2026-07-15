<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::connection();
$stmt = $pdo->query('SELECT id, image_path, media_type, description, published_at
                      FROM photos
                      ORDER BY published_at DESC');

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
