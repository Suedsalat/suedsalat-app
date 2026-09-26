<?php
declare(strict_types=1);

// Testbereich: Folgen (Titel, Beschreibung mit Kapiteln, ...) aus der Live-RSS nachziehen - dafuer
// laeuft hier einmal der normale Cronjob (cron/sync-episodes.php). Neue Folgen gibt es im Testbereich
// nicht, also auch keine Push-Nachricht. Nur im Ordner APP-test. Danach vom Server loeschen.

$secret = 'suedsalat-testfolgen-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}
if (basename(dirname(__DIR__)) !== 'APP-test') {
    http_response_code(403);
    die('Nur im Testbereich.');
}

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
$_GET['secret'] = (string) CRON_SECRET;
require __DIR__ . '/../cron/sync-episodes.php';
