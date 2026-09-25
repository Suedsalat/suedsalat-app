<?php
declare(strict_types=1);

// Wer ist an diesem Geraet angemeldet? listener = null heisst Gast.

require_once __DIR__ . '/../../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerApi as Api;

Api::requireMethod('GET');
$deviceId = Api::requireDeviceId();

$listener = Listener::forDevice(Database::connection(), $deviceId);
Api::json(200, ['listener' => $listener !== null ? Listener::publicProfile($listener) : null]);
