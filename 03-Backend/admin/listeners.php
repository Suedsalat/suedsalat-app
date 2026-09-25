<?php
declare(strict_types=1);

// Registrierte Hoerer (App 2.0): Uebersicht, Spitzname aendern (beide Admins),
// Sperren/Entsperren (nur Owner, Sperren mit Begruendung und 2FA-Bestaetigung).
// Konten in der Rueckkehrfrist erscheinen als "Loeschung vorgemerkt".

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerMail;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$currentAdminRole = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
$currentAdminRole->execute([':id' => $adminId]);
$isOwner = $currentAdminRole->fetchColumn() === 'owner';

$error = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $listenerId = (int) ($_POST['listener_id'] ?? 0);
    $listener = Listener::findById($pdo, $listenerId);

    if ($listener === null) {
        $error = 'Dieses Hörerkonto gibt es nicht mehr.';
    } elseif ($action === 'rename') {
        $nickname = trim(normalize_input((string) ($_POST['nickname'] ?? '')));
        $problem = Listener::nicknameProblem($pdo, $nickname, $listenerId);
        if ($problem !== null) {
            $error = $problem;
        } else {
            $pdo->prepare('UPDATE listeners SET nickname = :n, nickname_key = :k, updated_at = NOW() WHERE id = :id')
                ->execute([':n' => $nickname, ':k' => Listener::nicknameKey($nickname), ':id' => $listenerId]);
            header('Location: ' . BASE_PATH . '/admin/listeners.php?ok=rename#listener-' . $listenerId);
            exit;
        }
    } elseif ($action === 'block' && $isOwner) {
        $reason = trim(normalize_input((string) ($_POST['reason'] ?? '')));
        if (!verify_admin_delete_confirmation($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
            header('Location: ' . BASE_PATH . '/admin/listeners.php?delete_error=1#listener-' . $listenerId);
            exit;
        }
        if ($reason === '') {
            $error = 'Bitte gib einen Grund an – der Hörer bekommt ihn per E-Mail.';
        } else {
            $pdo->prepare('UPDATE listeners SET blocked_at = NOW(), blocked_reason = :r, updated_at = NOW() WHERE id = :id')
                ->execute([':r' => mb_substr($reason, 0, 255), ':id' => $listenerId]);
            ListenerMail::blocked($listener, $reason);
            header('Location: ' . BASE_PATH . '/admin/listeners.php?ok=block#listener-' . $listenerId);
            exit;
        }
    } elseif ($action === 'unblock' && $isOwner) {
        $pdo->prepare('UPDATE listeners SET blocked_at = NULL, blocked_reason = NULL, updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $listenerId]);
        ListenerMail::unblocked($listener);
        header('Location: ' . BASE_PATH . '/admin/listeners.php?ok=unblock#listener-' . $listenerId);
        exit;
    }
}

$notice = match ($_GET['ok'] ?? '') {
    'rename' => 'Spitzname geändert – er erscheint sofort bei allen Beiträgen.',
    'block' => 'Konto gesperrt. Der Hörer wurde per E-Mail mit Begründung informiert.',
    'unblock' => 'Sperre aufgehoben. Der Hörer wurde per E-Mail informiert.',
    default => null,
};
$deleteError = isset($_GET['delete_error']);

$search = trim((string) ($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE l.nickname LIKE :q OR l.first_name LIKE :q2 OR l.last_name LIKE :q3 OR l.email LIKE :q4';
    $like = '%' . $search . '%';
    $params = [':q' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
}
$stmt = $pdo->prepare(
    "SELECT l.*,
        (SELECT COUNT(*) FROM tip_reviews r WHERE r.listener_id = l.id)
          + (SELECT COUNT(*) FROM gallery_comments gc WHERE gc.listener_id = l.id) AS review_count,
        (SELECT COUNT(*) FROM photos p WHERE p.listener_id = l.id)
          + (SELECT COUNT(*) FROM movie_tips m WHERE m.listener_id = l.id)
          + (SELECT COUNT(*) FROM location_tips lt WHERE lt.listener_id = l.id)
          + (SELECT COUNT(*) FROM events e WHERE e.listener_id = l.id) AS adopted_count,
        (SELECT COUNT(*) FROM content_reports c WHERE c.status = 'open' AND (
            (c.content_type = 'review' AND c.content_id IN (SELECT id FROM tip_reviews WHERE listener_id = l.id)) OR
            (c.content_type = 'photo' AND c.content_id IN (SELECT id FROM photos WHERE listener_id = l.id)) OR
            (c.content_type = 'comment' AND c.content_id IN (SELECT id FROM gallery_comments WHERE listener_id = l.id)))) AS open_reports
     FROM listeners l {$where}
     ORDER BY l.deletion_requested_at IS NOT NULL, l.blocked_at IS NULL, l.nickname"
);
$stmt->execute($params);
$listeners = $stmt->fetchAll();

$fmt = static fn (?string $dt): string => $dt ? date('d.m.Y', strtotime($dt)) : '–';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Hörerkonten – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Hörerkonten</h1>
    <p style="font-size:0.9rem;color:#666;">Registrierte Hörer der App. Vor- und Nachname sowie E-Mail-Adresse sind nur hier sichtbar, in der App erscheint ausschließlich der Spitzname.<?= $isOwner ? '' : ' Sperren und Entsperren kann nur Thorsten.' ?></p>

    <?php if ($notice): ?><p class="info"><?= htmlspecialchars($notice, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($deleteError): ?><p class="error text-center">Falscher Code – das Konto wurde nicht gesperrt.</p><?php endif; ?>

    <form method="get" style="margin-bottom:16px;">
        <label>Suchen (Spitzname, Name, E-Mail) <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"></label>
        <button type="submit">Suchen</button>
        <?php if ($search !== ''): ?><a class="button button-secondary" href="<?= BASE_PATH ?>/admin/listeners.php">Zurücksetzen</a><?php endif; ?>
    </form>

    <p><?= count($listeners) ?> Konto/Konten<?= $search !== '' ? ' gefunden' : '' ?>.</p>

    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Spitzname</th><th>Name</th><th>E-Mail</th><th>Registriert</th><th>Zuletzt angemeldet</th><th>Beiträge</th><th>Status</th><th>Aktionen</th></tr>
        </thead>
        <tbody>
        <?php foreach ($listeners as $l): ?>
            <?php $inGrace = $l['deletion_requested_at'] !== null; ?>
            <tr id="listener-<?= (int) $l['id'] ?>" class="<?= $inGrace ? 'is-done' : '' ?>">
                <td><strong><?= htmlspecialchars($l['nickname'], ENT_QUOTES) ?></strong>
                    <?php if ((int) $l['review_account']): ?><br><small title="Beiträge sieht nur dieses Konto selbst">Prüfkonto (Apple/Google)</small><?php endif; ?></td>
                <td><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($l['email'], ENT_QUOTES) ?></td>
                <td><?= $fmt($l['created_at']) ?></td>
                <td><?= $fmt($l['last_login_at']) ?></td>
                <td>
                    <?= (int) $l['review_count'] ?> Rezension(en)/Kommentar(e), <?= (int) $l['adopted_count'] ?> übernommen
                    <?php if ((int) $l['open_reports'] > 0): ?><br><a href="<?= BASE_PATH ?>/admin/reports.php" class="error"><?= (int) $l['open_reports'] ?> offene Meldung(en)</a><?php endif; ?>
                </td>
                <td>
                    <?php if ($inGrace): ?>
                        <strong>Löschung vorgemerkt</strong><br>Rückkehrfrist bis <?= $fmt($l['deletion_final_at']) ?>
                        <br><small>Texte löschen: <?= $l['deletion_delete_texts'] ? 'ja' : 'nein' ?> · Fotos löschen: <?= $l['deletion_delete_photos'] ? 'ja' : 'nein' ?></small>
                    <?php elseif ($l['blocked_at'] !== null): ?>
                        <strong class="error">Gesperrt</strong> seit <?= $fmt($l['blocked_at']) ?><br><small><?= htmlspecialchars((string) $l['blocked_reason'], ENT_QUOTES) ?></small>
                    <?php else: ?>
                        Aktiv
                    <?php endif; ?>
                </td>
                <td>
                    <div class="actions">
                    <?php if (!$inGrace): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="listener_id" value="<?= (int) $l['id'] ?>">
                            <input type="text" name="nickname" value="<?= htmlspecialchars($l['nickname'], ENT_QUOTES) ?>" maxlength="30" required aria-label="Neuer Spitzname">
                            <button type="submit">Spitzname ändern</button>
                        </form>
                        <?php if ($isOwner && $l['blocked_at'] === null): ?>
                            <form method="post" onsubmit="return false;">
                                <input type="hidden" name="action" value="block">
                                <input type="hidden" name="listener_id" value="<?= (int) $l['id'] ?>">
                                <input type="text" name="reason" maxlength="255" placeholder="Grund (geht per Mail an den Hörer)" aria-label="Grund der Sperre">
                                <button type="button" class="button-danger" onclick="if (!this.form.reason.value.trim()) { alert('Bitte einen Grund angeben – der Hörer bekommt ihn per E-Mail.'); return; } requestDelete(this.form, 'Das Konto „<?= htmlspecialchars(addslashes($l['nickname']), ENT_QUOTES) ?>“ wird für Beiträge gesperrt. Hören, Lesen und allgemeines Feedback bleiben möglich. Der Hörer bekommt den Grund per E-Mail.')">Sperren</button>
                            </form>
                        <?php elseif ($isOwner): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="unblock">
                                <input type="hidden" name="listener_id" value="<?= (int) $l['id'] ?>">
                                <button type="submit" class="button-secondary">Sperre aufheben</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($listeners === []): ?>
            <tr><td colspan="8">Noch keine registrierten Hörer<?= $search !== '' ? ' zu dieser Suche' : '' ?>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</main>
<?php require __DIR__ . '/partials/confirm-modal.php'; ?>
<?php require __DIR__ . '/partials/sidebar-close.php'; ?>
<script src="<?= BASE_PATH ?>/admin/assets/confirm-delete.js?v=<?= @filemtime(__DIR__ . '/assets/confirm-delete.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/scroll-restore.js?v=<?= @filemtime(__DIR__ . '/assets/scroll-restore.js') ?>"></script>
</body>
</html>
