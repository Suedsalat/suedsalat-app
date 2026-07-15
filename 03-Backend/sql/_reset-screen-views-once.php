<?php
declare(strict_types=1);

// Einmaliges Wartungs-Skript: setzt die Nutzungsstatistik im Dashboard zurueck,
// indem alle Zeilen aus screen_views geloescht werden. Betrifft keine anderen Tabellen.
// Nach Gebrauch UNBEDINGT loeschen.

$secret = 'suedsalat-resetviews-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

$pdo = Database::connection();
$stmt = $pdo->query('SELECT COUNT(*) FROM screen_views');
$before = (int) $stmt->fetchColumn();

$pdo->exec('TRUNCATE TABLE screen_views');

echo "OK: $before Zeile(n) aus screen_views geloescht. Nutzungsstatistik ist zurueckgesetzt.\n";
