<?php
declare(strict_types=1);

// Outtakes (App 2.0, intern "bonus"): eigene Audiodateien mit Titel und Text - nicht aus dem RSS-Feed.
// Wie der Newsletter nur fuer den Owner (Thorsten): Menue-Link versteckt UND Seite serverseitig
// gesperrt. In der App sehen nur angemeldete Hoerer den Bereich (api/bonus.php).

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$currentAdminRole = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
$currentAdminRole->execute([':id' => $adminId]);
$isOwner = $currentAdminRole->fetchColumn() === 'owner';
if (!$isOwner) {
    header('Location: ' . BASE_PATH . '/admin/dashboard.php');
    exit;
}

const BONUS_MAX_BYTES = 24 * 1024 * 1024; // knapp unter dem Server-Limit von 25 MB (.user.ini)
const BONUS_AUDIO_TYPES = [
    'audio/mpeg' => 'mp3',
    'audio/mp3' => 'mp3',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
    'audio/m4a' => 'm4a',
    'audio/aac' => 'm4a',
    // Manche Server erkennen den MPEG-4-Container von .m4a als Video (siehe api/feedback.php).
    'video/mp4' => 'm4a',
];

/** Audiodatei speichern, liefert die volle Adresse oder null (mit Fehlertext). */
function bonus_store_audio(array $file, ?string &$error): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
        $error = 'Die Datei ist zu groß (höchstens 24 MB).';
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Bitte eine Audiodatei (MP3 oder M4A) auswählen.';
        return null;
    }
    if ($file['size'] > BONUS_MAX_BYTES) {
        $error = 'Die Datei ist zu groß (höchstens 24 MB).';
        return null;
    }
    $mime = (string) mime_content_type($file['tmp_name']);
    if (!isset(BONUS_AUDIO_TYPES[$mime])) {
        $error = 'Nur MP3- oder M4A-Dateien (erkannt wurde: ' . $mime . ').';
        return null;
    }
    $dir = UPLOAD_DIR . '/bonus';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Zufaelliger Name: die Adresse ist nicht zu erraten, obwohl uploads/ oeffentlich ist.
    $name = bin2hex(random_bytes(16)) . '.' . BONUS_AUDIO_TYPES[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        $error = 'Die Datei konnte nicht gespeichert werden.';
        return null;
    }
    return UPLOAD_URL_BASE . '/bonus/' . $name;
}

function bonus_delete_audio(string $url): void
{
    $rel = upload_url_to_relative_path($url);
    $path = $rel !== null ? resolve_upload_path($rel) : null;
    if ($path !== null && str_starts_with(str_replace('\\', '/', $rel), 'bonus/') && is_file($path)) {
        unlink($path);
    }
}

$error = null;
$showCreateForm = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $title = mb_substr(trim(normalize_input((string) ($_POST['title'] ?? ''))), 0, 200);
    $description = trim(normalize_input((string) ($_POST['description'] ?? ''))) ?: null;
    if ($title === '') {
        $error = 'Bitte einen Titel angeben.';
    } else {
        $url = bonus_store_audio($_FILES['audio'] ?? [], $error);
        if ($url !== null) {
            $pdo->prepare('INSERT INTO bonus_content (title, description, audio_url, created_by) VALUES (:t, :d, :u, :a)')
                ->execute([':t' => $title, ':d' => $description, ':u' => $url, ':a' => $adminId]);
            header('Location: ' . BASE_PATH . '/admin/bonus.php?angelegt=1#bonus-' . (int) $pdo->lastInsertId());
            exit;
        }
    }
    $showCreateForm = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $title = mb_substr(trim(normalize_input((string) ($_POST['title'] ?? ''))), 0, 200);
    $description = trim(normalize_input((string) ($_POST['description'] ?? ''))) ?: null;
    $stmt = $pdo->prepare('SELECT audio_url FROM bonus_content WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $alt = $stmt->fetchColumn();
    if ($alt === false) {
        $error = 'Diesen Eintrag gibt es nicht mehr.';
    } elseif ($title === '') {
        $error = 'Bitte einen Titel angeben.';
    } else {
        $url = (string) $alt;
        // Neue Datei nur, wenn eine ausgewaehlt wurde - sonst bleibt die bisherige.
        if (($_FILES['audio']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $neu = bonus_store_audio($_FILES['audio'], $error);
            if ($neu !== null) {
                bonus_delete_audio($url);
                $url = $neu;
            }
        }
        if ($error === null) {
            $pdo->prepare('UPDATE bonus_content SET title = :t, description = :d, audio_url = :u, updated_at = NOW() WHERE id = :id')
                ->execute([':t' => $title, ':d' => $description, ':u' => $url, ':id' => $id]);
            header('Location: ' . BASE_PATH . '/admin/bonus.php?gespeichert=1#bonus-' . $id);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_admin_delete_confirmation($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
        header('Location: ' . BASE_PATH . '/admin/bonus.php?delete_error=1');
        exit;
    }
    $id = (int) $_POST['delete_id'];
    $stmt = $pdo->prepare('SELECT audio_url FROM bonus_content WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $url = $stmt->fetchColumn();
    if ($url !== false) {
        bonus_delete_audio((string) $url);
        $pdo->prepare('DELETE FROM bonus_content WHERE id = :id')->execute([':id' => $id]);
    }
    header('Location: ' . BASE_PATH . '/admin/bonus.php?geloescht=1');
    exit;
}

$items = $pdo->query('SELECT * FROM bonus_content ORDER BY published_at DESC, id DESC')->fetchAll();
$editId = (int) ($_GET['edit'] ?? 0);
$notice = isset($_GET['angelegt']) ? 'Der Beitrag ist angelegt und sofort in der App.'
    : (isset($_GET['gespeichert']) ? 'Gespeichert.' : (isset($_GET['geloescht']) ? 'Gelöscht.' : null));
$deleteError = isset($_GET['delete_error']);
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Outtakes – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Outtakes</h1>
    <p style="font-size:0.9rem;color:#666;">Eigene Audiodateien, unabhängig vom Podcast-Feed. In der App sehen nur angemeldete Hörer diesen Bereich, Gäste nicht. Neue Beiträge erscheinen sofort, eine Push-Nachricht geht nicht raus.</p>

    <?php if ($notice): ?><p class="info"><?= $e($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= $e($error) ?></p><?php endif; ?>
    <?php if ($deleteError): ?><p class="error text-center">Falscher Code – nichts wurde gelöscht.</p><?php endif; ?>

    <button type="button" class="button" data-show-create-form="create-form" style="<?= $showCreateForm ? 'display:none;' : '' ?>">+ Outtake anlegen</button>
    <div id="create-form" style="<?= $showCreateForm ? '' : 'display:none;' ?>">
        <button type="button" class="button-secondary" data-hide-create-form="create-form">- Outtake anlegen</button>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create">
            <label>Titel <input type="text" name="title" maxlength="200" required value="<?= $showCreateForm ? $e($_POST['title'] ?? '') : '' ?>"></label>
            <label>Text dazu (optional) <textarea name="description" rows="4"><?= $showCreateForm ? $e($_POST['description'] ?? '') : '' ?></textarea></label>
            <label>Audiodatei (MP3 oder M4A, höchstens 24 MB) <input type="file" name="audio" accept="audio/mpeg,audio/mp4,audio/x-m4a,.mp3,.m4a" required></label>
            <button type="submit">Anlegen</button>
        </form>
    </div>

    <div class="table-scroll">
    <table>
        <thead><tr><th>Titel</th><th>Text</th><th>Anhören</th><th>Seit</th><th>Aktionen</th></tr></thead>
        <tbody>
        <?php foreach ($items as $b): ?>
            <tr id="bonus-<?= (int) $b['id'] ?>">
            <?php if ($editId === (int) $b['id']): ?>
                <td colspan="5">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                        <label>Titel <input type="text" name="title" maxlength="200" required value="<?= $e($b['title']) ?>"></label>
                        <label>Text dazu (optional) <textarea name="description" rows="4"><?= $e($b['description']) ?></textarea></label>
                        <label>Andere Audiodatei (leer lassen = bisherige behalten) <input type="file" name="audio" accept="audio/mpeg,audio/mp4,audio/x-m4a,.mp3,.m4a"></label>
                        <div class="actions">
                            <button type="submit">Speichern</button>
                            <a class="button button-secondary" href="<?= BASE_PATH ?>/admin/bonus.php#bonus-<?= (int) $b['id'] ?>">Abbrechen</a>
                        </div>
                    </form>
                </td>
            <?php else: ?>
                <td><strong><?= $e($b['title']) ?></strong></td>
                <td><?= nl2br($e($b['description'])) ?></td>
                <td><audio controls preload="none" src="<?= $e($b['audio_url']) ?>"></audio></td>
                <td><?= $e(date('d.m.Y', strtotime((string) $b['published_at']))) ?></td>
                <td>
                    <div class="actions">
                        <a class="button" href="<?= BASE_PATH ?>/admin/bonus.php?edit=<?= (int) $b['id'] ?>#bonus-<?= (int) $b['id'] ?>">Bearbeiten</a>
                        <a class="button" download href="<?= $e($b['audio_url']) ?>">Download</a>
                        <form method="post" onsubmit="return false;">
                            <input type="hidden" name="delete_id" value="<?= (int) $b['id'] ?>">
                            <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Der Beitrag „<?= $e(addslashes($b['title'])) ?>“ und seine Audiodatei werden dauerhaft gelöscht.')">Löschen</button>
                        </form>
                    </div>
                </td>
            <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?>
            <tr><td colspan="5">Noch keine Outtakes.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</main>
<?php require __DIR__ . '/partials/confirm-modal.php'; ?>
<?php require __DIR__ . '/partials/sidebar-close.php'; ?>
<script src="<?= BASE_PATH ?>/admin/assets/confirm-delete.js?v=<?= @filemtime(__DIR__ . '/assets/confirm-delete.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/toggle-create-form.js?v=<?= @filemtime(__DIR__ . '/assets/toggle-create-form.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/scroll-restore.js?v=<?= @filemtime(__DIR__ . '/assets/scroll-restore.js') ?>"></script>
</body>
</html>
