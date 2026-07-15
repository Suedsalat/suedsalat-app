<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::connection();
$stmt = $pdo->query('SELECT guid, title, description, audio_url, image_url, duration, pub_date
                      FROM episodes_cache
                      ORDER BY pub_date DESC');

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
