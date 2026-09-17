<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;

header('Content-Type: application/json; charset=utf-8');

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode nicht erlaubt.']);
    exit;
}

$relPath = (string) ($_POST['path'] ?? '');
$publishedAbsPath = resolve_upload_path($relPath);
if ($publishedAbsPath === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Bild nicht gefunden.']);
    exit;
}

$uploaded = $_FILES['image'] ?? null;
if (!$uploaded || $uploaded['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Kein Bild empfangen.']);
    exit;
}

// Nur echte Bilder akzeptieren, keine beliebigen Dateien - der Editor exportiert
// immer JPEG, aber die Endung der Zieldatei (z.B. .png) bleibt bewusst erhalten,
// damit alle bisherigen Referenzen (DB, andere Seiten) unveraendert weiter
// funktionieren; der Bildinhalt wird also als JPEG in eine ggf. anders benannte
// Datei geschrieben, was fuer <img>-Tags unproblematisch ist.
$mime = mime_content_type($uploaded['tmp_name']);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiges Bildformat.']);
    exit;
}

$nonDestructive = ($_POST['nondestructive'] ?? '0') === '1';

if (!$nonDestructive) {
    // Aeltere Fotos ohne separates Original (siehe admin/photo-editor.php) -
    // bleibt beim alten Verhalten: das gesendete, bereits flach zusammen-
    // gerechnete Bild wird direkt zur veroeffentlichten Datei.
    if (!move_uploaded_file($uploaded['tmp_name'], $publishedAbsPath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Datei konnte nicht gespeichert werden.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// Nicht-destruktiver Fall: das gesendete Bild ist Original+Aufkleber (OHNE
// Wasserzeichen, das zeichnet der Editor bewusst nie mit) - Original bleibt
// unangetastet, nur die veroeffentlichte Datei wird aus diesem Stand +
// frischem Wasserzeichen neu erzeugt. Die Aufkleber-Liste wird zusaetzlich
// als JSON gespeichert, damit sie beim naechsten Bearbeiten wieder als
// bewegliche Objekte startet.
$originalRelPath = original_path_for_relative($relPath);
$originalAbsPath = resolve_upload_path($originalRelPath);
if ($originalAbsPath === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Original nicht gefunden.']);
    exit;
}

if (!apply_mic_watermark_copy($uploaded['tmp_name'], $publishedAbsPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Bild konnte nicht verarbeitet werden.']);
    exit;
}

$stickersRaw = (string) ($_POST['stickers'] ?? '[]');
$stickersDecoded = json_decode($stickersRaw, true);
if (!is_array($stickersDecoded)) {
    $stickersDecoded = [];
}
// Nur die erwarteten Felder uebernehmen (kein Vertrauen in beliebige vom
// Client geschickte JSON-Struktur) und auf reine Zahlen/Strings begrenzen.
$sanitizedStickers = [];
foreach ($stickersDecoded as $sticker) {
    if (!is_array($sticker) || !isset($sticker['emoji'], $sticker['x'], $sticker['y'], $sticker['size'])) {
        continue;
    }
    $sanitizedStickers[] = [
        'emoji' => (string) $sticker['emoji'],
        'x' => (float) $sticker['x'],
        'y' => (float) $sticker['y'],
        'size' => (float) $sticker['size'],
    ];
}

$stickersAbsPath = resolve_upload_write_path(stickers_json_path_for_relative($relPath));
if ($stickersAbsPath !== null) {
    $stickersDir = dirname($stickersAbsPath);
    if (!is_dir($stickersDir)) {
        mkdir($stickersDir, 0755, true);
    }
    file_put_contents($stickersAbsPath, json_encode($sanitizedStickers));
}

echo json_encode(['ok' => true]);
