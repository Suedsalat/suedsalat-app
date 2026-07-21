<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\ActivityLog;
use Suedsalat\Auth;
use Suedsalat\Database;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$admin = $pdo->prepare('SELECT name, totp_enabled, role FROM admins WHERE id = :id');
$admin->execute([':id' => $adminId]);
$admin = $admin->fetch();
$isOwner = $admin['role'] === 'owner';

$eventCount = (int) $pdo->query('SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()')->fetchColumn();
$photoCount = (int) $pdo->query('SELECT COUNT(*) FROM photos')->fetchColumn();
$episodeCount = (int) $pdo->query('SELECT COUNT(*) FROM episodes_cache')->fetchColumn();

// Immer ALLE offenen Nutzeranfragen (keine Begrenzung).
$openFeedback = $pdo->query(
    "SELECT f.*
     FROM feedback_messages f
     WHERE f.status = 'offen'
     ORDER BY f.created_at DESC"
)->fetchAll();

// Die letzten 10 direkten Admin-Aenderungen (Termine/Fotos, nicht aus Feedback uebernommen).
$recentAdminActions = ActivityLog::adminActions($pdo, 10);

$feedbackTypeLabels = [
    'termin_tipp' => 'Termintipp',
    'foto_vorschlag' => 'Fotoempfehlung',
    'kino_tipp' => 'Kinotipp',
    'allgemein' => 'Allgemeines Feedback',
    'sprachnachricht' => 'Sprachnachricht',
];

// Anonyme Nutzungsstatistik (siehe api/track-view.php): Aufrufe pro Bereich,
// gesamt und in den letzten 7 Tagen.
$screenLabels = [
    'start' => 'Start',
    'episodes' => 'Folgen',
    'events' => 'Veranstaltungen',
    'movie_tips' => 'Kinotipps',
    'gallery' => 'Galerie',
    'feedback' => 'Feedback',
];
$totalViews = $pdo->query('SELECT screen, SUM(count) AS total FROM screen_views GROUP BY screen')
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$recentViews = $pdo->query(
    "SELECT screen, SUM(count) AS total FROM screen_views WHERE day >= CURDATE() - INTERVAL 7 DAY GROUP BY screen"
)->fetchAll(PDO::FETCH_KEY_PAIR);
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
<header class="admin-header">
    <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Südsalat">
    <p>APP-Administrationsbereich</p>
</header>
<nav class="admin-nav">
    <a href="<?= BASE_PATH ?>/admin/dashboard.php">Dashboard</a>
    <a href="<?= BASE_PATH ?>/admin/feedback.php">Aktivitäten</a>
    <a class="nav-gap" href="<?= BASE_PATH ?>/admin/events.php">Termine</a>
    <a href="<?= BASE_PATH ?>/admin/gallery.php">Galerie</a>
    <a href="<?= BASE_PATH ?>/admin/movie-tips.php">Kino</a>
    <a href="<?= BASE_PATH ?>/admin/location-tips.php">Locations</a>
    <a href="<?= BASE_PATH ?>/admin/tip-reviews.php">Rezensionen</a>
    <?php if ($isOwner): ?><a href="<?= BASE_PATH ?>/admin/newsletter.php">Newsletter</a><?php endif; ?>
    <a href="<?= BASE_PATH ?>/admin/change-password.php">Passwort ändern</a>
    <a href="<?= BASE_PATH ?>/admin/logout.php">Abmelden (<span id="logout-countdown" data-timeout-seconds="<?= ADMIN_IDLE_TIMEOUT_MINUTES * 60 ?>"></span>)</a>
</nav>
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
        <strong><?= $eventCount ?></strong> kommende Termine ·
        <strong><?= $photoCount ?></strong> Fotos in der Galerie
    </p>
</main>

<section class="content-box">
    <h2>Nutzung nach Bereich <span style="font-weight:normal;font-size:0.85rem;">(anonym, ohne Personenbezug)</span></h2>
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
</section>

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

<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
</body>
</html>
