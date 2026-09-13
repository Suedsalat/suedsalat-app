<?php
declare(strict_types=1);

// Rein anonyme Zaehlung, wie oft/wann/auf welcher Plattform eine Folge
// abgespielt wurde (fuer die Statistik im Admin-Bereich). Es wird bewusst
// NICHTS gespeichert, das Rueckschluesse auf einzelne Nutzer zulaesst:
// - keine IP, kein Klartext-Geraete-Token, keine Sitzungs-ID
// - die "Reichweite" (eindeutige Geraete) wird nur ueber einen Einweg-Hash aus
//   Geraete-ID + Folge + Tag gezaehlt, der sich NICHT folgen- oder
//   tagesuebergreifend einem Geraet zuordnen laesst (siehe episode_unique_devices)

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$claims = ApiAuth::requireDeviceToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.']);
    exit;
}

$ip = ApiAuth::clientIp();
if (RateLimiter::tooMany('track_episode_play', $ip, 60, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Zu viele Anfragen.']);
    exit;
}
RateLimiter::record('track_episode_play', $ip);

$episodeGuid = trim((string) ($_POST['episode_guid'] ?? ''));
if ($episodeGuid === '') {
    http_response_code(422);
    echo json_encode(['error' => 'episode_guid fehlt.']);
    exit;
}

$pdo = Database::connection();

// Nur zaehlen, wenn die Folge tatsaechlich bekannt ist - verhindert, dass
// beliebige Zeichenketten den Fremdschluessel-Constraint verletzen bzw. Muell
// in die Tabelle wandert.
$existsStmt = $pdo->prepare('SELECT 1 FROM episodes_cache WHERE guid = :guid');
$existsStmt->execute([':guid' => $episodeGuid]);
if ($existsStmt->fetchColumn() === false) {
    http_response_code(422);
    echo json_encode(['error' => 'Unbekannte Folge.']);
    exit;
}

$hour = (int) date('G');
$stmt = $pdo->prepare(
    'INSERT INTO episode_play_counts (episode_guid, day, hour, count) VALUES (:guid, CURDATE(), :hour, 1)
     ON DUPLICATE KEY UPDATE count = count + 1'
);
$stmt->execute([':guid' => $episodeGuid, ':hour' => $hour]);

// Plattform + Reichweite nur erfassbar, wenn ein gueltiges Geraete-Token vorliegt
// (im Soft-Auth-Uebergang faellt das bei sehr alten App-Versionen weg - dann
// zaehlt nur der reine Start-Zaehler oben, ohne Plattform/Reichweite-Aufschluesselung).
$deviceId = $claims['sub'] ?? null;
if ($deviceId !== null) {
    $platform = $pdo->prepare('SELECT platform FROM devices WHERE id = :id');
    $platform->execute([':id' => $deviceId]);
    $platformValue = $platform->fetchColumn();

    if ($platformValue !== false) {
        $stmt = $pdo->prepare(
            'INSERT INTO episode_play_platform (episode_guid, day, platform, count) VALUES (:guid, CURDATE(), :platform, 1)
             ON DUPLICATE KEY UPDATE count = count + 1'
        );
        $stmt->execute([':guid' => $episodeGuid, ':platform' => $platformValue]);
    }

    $deviceHash = hash('sha256', $deviceId . '|' . $episodeGuid . '|' . date('Y-m-d') . '|' . APP_SECRET);
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO episode_unique_devices (episode_guid, day, device_hash) VALUES (:guid, CURDATE(), :hash)'
    );
    $stmt->execute([':guid' => $episodeGuid, ':hash' => $deviceHash]);
}

// Android Auto/CarPlay-Kontext (siehe CarContextService in der App) - rein
// informativ, kein Geraete-/Fahrzeug-Identifier, nur ein Tageszaehler.
$carContext = trim((string) ($_POST['car_context'] ?? ''));
if (in_array($carContext, ['android_auto', 'carplay'], true)) {
    $stmt = $pdo->prepare(
        'INSERT INTO episode_play_car_context (episode_guid, day, context, count) VALUES (:guid, CURDATE(), :context, 1)
         ON DUPLICATE KEY UPDATE count = count + 1'
    );
    $stmt->execute([':guid' => $episodeGuid, ':context' => $carContext]);
}

echo json_encode(['status' => 'ok']);
