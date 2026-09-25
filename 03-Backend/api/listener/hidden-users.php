<?php
declare(strict_types=1);

// Ausgeblendete Nutzer des angemeldeten Hoerers.
//  GET:  Liste [{id, nickname}] fuer die Einstellungen.
//  POST: {"listener_id": X} blendet X wieder ein.

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\ListenerApi as Api;
use Suedsalat\Moderation;

$deviceId = Api::requireDeviceId();
$pdo = Database::connection();
$listener = Api::requireListener($pdo, $deviceId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Api::limit('listener_hide', 30);
    $hiddenId = (int) (Api::input()['listener_id'] ?? 0);
    Moderation::unhide($pdo, (int) $listener['id'], $hiddenId);
}

Api::json(200, ['hidden_users' => Moderation::hiddenUsers($pdo, (int) $listener['id'])]);
