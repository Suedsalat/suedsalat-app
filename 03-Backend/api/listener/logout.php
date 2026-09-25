<?php
declare(strict_types=1);

// Abmelden: nur dieses Geraet, andere angemeldete Geraete bleiben angemeldet.

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerApi as Api;

Api::requireMethod('POST');
$deviceId = Api::requireDeviceId();

Listener::unlinkDevice(Database::connection(), $deviceId);
Api::json(200, ['ok' => true]);
