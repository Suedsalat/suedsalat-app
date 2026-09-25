<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\ListenerContent;

header('Content-Type: application/json; charset=utf-8');

ApiAuth::requireDeviceToken();

$pdo = Database::connection();
// Name live aus dem Konto (siehe ListenerContent); ausgeblendete Fotos (Kontoloeschung in der
// Rueckkehrfrist, spaeter auch Meldungen) erscheinen nicht.
$stmt = $pdo->query('SELECT ph.id, ph.image_path, ph.media_type, ph.description,
                      ' . ListenerContent::displayNameSql('ph', 'submitted_by_name') . ' AS submitted_by_name, ph.published_at
                      FROM photos ph ' . ListenerContent::joinSql('ph') . '
                      WHERE ph.hidden_at IS NULL
                      ORDER BY ph.published_at DESC');

echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
