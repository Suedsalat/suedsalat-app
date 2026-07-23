<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;
use Suedsalat\FcmSender;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$currentAdminRole = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
$currentAdminRole->execute([':id' => $adminId]);
$isOwner = $currentAdminRole->fetchColumn() === 'owner';

$error = null;
$allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$allowedVideoTypes = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm'];
$maxSizeBytes = 8 * 1024 * 1024;
$maxVideoSizeBytes = 20 * 1024 * 1024;

/** Prueft eine hochgeladene Foto- oder Video-Datei und gibt [extension, media_type] zurueck,
 *  oder null bei Fehler (setzt dann $error). */
function validate_gallery_upload(
    array $file,
    array $allowedImageTypes,
    array $allowedVideoTypes,
    int $maxImageBytes,
    int $maxVideoBytes,
    ?string &$error
): ?array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload fehlgeschlagen.';
        return null;
    }
    $mime = mime_content_type($file['tmp_name']);
    if (isset($allowedVideoTypes[$mime])) {
        if ($file['size'] > $maxVideoBytes) {
            $error = 'Video ist zu groß (max. 20 MB).';
            return null;
        }
        return ['extension' => $allowedVideoTypes[$mime], 'media_type' => 'video'];
    }
    if (isset($allowedImageTypes[$mime])) {
        if ($file['size'] > $maxImageBytes) {
            $error = 'Foto ist zu groß (max. 8 MB).';
            return null;
        }
        return ['extension' => $allowedImageTypes[$mime], 'media_type' => 'photo'];
    }
    $error = 'Nur JPG, PNG, WebP (Foto) oder MP4, MOV, WebM (Video) sind erlaubt.';
    return null;
}

// Loeschen - Passwort-Bestaetigung erforderlich.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_admin_password($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
        header('Location: ' . BASE_PATH . '/admin/gallery.php?delete_error=1');
        exit;
    }
    $id = (int) $_POST['delete_id'];
    $stmt = $pdo->prepare('SELECT image_path FROM photos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $photo = $stmt->fetch();
    if ($photo) {
        $localPath = UPLOAD_DIR . '/gallery/' . basename($photo['image_path']);
        if (is_file($localPath)) {
            unlink($localPath);
        }
        $pdo->prepare('DELETE FROM photos WHERE id = :id')->execute([':id' => $id]);
    }
    header('Location: ' . BASE_PATH . '/admin/gallery.php');
    exit;
}

// Aus einem Feedback-Fotovorschlag uebernehmen (Beschreibung vorher noch anpassbar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_feedback_id'])) {
    $feedbackId = (int) $_POST['import_feedback_id'];
    $description = trim((string) ($_POST['description'] ?? '')) ?: null;

    $stmt = $pdo->prepare('SELECT * FROM feedback_messages WHERE id = :id');
    $stmt->execute([':id' => $feedbackId]);
    $fb = $stmt->fetch();

    if (!$fb || empty($fb['image_path']) || !empty($fb['photo_imported_at'])) {
        $error = 'Dieses Foto wurde bereits übernommen oder existiert nicht mehr.';
    } else {
        $sourceLocal = UPLOAD_DIR . '/feedback/' . basename($fb['image_path']);
        $galleryDir = UPLOAD_DIR . '/gallery';
        if (!is_dir($galleryDir)) {
            mkdir($galleryDir, 0755, true);
        }
        $extension = pathinfo($sourceLocal, PATHINFO_EXTENSION);
        $newFilename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destLocal = $galleryDir . '/' . $newFilename;

        if (!is_file($sourceLocal) || !copy($sourceLocal, $destLocal)) {
            $error = 'Datei konnte nicht übernommen werden.';
        } else {
            $imageUrl = UPLOAD_URL_BASE . '/gallery/' . $newFilename;
            $mediaType = ($fb['media_type'] ?? 'image') === 'video' ? 'video' : 'photo';
            $insert = $pdo->prepare(
                'INSERT INTO photos (image_path, media_type, description, created_by, created_via_feedback_id)
                 VALUES (:path, :media_type, :description, :created_by, :feedback_id)'
            );
            $insert->execute([
                ':path' => $imageUrl,
                ':media_type' => $mediaType,
                ':description' => $description,
                ':created_by' => $adminId,
                ':feedback_id' => $feedbackId,
            ]);
            $newPhotoId = (int) $pdo->lastInsertId();

            $update = $pdo->prepare(
                'UPDATE feedback_messages SET photo_imported_at = NOW(), status = "erledigt", handled_by = :admin_id, handled_at = NOW() WHERE id = :id'
            );
            $update->execute([':admin_id' => $adminId, ':id' => $feedbackId]);

            FcmSender::sendToAllDevices('Neues Foto in der Galerie', 'Schau es dir an!');
            header('Location: ' . BASE_PATH . '/admin/gallery.php#photo-' . $newPhotoId);
            exit;
        }
    }
}

// Aus einer Mehrfach-Foto-Einreichung EIN bestimmtes Foto uebernehmen (siehe feedback_media) -
// jedes Foto einzeln trackbar ueber imported_at, statt wie beim Einzel-Foto-Pfad oben nur
// einmal pro Feedback-Nachricht.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_feedback_media_id'])) {
    $mediaId = (int) $_POST['import_feedback_media_id'];
    $description = trim((string) ($_POST['description'] ?? '')) ?: null;

    $stmt = $pdo->prepare('SELECT * FROM feedback_media WHERE id = :id');
    $stmt->execute([':id' => $mediaId]);
    $mediaRow = $stmt->fetch();

    if (!$mediaRow || !empty($mediaRow['imported_at'])) {
        $error = 'Dieses Foto wurde bereits übernommen oder existiert nicht mehr.';
    } else {
        $sourceLocal = UPLOAD_DIR . '/feedback/' . basename($mediaRow['image_path']);
        $galleryDir = UPLOAD_DIR . '/gallery';
        if (!is_dir($galleryDir)) {
            mkdir($galleryDir, 0755, true);
        }
        $extension = pathinfo($sourceLocal, PATHINFO_EXTENSION);
        $newFilename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destLocal = $galleryDir . '/' . $newFilename;

        if (!is_file($sourceLocal) || !copy($sourceLocal, $destLocal)) {
            $error = 'Datei konnte nicht übernommen werden.';
        } else {
            $imageUrl = UPLOAD_URL_BASE . '/gallery/' . $newFilename;
            $insert = $pdo->prepare(
                'INSERT INTO photos (image_path, media_type, description, created_by, created_via_feedback_id)
                 VALUES (:path, "photo", :description, :created_by, :feedback_id)'
            );
            $insert->execute([
                ':path' => $imageUrl,
                ':description' => $description,
                ':created_by' => $adminId,
                ':feedback_id' => $mediaRow['feedback_message_id'],
            ]);
            $newPhotoId = (int) $pdo->lastInsertId();

            $update = $pdo->prepare('UPDATE feedback_media SET imported_at = NOW() WHERE id = :id');
            $update->execute([':id' => $mediaId]);

            // War das das letzte noch offene Foto dieser Nachricht, die Nachricht selbst
            // ebenfalls als erledigt markieren (konsistent zum Einzel-Foto-Pfad oben).
            $remaining = $pdo->prepare(
                'SELECT COUNT(*) FROM feedback_media WHERE feedback_message_id = :fid AND imported_at IS NULL'
            );
            $remaining->execute([':fid' => $mediaRow['feedback_message_id']]);
            if ((int) $remaining->fetchColumn() === 0) {
                $markDone = $pdo->prepare(
                    'UPDATE feedback_messages SET photo_imported_at = NOW(), status = "erledigt", handled_by = :admin_id, handled_at = NOW() WHERE id = :id'
                );
                $markDone->execute([':admin_id' => $adminId, ':id' => $mediaRow['feedback_message_id']]);
            }

            FcmSender::sendToAllDevices('Neues Foto in der Galerie', 'Schau es dir an!');
            header('Location: ' . BASE_PATH . '/admin/gallery.php#photo-' . $newPhotoId);
            exit;
        }
    }
}

// Bearbeiten (Beschreibung aendern, optional Foto ersetzen)
$editPhoto = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $id = (int) $_POST['edit_id'];
    $description = trim((string) ($_POST['description'] ?? '')) ?: null;

    $stmt = $pdo->prepare('SELECT * FROM photos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch();

    if (!$existing) {
        $error = 'Foto nicht gefunden.';
    } else {
        $imageUrl = $existing['image_path'];
        $mediaType = $existing['media_type'];

        if (!empty($_FILES['photo']['name'])) {
            $file = $_FILES['photo'];
            $result = validate_gallery_upload($file, $allowedTypes, $allowedVideoTypes, $maxSizeBytes, $maxVideoSizeBytes, $error);
            if ($result !== null) {
                $galleryDir = UPLOAD_DIR . '/gallery';
                if (!is_dir($galleryDir)) {
                    mkdir($galleryDir, 0755, true);
                }
                $filename = bin2hex(random_bytes(16)) . '.' . $result['extension'];
                move_uploaded_file($file['tmp_name'], $galleryDir . '/' . $filename);

                $oldLocalPath = UPLOAD_DIR . '/gallery/' . basename($existing['image_path']);
                if (is_file($oldLocalPath)) {
                    unlink($oldLocalPath);
                }
                $imageUrl = UPLOAD_URL_BASE . '/gallery/' . $filename;
                $mediaType = $result['media_type'];
            }
        }

        if ($error === null) {
            $update = $pdo->prepare('UPDATE photos SET image_path = :path, media_type = :media_type, description = :description WHERE id = :id');
            $update->execute([
                ':path' => $imageUrl,
                ':media_type' => $mediaType,
                ':description' => $description,
                ':id' => $id,
            ]);
            header('Location: ' . BASE_PATH . '/admin/gallery.php#photo-' . $id);
            exit;
        }

        $editPhoto = $existing;
    }
}

// Hochladen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo']) && !isset($_POST['edit_id'])) {
    $description = trim((string) ($_POST['description'] ?? '')) ?: null;
    $file = $_FILES['photo'];

    $result = validate_gallery_upload($file, $allowedTypes, $allowedVideoTypes, $maxSizeBytes, $maxVideoSizeBytes, $error);
    if ($result !== null) {
        $galleryDir = UPLOAD_DIR . '/gallery';
        if (!is_dir($galleryDir)) {
            mkdir($galleryDir, 0755, true);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $result['extension'];
        move_uploaded_file($file['tmp_name'], $galleryDir . '/' . $filename);

        $imageUrl = UPLOAD_URL_BASE . '/gallery/' . $filename;
        $stmt = $pdo->prepare(
            'INSERT INTO photos (image_path, media_type, description, created_by) VALUES (:path, :media_type, :description, :created_by)'
        );
        $stmt->execute([
            ':path' => $imageUrl,
            ':media_type' => $result['media_type'],
            ':description' => $description,
            ':created_by' => $adminId,
        ]);
        $newPhotoId = (int) $pdo->lastInsertId();
        FcmSender::sendToAllDevices('Neues Foto in der Galerie', 'Schau es dir an!');
        header('Location: ' . BASE_PATH . '/admin/gallery.php#photo-' . $newPhotoId);
        exit;
    }
}

if ($editPhoto === null && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM photos WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $editPhoto = $stmt->fetch() ?: null;
}

// Aus Feedback vorausgefuellte Uebernahme (GET) - nur wenn nicht gerade ein anderes Foto bearbeitet wird
$importFeedback = null;
$suggestedDescription = '';
if ($editPhoto === null && isset($_GET['import_feedback_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM feedback_messages WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['import_feedback_id']]);
    $importFeedback = $stmt->fetch() ?: null;
    if ($importFeedback && (!empty($importFeedback['photo_imported_at']) || empty($importFeedback['image_path']))) {
        $importFeedback = null;
    }
    if ($importFeedback) {
        $senderLabel = $importFeedback['sender_name'] ?: 'Anonym';
        $suggestedDescription = "von {$senderLabel}: {$importFeedback['message']}";
    }
}

// Aus einer Mehrfach-Foto-Einreichung EIN bestimmtes Foto vorausfuellen (GET)
$importFeedbackMedia = null;
if ($editPhoto === null && $importFeedback === null && isset($_GET['import_feedback_media_id'])) {
    $stmt = $pdo->prepare(
        'SELECT fm.*, f.sender_name, f.message
         FROM feedback_media fm
         JOIN feedback_messages f ON f.id = fm.feedback_message_id
         WHERE fm.id = :id'
    );
    $stmt->execute([':id' => (int) $_GET['import_feedback_media_id']]);
    $importFeedbackMedia = $stmt->fetch() ?: null;
    if ($importFeedbackMedia && !empty($importFeedbackMedia['imported_at'])) {
        $importFeedbackMedia = null;
    }
    if ($importFeedbackMedia) {
        $senderLabel = $importFeedbackMedia['sender_name'] ?: 'Anonym';
        $suggestedDescription = "von {$senderLabel}: {$importFeedbackMedia['message']}";
    }
}

$photos = $pdo->query('SELECT * FROM photos ORDER BY published_at DESC')->fetchAll();
$deleteError = isset($_GET['delete_error']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Galerie – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<header class="admin-header">
    <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Südsalat">
    <p>APP-Administrationsbereich</p>
</header>
<nav class="admin-nav">
    <a href="<?= BASE_PATH ?>/admin/dashboard.php">Dashboard</a>
    <a href="<?= BASE_PATH ?>/admin/feedback.php">Aktivitäten</a>
    <a class="nav-gap" href="<?= BASE_PATH ?>/admin/events.php">Veranstaltungen</a>
    <a href="<?= BASE_PATH ?>/admin/gallery.php">Galerie</a>
    <a href="<?= BASE_PATH ?>/admin/movie-tips.php">Filmtipps</a>
    <a href="<?= BASE_PATH ?>/admin/location-tips.php">Locations</a>
    <a href="<?= BASE_PATH ?>/admin/tip-reviews.php">Rezensionen</a>
    <?php if ($isOwner): ?><a href="<?= BASE_PATH ?>/admin/newsletter.php">Newsletter</a><?php endif; ?>
    <a href="<?= BASE_PATH ?>/admin/change-password.php">Passwort ändern</a>
    <a href="<?= BASE_PATH ?>/admin/logout.php">Abmelden (<span id="logout-countdown" data-timeout-seconds="<?= ADMIN_IDLE_TIMEOUT_MINUTES * 60 ?>"></span>)</a>
</nav>
<main class="content-box">
    <h1>Galerie</h1>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if ($deleteError): ?>
        <p class="error text-center">Falsches Passwort — nichts wurde gelöscht.</p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?php if ($editPhoto): ?>
            <input type="hidden" name="edit_id" value="<?= (int) $editPhoto['id'] ?>">
            <p>
                <?php if ($editPhoto['media_type'] === 'video'): ?>
                    <video src="<?= htmlspecialchars($editPhoto['image_path'], ENT_QUOTES) ?>" controls style="width:160px;border-radius:6px;"></video>
                <?php else: ?>
                    <img src="<?= htmlspecialchars($editPhoto['image_path'], ENT_QUOTES) ?>" alt="" style="width:120px;border-radius:6px;">
                <?php endif; ?>
            </p>
            <label>Neues Foto/Video (optional, ersetzt das aktuelle) <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm"></label>
            <label>Beschreibung (optional) <textarea name="description" rows="2"><?= htmlspecialchars($editPhoto['description'] ?? '', ENT_QUOTES) ?></textarea></label>
            <button type="submit">Speichern</button>
            <a class="button" href="<?= BASE_PATH ?>/admin/gallery.php">Abbrechen</a>
        <?php elseif ($importFeedback): ?>
            <input type="hidden" name="import_feedback_id" value="<?= (int) $importFeedback['id'] ?>">
            <p>
                <?php if (($importFeedback['media_type'] ?? 'image') === 'video'): ?>
                    <video src="<?= htmlspecialchars($importFeedback['image_path'], ENT_QUOTES) ?>" controls style="width:200px;border-radius:6px;"></video>
                <?php else: ?>
                    <img src="<?= htmlspecialchars($importFeedback['image_path'], ENT_QUOTES) ?>" alt="" style="width:160px;border-radius:6px;">
                <?php endif; ?>
            </p>
            <label>Beschreibung <textarea name="description" rows="3"><?= htmlspecialchars($suggestedDescription, ENT_QUOTES) ?></textarea></label>
            <button type="submit">In Galerie übernehmen</button>
            <a class="button" href="<?= BASE_PATH ?>/admin/feedback.php">Abbrechen</a>
        <?php elseif ($importFeedbackMedia): ?>
            <input type="hidden" name="import_feedback_media_id" value="<?= (int) $importFeedbackMedia['id'] ?>">
            <p>
                <img src="<?= htmlspecialchars($importFeedbackMedia['image_path'], ENT_QUOTES) ?>" alt="" style="width:160px;border-radius:6px;">
            </p>
            <label>Beschreibung <textarea name="description" rows="3"><?= htmlspecialchars($suggestedDescription, ENT_QUOTES) ?></textarea></label>
            <button type="submit">In Galerie übernehmen</button>
            <a class="button" href="<?= BASE_PATH ?>/admin/feedback.php">Abbrechen</a>
        <?php else: ?>
            <label>Foto/Video <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" required></label>
            <label>Beschreibung (optional) <textarea name="description" rows="2"></textarea></label>
            <button type="submit">Hochladen</button>
        <?php endif; ?>
    </form>

    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Vorschau</th><th>Beschreibung</th><th>Datum</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($photos as $photo): ?>
            <tr id="photo-<?= (int) $photo['id'] ?>">
                <td>
                    <?php if ($photo['media_type'] === 'video'): ?>
                        <video src="<?= htmlspecialchars($photo['image_path'], ENT_QUOTES) ?>" controls muted style="width:100px;border-radius:6px;"></video>
                    <?php else: ?>
                        <img src="<?= htmlspecialchars($photo['image_path'], ENT_QUOTES) ?>" alt="" style="width:80px;border-radius:6px;">
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($photo['description'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars(date('d.m.Y', strtotime($photo['published_at'])), ENT_QUOTES) ?></td>
                <td>
                    <div class="actions">
                        <a class="button" href="<?= BASE_PATH ?>/admin/gallery.php?edit=<?= (int) $photo['id'] ?>">Bearbeiten</a>
                        <form method="post" onsubmit="return false;">
                            <input type="hidden" name="delete_id" value="<?= (int) $photo['id'] ?>">
                            <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Das Foto wird dauerhaft gelöscht.')">Löschen</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</main>

<div id="confirm-step1" class="modal-overlay">
    <div class="modal-box">
        <p><strong>Bist du sicher?</strong></p>
        <p id="confirm-step1-text"></p>
        <div class="modal-actions">
            <button type="button" onclick="confirmStep1No()">Nein</button>
            <button type="button" class="button-danger" onclick="confirmStep1Yes()">Ja</button>
        </div>
    </div>
</div>
<div id="confirm-step2" class="modal-overlay">
    <div class="modal-box">
        <p><strong>Zur Bestätigung: dein Passwort</strong></p>
        <input type="password" id="confirm-password" placeholder="Passwort">
        <p id="confirm-error" class="error" style="display:none;"></p>
        <div class="modal-actions">
            <button type="button" onclick="confirmStep2Cancel()">Abbrechen</button>
            <button type="button" class="button-danger" onclick="confirmStep2Ok()">OK</button>
        </div>
    </div>
</div>
<script src="<?= BASE_PATH ?>/admin/assets/confirm-delete.js?v=<?= @filemtime(__DIR__ . '/assets/confirm-delete.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
</body>
</html>
