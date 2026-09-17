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
$absPath = resolve_upload_path($relPath);
if ($absPath === null) {
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

if (!move_uploaded_file($uploaded['tmp_name'], $absPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Datei konnte nicht gespeichert werden.']);
    exit;
}

echo json_encode(['ok' => true]);
