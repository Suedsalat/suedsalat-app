<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\Mailer;
use Suedsalat\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

ApiAuth::requireDeviceToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt.']);
    exit;
}

$ip = ApiAuth::clientIp();
if (RateLimiter::tooMany('feedback_submit', $ip, 20, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Zu viele Anfragen. Bitte spaeter erneut versuchen.']);
    exit;
}
RateLimiter::record('feedback_submit', $ip);

$senderName = trim((string) ($_POST['sender_name'] ?? ''));
$type = trim((string) ($_POST['type'] ?? 'allgemein'));
$message = trim((string) ($_POST['message'] ?? ''));

$allowedTypes = ['allgemein', 'termin_tipp', 'foto_vorschlag', 'kino_tipp', 'sprachnachricht', 'frage'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'allgemein';
}

if ($type !== 'sprachnachricht' && $message === '') {
    http_response_code(422);
    echo json_encode(['error' => 'message ist erforderlich.']);
    exit;
}
if (mb_strlen($message) > 2000) {
    http_response_code(422);
    echo json_encode(['error' => 'Nachricht ist zu lang (max. 2000 Zeichen).']);
    exit;
}
if (mb_strlen($senderName) > 100) {
    $senderName = mb_substr($senderName, 0, 100);
}

$consentPublish = false;
if ($type === 'sprachnachricht') {
    if ($senderName === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Für eine Sprachnachricht wird dein Name benötigt.']);
        exit;
    }
    $consentPublish = ($_POST['consent_publish'] ?? '') === '1';
    if (empty($_FILES['media']['name'])) {
        http_response_code(422);
        echo json_encode(['error' => 'Für eine Sprachnachricht wird eine Aufnahme benötigt.']);
        exit;
    }
}

$suggestedDate = null;
if ($type === 'termin_tipp') {
    $rawDate = trim((string) ($_POST['suggested_date'] ?? ''));
    $parsed = DateTime::createFromFormat('Y-m-d', $rawDate);
    if (!$parsed || $parsed->format('Y-m-d') !== $rawDate) {
        http_response_code(422);
        echo json_encode(['error' => 'Für einen Termintipp wird ein gültiges Datum benötigt.']);
        exit;
    }
    $suggestedDate = $rawDate;
}

if ($type === 'foto_vorschlag' && empty($_FILES['media']['name'])) {
    http_response_code(422);
    echo json_encode(['error' => 'Für eine Fotoempfehlung wird ein Foto benötigt.']);
    exit;
}

$allowedImageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$allowedVideoTypes = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm'];
$allowedAudioTypes = [
    'audio/mp4' => 'm4a',
    'audio/m4a' => 'm4a',
    'audio/x-m4a' => 'm4a',
    'audio/aac' => 'm4a',
];
$maxImageSizeBytes = 8 * 1024 * 1024;
$maxVideoSizeBytes = 20 * 1024 * 1024;
$maxAudioSizeBytes = 15 * 1024 * 1024;

$imageUrl = null;
$mediaType = 'image';
$additionalPhotoUrls = [];

// Mehrere Fotos: das Formularfeld heisst dann "media[]", PHP liefert $_FILES['media']['name']
// dann als Array statt als einzelnen String. Nur Fotos werden auf diesem Weg unterstuetzt
// (kein Video/Audio) - jedes wird einzeln validiert und gespeichert, alle Pfade landen in
// feedback_media; das erste zusaetzlich in image_path fuer Abwaertskompatibilitaet.
if (!empty($_FILES['media']['name']) && is_array($_FILES['media']['name'])) {
    $feedbackDir = UPLOAD_DIR . '/feedback';
    if (!is_dir($feedbackDir)) {
        mkdir($feedbackDir, 0755, true);
    }
    $fileCount = count($_FILES['media']['name']);
    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['media']['error'][$i] !== UPLOAD_ERR_OK) {
            http_response_code(422);
            echo json_encode(['error' => 'Upload fehlgeschlagen.']);
            exit;
        }
        $tmpName = $_FILES['media']['tmp_name'][$i];
        $mime = mime_content_type($tmpName);
        if (!isset($allowedImageTypes[$mime])) {
            http_response_code(422);
            echo json_encode(['error' => 'Bei mehreren Dateien sind nur JPG, PNG oder WebP erlaubt.']);
            exit;
        }
        if ($_FILES['media']['size'][$i] > $maxImageSizeBytes) {
            http_response_code(422);
            echo json_encode(['error' => 'Ein Foto ist zu groß (max. 8 MB).']);
            exit;
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $allowedImageTypes[$mime];
        move_uploaded_file($tmpName, $feedbackDir . '/' . $filename);
        $additionalPhotoUrls[] = UPLOAD_URL_BASE . '/feedback/' . $filename;
    }
    $mediaType = 'image';
    $imageUrl = $additionalPhotoUrls[0] ?? null;
} elseif (!empty($_FILES['media']['name'])) {
    $file = $_FILES['media'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['error' => 'Upload fehlgeschlagen.']);
        exit;
    }

    $mime = mime_content_type($file['tmp_name']);
    // Sprachnachrichten (M4A/AAC-Container) werden von mime_content_type() auf manchen
    // Servern als video/mp4 statt audio/mp4 erkannt, da der MPEG-4-Container identisch
    // aufgebaut ist. Bei diesem Typ ist eine Video-Datei ohnehin nie moeglich, daher wird
    // video/mp4 hier bewusst als Audio behandelt statt in den Video-Zweig zu fallen.
    if ($type === 'sprachnachricht' && ($mime === 'video/mp4' || isset($allowedAudioTypes[$mime]))) {
        $mediaType = 'audio';
        if ($file['size'] > $maxAudioSizeBytes) {
            http_response_code(422);
            echo json_encode(['error' => 'Aufnahme ist zu groß (max. 15 MB).']);
            exit;
        }
        $extension = 'm4a';
    } elseif (isset($allowedVideoTypes[$mime])) {
        $mediaType = 'video';
        if ($file['size'] > $maxVideoSizeBytes) {
            http_response_code(422);
            echo json_encode(['error' => 'Video ist zu groß (max. 20 MB).']);
            exit;
        }
        $extension = $allowedVideoTypes[$mime];
    } elseif (isset($allowedImageTypes[$mime])) {
        $mediaType = 'image';
        if ($file['size'] > $maxImageSizeBytes) {
            http_response_code(422);
            echo json_encode(['error' => 'Foto ist zu groß (max. 8 MB).']);
            exit;
        }
        $extension = $allowedImageTypes[$mime];
    } elseif (isset($allowedAudioTypes[$mime])) {
        $mediaType = 'audio';
        if ($file['size'] > $maxAudioSizeBytes) {
            http_response_code(422);
            echo json_encode(['error' => 'Aufnahme ist zu groß (max. 15 MB).']);
            exit;
        }
        $extension = $allowedAudioTypes[$mime];
    } else {
        http_response_code(422);
        echo json_encode(['error' => 'Nur JPG, PNG, WebP (Foto), MP4, MOV, WebM (Video) oder M4A/AAC (Audio) sind erlaubt.']);
        exit;
    }

    $feedbackDir = UPLOAD_DIR . '/feedback';
    if (!is_dir($feedbackDir)) {
        mkdir($feedbackDir, 0755, true);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    move_uploaded_file($file['tmp_name'], $feedbackDir . '/' . $filename);
    $imageUrl = UPLOAD_URL_BASE . '/feedback/' . $filename;
}

$pdo = Database::connection();
$stmt = $pdo->prepare(
    'INSERT INTO feedback_messages (sender_name, type, message, suggested_date, image_path, media_type, consent_publish)
     VALUES (:sender_name, :type, :message, :suggested_date, :image_path, :media_type, :consent_publish)'
);
$stmt->execute([
    ':sender_name' => $senderName !== '' ? $senderName : null,
    ':type' => $type,
    ':message' => $message,
    ':suggested_date' => $suggestedDate,
    ':image_path' => $imageUrl,
    ':media_type' => $mediaType,
    ':consent_publish' => $consentPublish ? 1 : 0,
]);

if (!empty($additionalPhotoUrls)) {
    $feedbackId = (int) $pdo->lastInsertId();
    $mediaStmt = $pdo->prepare('INSERT INTO feedback_media (feedback_message_id, image_path) VALUES (:fid, :path)');
    foreach ($additionalPhotoUrls as $photoUrl) {
        $mediaStmt->execute([':fid' => $feedbackId, ':path' => $photoUrl]);
    }
}

$typeLabels = [
    'termin_tipp' => 'Veranstaltungstipp',
    'foto_vorschlag' => 'Fotoempfehlung',
    'kino_tipp' => 'Kino- und Filmtipp',
    'sprachnachricht' => 'Sprachnachricht',
    'allgemein' => 'Allgemeines Feedback',
    'frage' => 'Frage',
];
$displayName = $senderName !== '' ? $senderName : 'Anonym';
$activityLink = APP_URL . '/admin/feedback.php';

$emailBody = "<p>Neue Nachricht über das Feedback-Formular der App:</p>"
    . "<p><strong>Von:</strong> " . htmlspecialchars($displayName, ENT_QUOTES) . "<br>"
    . "<strong>Typ:</strong> " . htmlspecialchars($typeLabels[$type] ?? 'Allgemeines Feedback', ENT_QUOTES);
if ($type === 'sprachnachricht') {
    $emailBody .= "<br><strong>Veröffentlichung im Podcast:</strong> " . ($consentPublish ? 'Ja' : 'Nein');
}
$emailBody .= "</p>"
    . "<p>" . nl2br(htmlspecialchars($message, ENT_QUOTES)) . "</p>"
    . "<p><a href=\"{$activityLink}\">Im Admin-Bereich ansehen</a></p>";

try {
    $admins = $pdo->query('SELECT name, email FROM admins')->fetchAll();
    foreach ($admins as $adminRow) {
        Mailer::send(
            $adminRow['email'],
            $adminRow['name'],
            'Neues Feedback – Südsalat',
            $emailBody
        );
    }
} catch (\Throwable $e) {
    error_log('Feedback-Benachrichtigung fehlgeschlagen: ' . $e->getMessage());
}

echo json_encode(['status' => 'ok']);
