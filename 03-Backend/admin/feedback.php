<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ActivityLog;
use Suedsalat\Auth;
use Suedsalat\Database;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$deleteError = false;

// Nachricht loeschen (nur wenn bereits erledigt) - Passwort-Bestaetigung erforderlich.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_admin_password($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
        header('Location: ' . BASE_PATH . '/admin/feedback.php?delete_error=1');
        exit;
    }

    $id = (int) $_POST['delete_id'];
    $stmt = $pdo->prepare('SELECT image_path, status FROM feedback_messages WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $msg = $stmt->fetch();

    if ($msg && $msg['status'] === 'erledigt') {
        if (!empty($msg['image_path'])) {
            $localPath = UPLOAD_DIR . '/feedback/' . basename($msg['image_path']);
            if (is_file($localPath)) {
                unlink($localPath);
            }
        }
        $pdo->prepare('DELETE FROM feedback_messages WHERE id = :id')->execute([':id' => $id]);
    }
    header('Location: ' . BASE_PATH . '/admin/feedback.php');
    exit;
}

// Selbst angelegten Termin/Foto/Filmtipp/Locationtipp nur aus der Aktivitaeten-Liste
// entfernen, OHNE den eigentlichen Eintrag zu loeschen (anders als beim "echten"
// Loeschen ueber die jeweilige Seite selbst, siehe delete_action in ActivityLog).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_activity') {
    $activityDismissTables = [
        'event' => 'events',
        'photo' => 'photos',
        'movie_tip' => 'movie_tips',
        'location_tip' => 'location_tips',
    ];
    $entity = (string) ($_POST['entity'] ?? '');
    $entityId = (int) ($_POST['entity_id'] ?? 0);
    if (isset($activityDismissTables[$entity]) && $entityId > 0) {
        $table = $activityDismissTables[$entity];
        $stmt = $pdo->prepare("UPDATE {$table} SET dismissed_from_activity_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $entityId]);
    }
    header('Location: ' . BASE_PATH . '/admin/feedback.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $id = (int) $_POST['toggle_id'];
    $stmt = $pdo->prepare('SELECT status FROM feedback_messages WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $currentStatus = $stmt->fetchColumn();

    if ($currentStatus === 'offen') {
        $update = $pdo->prepare(
            'UPDATE feedback_messages SET status = "erledigt", handled_by = :admin_id, handled_at = NOW() WHERE id = :id'
        );
        $update->execute([':admin_id' => $adminId, ':id' => $id]);
    } elseif ($currentStatus === 'erledigt') {
        // Wieder oeffnen setzt auch die "bereits uebernommen"-Markierungen zurueck,
        // damit "Foto uebernehmen" / "Veranstaltung anlegen" / "Filmtipp anlegen" wieder angeboten werden.
        $update = $pdo->prepare(
            'UPDATE feedback_messages SET status = "offen", handled_by = NULL, handled_at = NULL,
             photo_imported_at = NULL, event_created_at = NULL, movietip_created_at = NULL WHERE id = :id'
        );
        $update->execute([':id' => $id]);
    }
    header('Location: ' . BASE_PATH . '/admin/feedback.php#msg-' . $id);
    exit;
}

if (isset($_GET['delete_error'])) {
    $deleteError = true;
}

$currentAdminRole = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
$currentAdminRole->execute([':id' => $adminId]);
$isOwner = $currentAdminRole->fetchColumn() === 'owner';

$typeLabels = [
    'termin_tipp' => 'Veranstaltungstipp',
    'kino_tipp' => 'Filmtipp',
    'foto_vorschlag' => 'Fotoempfehlung',
    'sprachnachricht' => 'Sprachnachricht',
    'allgemein' => 'Allgemeines Feedback',
    'frage' => 'Frage',
    'location_tipp' => 'Locationtipp',
];

// Nutzer-Feedback
$feedbackRows = $pdo->query(
    "SELECT f.*, a.name AS handled_by_name
     FROM feedback_messages f
     LEFT JOIN admins a ON a.id = f.handled_by
     ORDER BY f.created_at DESC"
)->fetchAll();

// Zusaetzliche Fotos bei Mehrfach-Einreichungen (siehe feedback_media) - einmal komplett
// laden und nach Feedback-ID gruppieren, statt pro Zeile einzeln nachzufragen. Jede Zeile
// (nicht nur der Pfad) wird gebraucht, damit jedes Foto einzeln in die Galerie uebernommen
// werden kann (imported_at trackt das pro Foto statt nur einmal pro Nachricht).
$feedbackMediaByMessage = [];
foreach ($pdo->query('SELECT id, feedback_message_id, image_path, imported_at FROM feedback_media ORDER BY id ASC')->fetchAll() as $mediaRow) {
    $feedbackMediaByMessage[$mediaRow['feedback_message_id']][] = $mediaRow;
}

$activity = [];

foreach ($feedbackRows as $msg) {
    if ($msg['status'] === 'erledigt') {
        if (!empty($msg['event_created_at'])) {
            $statusLabel = 'Veranstaltung übernommen';
        } elseif (!empty($msg['movietip_created_at'])) {
            $statusLabel = 'Filmtipp übernommen';
        } elseif (!empty($msg['photo_imported_at'])) {
            $statusLabel = 'Foto übernommen';
        } else {
            $statusLabel = 'Erledigt';
        }
        $statusExtra = $msg['handled_by_name'] ? ' von ' . $msg['handled_by_name'] : '';
        if ($msg['handled_at']) {
            $statusExtra .= ' (' . date('d.m.Y H:i', strtotime($msg['handled_at'])) . ')';
        }
    } else {
        $statusLabel = 'Offen';
        $statusExtra = '';
    }

    $activity[] = [
        'source' => 'user',
        'anchor' => 'msg-' . $msg['id'],
        'sort_date' => $msg['created_at'],
        'is_open' => $msg['status'] === 'offen',
        'date' => $msg['created_at'],
        'status_label' => $statusLabel,
        'status_extra' => $statusExtra,
        'from' => $msg['sender_name'] ?: 'Anonym',
        'type' => $typeLabels[$msg['type']] ?? 'Allgemein',
        'content' => $msg['message'],
        'suggested_date' => $msg['suggested_date'],
        'image_path' => $msg['image_path'],
        'media_type' => $msg['media_type'] ?? 'image',
        'consent_publish' => (bool) ($msg['consent_publish'] ?? false),
        'extra_photos' => $feedbackMediaByMessage[$msg['id']] ?? [],
        'row' => $msg,
    ];
}

foreach (ActivityLog::adminActions($pdo) as $item) {
    $item['is_open'] = false;
    $item['status_extra'] = '';
    $item['suggested_date'] = null;
    $item['anchor'] = $item['entity'] . '-' . $item['entity_id'];
    $activity[] = $item;
}

usort($activity, fn (array $a, array $b): int => strcmp($b['sort_date'], $a['sort_date']));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Aktivitäten – Südsalat Admin</title>
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
    <h1>Aktivitäten</h1>

    <?php if ($deleteError): ?>
        <p class="error text-center">Falsches Passwort — nichts wurde gelöscht.</p>
    <?php endif; ?>

    <?php if (empty($activity)): ?>
        <p class="text-center">Noch keine Einträge.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
                <tr><th>Herkunft</th><th>Datum</th><th>Status</th><th>Von</th><th>Typ</th><th>Nachricht</th><th>Veranstaltungstipp</th><th>Info</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($activity as $item): ?>
                <tr id="<?= htmlspecialchars($item['anchor'], ENT_QUOTES) ?>" class="<?= (!$item['is_open']) ? 'is-done' : '' ?>">
                    <td><span class="badge <?= $item['source'] === 'admin' ? 'badge-muted' : '' ?>"><?= $item['source'] === 'admin' ? 'Admin' : 'Nutzer' ?></span></td>
                    <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($item['date'])), ENT_QUOTES) ?></td>
                    <td>
                        <?= htmlspecialchars($item['status_label'], ENT_QUOTES) ?><?= htmlspecialchars($item['status_extra'], ENT_QUOTES) ?>
                    </td>
                    <td><?= htmlspecialchars($item['from'], ENT_QUOTES) ?></td>
                    <td style="text-align:left;">
                        <?= htmlspecialchars($item['type'], ENT_QUOTES) ?>
                        <?php if (($item['media_type'] ?? 'image') === 'audio'): ?>
                            <br><span class="badge <?= !empty($item['consent_publish']) ? '' : 'badge-danger' ?>" style="margin-left:0;">Veröffentlichung: <?= !empty($item['consent_publish']) ? 'Ja' : 'Nein' ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:220px;overflow-wrap:break-word;"><?= nl2br(htmlspecialchars($item['content'], ENT_QUOTES)) ?></td>
                    <td><?= !empty($item['suggested_date']) ? htmlspecialchars(date('d.m.Y', strtotime($item['suggested_date'])), ENT_QUOTES) : '' ?></td>
                    <td>
                        <?php if (!empty($item['image_path']) && ($item['media_type'] ?? 'image') === 'video'): ?>
                            <video src="<?= htmlspecialchars($item['image_path'], ENT_QUOTES) ?>" controls muted style="width:70px;border-radius:6px;"></video>
                        <?php elseif (!empty($item['image_path']) && ($item['media_type'] ?? 'image') === 'audio'): ?>
                            <audio src="<?= htmlspecialchars($item['image_path'], ENT_QUOTES) ?>" controls style="width:170px;height:32px;"></audio>
                        <?php elseif (!empty($item['extra_photos']) && count($item['extra_photos']) > 1): ?>
                            <div style="display:flex;flex-wrap:wrap;gap:4px;max-width:130px;">
                                <?php foreach ($item['extra_photos'] as $photoRow): ?>
                                    <img class="thumb" src="<?= htmlspecialchars($photoRow['image_path'], ENT_QUOTES) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;" onclick="openLightbox('<?= htmlspecialchars($photoRow['image_path'], ENT_QUOTES) ?>')">
                                <?php endforeach; ?>
                            </div>
                        <?php elseif (!empty($item['image_path'])): ?>
                            <img class="thumb" src="<?= htmlspecialchars($item['image_path'], ENT_QUOTES) ?>" alt="" style="width:44px;border-radius:6px;" onclick="openLightbox('<?= htmlspecialchars($item['image_path'], ENT_QUOTES) ?>')">
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item['source'] === 'user'): $msg = $item['row']; ?>
                            <div class="actions">
                                <?php if (!empty($item['extra_photos'])): ?>
                                    <?php foreach ($item['extra_photos'] as $i => $photoRow): ?>
                                        <?php if (empty($photoRow['imported_at'])): ?>
                                            <a class="button" href="<?= BASE_PATH ?>/admin/gallery.php?import_feedback_media_id=<?= (int) $photoRow['id'] ?>">Foto <?= $i + 1 ?> in Galerie übernehmen</a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php elseif (!empty($msg['image_path']) && empty($msg['photo_imported_at']) && ($msg['media_type'] ?? 'image') !== 'audio'): ?>
                                    <a class="button" href="<?= BASE_PATH ?>/admin/gallery.php?import_feedback_id=<?= (int) $msg['id'] ?>">Foto in Galerie übernehmen</a>
                                <?php endif; ?>
                                <?php if (!empty($msg['image_path'])): ?>
                                    <a class="button" download href="<?= htmlspecialchars($msg['image_path'], ENT_QUOTES) ?>">Download</a>
                                <?php endif; ?>
                                <?php $prefillDescriptionWithSender = 'von ' . ($msg['sender_name'] ?: 'Anonym') . ': ' . $msg['message']; ?>
                                <?php if ($msg['type'] === 'termin_tipp' && empty($msg['event_created_at'])): ?>
                                    <a class="button" href="<?= BASE_PATH ?>/admin/events.php?prefill_title=<?= urlencode(mb_strimwidth($msg['message'], 0, 80, '')) ?>&prefill_description=<?= urlencode($prefillDescriptionWithSender) ?>&prefill_date=<?= urlencode($msg['suggested_date'] ?? '') ?>&prefill_feedback_id=<?= (int) $msg['id'] ?>">Veranstaltung daraus anlegen</a>
                                <?php endif; ?>
                                <?php if ($msg['type'] === 'kino_tipp' && empty($msg['movietip_created_at'])): ?>
                                    <a class="button" href="<?= BASE_PATH ?>/admin/movie-tips.php?prefill_title=<?= urlencode(mb_strimwidth($msg['message'], 0, 80, '')) ?>&prefill_description=<?= urlencode($prefillDescriptionWithSender) ?>&prefill_feedback_id=<?= (int) $msg['id'] ?>">Filmtipp daraus anlegen</a>
                                <?php endif; ?>
                                <form method="post">
                                    <input type="hidden" name="toggle_id" value="<?= (int) $msg['id'] ?>">
                                    <button type="submit"><?= $msg['status'] === 'offen' ? 'Erledigt' : 'Öffnen' ?></button>
                                </form>
                                <?php if ($msg['status'] === 'erledigt'): ?>
                                    <form method="post" onsubmit="return false;">
                                        <input type="hidden" name="delete_id" value="<?= (int) $msg['id'] ?>">
                                        <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Es wird nur dieser Feedback-Eintrag gelöscht. Eine daraus bereits übernommene Veranstaltung, ein Filmtipp oder ein Foto bleibt erhalten.')">Löschen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php
                                $entityDismissLabels = [
                                    'event' => 'die Veranstaltung',
                                    'photo' => 'das Foto',
                                    'movie_tip' => 'der Filmtipp',
                                    'location_tip' => 'der Locationtipp',
                                ];
                            ?>
                            <div class="actions">
                                <a class="button" href="<?= BASE_PATH . htmlspecialchars($item['edit_link'], ENT_QUOTES) ?>">Bearbeiten</a>
                                <?php if ($isOwner): ?>
                                    <form method="post" onsubmit="return confirm('Nur aus der Aktivitäten-Liste entfernen? <?= $entityDismissLabels[$item['entity']] ?? 'Der Eintrag' ?> bleibt bestehen.');">
                                        <input type="hidden" name="action" value="dismiss_activity">
                                        <input type="hidden" name="entity" value="<?= htmlspecialchars($item['entity'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="entity_id" value="<?= $item['entity_id'] ?>">
                                        <button type="submit" class="button-danger">Aus Liste entfernen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</main>

<div id="lightbox" class="lightbox" onclick="closeLightbox()">
    <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
    <img id="lightbox-img" src="" alt="">
</div>

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
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
<script>
function openLightbox(src) {
    document.getElementById('lightbox-img').src = src;
    document.getElementById('lightbox').classList.add('is-open');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('is-open');
    document.getElementById('lightbox-img').src = '';
}
</script>
</body>
</html>
