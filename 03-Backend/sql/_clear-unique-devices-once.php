<?php
declare(strict_types=1);

// Einmaliges Skript: loescht alle bisher gespeicherten Pruefwerte fuer "eindeutige Hoerer"
// (episode_unique_devices). Sie wurden ohne Einwilligung erhoben, die nach § 25 TDDDG
// noetig ist, weil die Zaehlung ein Geraet ueber die gespeicherte Installations-Kennung
// wiedererkennt. Die Tabelle selbst bleibt - ab App-Version 2.0.0 wird sie wieder
// befuellt, dann nur fuer Geraete mit Einwilligung.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-clearunique-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    $vorher = (int) $pdo->query('SELECT COUNT(*) FROM episode_unique_devices')->fetchColumn();
    $pdo->exec('DELETE FROM episode_unique_devices');
    $nachher = (int) $pdo->query('SELECT COUNT(*) FROM episode_unique_devices')->fetchColumn();
    echo "OK: {$vorher} Pruefwert(e) geloescht, verbleibend: {$nachher}.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
