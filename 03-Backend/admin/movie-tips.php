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
$allowedImageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$maxPosterSizeBytes = 8 * 1024 * 1024;

/** Laedt ein hochgeladenes Poster hoch und gibt die oeffentliche URL zurueck, oder null bei Fehler (setzt dann $error). */
function upload_movie_tip_poster(array $file, array $allowedImageTypes, int $maxSizeBytes, ?string &$error): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Poster-Upload fehlgeschlagen.';
        return null;
    }
    if ($file['size'] > $maxSizeBytes) {
        $error = 'Poster ist zu groß (max. 8 MB).';
        return null;
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowedImageTypes[$mime])) {
        $error = 'Nur JPG, PNG oder WebP sind erlaubt.';
        return null;
    }

    $movieTipsDir = UPLOAD_DIR . '/movie-tips';
    if (!is_dir($movieTipsDir)) {
        mkdir($movieTipsDir, 0755, true);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowedImageTypes[$mime];
    $targetPath = $movieTipsDir . '/' . $filename;

    if (!resize_poster_image($file['tmp_name'], $targetPath, $mime, 1600)) {
        move_uploaded_file($file['tmp_name'], $targetPath);
    }

    return UPLOAD_URL_BASE . '/movie-tips/' . $filename;
}

/** Skaliert ein Bild auf max. Kantenlaenge und speichert es komprimiert unter $targetPath.
 *  Gibt false zurueck, wenn GD fehlt oder das Bild nicht gelesen werden konnte (dann Original unveraendert uebernehmen). */
function resize_poster_image(string $sourcePath, string $targetPath, string $mime, int $maxDimension): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($sourcePath),
        'image/png' => @imagecreatefrompng($sourcePath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        default => false,
    };
    if ($image === false) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $scale = min(1, $maxDimension / max($width, $height));

    if ($scale < 1) {
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
    }

    $saved = match ($mime) {
        'image/jpeg' => imagejpeg($image, $targetPath, 82),
        'image/png' => imagepng($image, $targetPath, 6),
        'image/webp' => function_exists('imagewebp') ? imagewebp($image, $targetPath, 82) : false,
        default => false,
    };
    imagedestroy($image);

    return $saved;
}

/** Kopiert ein bereits per Feedback eingereichtes Foto als Poster (mit Resize wie beim normalen Upload). */
function copy_feedback_photo_as_poster(string $feedbackImagePath, string $destDirName): ?string
{
    $sourceLocal = UPLOAD_DIR . '/feedback/' . basename($feedbackImagePath);
    if (!is_file($sourceLocal)) {
        return null;
    }
    $destDir = UPLOAD_DIR . '/' . $destDirName;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $extension = pathinfo($sourceLocal, PATHINFO_EXTENSION);
    $mime = mime_content_type($sourceLocal);
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = $destDir . '/' . $filename;

    if (!resize_poster_image($sourceLocal, $targetPath, $mime, 1600)) {
        copy($sourceLocal, $targetPath);
    }

    return UPLOAD_URL_BASE . '/' . $destDirName . '/' . $filename;
}

/** Parst eine Zeitmarke im Format "m:ss", "mm:ss" oder "hh:mm:ss" in Sekunden. Leer/ungueltig -> null. */
function parse_timestamp_to_seconds(?string $value): ?int
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $parts = explode(':', $value);
    if (count($parts) < 2 || count($parts) > 3 || !preg_match('/^\d{1,2}(:\d{2}){1,2}$/', $value)) {
        return null;
    }
    $seconds = 0;
    foreach ($parts as $part) {
        $seconds = $seconds * 60 + (int) $part;
    }
    return $seconds;
}

/** Formatiert Sekunden zurueck in "mm:ss" bzw. "hh:mm:ss" fuer die Formular-Vorbelegung. */
function format_seconds_to_timestamp(?int $seconds): string
{
    if ($seconds === null) {
        return '';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    return $hours > 0
        ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
        : sprintf('%d:%02d', $minutes, $secs);
}

// Loeschen - Passwort-Bestaetigung erforderlich.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_admin_password($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
        header('Location: ' . BASE_PATH . '/admin/movie-tips.php?delete_error=1');
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM movie_tips WHERE id = :id');
    $stmt->execute([':id' => (int) $_POST['delete_id']]);
    header('Location: ' . BASE_PATH . '/admin/movie-tips.php');
    exit;
}

// Rezension direkt beim Bearbeiten eintragen - wird sofort freigegeben (der Admin ist
// hier selbst die freigebende Instanz), siehe auch admin/tip-reviews.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_review') {
    $reviewTipId = (int) ($_POST['tip_id'] ?? 0);
    $reviewRating = (int) ($_POST['rating'] ?? 0);
    $reviewText = trim((string) ($_POST['review_text'] ?? '')) ?: null;
    $reviewerName = trim((string) ($_POST['reviewer_name'] ?? '')) ?: null;
    if ($reviewTipId > 0 && $reviewRating >= 1 && $reviewRating <= 5) {
        $stmt = $pdo->prepare(
            'INSERT INTO tip_reviews (tip_type, tip_id, rating, review_text, reviewer_name, approved, approved_at, approved_by)
             VALUES ("movie_tip", :tip_id, :rating, :review_text, :reviewer_name, 1, NOW(), :admin_id)'
        );
        $stmt->execute([
            ':tip_id' => $reviewTipId,
            ':rating' => $reviewRating,
            ':review_text' => $reviewText,
            ':reviewer_name' => $reviewerName,
            ':admin_id' => $adminId,
        ]);
    }
    header('Location: ' . BASE_PATH . '/admin/movie-tips.php?edit=' . $reviewTipId);
    exit;
}

// Bestehende Rezension direkt hier freigeben/zurueckziehen/ablehnen - gleiche Aktionen
// wie auf admin/tip-reviews.php, nur auf diesen einen Filmtipp bezogen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_review') {
    $stmt = $pdo->prepare('UPDATE tip_reviews SET approved = 1, approved_at = NOW(), approved_by = :admin_id WHERE id = :id AND tip_type = "movie_tip"');
    $stmt->execute([':admin_id' => $adminId, ':id' => (int) $_POST['review_id']]);
    header('Location: ' . BASE_PATH . '/admin/movie-tips.php?edit=' . (int) $_POST['tip_id']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke_review') {
    $stmt = $pdo->prepare('UPDATE tip_reviews SET approved = 0, approved_at = NULL, approved_by = NULL WHERE id = :id AND tip_type = "movie_tip"');
    $stmt->execute([':id' => (int) $_POST['review_id']]);
    header('Location: ' . BASE_PATH . '/admin/movie-tips.php?edit=' . (int) $_POST['tip_id']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_review') {
    if (!verify_admin_password($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
        header('Location: ' . BASE_PATH . '/admin/movie-tips.php?edit=' . (int) $_POST['tip_id'] . '&delete_error=1');
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM tip_reviews WHERE id = :id AND tip_type = "movie_tip"');
    $stmt->execute([':id' => (int) $_POST['review_id']]);
    header('Location: ' . BASE_PATH . '/admin/movie-tips.php?edit=' . (int) $_POST['tip_id']);
    exit;
}

// Manuelle Reihenfolge: mit dem direkten Nachbarn (naechst kleinere/groessere
// sort_order) die Position tauschen. id als Tiebreaker, falls sort_order mal
// nicht eindeutig sein sollte.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['move_up', 'move_down'], true)) {
    $moveId = (int) ($_POST['tip_id'] ?? 0);
    $current = $pdo->prepare('SELECT id, sort_order FROM movie_tips WHERE id = :id');
    $current->execute([':id' => $moveId]);
    $currentTip = $current->fetch();

    if ($currentTip) {
        if ($_POST['action'] === 'move_up') {
            $neighborStmt = $pdo->prepare(
                'SELECT id, sort_order FROM movie_tips
                 WHERE sort_order < :so OR (sort_order = :so2 AND id < :id)
                 ORDER BY sort_order DESC, id DESC LIMIT 1'
            );
        } else {
            $neighborStmt = $pdo->prepare(
                'SELECT id, sort_order FROM movie_tips
                 WHERE sort_order > :so OR (sort_order = :so2 AND id > :id)
                 ORDER BY sort_order ASC, id ASC LIMIT 1'
            );
        }
        $neighborStmt->execute([':so' => $currentTip['sort_order'], ':so2' => $currentTip['sort_order'], ':id' => $moveId]);
        $neighbor = $neighborStmt->fetch();

        if ($neighbor) {
            $swap = $pdo->prepare('UPDATE movie_tips SET sort_order = :so WHERE id = :id');
            $swap->execute([':so' => $neighbor['sort_order'], ':id' => $currentTip['id']]);
            $swap->execute([':so' => $currentTip['sort_order'], ':id' => $neighbor['id']]);
        }
    }
    header('Location: ' . BASE_PATH . '/admin/movie-tips.php');
    exit;
}

// Anlegen oder Bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int) $_POST['edit_id'] : null;
    $title = trim((string) $_POST['title']);
    $description = trim((string) ($_POST['description'] ?? '')) ?: null;
    $link = trim((string) ($_POST['link'] ?? '')) ?: null;
    if ($link !== null && !preg_match('#^https?://#i', $link)) {
        $link = 'https://' . $link;
    }
    $episodeGuid = trim((string) ($_POST['episode_guid'] ?? '')) ?: null;
    $episodeTimestampSeconds = parse_timestamp_to_seconds($_POST['episode_timestamp'] ?? null);

    if ($title === '') {
        $error = 'Titel ist ein Pflichtfeld.';
    } elseif ($editId) {
        $stmt = $pdo->prepare('SELECT image_path FROM movie_tips WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $existingImagePath = $stmt->fetchColumn();
        $imagePath = $existingImagePath ?: null;

        if (!empty($_FILES['poster']['name'])) {
            $uploaded = upload_movie_tip_poster($_FILES['poster'], $allowedImageTypes, $maxPosterSizeBytes, $error);
            if ($uploaded === null) {
                goto render_movie_tips_page;
            }
            if ($existingImagePath) {
                $oldLocal = UPLOAD_DIR . '/movie-tips/' . basename($existingImagePath);
                if (is_file($oldLocal)) {
                    unlink($oldLocal);
                }
            }
            $imagePath = $uploaded;
        } elseif (!empty($_POST['remove_poster'])) {
            if ($existingImagePath) {
                $oldLocal = UPLOAD_DIR . '/movie-tips/' . basename($existingImagePath);
                if (is_file($oldLocal)) {
                    unlink($oldLocal);
                }
            }
            $imagePath = null;
        }

        $stmt = $pdo->prepare(
            'UPDATE movie_tips SET title = :title, description = :description, link = :link,
             episode_guid = :episode_guid, episode_timestamp_seconds = :episode_timestamp_seconds,
             image_path = :image_path, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':link' => $link,
            ':episode_guid' => $episodeGuid,
            ':episode_timestamp_seconds' => $episodeTimestampSeconds,
            ':image_path' => $imagePath,
            ':id' => $editId,
        ]);
        header('Location: ' . BASE_PATH . '/admin/movie-tips.php');
        exit;
    } else {
        $feedbackId = isset($_POST['feedback_id']) && $_POST['feedback_id'] !== '' ? (int) $_POST['feedback_id'] : null;

        $imagePath = null;
        if (!empty($_FILES['poster']['name'])) {
            $imagePath = upload_movie_tip_poster($_FILES['poster'], $allowedImageTypes, $maxPosterSizeBytes, $error);
            if ($imagePath === null) {
                goto render_movie_tips_page;
            }
        } elseif (!empty($_POST['use_feedback_photo']) && $feedbackId) {
            $stmt = $pdo->prepare('SELECT image_path, media_type FROM feedback_messages WHERE id = :id');
            $stmt->execute([':id' => $feedbackId]);
            $fbRow = $stmt->fetch();
            if ($fbRow && !empty($fbRow['image_path']) && ($fbRow['media_type'] ?? 'image') === 'image') {
                $imagePath = copy_feedback_photo_as_poster($fbRow['image_path'], 'movie-tips');
            }
        }

        $nextSortOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM movie_tips')->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO movie_tips (title, description, link, episode_guid, episode_timestamp_seconds, image_path, created_by, created_via_feedback_id, sort_order)
             VALUES (:title, :description, :link, :episode_guid, :episode_timestamp_seconds, :image_path, :created_by, :feedback_id, :sort_order)'
        );
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':link' => $link,
            ':episode_guid' => $episodeGuid,
            ':episode_timestamp_seconds' => $episodeTimestampSeconds,
            ':image_path' => $imagePath,
            ':created_by' => $adminId,
            ':feedback_id' => $feedbackId,
            ':sort_order' => $nextSortOrder,
        ]);
        $newTipId = (int) $pdo->lastInsertId();

        if ($feedbackId) {
            $markDone = $pdo->prepare(
                'UPDATE feedback_messages SET movietip_created_at = NOW(), status = "erledigt", handled_by = :admin_id, handled_at = NOW() WHERE id = :id'
            );
            $markDone->execute([':admin_id' => $adminId, ':id' => $feedbackId]);
        }

        // Rezension gleich beim Anlegen mit eintragen, falls das Haekchen gesetzt war.
        if (!empty($_POST['add_review_now'])) {
            $newRating = (int) ($_POST['new_rating'] ?? 0);
            $newReviewText = trim((string) ($_POST['new_review_text'] ?? '')) ?: null;
            if ($newRating >= 1 && $newRating <= 5) {
                $stmt = $pdo->prepare(
                    'INSERT INTO tip_reviews (tip_type, tip_id, rating, review_text, approved, approved_at, approved_by)
                     VALUES ("movie_tip", :tip_id, :rating, :review_text, 1, NOW(), :admin_id)'
                );
                $stmt->execute([
                    ':tip_id' => $newTipId,
                    ':rating' => $newRating,
                    ':review_text' => $newReviewText,
                    ':admin_id' => $adminId,
                ]);
            }
        }

        FcmSender::sendToAllDevices("Neuer Filmtipp: $title", 'Jenny hat einen neuen Filmtipp!');
        // Direkt zur Bearbeiten-Ansicht des neuen Eintrags, damit sofort auch eine
        // Rezension dazu eingetragen werden kann (braucht zwingend die neue ID).
        header('Location: ' . BASE_PATH . '/admin/movie-tips.php?edit=' . $newTipId);
        exit;
    }
}

render_movie_tips_page:

// Zum Bearbeiten laden
$editTip = null;
$tipPendingReviews = [];
$tipApprovedReviews = [];
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM movie_tips WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $editTip = $stmt->fetch() ?: null;

    if ($editTip) {
        $stmt = $pdo->prepare('SELECT * FROM tip_reviews WHERE tip_type = "movie_tip" AND tip_id = :id AND approved = 0 ORDER BY created_at ASC');
        $stmt->execute([':id' => $editTip['id']]);
        $tipPendingReviews = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM tip_reviews WHERE tip_type = "movie_tip" AND tip_id = :id AND approved = 1 ORDER BY created_at DESC');
        $stmt->execute([':id' => $editTip['id']]);
        $tipApprovedReviews = $stmt->fetchAll();
    }
}

// Vorbelegung aus einem Feedback-Filmtipp (siehe feedback.php) - der Feedback-Typ selbst heisst intern weiter "kino_tipp"
$prefillTitle = (string) ($_GET['prefill_title'] ?? '');
$prefillDescription = (string) ($_GET['prefill_description'] ?? '');
$prefillFeedbackId = (string) ($_GET['prefill_feedback_id'] ?? '');

$prefillFeedbackImage = null;
if ($prefillFeedbackId !== '') {
    $stmt = $pdo->prepare('SELECT image_path, media_type FROM feedback_messages WHERE id = :id');
    $stmt->execute([':id' => (int) $prefillFeedbackId]);
    $fbRow = $stmt->fetch();
    if ($fbRow && !empty($fbRow['image_path']) && ($fbRow['media_type'] ?? 'image') === 'image') {
        $prefillFeedbackImage = $fbRow['image_path'];
    }
}

// Kurzform fuer die Folgen-Auswahl (z.B. "Episode 21" statt "09.04.2026 – Episode 21: Gratulation") -
// die Dropdown-Eintraege waren mit Datum+Volltitel zu lang.
function episode_short_label(string $title): string
{
    return preg_match('/^(Episode\s+\d+)/i', $title, $matches) ? $matches[1] : $title;
}

$episodeOptions = $pdo->query('SELECT guid, title, pub_date FROM episodes_cache ORDER BY pub_date DESC')->fetchAll();

$allTips = $pdo->query(
    'SELECT mt.*,
        (SELECT AVG(rating) FROM tip_reviews WHERE tip_type = "movie_tip" AND tip_id = mt.id AND approved = 1) AS avg_rating,
        (SELECT COUNT(*) FROM tip_reviews WHERE tip_type = "movie_tip" AND tip_id = mt.id AND approved = 1) AS review_count
     FROM movie_tips mt
     ORDER BY mt.sort_order ASC'
)->fetchAll();

$deleteError = isset($_GET['delete_error']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Filmtipps – Südsalat Admin</title>
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
    <h1>Filmtipps</h1>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if ($deleteError): ?>
        <p class="error text-center">Falsches Passwort — nichts wurde gelöscht.</p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?php if ($editTip): ?>
            <input type="hidden" name="edit_id" value="<?= (int) $editTip['id'] ?>">
        <?php elseif ($prefillFeedbackId !== ''): ?>
            <input type="hidden" name="feedback_id" value="<?= htmlspecialchars($prefillFeedbackId, ENT_QUOTES) ?>">
        <?php endif; ?>
        <label>Titel des Films <input type="text" name="title" required value="<?= htmlspecialchars($editTip['title'] ?? $prefillTitle, ENT_QUOTES) ?>"></label>
        <label>Beschreibung (optional) <textarea name="description" rows="3"><?= htmlspecialchars($editTip['description'] ?? $prefillDescription, ENT_QUOTES) ?></textarea></label>
        <label>Link (optional, z. B. Trailer/Webseite) <input type="text" name="link" placeholder="www.beispiel.de" value="<?= htmlspecialchars($editTip['link'] ?? '', ENT_QUOTES) ?>"></label>
        <div class="field-row">
            <label>Folge dazu (optional)
                <select name="episode_guid">
                    <option value="">— keine Folge —</option>
                    <?php foreach ($episodeOptions as $episode): ?>
                        <option value="<?= htmlspecialchars($episode['guid'], ENT_QUOTES) ?>"
                            <?= ($editTip['episode_guid'] ?? '') === $episode['guid'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars(episode_short_label($episode['title']), ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Zeitmarke (optional, z. B. 12:34)
                <input type="text" name="episode_timestamp" placeholder="mm:ss"
                    value="<?= htmlspecialchars(format_seconds_to_timestamp(isset($editTip['episode_timestamp_seconds']) ? (int) $editTip['episode_timestamp_seconds'] : null), ENT_QUOTES) ?>">
            </label>
        </div>
        <?php if (!$editTip && $prefillFeedbackImage): ?>
            <p>
                <img src="<?= htmlspecialchars($prefillFeedbackImage, ENT_QUOTES) ?>" alt="" style="max-width:160px;border-radius:8px;display:block;margin-bottom:8px;">
                <label style="font-weight:normal; display:inline-flex; align-items:center; gap:8px; font-size:1.1rem;"><input type="checkbox" name="use_feedback_photo" value="1" checked style="width:20px;height:20px;"> Foto aus dem Feedback übernehmen</label>
            </p>
        <?php endif; ?>
        <label>Poster (optional, z. B. Filmplakat)
            <input type="file" name="poster" accept="image/jpeg,image/png,image/webp">
        </label>
        <?php if ($editTip && !empty($editTip['image_path'])): ?>
            <p>
                <img src="<?= htmlspecialchars($editTip['image_path'], ENT_QUOTES) ?>" alt="" style="max-width:160px;border-radius:8px;display:block;margin-bottom:8px;">
                <label style="font-weight:normal;"><input type="checkbox" name="remove_poster" value="1"> Poster entfernen</label>
            </p>
        <?php endif; ?>

        <?php if (!$editTip): ?>
        <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
            <input type="checkbox" id="chk_new_review" name="add_review_now" style="width:auto;">
            Gleich eine Rezension dazu eintragen
        </label>
        <div id="field_new_review" style="display:none;">
            <label>Mikros
                <div class="mikro-rating">
                    <input type="radio" name="new_rating" value="5" id="new-rating-5"><label for="new-rating-5"></label>
                    <input type="radio" name="new_rating" value="4" id="new-rating-4"><label for="new-rating-4"></label>
                    <input type="radio" name="new_rating" value="3" id="new-rating-3"><label for="new-rating-3"></label>
                    <input type="radio" name="new_rating" value="2" id="new-rating-2"><label for="new-rating-2"></label>
                    <input type="radio" name="new_rating" value="1" id="new-rating-1"><label for="new-rating-1"></label>
                </div>
            </label>
            <label>Rezensionstext (optional) <textarea name="new_review_text" rows="2"></textarea></label>
        </div>
        <script>
            (function () {
                var checkbox = document.getElementById('chk_new_review');
                var field = document.getElementById('field_new_review');
                if (!checkbox || !field) return;
                function update() { field.style.display = checkbox.checked ? '' : 'none'; }
                checkbox.addEventListener('change', update);
                update();
            })();
        </script>
        <?php endif; ?>

        <button type="submit"><?= $editTip ? 'Filmtipp aktualisieren' : 'Filmtipp anlegen' ?></button>
        <?php if ($editTip): ?>
            <a class="button" href="<?= BASE_PATH ?>/admin/movie-tips.php">Abbrechen</a>
        <?php endif; ?>
    </form>

    <?php if ($editTip): ?>
    <h2>Rezensionen zu „<?= htmlspecialchars($editTip['title'], ENT_QUOTES) ?>“</h2>

    <?php if (!empty($tipPendingReviews)): ?>
        <p><strong>Ausstehend:</strong></p>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Mikros</th><th>Name</th><th>Rezension</th><th>Eingereicht</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tipPendingReviews as $review): ?>
                <tr>
                    <td><?= (int) $review['rating'] ?> / 5</td>
                    <td><?= htmlspecialchars($review['reviewer_name'] ?? '—', ENT_QUOTES) ?></td>
                    <td><?= $review['review_text'] !== null ? nl2br(htmlspecialchars($review['review_text'], ENT_QUOTES)) : '<em>(kein Text)</em>' ?></td>
                    <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($review['created_at'])), ENT_QUOTES) ?></td>
                    <td>
                        <div class="actions">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="approve_review">
                                <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
                                <input type="hidden" name="tip_id" value="<?= (int) $editTip['id'] ?>">
                                <button type="submit">Freigeben</button>
                            </form>
                            <a class="button" href="<?= BASE_PATH ?>/admin/tip-reviews.php?edit_review=<?= (int) $review['id'] ?>">Bearbeiten</a>
                            <form method="post" onsubmit="return false;">
                                <input type="hidden" name="action" value="reject_review">
                                <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
                                <input type="hidden" name="tip_id" value="<?= (int) $editTip['id'] ?>">
                                <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Die Rezension wird dauerhaft abgelehnt und gelöscht.')">Ablehnen</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($tipApprovedReviews)): ?>
        <p><strong>Freigegeben:</strong></p>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Mikros</th><th>Name</th><th>Rezension</th><th>Freigegeben</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tipApprovedReviews as $review): ?>
                <tr>
                    <td><?= (int) $review['rating'] ?> / 5</td>
                    <td><?= htmlspecialchars($review['reviewer_name'] ?? '—', ENT_QUOTES) ?></td>
                    <td><?= $review['review_text'] !== null ? nl2br(htmlspecialchars($review['review_text'], ENT_QUOTES)) : '<em>(kein Text)</em>' ?></td>
                    <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $review['approved_at'])), ENT_QUOTES) ?></td>
                    <td>
                        <div class="actions">
                            <a class="button" href="<?= BASE_PATH ?>/admin/tip-reviews.php?edit_review=<?= (int) $review['id'] ?>">Bearbeiten</a>
                            <form method="post">
                                <input type="hidden" name="action" value="revoke_review">
                                <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
                                <input type="hidden" name="tip_id" value="<?= (int) $editTip['id'] ?>">
                                <button type="submit" class="button">Freigabe zurückziehen</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if (empty($tipPendingReviews) && empty($tipApprovedReviews)): ?>
        <p>Noch keine Rezensionen zu diesem Eintrag.</p>
    <?php endif; ?>

    <h3>Rezension eintragen</h3>
    <form method="post">
        <input type="hidden" name="action" value="add_review">
        <input type="hidden" name="tip_id" value="<?= (int) $editTip['id'] ?>">
        <label>Mikros
            <div class="mikro-rating">
                <input type="radio" name="rating" value="5" id="add-rating-5" required><label for="add-rating-5"></label>
                <input type="radio" name="rating" value="4" id="add-rating-4"><label for="add-rating-4"></label>
                <input type="radio" name="rating" value="3" id="add-rating-3"><label for="add-rating-3"></label>
                <input type="radio" name="rating" value="2" id="add-rating-2"><label for="add-rating-2"></label>
                <input type="radio" name="rating" value="1" id="add-rating-1"><label for="add-rating-1"></label>
            </div>
        </label>
        <label>Name (optional) <input type="text" name="reviewer_name"></label>
        <label>Rezensionstext (optional) <textarea name="review_text" rows="2"></textarea></label>
        <button type="submit">Rezension eintragen</button>
    </form>
    <?php endif; ?>

    <h2>Alle Filmtipps</h2>
    <?php if (empty($allTips)): ?>
        <p>Noch keine Filmtipps vorhanden.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Reihenfolge</th><th>Poster</th><th>Titel</th><th>Ø Bewertung</th><th>Angelegt</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($allTips as $i => $tip): ?>
            <tr>
                <td>
                    <div class="actions">
                        <?php if ($i > 0): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="move_up">
                                <input type="hidden" name="tip_id" value="<?= (int) $tip['id'] ?>">
                                <button type="submit" class="button" title="Nach oben">▲</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($i < count($allTips) - 1): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="move_down">
                                <input type="hidden" name="tip_id" value="<?= (int) $tip['id'] ?>">
                                <button type="submit" class="button" title="Nach unten">▼</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php if (!empty($tip['image_path'])): ?>
                        <img src="<?= htmlspecialchars($tip['image_path'], ENT_QUOTES) ?>" alt="" style="width:40px;height:40px;object-fit:cover;object-position:top;border-radius:6px;">
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($tip['title'], ENT_QUOTES) ?></td>
                <td><?= $tip['avg_rating'] !== null ? htmlspecialchars(number_format((float) $tip['avg_rating'], 1, ',', '.'), ENT_QUOTES) . ' Mikros (' . (int) $tip['review_count'] . ')' : '—' ?></td>
                <td><?= htmlspecialchars(date('d.m.Y', strtotime($tip['created_at'])), ENT_QUOTES) ?></td>
                <td>
                    <div class="actions">
                        <a class="button" href="<?= BASE_PATH ?>/admin/movie-tips.php?edit=<?= (int) $tip['id'] ?>">Bearbeiten</a>
                        <form method="post" onsubmit="return false;">
                            <input type="hidden" name="delete_id" value="<?= (int) $tip['id'] ?>">
                            <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Der Filmtipp „<?= htmlspecialchars(addslashes($tip['title']), ENT_QUOTES) ?>“ wird dauerhaft gelöscht.')">Löschen</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
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
