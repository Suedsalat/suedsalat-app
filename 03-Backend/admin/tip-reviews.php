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

// Rezensionen gibt es bewusst nur fuer Filmtipps und Locationtipps, nicht fuer
// Veranstaltungen - dort ergibt eine Bewertung inhaltlich keinen Sinn.
$tipTypeLabels = [
    'movie_tip' => 'Filmtipp',
    'location_tip' => 'Locationtipp',
];

$tipTypeTables = [
    'movie_tip' => ['table' => 'movie_tips', 'name_column' => 'title'],
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
    $reviewerName = trim((string) ($_POST['reviewer_name'] ?? '')) ?: null;

    [$tipType, $tipIdRaw] = array_pad(explode(':', $tipRef, 2), 2, null);
    $tipId = $tipIdRaw !== null ? (int) $tipIdRaw : 0;

    if (!isset($tipTypeTables[$tipType]) || $tipId <= 0) {
        $error = 'Bitte einen Eintrag auswählen.';
    } elseif ($rating < 1 || $rating > 5) {
        $error = 'Bitte 1 bis 5 Mikros auswählen.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO tip_reviews (tip_type, tip_id, rating, review_text, reviewer_name, approved, approved_at, approved_by)
             VALUES (:tip_type, :tip_id, :rating, :review_text, :reviewer_name, 1, NOW(), :admin_id)'
        );
        $stmt->execute([
            ':tip_type' => $tipType,
            ':tip_id' => $tipId,
            ':rating' => $rating,
            ':review_text' => $reviewText,
            ':reviewer_name' => $reviewerName,
            ':admin_id' => $adminId,
        ]);
        $newReviewId = (int) $pdo->lastInsertId();
        header('Location: ' . BASE_PATH . '/admin/tip-reviews.php#review-' . $newReviewId);
        exit;
    }
}

// Name + Rezensionstext nachtraeglich korrigieren (z.B. Rechtschreibfehler) - bewusst
// OHNE die Bewertung selbst aendern zu koennen, die stammt von der Person, die
// bewertet hat und soll inhaltlich unangetastet bleiben.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_review') {
    $reviewId = (int) ($_POST['review_id'] ?? 0);
    $reviewerName = trim((string) ($_POST['reviewer_name'] ?? '')) ?: null;
    $reviewText = trim((string) ($_POST['review_text'] ?? '')) ?: null;
    $stmt = $pdo->prepare('UPDATE tip_reviews SET reviewer_name = :reviewer_name, review_text = :review_text WHERE id = :id');
    $stmt->execute([':reviewer_name' => $reviewerName, ':review_text' => $reviewText, ':id' => $reviewId]);
    header('Location: ' . BASE_PATH . '/admin/tip-reviews.php#review-' . $reviewId);
    exit;
}

// Loeschen - Rezensionen erscheinen seit der Umstellung auf "sofort live"
// ohne Admin-Freigabe, Moderation passiert dadurch nur noch im Nachhinein
// per Bearbeiten/Loeschen. Passwort-/2FA-Bestaetigung wie bei anderen
// Loeschvorgaengen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_review') {
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

// Formular standardmaessig eingeklappt, ausser nach einem Fehler - dann direkt offen.
$showCreateForm = $error !== null;

// Zum Bearbeiten (Name/Text) laden
$editReview = null;
if (isset($_GET['edit_review'])) {
    $stmt = $pdo->prepare('SELECT * FROM tip_reviews WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit_review']]);
    $editReview = $stmt->fetch() ?: null;
}

/** Laedt alle Rezensionen inkl. eines lesbaren Namens fuer den bewerteten Tipp. */
function load_tip_reviews(\PDO $pdo, array $tipTypeTables): array
{
    $stmt = $pdo->query('SELECT * FROM tip_reviews ORDER BY created_at DESC');
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

$allReviews = load_tip_reviews($pdo, $tipTypeTables);

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
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Rezensionen</h1>
    <p style="font-size:0.9rem;color:#666;">Mikro-Bewertungen und Rezensionstexte, die Nutzer:innen zu Filmtipps und Locationtipps abgegeben haben. Neue Rezensionen erscheinen sofort öffentlich in der App, ohne Freigabe hier — du kannst sie im Nachhinein bearbeiten oder löschen.</p>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if ($deleteError): ?>
        <p class="error text-center">Falsches Passwort — nichts wurde gelöscht.</p>
    <?php endif; ?>

    <button type="button" class="button" data-show-create-form="create-form" style="<?= $showCreateForm ? 'display:none;' : '' ?>">+ Rezension eintragen</button>
    <div id="create-form" style="<?= $showCreateForm ? '' : 'display:none;' ?>">
    <button type="button" class="button-secondary" data-hide-create-form="create-form">- Rezension eintragen</button>
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
        <label>Name (optional) <input type="text" name="reviewer_name"></label>
        <label>Rezensionstext (optional) <textarea name="review_text" rows="3"></textarea></label>
        <button type="submit">Rezension eintragen</button>
    </form>
    </div>

    <?php if ($editReview): ?>
    <h2>Rezension bearbeiten</h2>
    <p style="font-size:0.9rem;color:#666;">Nur Name und Rezensionstext lassen sich hier korrigieren (z. B. Rechtschreibfehler) - die Mikro-Bewertung selbst kommt von der bewertenden Person und bleibt unverändert.</p>
    <form method="post">
        <input type="hidden" name="action" value="update_review">
        <input type="hidden" name="review_id" value="<?= (int) $editReview['id'] ?>">
        <label>Mikros (nicht änderbar) <input type="text" value="<?= (int) $editReview['rating'] ?> / 5" disabled></label>
        <label>Name <input type="text" name="reviewer_name" value="<?= htmlspecialchars($editReview['reviewer_name'] ?? '', ENT_QUOTES) ?>"></label>
        <label>Rezensionstext <textarea name="review_text" rows="3"><?= htmlspecialchars($editReview['review_text'] ?? '', ENT_QUOTES) ?></textarea></label>
        <div class="button-row">
            <button type="submit">Speichern</button>
            <a class="button" href="<?= BASE_PATH ?>/admin/tip-reviews.php">Abbrechen</a>
        </div>
    </form>
    <?php endif; ?>

    <h2>Alle Rezensionen (<?= count($allReviews) ?>)</h2>
    <?php if (empty($allReviews)): ?>
        <p>Noch keine Rezensionen.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Bereich</th><th>Eintrag</th><th>Mikros</th><th>Name</th><th>Rezension</th><th>Eingereicht</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($allReviews as $review): ?>
            <tr id="review-<?= (int) $review['id'] ?>">
                <td><?= htmlspecialchars($tipTypeLabels[$review['tip_type']] ?? $review['tip_type'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($review['tip_label'], ENT_QUOTES) ?></td>
                <td><?= (int) $review['rating'] ?> / 5</td>
                <td><?= htmlspecialchars($review['reviewer_name'] ?? '—', ENT_QUOTES) ?></td>
                <td><?= $review['review_text'] !== null ? nl2br(htmlspecialchars($review['review_text'], ENT_QUOTES)) : '<em>(kein Text)</em>' ?></td>
                <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($review['created_at'])), ENT_QUOTES) ?></td>
                <td>
                    <div class="actions">
                        <a class="button" href="<?= BASE_PATH ?>/admin/tip-reviews.php?edit_review=<?= (int) $review['id'] ?>">Bearbeiten</a>
                        <form method="post" onsubmit="return false;">
                            <input type="hidden" name="action" value="delete_review">
                            <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
                            <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Die Rezension zu „<?= htmlspecialchars(addslashes($review['tip_label']), ENT_QUOTES) ?>“ wird dauerhaft gelöscht.')">Löschen</button>
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
        <p><strong>Zur Bestätigung: Code aus deiner Authenticator-App</strong></p>
        <p style="font-size:0.85rem;color:#666;margin-top:-8px;">Falls du noch kein 2FA eingerichtet hast, geht hier auch dein normales Passwort.</p>
        <input type="text" inputmode="numeric" autocomplete="one-time-code" id="confirm-password" placeholder="Code oder Passwort">
        <p id="confirm-error" class="error" style="display:none;"></p>
        <div class="modal-actions">
            <button type="button" onclick="confirmStep2Cancel()">Abbrechen</button>
            <button type="button" class="button-danger" onclick="confirmStep2Ok()">OK</button>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/sidebar-close.php'; ?>
<script src="<?= BASE_PATH ?>/admin/assets/confirm-delete.js?v=<?= @filemtime(__DIR__ . '/assets/confirm-delete.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/toggle-create-form.js?v=<?= @filemtime(__DIR__ . '/assets/toggle-create-form.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/scroll-restore.js?v=<?= @filemtime(__DIR__ . '/assets/scroll-restore.js') ?>"></script>
</body>
</html>
