<?php
declare(strict_types=1);

// Bonus und Outtakes (App 2.0): nur fuer angemeldete Hoerer. Gaeste sehen den Bereich in der
// App gar nicht; kommt trotzdem eine Anfrage ohne Konto, gibt es 403 statt der Liste.
// Gesperrte Hoerer duerfen weiter hoeren (Sperre betrifft nur das Veroeffentlichen).

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\Listener;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur GET erlaubt.']);
    exit;
}

$claims = ApiAuth::requireDeviceToken();
$pdo = Database::connection();
$listener = Listener::forDevice($pdo, isset($claims['sub']) ? (int) $claims['sub'] : null);
if ($listener === null) {
    http_response_code(403);
    echo json_encode(['error' => 'Bonus und Outtakes gibt es mit einem kostenlosen Hörerkonto.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = $pdo->query('SELECT id, title, description, audio_url, published_at FROM bonus_content
                     WHERE published_at <= NOW() ORDER BY published_at DESC, id DESC')->fetchAll();

echo json_encode(array_map(static fn (array $r): array => [
    'id' => (int) $r['id'],
    'title' => $r['title'],
    'description' => $r['description'],
    'audio_url' => $r['audio_url'],
    'published_at' => $r['published_at'],
], $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
