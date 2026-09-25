<?php
declare(strict_types=1);

// Meldungen zu oeffentlichen Beitraegen (App 2.0), pro Beitrag zusammengefasst.
// "In Ordnung": Meldungen zurueckweisen, Beitrag (wieder) sichtbar.
// "Entfernen": Rezension/Galeriefoto loeschen, Verfasser bekommt den Grund per Mail (2FA-Bestaetigung).
// Tipps und Veranstaltungen werden auf ihrer eigenen Seite bearbeitet.

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;
use Suedsalat\ListenerContent;
use Suedsalat\Moderation;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$currentAdminRole = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
$currentAdminRole->execute([':id' => $adminId]);
$isOwner = $currentAdminRole->fetchColumn() === 'owner';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = (string) ($_POST['content_type'] ?? '');
    $id = (int) ($_POST['content_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if (!Moderation::isKnownType($type) || $id <= 0) {
        $error = 'Unbekannter Beitrag.';
    } elseif ($action === 'dismiss') {
        Moderation::dismiss($pdo, $type, $id, $adminId);
        header('Location: ' . BASE_PATH . '/admin/reports.php?ok=dismiss');
        exit;
    } elseif ($action === 'remove' && in_array($type, ['review', 'photo', 'comment'], true)) {
        if (!verify_admin_delete_confirmation($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
            header('Location: ' . BASE_PATH . '/admin/reports.php?delete_error=1');
            exit;
        }
        $reason = trim(normalize_input((string) ($_POST['reason'] ?? '')));
        Moderation::removeContent($pdo, $type, $id, $adminId, $reason !== '' ? $reason : 'Verstoß gegen die Nutzungsbedingungen');
        header('Location: ' . BASE_PATH . '/admin/reports.php?ok=remove');
        exit;
    }
}

$notice = match ($_GET['ok'] ?? '') {
    'dismiss' => 'Meldungen zurückgewiesen – der Beitrag ist (wieder) sichtbar.',
    'remove' => 'Beitrag entfernt. Der Verfasser wurde per E-Mail mit Grund informiert.',
    default => null,
};
$deleteError = isset($_GET['delete_error']);

// Offene Meldungen, pro Beitrag gebuendelt
$groups = $pdo->query(
    "SELECT content_type, content_id, COUNT(*) AS total,
            COUNT(DISTINCT reporter_listener_id) AS registered,
            MIN(created_at) AS first_at, MAX(created_at) AS last_at
     FROM content_reports WHERE status = 'open'
     GROUP BY content_type, content_id
     ORDER BY MAX(created_at) DESC"
)->fetchAll();

$detailStmt = $pdo->prepare(
    "SELECT r.category, r.report_text, r.created_at, l.nickname AS reporter
     FROM content_reports r LEFT JOIN listeners l ON l.id = r.reporter_listener_id
     WHERE r.status = 'open' AND r.content_type = :t AND r.content_id = :id ORDER BY r.created_at"
);

/** Vorschau eines Beitrags fuer die Liste. @return array{text:string,image:?string,author:?string,author_id:?int,hidden:bool,edit:?string,exists:bool} */
function report_preview(PDO $pdo, string $type, int $id): array
{
    $none = ['text' => '(Beitrag existiert nicht mehr)', 'image' => null, 'author' => null, 'author_id' => null, 'hidden' => false, 'edit' => null, 'exists' => false];
    $name = ListenerContent::displayNameSql('x', match ($type) { 'review' => 'reviewer_name', 'comment' => 'author_name', default => 'submitted_by_name' });
    $join = ListenerContent::joinSql('x');
    switch ($type) {
        case 'review':
            $stmt = $pdo->prepare("SELECT x.review_text AS text, x.rating, x.listener_id, x.hidden_at, {$name} AS author,
                    COALESCE(m.title, lt.name, ev.title) AS tip
                FROM tip_reviews x {$join}
                LEFT JOIN movie_tips m ON x.tip_type = 'movie_tip' AND m.id = x.tip_id
                LEFT JOIN location_tips lt ON x.tip_type = 'location_tip' AND lt.id = x.tip_id
                LEFT JOIN events ev ON x.tip_type = 'event' AND ev.id = x.tip_id
                WHERE x.id = :id");
            $stmt->execute([':id' => $id]);
            $r = $stmt->fetch();
            return $r ? ['text' => str_repeat('★', (int) $r['rating']) . ' zu „' . $r['tip'] . '“: ' . ($r['text'] ?? ''), 'image' => null,
                'author' => $r['author'], 'author_id' => $r['listener_id'] !== null ? (int) $r['listener_id'] : null,
                'hidden' => $r['hidden_at'] !== null, 'edit' => null, 'exists' => true] : $none;
        case 'photo':
            $stmt = $pdo->prepare("SELECT x.description AS text, x.image_path, x.media_type, x.listener_id, x.hidden_at, {$name} AS author
                FROM photos x {$join} WHERE x.id = :id");
            $stmt->execute([':id' => $id]);
            $r = $stmt->fetch();
            return $r ? ['text' => (string) ($r['text'] ?? ''), 'image' => $r['media_type'] === 'video' ? null : $r['image_path'],
                'author' => $r['author'], 'author_id' => $r['listener_id'] !== null ? (int) $r['listener_id'] : null,
                'hidden' => $r['hidden_at'] !== null, 'edit' => BASE_PATH . '/admin/gallery.php?edit=' . $id, 'exists' => true] : $none;
        case 'comment':
            $stmt = $pdo->prepare("SELECT x.comment_text AS text, x.photo_id, x.listener_id, x.hidden_at, {$name} AS author, ph.image_path
                FROM gallery_comments x {$join} LEFT JOIN photos ph ON ph.id = x.photo_id WHERE x.id = :id");
            $stmt->execute([':id' => $id]);
            $r = $stmt->fetch();
            return $r ? ['text' => 'Kommentar zum Foto: ' . $r['text'], 'image' => $r['image_path'],
                'author' => $r['author'], 'author_id' => $r['listener_id'] !== null ? (int) $r['listener_id'] : null,
                'hidden' => $r['hidden_at'] !== null, 'edit' => BASE_PATH . '/admin/gallery.php?edit=' . $r['photo_id'] . '#kommentare', 'exists' => true] : $none;
        default:
            [$titleCol, $page] = [
                'movie_tip' => ['title', 'movie-tips.php'],
                'location_tip' => ['name', 'location-tips.php'],
                'event' => ['title', 'events.php'],
            ][$type];
            $table = Moderation::TYPES[$type]['table'];
            $stmt = $pdo->prepare("SELECT x.{$titleCol} AS title, x.description AS text, x.image_path, x.listener_id, {$name} AS author
                FROM {$table} x {$join} WHERE x.id = :id");
            $stmt->execute([':id' => $id]);
            $r = $stmt->fetch();
            return $r ? ['text' => '„' . $r['title'] . '“ – ' . ($r['text'] ?? ''), 'image' => $r['image_path'],
                'author' => $r['author'], 'author_id' => $r['listener_id'] !== null ? (int) $r['listener_id'] : null,
                'hidden' => false, 'edit' => BASE_PATH . '/admin/' . $page . '?edit=' . $id, 'exists' => true] : $none;
    }
}

$handled = $pdo->query(
    "SELECT content_type, content_id, status, MAX(handled_at) AS handled_at, COUNT(*) AS total, MAX(a.name) AS admin
     FROM content_reports c LEFT JOIN admins a ON a.id = c.handled_by
     WHERE status <> 'open' AND handled_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY content_type, content_id, status ORDER BY MAX(handled_at) DESC LIMIT 50"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Meldungen – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Meldungen</h1>
    <p style="font-size:0.9rem;color:#666;">Von Hörern gemeldete Beiträge. Rezensionen, Galeriefotos und Kommentare werden automatisch ausgeblendet, sobald <?= Moderation::AUTO_HIDE_THRESHOLD ?> verschiedene registrierte Hörer sie gemeldet haben – bis du entscheidest. Offensichtlich rechtswidrige Beiträge bitte sofort entfernen.</p>

    <?php if ($notice): ?><p class="info"><?= htmlspecialchars($notice, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($deleteError): ?><p class="error text-center">Falscher Code – nichts wurde entfernt.</p><?php endif; ?>

    <h2>Offen (<?= count($groups) ?>)</h2>
    <?php if ($groups === []): ?>
        <p>Keine offenen Meldungen.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table>
        <thead><tr><th>Beitrag</th><th>Verfasser</th><th>Meldungen</th><th>Gründe</th><th>Status</th><th>Aktionen</th></tr></thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
            <?php
            $type = (string) $g['content_type'];
            $cid = (int) $g['content_id'];
            $p = report_preview($pdo, $type, $cid);
            $detailStmt->execute([':t' => $type, ':id' => $cid]);
            $details = $detailStmt->fetchAll();
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars(Moderation::TYPES[$type]['label'], ENT_QUOTES) ?></strong><br>
                    <?php if ($p['image']): ?><img src="<?= htmlspecialchars($p['image'], ENT_QUOTES) ?>" alt="" style="max-width:120px;border-radius:6px;display:block;margin:4px 0;"><?php endif; ?>
                    <?= htmlspecialchars(mb_strimwidth($p['text'], 0, 200, '…'), ENT_QUOTES) ?>
                </td>
                <td>
                    <?= htmlspecialchars((string) ($p['author'] ?? '–'), ENT_QUOTES) ?>
                    <?php if ($p['author_id'] !== null): ?><br><a href="<?= BASE_PATH ?>/admin/listeners.php#listener-<?= $p['author_id'] ?>">Zum Hörerkonto</a><?php endif; ?>
                </td>
                <td><?= (int) $g['total'] ?> gesamt<br><small><?= (int) $g['registered'] ?> von registrierten Hörern</small></td>
                <td>
                    <?php foreach ($details as $d): ?>
                        <div style="margin-bottom:6px;">
                            <strong><?= htmlspecialchars(Moderation::CATEGORIES[$d['category']] ?? $d['category'], ENT_QUOTES) ?></strong>
                            <small>(<?= htmlspecialchars($d['reporter'] ?? 'Gast', ENT_QUOTES) ?>, <?= date('d.m. H:i', strtotime($d['created_at'])) ?>)</small>
                            <?php if ($d['report_text']): ?><br>„<?= htmlspecialchars($d['report_text'], ENT_QUOTES) ?>“<?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </td>
                <td><?= $p['hidden'] ? '<strong class="error">Automatisch ausgeblendet</strong>' : ($p['exists'] ? 'Sichtbar' : '–') ?></td>
                <td>
                    <div class="actions">
                        <form method="post">
                            <input type="hidden" name="action" value="dismiss">
                            <input type="hidden" name="content_type" value="<?= htmlspecialchars($type, ENT_QUOTES) ?>">
                            <input type="hidden" name="content_id" value="<?= $cid ?>">
                            <button type="submit"><?= $p['hidden'] ? 'In Ordnung – wieder einblenden' : 'In Ordnung' ?></button>
                        </form>
                        <?php if (in_array($type, ['review', 'photo', 'comment'], true) && $p['exists']): ?>
                            <form method="post" onsubmit="return false;">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="content_type" value="<?= htmlspecialchars($type, ENT_QUOTES) ?>">
                                <input type="hidden" name="content_id" value="<?= $cid ?>">
                                <input type="text" name="reason" maxlength="255" placeholder="Grund (geht per Mail an den Verfasser)" aria-label="Grund">
                                <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Der Beitrag wird endgültig gelöscht. Der Verfasser bekommt den Grund per E-Mail.')">Entfernen</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($p['edit'] !== null): ?>
                            <a class="button" href="<?= htmlspecialchars($p['edit'], ENT_QUOTES) ?>"><?= $type === 'comment' ? 'Zum Foto' : 'Bearbeiten' ?></a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <h2>Erledigt (letzte 30 Tage)</h2>
    <?php if ($handled === []): ?>
        <p>Nichts erledigt in den letzten 30 Tagen.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table>
        <thead><tr><th>Beitrag</th><th>Meldungen</th><th>Entscheidung</th><th>Von</th><th>Am</th></tr></thead>
        <tbody>
        <?php foreach ($handled as $h): ?>
            <tr class="is-done">
                <td><?= htmlspecialchars(Moderation::TYPES[$h['content_type']]['label'] ?? $h['content_type'], ENT_QUOTES) ?> #<?= (int) $h['content_id'] ?></td>
                <td><?= (int) $h['total'] ?></td>
                <td><?= $h['status'] === 'removed' ? 'Entfernt' : 'In Ordnung' ?></td>
                <td><?= htmlspecialchars((string) ($h['admin'] ?? '–'), ENT_QUOTES) ?></td>
                <td><?= date('d.m.Y H:i', strtotime($h['handled_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/confirm-modal.php'; ?>
<?php require __DIR__ . '/partials/sidebar-close.php'; ?>
<script src="<?= BASE_PATH ?>/admin/assets/confirm-delete.js?v=<?= @filemtime(__DIR__ . '/assets/confirm-delete.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/scroll-restore.js?v=<?= @filemtime(__DIR__ . '/assets/scroll-restore.js') ?>"></script>
</body>
</html>
