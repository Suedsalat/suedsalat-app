<?php
declare(strict_types=1);

// Rein anonyme Zaehlung, welcher App-Bereich geoeffnet wurde (fuer die Statistik
// im Admin-Dashboard). Es wird bewusst NICHTS gespeichert, das Rueckschluesse auf
// einzelne Nutzer zulaesst - keine IP, kein Geraete-Token, keine Sitzungs-ID,
// nur ein taeglicher Zaehler pro Bereich.

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.']);
    exit;
}

$allowedScreens = ['start', 'episodes', 'events', 'movie_tips', 'gallery', 'feedback'];
$screen = trim((string) ($_POST['screen'] ?? ''));

if (!in_array($screen, $allowedScreens, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Unbekannter Bereich.']);
    exit;
}

$pdo = Database::connection();
$stmt = $pdo->prepare(
    'INSERT INTO screen_views (screen, day, count) VALUES (:screen, CURDATE(), 1)
     ON DUPLICATE KEY UPDATE count = count + 1'
);
$stmt->execute([':screen' => $screen]);

echo json_encode(['status' => 'ok']);
