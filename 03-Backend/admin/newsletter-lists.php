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

// Wie beim eigentlichen Newsletter-Versand ist auch die Verwaltung der
// Empfaengerlisten bewusst nur dem Owner (Thorsten) vorbehalten.
if (!$isOwner) {
    header('Location: ' . BASE_PATH . '/admin/dashboard.php');
    exit;
}

$error = null;

// Normalisiert/prueft eine Adresse - identisch zur Logik in admin/newsletter.php,
// damit hier gepflegte Listen genauso zuverlaessig funktionieren wie die
// oeffentliche Double-Opt-In-Liste (Unicode im lokalen Teil, IDN-Domains).
function normalize_list_member_email(string $email): ?string
{
    $email = trim($email);
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($email, Normalizer::FORM_C);
        if ($normalized !== false) {
            $email = $normalized;
        }
    }

    $atPos = strrpos($email, '@');
    if ($atPos === false) {
        return null;
    }
    $local = substr($email, 0, $atPos);
    $domain = substr($email, $atPos + 1);

    if (function_exists('idn_to_ascii')) {
        $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($asciiDomain !== false) {
            $domain = $asciiDomain;
        }
    }
    $domain = strtolower($domain);

    if (!preg_match('/^[\p{L}\p{N}.!#$%&\'*+\/=?^_`{|}~-]+$/u', $local)) {
        return null;
    }
    if (!filter_var('a@' . $domain, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    return $local . '@' . $domain;
}

// Loeschen - Passwort-Bestaetigung erforderlich, gleiches Muster wie ueberall sonst im Admin-Bereich.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_admin_password($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
        header('Location: ' . BASE_PATH . '/admin/newsletter-lists.php?delete_error=1');
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM newsletter_lists WHERE id = :id');
    $stmt->execute([':id' => (int) $_POST['delete_id']]);
    header('Location: ' . BASE_PATH . '/admin/newsletter-lists.php');
    exit;
}

// Anlegen oder Bearbeiten (Name + Mitglieder als Fliesstext, eine Adresse pro Zeile -
// beim Speichern wird die komplette Mitgliederliste ersetzt, das ist einfacher zu
// bedienen als einzelne Zeilen an-/abzuhaken).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int) $_POST['edit_id'] : null;
    $name = trim((string) $_POST['name']);
    $rawEmails = (string) ($_POST['emails'] ?? '');

    $emails = [];
    $invalidLines = [];
    foreach (preg_split('/[\r\n,;]+/', $rawEmails) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $normalized = normalize_list_member_email($line);
        if ($normalized === null) {
            $invalidLines[] = $line;
        } elseif (!in_array($normalized, $emails, true)) {
            $emails[] = $normalized;
        }
    }

    if ($name === '') {
        $error = 'Name der Liste ist ein Pflichtfeld.';
    } elseif (!empty($invalidLines)) {
        $error = 'Ungültige E-Mail-Adresse(n): ' . implode(', ', $invalidLines);
    } elseif ($editId) {
        $stmt = $pdo->prepare('UPDATE newsletter_lists SET name = :name WHERE id = :id');
        $stmt->execute([':name' => $name, ':id' => $editId]);

        $pdo->prepare('DELETE FROM newsletter_list_members WHERE list_id = :id')->execute([':id' => $editId]);
        if (!empty($emails)) {
            $insertMember = $pdo->prepare('INSERT INTO newsletter_list_members (list_id, email) VALUES (:list_id, :email)');
            foreach ($emails as $email) {
                $insertMember->execute([':list_id' => $editId, ':email' => $email]);
            }
        }
        header('Location: ' . BASE_PATH . '/admin/newsletter-lists.php');
        exit;
    } else {
        $stmt = $pdo->prepare('INSERT INTO newsletter_lists (name) VALUES (:name)');
        $stmt->execute([':name' => $name]);
        $newListId = (int) $pdo->lastInsertId();

        if (!empty($emails)) {
            $insertMember = $pdo->prepare('INSERT INTO newsletter_list_members (list_id, email) VALUES (:list_id, :email)');
            foreach ($emails as $email) {
                $insertMember->execute([':list_id' => $newListId, ':email' => $email]);
            }
        }
        header('Location: ' . BASE_PATH . '/admin/newsletter-lists.php');
        exit;
    }
}

$editList = null;
$editListEmails = '';
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM newsletter_lists WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $editList = $stmt->fetch() ?: null;

    if ($editList) {
        $stmt = $pdo->prepare('SELECT email FROM newsletter_list_members WHERE list_id = :id ORDER BY email ASC');
        $stmt->execute([':id' => $editList['id']]);
        $editListEmails = implode("\n", $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

$deleteError = isset($_GET['delete_error']);
$showCreateForm = $editList !== null || $error !== null;

$allLists = $pdo->query(
    'SELECT nl.*, (SELECT COUNT(*) FROM newsletter_list_members WHERE list_id = nl.id) AS member_count
     FROM newsletter_lists nl
     ORDER BY nl.created_at DESC'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Newsletter-Empfängerlisten – Südsalat Admin</title>
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
    <a href="<?= BASE_PATH ?>/admin/statistics.php">Statistiken</a>
    <?php if ($isOwner): ?>
    <a href="<?= BASE_PATH ?>/admin/newsletter.php">Newsletter</a>
    <a href="<?= BASE_PATH ?>/admin/newsletter-lists.php">Empfängerlisten</a>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/admin/change-password.php">Passwort ändern</a>
    <a href="<?= BASE_PATH ?>/admin/logout.php">Abmelden (<span id="logout-countdown" data-timeout-seconds="<?= ADMIN_IDLE_TIMEOUT_MINUTES * 60 ?>"></span>)</a>
</nav>
<main class="content-box">
    <h1>Newsletter-Empfängerlisten</h1>
    <p style="font-size:0.9rem;color:#666;">
        Zusätzlich zur normalen Abonnenten-Liste (Double-Opt-In über die Website) kannst du hier eigene Listen anlegen,
        z. B. für eine Testergruppe. Beim Newsletter-Versand kannst du dann wählen, ob er an "Alle Abonnenten" oder
        an eine dieser eigenen Listen gehen soll.
    </p>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if ($deleteError): ?>
        <p class="error text-center">Falsches Passwort — nichts wurde gelöscht.</p>
    <?php endif; ?>

    <button type="button" class="button" data-show-create-form="create-form" style="<?= $showCreateForm ? 'display:none;' : '' ?>">+ Liste anlegen</button>
    <div id="create-form" style="<?= $showCreateForm ? '' : 'display:none;' ?>">
        <?php if (!$editList): ?>
            <button type="button" class="button-secondary" data-hide-create-form="create-form">- Liste anlegen</button>
        <?php endif; ?>
        <form method="post">
            <?php if ($editList): ?>
                <input type="hidden" name="edit_id" value="<?= (int) $editList['id'] ?>">
            <?php endif; ?>
            <label>Name der Liste
                <input type="text" name="name" required value="<?= htmlspecialchars($editList['name'] ?? '', ENT_QUOTES) ?>" placeholder="z. B. Play Store Tester">
            </label>
            <label>E-Mail-Adressen (eine pro Zeile)
                <textarea name="emails" rows="8"><?= htmlspecialchars($editListEmails, ENT_QUOTES) ?></textarea>
            </label>
            <p style="font-size:0.85rem;color:#666;">Beim Speichern ersetzt diese Liste komplett die bisherigen Mitglieder der Liste.</p>
            <div class="button-row">
                <button type="submit"><?= $editList ? 'Liste aktualisieren' : 'Liste anlegen' ?></button>
                <?php if ($editList): ?>
                    <a class="button" href="<?= BASE_PATH ?>/admin/newsletter-lists.php">Abbrechen</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <h2>Bisherige Listen</h2>
    <?php if (empty($allLists)): ?>
        <p>Noch keine eigene Liste angelegt.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Name</th><th>Mitglieder</th><th>Angelegt</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($allLists as $list): ?>
            <tr>
                <td><?= htmlspecialchars($list['name'], ENT_QUOTES) ?></td>
                <td><?= (int) $list['member_count'] ?></td>
                <td><?= htmlspecialchars(date('d.m.Y', strtotime($list['created_at'])), ENT_QUOTES) ?></td>
                <td>
                    <div class="actions">
                        <a class="button" href="<?= BASE_PATH ?>/admin/newsletter-lists.php?edit=<?= (int) $list['id'] ?>">Bearbeiten</a>
                        <form method="post" onsubmit="return false;">
                            <input type="hidden" name="delete_id" value="<?= (int) $list['id'] ?>">
                            <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Die Liste „<?= htmlspecialchars(addslashes($list['name']), ENT_QUOTES) ?>“ wird dauerhaft gelöscht.')">Löschen</button>
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
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/toggle-create-form.js?v=<?= @filemtime(__DIR__ . '/assets/toggle-create-form.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
</body>
</html>
