<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerContent;
use Suedsalat\Moderation;

header('Content-Type: application/json; charset=utf-8');

$claims = ApiAuth::requireDeviceToken();

$pdo = Database::connection();
// Wer schaut? Fotos von Nutzern, die der Betrachter ausgeblendet hat, fehlen fuer ihn.
$viewer = Listener::forDevice($pdo, isset($claims['sub']) ? (int) $claims['sub'] : null);

// Name live aus dem Konto (siehe ListenerContent); ausgeblendete Fotos (Kontoloeschung in der
// Rueckkehrfrist, Meldungen) erscheinen nicht.
$stmt = $pdo->query('SELECT ph.id, ph.image_path, ph.media_type, ph.description,
                      ' . ListenerContent::displayNameSql('ph', 'submitted_by_name') . ' AS submitted_by_name, ph.published_at, ph.listener_id
                      FROM photos ph ' . ListenerContent::joinSql('ph') . '
                      WHERE ph.hidden_at IS NULL' . Moderation::viewerFilterSql('ph', $viewer !== null ? (int) $viewer['id'] : null) . '
                      ORDER BY ph.published_at DESC');

echo json_encode(ListenerContent::markOwn($stmt->fetchAll(), $viewer !== null ? (int) $viewer['id'] : null),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
