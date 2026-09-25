<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\ListenerContent;

header('Content-Type: application/json; charset=utf-8');

ApiAuth::requireDeviceToken();

$pdo = Database::connection();
// Name live aus dem Konto (siehe ListenerContent).
$stmt = $pdo->query('SELECT ev.id, ev.title, ev.event_date, ev.event_time, ev.event_end_time, ev.description, ev.link,
                      ev.episode_guid, ev.episode_timestamp_seconds, ev.image_path,
                      ' . ListenerContent::displayNameSql('ev', 'submitted_by_name') . ' AS submitted_by_name
                      FROM events ev ' . ListenerContent::joinSql('ev') . '
                      WHERE ev.event_date >= CURDATE()
                      ORDER BY ev.event_date ASC, ev.event_time ASC');

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
