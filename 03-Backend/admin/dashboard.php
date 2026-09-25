<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ActivityLog;
use Suedsalat\Auth;
use Suedsalat\Database;
use Suedsalat\ListenerContent;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$admin = $pdo->prepare('SELECT name, totp_enabled, role FROM admins WHERE id = :id');
$admin->execute([':id' => $adminId]);
$admin = $admin->fetch();
$isOwner = $admin['role'] === 'owner';

// Hinweis "Tipp ohne Bild" wegklicken (App 2.0, Konto geloescht mit "Meine Fotos loeschen")
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_image_notice') {
    $table = (string) ($_POST['table'] ?? '');
    if (array_key_exists($table, ListenerContent::TIP_TABLES)) {
        $pdo->prepare("UPDATE {$table} SET image_notice_dismissed_at = NOW() WHERE id = :id")
            ->execute([':id' => (int) ($_POST['id'] ?? 0)]);
    }
    header('Location: ' . BASE_PATH . '/admin/dashboard.php#tips-without-image');
    exit;
}

// App 2.0: offene Meldungen und Tipps, deren Bild mit einem Konto geloescht wurde.
// try/catch, damit das Dashboard auch ohne die 2.0-Tabellen laeuft.
$openReportCount = 0;
$hiddenByReports = 0;
$tipsWithoutImage = [];
try {
    $openReportCount = (int) $pdo->query("SELECT COUNT(DISTINCT content_type, content_id) FROM content_reports WHERE status = 'open'")->fetchColumn();
    $hiddenByReports = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM tip_reviews WHERE hidden_reason = 'reports')
                                               + (SELECT COUNT(*) FROM photos WHERE hidden_reason = 'reports')
                                               + (SELECT COUNT(*) FROM gallery_comments WHERE hidden_reason = 'reports')")->fetchColumn();
    foreach (ListenerContent::TIP_TABLES as $table => [$titleColumn, $page, $label]) {
        $rows = $pdo->query("SELECT id, {$titleColumn} AS title, image_removed_at FROM {$table}
                             WHERE image_removed_at IS NOT NULL AND image_path IS NULL AND image_notice_dismissed_at IS NULL")->fetchAll();
        foreach ($rows as $row) {
            $tipsWithoutImage[] = ['table' => $table, 'id' => (int) $row['id'], 'title' => (string) $row['title'],
                'label' => $label, 'page' => $page, 'since' => (string) $row['image_removed_at']];
        }
    }
} catch (\PDOException $e) {
    // Datenbank noch ohne 2.0-Migration
}

$eventCount = (int) $pdo->query('SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()')->fetchColumn();
$photoCount = (int) $pdo->query('SELECT COUNT(*) FROM photos')->fetchColumn();
$episodeCount = (int) $pdo->query('SELECT COUNT(*) FROM episodes_cache')->fetchColumn();
$pendingReviewCount = (int) $pdo->query('SELECT COUNT(*) FROM tip_reviews WHERE approved = 0')->fetchColumn();

// Immer ALLE offenen Nutzeranfragen (keine Begrenzung).
$openFeedback = $pdo->query(
    "SELECT f.*
     FROM feedback_messages f
     WHERE f.status = 'offen'
     ORDER BY f.created_at DESC"
)->fetchAll();

// Die letzten 10 direkten Admin-Aenderungen (Veranstaltungen/Fotos, nicht aus Feedback uebernommen).
$recentAdminActions = ActivityLog::adminActions($pdo, 10);

$feedbackTypeLabels = [
    'termin_tipp' => 'Veranstaltungstipp',
    'foto_vorschlag' => 'Fotoempfehlung',
    'kino_tipp' => 'Filmtipp',
    'allgemein' => 'Allgemeines Feedback',
    'sprachnachricht' => 'Sprachnachricht',
    'frage' => 'Frage',
    'location_tipp' => 'Locationtipp',
];

// Anonyme Nutzungsstatistik (siehe api/track-view.php): Aufrufe pro Bereich,
// gesamt und in den letzten 7 Tagen.
$screenLabels = [
    'start' => 'Start',
    'episodes' => 'Folgen',
    'events' => 'Veranstaltungen',
    'movie_tips' => 'Filmtipps',
    'location_tips' => 'Locationtipps',
    'gallery' => 'Galerie',
    'feedback' => 'Feedback',
];
$totalViews = $pdo->query('SELECT screen, SUM(count) AS total FROM screen_views GROUP BY screen')
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$recentViews = $pdo->query(
    "SELECT screen, SUM(count) AS total FROM screen_views WHERE day >= CURDATE() - INTERVAL 7 DAY GROUP BY screen"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// Anonyme Folgen-Wiedergaben (siehe api/track-episode-play.php): wie oft welche
// Folge abgespielt wurde, gesamt und in den letzten 7 Tagen. LEFT JOIN, damit
// auch Folgen ohne bisherige Wiedergabe mit 0 auftauchen.
$episodePlayCounts = $pdo->query(
    "SELECT e.guid, e.title, e.pub_date,
        COALESCE(SUM(c.count), 0) AS total_plays,
        COALESCE(SUM(CASE WHEN c.day >= CURDATE() - INTERVAL 7 DAY THEN c.count ELSE 0 END), 0) AS recent_plays
     FROM episodes_cache e
     LEFT JOIN episode_play_counts c ON c.episode_guid = e.guid
     GROUP BY e.guid, e.title, e.pub_date
     ORDER BY total_plays DESC, e.pub_date DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Dashboard – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Hallo, <?= htmlspecialchars($admin['name'], ENT_QUOTES) ?>!</h1>

    <?php if (!$admin['totp_enabled']): ?>
        <p class="error text-center">
            Zwei-Faktor-Authentifizierung ist noch nicht aktiv.<br>
            <a class="button" href="<?= BASE_PATH ?>/admin/2fa-setup.php">Jetzt einrichten</a>
        </p>
    <?php endif; ?>

    <p class="text-center">
        <strong><?= $episodeCount ?></strong> Folgen im Cache ·
        <strong><?= $eventCount ?></strong> kommende Veranstaltungen ·
        <strong><?= $photoCount ?></strong> Fotos in der Galerie
    </p>
</main>

<section class="content-box">
    <h2>Nutzung nach Bereich <span style="font-weight:normal;font-size:0.85rem;">(anonym, ohne Personenbezug)</span></h2>
    <button type="button" class="button" data-show-create-form="screen-views-table">+ anzeigen</button>
    <div id="screen-views-table" style="display:none;">
    <button type="button" class="button-secondary" data-hide-create-form="screen-views-table">- ausblenden</button>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Bereich</th><th>Letzte 7 Tage</th><th>Gesamt</th></tr>
        </thead>
        <tbody>
        <?php foreach ($screenLabels as $key => $label): ?>
            <tr>
                <td><?= htmlspecialchars($label, ENT_QUOTES) ?></td>
                <td><?= (int) ($recentViews[$key] ?? 0) ?></td>
                <td><?= (int) ($totalViews[$key] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</section>

<section class="content-box">
    <h2>Folgen-Wiedergaben <span style="font-weight:normal;font-size:0.85rem;">(anonym, ohne Personenbezug)</span></h2>
    <button type="button" class="button" data-show-create-form="episode-plays-table">+ anzeigen</button>
    <div id="episode-plays-table" style="display:none;">
    <button type="button" class="button-secondary" data-hide-create-form="episode-plays-table">- ausblenden</button>
    <?php if (empty($episodePlayCounts)): ?>
        <p>Noch keine Folgen im Cache.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Folge</th><th>Veröffentlicht</th><th>Letzte 7 Tage</th><th>Gesamt</th></tr>
        </thead>
        <tbody>
        <?php foreach ($episodePlayCounts as $ep): ?>
            <tr>
                <td><?= htmlspecialchars($ep['title'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars(date('d.m.Y', strtotime($ep['pub_date'])), ENT_QUOTES) ?></td>
                <td><?= (int) $ep['recent_plays'] ?></td>
                <td><?= (int) $ep['total_plays'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div>
</section>

<?php if ($openReportCount > 0): ?>
<section class="content-box">
    <h2>Meldungen<span class="badge"><?= $openReportCount ?> offen</span></h2>
    <p>
        <?= $openReportCount ?> Beitr<?= $openReportCount === 1 ? 'ag wurde' : 'äge wurden' ?> in der App gemeldet.
        <?php if ($hiddenByReports > 0): ?><strong><?= $hiddenByReports ?> davon <?= $hiddenByReports === 1 ? 'ist' : 'sind' ?> automatisch ausgeblendet</strong> und warte<?= $hiddenByReports === 1 ? 't' : 'n' ?> auf deine Entscheidung.<?php endif; ?>
    </p>
    <a class="button" href="<?= BASE_PATH ?>/admin/reports.php">Zu den Meldungen</a>
</section>
<?php endif; ?>

<?php if ($tipsWithoutImage !== []): ?>
<section class="content-box" id="tips-without-image">
    <h2>Tipps ohne Bild<span class="badge"><?= count($tipsWithoutImage) ?></span></h2>
    <p style="font-size:0.9rem;color:#666;">Ein Hörer hat sein Konto gelöscht und seine Fotos mitgenommen. Der Tipp bleibt in der App – mit einem neuen Bild sieht er wieder vollständig aus.</p>
    <ul class="feedback-list">
    <?php foreach ($tipsWithoutImage as $tip): ?>
        <li style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <a class="activity-link" href="<?= BASE_PATH ?>/admin/<?= $tip['page'] ?>?edit=<?= $tip['id'] ?>">
                <span>
                    <strong><?= htmlspecialchars($tip['label'], ENT_QUOTES) ?></strong> „<?= htmlspecialchars($tip['title'], ENT_QUOTES) ?>“ – neues Bild hinterlegen
                    <span class="meta">Bild fehlt seit <?= date('d.m.Y', strtotime($tip['since'])) ?></span>
                </span>
            </a>
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="dismiss_image_notice">
                <input type="hidden" name="table" value="<?= $tip['table'] ?>">
                <input type="hidden" name="id" value="<?= $tip['id'] ?>">
                <button type="submit" class="button-secondary" title="Hinweis ausblenden, der Tipp bleibt ohne Bild">Ohne Bild lassen</button>
            </form>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="content-box">
    <h2>Offene Nutzeranfragen<?php if (!empty($openFeedback)): ?><span class="badge"><?= count($openFeedback) ?> offen</span><?php endif; ?></h2>

    <?php if (empty($openFeedback)): ?>
        <p>Aktuell keine offenen Anfragen.</p>
    <?php else: ?>
        <ul class="feedback-list">
        <?php foreach ($openFeedback as $fb): ?>
            <li>
                <a class="activity-link" href="<?= BASE_PATH ?>/admin/feedback.php#msg-<?= (int) $fb['id'] ?>">
                    <?php if (!empty($fb['image_path']) && ($fb['media_type'] ?? 'image') === 'video'): ?>
                        <video class="thumb" src="<?= htmlspecialchars($fb['image_path'], ENT_QUOTES) ?>" muted style="width:40px;height:40px;object-fit:cover;border-radius:6px;flex-shrink:0;"></video>
                    <?php elseif (!empty($fb['image_path']) && ($fb['media_type'] ?? 'image') === 'audio'): ?>
                        <audio src="<?= htmlspecialchars($fb['image_path'], ENT_QUOTES) ?>" controls style="width:180px;height:28px;flex-shrink:0;" onclick="event.stopPropagation()"></audio>
                    <?php elseif (!empty($fb['image_path'])): ?>
                        <img class="thumb" src="<?= htmlspecialchars($fb['image_path'], ENT_QUOTES) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;flex-shrink:0;">
                    <?php endif; ?>
                    <span>
                        <strong><?= htmlspecialchars($fb['sender_name'] ?: 'Anonym', ENT_QUOTES) ?></strong>
                        (<?= htmlspecialchars($feedbackTypeLabels[$fb['type']] ?? 'Allgemein', ENT_QUOTES) ?>):
                        <?= htmlspecialchars(mb_strimwidth($fb['message'], 0, 100, '…'), ENT_QUOTES) ?>
                        <span class="meta"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($fb['created_at'])), ENT_QUOTES) ?></span>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <a class="button" href="<?= BASE_PATH ?>/admin/feedback.php">Alle Aktivitäten ansehen</a>
</section>

<section class="content-box">
    <h2>Ausstehende Rezensionen<?php if ($pendingReviewCount > 0): ?><span class="badge"><?= $pendingReviewCount ?> offen</span><?php endif; ?></h2>

    <?php if ($pendingReviewCount === 0): ?>
        <p>Aktuell keine ausstehenden Rezensionen.</p>
    <?php else: ?>
        <p><?= $pendingReviewCount ?> Rezension<?= $pendingReviewCount === 1 ? '' : 'en' ?> warte<?= $pendingReviewCount === 1 ? 't' : 'n' ?> auf Freigabe.</p>
    <?php endif; ?>

    <a class="button" href="<?= BASE_PATH ?>/admin/tip-reviews.php">Zur Rezensionsübersicht</a>
</section>

<section class="content-box">
    <h2>Letzte Admin-Anpassungen</h2>

    <?php if (empty($recentAdminActions)): ?>
        <p>Noch keine Admin-Anpassungen.</p>
    <?php else: ?>
        <ul class="feedback-list">
        <?php foreach ($recentAdminActions as $item): ?>
            <li class="is-done">
                <a class="activity-link" href="<?= BASE_PATH ?>/admin/feedback.php#<?= htmlspecialchars($item['entity'], ENT_QUOTES) ?>-<?= (int) $item['entity_id'] ?>">
                    <?php if (!empty($item['image_path'])): ?>
                        <img class="thumb" src="<?= htmlspecialchars($item['image_path'], ENT_QUOTES) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;flex-shrink:0;">
                    <?php endif; ?>
                    <span>
                        <strong><?= htmlspecialchars($item['status_label'], ENT_QUOTES) ?></strong>
                        von <?= htmlspecialchars($item['from'], ENT_QUOTES) ?>:
                        <?= htmlspecialchars(mb_strimwidth($item['content'], 0, 100, '…'), ENT_QUOTES) ?>
                        <span class="meta"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($item['date'])), ENT_QUOTES) ?></span>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <a class="button" href="<?= BASE_PATH ?>/admin/feedback.php">Alle Aktivitäten ansehen</a>
</section>

<?php require __DIR__ . '/partials/sidebar-close.php'; ?>
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/toggle-create-form.js?v=<?= @filemtime(__DIR__ . '/assets/toggle-create-form.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/scroll-restore.js?v=<?= @filemtime(__DIR__ . '/assets/scroll-restore.js') ?>"></script>
</body>
</html>
