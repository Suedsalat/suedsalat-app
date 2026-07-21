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

$tipTypeLabels = [
    'movie_tip' => 'Kino-/Filmtipp',
    'event' => 'Termin',
    'location_tip' => 'Locationtipp',
];

$tipTypeTables = [
    'movie_tip' => ['table' => 'movie_tips', 'name_column' => 'title'],
    'event' => ['table' => 'events', 'name_column' => 'title'],
    'location_tip' => ['table' => 'location_tips', 'name_column' => 'name'],
];

$deleteError = false;
$error = null;

// Manuell als Admin eintragen (z.B. zum Testen oder wenn jemand eine Bewertung
// muendlich/per Chat mitteilt statt ueber die App) - wird sofort freigegeben,
// da der Admin hier selbst die freigebende Instanz ist.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_add') {
    $tipRef = (string) ($_POST['tip_ref'] ?? '');
    $rating = (int) ($_POST['rating'] ?? 0);
    $reviewText = trim((string) ($_POST['review_text'] ?? '')) ?: null;

    [$tipType, $tipIdRaw] = array_pad(explode(':', $tipRef, 2), 2, null);
    $tipId = $tipIdRaw !== null ? (int) $tipIdRaw : 0;

    if (!isset($tipTypeTables[$tipType]) || $tipId <= 0) {
        $error = 'Bitte einen Eintrag auswählen.';
    } elseif ($rating < 1 || $rating > 5) {
        $error = 'Bitte 1 bis 5 Mikros auswählen.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO tip_reviews (tip_type, tip_id, rating, review_text, approved, approved_at, approved_by)
             VALUES (:tip_type, :tip_id, :rating, :review_text, 1, NOW(), :admin_id)'
        );
        $stmt->execute([
            ':tip_type' => $tipType,
            ':tip_id' => $tipId,
            ':rating' => $rating,
            ':review_text' => $reviewText,
            ':admin_id' => $adminId,
        ]);
        header('Location: ' . BASE_PATH . '/admin/tip-reviews.php');
        exit;
    }
}

// Freigeben
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    $stmt = $pdo->prepare('UPDATE tip_reviews SET approved = 1, approved_at = NOW(), approved_by = :admin_id WHERE id = :id');
    $stmt->execute([':admin_id' => $adminId, ':id' => (int) $_POST['review_id']]);
    header('Location: ' . BASE_PATH . '/admin/tip-reviews.php');
    exit;
}

// Freigabe zurueckziehen (kein Passwort noetig, nicht destruktiv - die Rezension bleibt erhalten)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    $stmt = $pdo->prepare('UPDATE tip_reviews SET approved = 0, approved_at = NULL, approved_by = NULL WHERE id = :id');
    $stmt->execute([':id' => (int) $_POST['review_id']]);
    header('Location: ' . BASE_PATH . '/admin/tip-reviews.php');
    exit;
}

// Ablehnen (loescht die Rezension dauerhaft) - Passwort-Bestaetigung erforderlich wie bei anderen Loeschvorgaengen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {
    if (!verify_admin_password($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
        header('Location: ' . BASE_PATH . '/admin/tip-reviews.php?delete_error=1');
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM tip_reviews WHERE id = :id');
    $stmt->execute([':id' => (int) $_POST['review_id']]);
    header('Location: ' . BASE_PATH . '/admin/tip-reviews.php');
    exit;
}

$deleteError = isset($_GET['delete_error']);

/** Laedt Rezensionen (pending oder approved) inkl. eines lesbaren Namens fuer den bewerteten Tipp. */
function load_tip_reviews(\PDO $pdo, bool $approved, array $tipTypeTables): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM tip_reviews WHERE approved = :approved ORDER BY created_at ' . ($approved ? 'DESC' : 'ASC')
    );
    $stmt->execute([':approved' => $approved ? 1 : 0]);
    $reviews = $stmt->fetchAll();

    foreach ($reviews as &$review) {
        $meta = $tipTypeTables[$review['tip_type']] ?? null;
        $review['tip_label'] = '(unbekannt)';
        if ($meta) {
            $lookup = $pdo->prepare(
                'SELECT ' . $meta['name_column'] . ' FROM ' . $meta['table'] . ' WHERE id = :id'
            );
            $lookup->execute([':id' => $review['tip_id']]);
            $label = $lookup->fetchColumn();
            if ($label !== false) {
                $review['tip_label'] = $label;
            }
        }
    }
    unset($review);

    return $reviews;
}

$pendingReviews = load_tip_reviews($pdo, false, $tipTypeTables);
$approvedReviews = load_tip_reviews($pdo, true, $tipTypeTables);

// Dropdown-Optionen fuer das manuelle Eintragen, gruppiert nach Bereich.
$tipOptions = [];
foreach ($tipTypeTables as $tipType => $meta) {
    $rows = $pdo->query('SELECT id, ' . $meta['name_column'] . ' AS label FROM ' . $meta['table'] . ' ORDER BY id DESC')->fetchAll();
    $tipOptions[$tipType] = $rows;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Rezensionen – Südsalat Admin</title>
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
    <h1>Rezensionen</h1>
    <p style="font-size:0.9rem;color:#666;">Mikro-Bewertungen und Rezensionstexte, die Nutzer:innen zu Kino-/Filmtipps, Terminen und Locationtipps abgegeben haben. Neue Rezensionen erscheinen erst öffentlich in der App, nachdem sie hier freigegeben wurden.</p>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if ($deleteError): ?>
        <p class="error text-center">Falsches Passwort — nichts wurde abgelehnt.</p>
    <?php endif; ?>

    <h2>Rezension manuell eintragen</h2>
    <p style="font-size:0.9rem;color:#666;">Zum Testen oder wenn euch jemand eine Bewertung mündlich/per Nachricht mitteilt statt über die App. Wird sofort freigegeben.</p>
    <form method="post">
        <input type="hidden" name="action" value="manual_add">
        <label>Eintrag
            <select name="tip_ref" required>
                <option value="">— auswählen —</option>
                <?php foreach ($tipTypeLabels as $type => $label): ?>
                    <?php if (!empty($tipOptions[$type])): ?>
                        <optgroup label="<?= htmlspecialchars($label, ENT_QUOTES) ?>">
                            <?php foreach ($tipOptions[$type] as $option): ?>
                                <option value="<?= htmlspecialchars($type, ENT_QUOTES) ?>:<?= (int) $option['id'] ?>">
                                    <?= htmlspecialchars($option['label'], ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Mikros
            <div class="mikro-rating">
                <input type="radio" name="rating" value="5" id="manual-rating-5" required><label for="manual-rating-5"></label>
                <input type="radio" name="rating" value="4" id="manual-rating-4"><label for="manual-rating-4"></label>
                <input type="radio" name="rating" value="3" id="manual-rating-3"><label for="manual-rating-3"></label>
                <input type="radio" name="rating" value="2" id="manual-rating-2"><label for="manual-rating-2"></label>
                <input type="radio" name="rating" value="1" id="manual-rating-1"><label for="manual-rating-1"></label>
            </div>
        </label>
        <label>Rezensionstext (optional) <textarea name="review_text" rows="3"></textarea></label>
        <button type="submit">Rezension eintragen</button>
    </form>

    <h2>Ausstehende Rezensionen (<?= count($pendingReviews) ?>)</h2>
    <?php if (empty($pendingReviews)): ?>
        <p>Aktuell keine ausstehenden Rezensionen.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Bereich</th><th>Eintrag</th><th>Mikros</th><th>Rezension</th><th>Eingereicht</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($pendingReviews as $review): ?>
            <tr>
                <td><?= htmlspecialchars($tipTypeLabels[$review['tip_type']] ?? $review['tip_type'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($review['tip_label'], ENT_QUOTES) ?></td>
                <td><?= (int) $review['rating'] ?> / 5</td>
                <td><?= $review['review_text'] !== null ? nl2br(htmlspecialchars($review['review_text'], ENT_QUOTES)) : '<em>(kein Text)</em>' ?></td>
                <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($review['created_at'])), ENT_QUOTES) ?></td>
                <td>
                    <div class="actions">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
                            <button type="submit">Freigeben</button>
                        </form>
                        <form method="post" onsubmit="return false;">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
                            <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Die Rezension zu „<?= htmlspecialchars(addslashes($review['tip_label']), ENT_QUOTES) ?>“ wird dauerhaft abgelehnt und gelöscht.')">Ablehnen</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <h2>Bereits freigegebene Rezensionen (<?= count($approvedReviews) ?>)</h2>
    <?php if (empty($approvedReviews)): ?>
        <p>Noch keine freigegebenen Rezensionen.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Bereich</th><th>Eintrag</th><th>Mikros</th><th>Rezension</th><th>Freigegeben</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($approvedReviews as $review): ?>
            <tr>
                <td><?= htmlspecialchars($tipTypeLabels[$review['tip_type']] ?? $review['tip_type'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($review['tip_label'], ENT_QUOTES) ?></td>
                <td><?= (int) $review['rating'] ?> / 5</td>
                <td><?= $review['review_text'] !== null ? nl2br(htmlspecialchars($review['review_text'], ENT_QUOTES)) : '<em>(kein Text)</em>' ?></td>
                <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $review['approved_at'])), ENT_QUOTES) ?></td>
                <td>
                    <div class="actions">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
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
