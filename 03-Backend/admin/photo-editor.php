<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$currentAdminRole = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
$currentAdminRole->execute([':id' => $adminId]);
$isOwner = $currentAdminRole->fetchColumn() === 'owner';

// Nur Bilder unterhalb von uploads/ duerfen bearbeitet werden (siehe
// resolve_upload_path() in config/config.php) - verhindert, dass ueber den
// path-Parameter beliebige Dateien auf dem Server angezeigt/ueberschrieben
// werden koennen.
$relPath = (string) ($_GET['path'] ?? '');
$absPath = resolve_upload_path($relPath);
if ($absPath === null) {
    http_response_code(404);
    die('Bild nicht gefunden.');
}

// "Zurueck"-Ziel: nur interne Admin-Seiten erlaubt (kein offener Redirect).
$returnUrl = (string) ($_GET['return'] ?? (BASE_PATH . '/admin/gallery.php'));
if (!str_starts_with($returnUrl, BASE_PATH . '/admin/')) {
    $returnUrl = BASE_PATH . '/admin/gallery.php';
}

// Fotos, die ueber die neuen Upload-Pipelines veroeffentlicht wurden, haben
// ein separates, unangetastetes Original (originals/<datei>) - der Editor
// bearbeitet IMMER dieses Original, nie das veroeffentlichte (mit
// Wasserzeichen versehene) Bild, damit Aufkleber jederzeit wieder verschoben/
// entfernt werden koennen, statt sich mit jedem Speichern fester "einzubrennen".
// Aeltere Fotos von VOR dieser Funktion haben noch kein solches Original -
// fuer die bleibt es (nur fuer dieses eine Mal) beim alten, direkten
// Bearbeiten der veroeffentlichten Datei.
$originalRelPath = original_path_for_relative($relPath);
$originalAbsPath = resolve_upload_path($originalRelPath);
$hasOriginal = $originalAbsPath !== null;

$imageUrl = UPLOAD_URL_BASE . '/' . ($hasOriginal ? $originalRelPath : $relPath);

$existingStickers = [];
if ($hasOriginal) {
    $stickersAbsPath = resolve_upload_path(stickers_json_path_for_relative($relPath));
    if ($stickersAbsPath !== null) {
        $decoded = json_decode((string) file_get_contents($stickersAbsPath), true);
        if (is_array($decoded)) {
            $existingStickers = $decoded;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Foto bearbeiten – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
    <style>
        .editor-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 14px; }
        .emoji-picker { display: flex; gap: 6px; flex-wrap: wrap; }
        .emoji-picker button {
            font-size: 1.4rem; line-height: 1; padding: 6px 10px; background: #fff; color: initial;
            border: 2px solid transparent; border-radius: var(--radius-input); cursor: pointer;
        }
        .emoji-picker button.is-selected { border-color: var(--color-primary); background: rgba(119,181,56,0.15); }
        .editor-canvas-wrap { max-width: 100%; overflow: auto; border: 1px solid rgba(0,0,0,0.15); border-radius: var(--radius-input); background: repeating-conic-gradient(#ddd 0% 25%, #eee 0% 50%) 50% / 20px 20px; }
        #editorCanvas { display: block; max-width: 100%; height: auto; cursor: crosshair; touch-action: none; }
        .editor-hint { font-size: 0.85rem; color: #666; margin: 8px 0 16px; }
        .editor-save-status { font-size: 0.9rem; font-weight: 700; margin-left: 8px; }
    </style>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Foto bearbeiten</h1>
    <p class="editor-hint">
        Emoji unten auswählen, dann aufs Bild klicken, um es dort abzulegen (z. B. über ein Gesicht) - Kindesrechte gehen vor. Aufkleber lassen sich anschließend verschieben (ziehen), über den Anfasser unten rechts vergrößern/verkleinern, und über "Löschen" oder die Entf-Taste wieder entfernen.
        <?php if ($hasOriginal): ?>
            Das unveränderte Originalfoto bleibt dabei immer erhalten - du kannst die Aufkleber jederzeit später wieder anpassen oder entfernen.
        <?php else: ?>
            <strong>Hinweis:</strong> Dieses Foto ist älter als diese Funktion, es gibt noch kein separates Original dazu. Diesmal wird direkt im sichtbaren Bild gespeichert (danach nicht mehr rückgängig zu machen) - ab jetzt neu hochgeladene Fotos bleiben dagegen dauerhaft nachbearbeitbar.
        <?php endif; ?>
    </p>

    <div class="editor-toolbar">
        <div class="emoji-picker" id="emojiPicker">
            <button type="button" data-emoji="😊" class="is-selected">😊</button>
            <button type="button" data-emoji="🙂">🙂</button>
            <button type="button" data-emoji="😄">😄</button>
            <button type="button" data-emoji="⭐">⭐</button>
            <button type="button" data-emoji="⬛">⬛</button>
            <button type="button" data-emoji="⚪">⚪</button>
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin:0;font-weight:normal;">
            Größe
            <input type="range" id="sizeSlider" min="20" max="600" value="80" style="width:140px;margin-top:0;" disabled>
        </label>
        <button type="button" id="deleteSelectedBtn" class="button-secondary">Ausgewählten Aufkleber löschen</button>
        <button type="button" id="clearAllBtn" class="button-secondary">Alle Aufkleber entfernen</button>
    </div>

    <div class="editor-canvas-wrap">
        <canvas id="editorCanvas"></canvas>
    </div>

    <div class="button-row" style="margin-top:16px;">
        <button type="button" id="saveBtn">Speichern</button>
        <a class="button button-secondary" style="margin-bottom:0;" href="<?= htmlspecialchars($returnUrl, ENT_QUOTES) ?>">Ohne Speichern zurück</a>
        <span id="saveStatus" class="editor-save-status"></span>
    </div>
</main>
<?php require __DIR__ . '/partials/sidebar-close.php'; ?>
<script src="<?= BASE_PATH ?>/admin/assets/photo-editor.js?v=<?= @filemtime(__DIR__ . '/assets/photo-editor.js') ?>"></script>
<script>
    PhotoEditor.init({
        imageUrl: <?= json_encode($imageUrl) ?>,
        savePath: <?= json_encode($relPath) ?>,
        saveUrl: <?= json_encode(BASE_PATH . '/admin/photo-editor-save.php') ?>,
        returnUrl: <?= json_encode($returnUrl) ?>,
        nonDestructive: <?= json_encode($hasOriginal) ?>,
        existingStickers: <?= json_encode($existingStickers) ?>,
    });
</script>
</body>
</html>
