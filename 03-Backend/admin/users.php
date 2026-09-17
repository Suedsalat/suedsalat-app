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

// Benutzerverwaltung ist bewusst nur fuer den Owner (Thorsten) gedacht - gleiches
// Muster wie beim Newsletter (admin/newsletter.php): direkter Aufruf wird auch
// serverseitig abgeblockt, nicht nur ueber die fehlende Nav-Verlinkung.
if (!$isOwner) {
    header('Location: ' . BASE_PATH . '/admin/dashboard.php');
    exit;
}

$error = null;
$newSetupLink = null;
$newAdminName = null;

function admins_count_owners(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'owner'")->fetchColumn();
}

// --- Neues Konto anlegen: nur der Datensatz + ein Verifizierungs-Token, OHNE
// Passwort zu setzen und OHNE automatisch eine E-Mail zu verschicken - der
// Setup-Link wird stattdessen hier angezeigt und muss manuell weitergegeben
// werden (identisches Vorgehen wie bisher per sql/_create-*-once.php-Skript,
// jetzt direkt im Admin-Bereich statt als Einmal-Skript).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $name = normalize_input(trim((string) ($_POST['name'] ?? '')));
    $email = normalize_email(trim((string) ($_POST['email'] ?? '')));
    $role = ($_POST['role'] ?? 'member') === 'owner' ? 'owner' : 'member';

    if ($name === '') {
        $error = 'Bitte einen Namen eingeben.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Bitte eine gültige E-Mail-Adresse eingeben.';
    } else {
        $existing = $pdo->prepare('SELECT id FROM admins WHERE email = :email');
        $existing->execute([':email' => $email]);
        if ($existing->fetch()) {
            $error = 'Ein Konto mit dieser E-Mail-Adresse existiert bereits.';
        } else {
            // Zufaelliger, nicht nutzbarer Platzhalter-Hash - wird beim Setup ueberschrieben.
            $placeholderHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

            $insert = $pdo->prepare('INSERT INTO admins (name, email, password_hash, role) VALUES (:name, :email, :hash, :role)');
            $insert->execute([':name' => $name, ':email' => $email, ':hash' => $placeholderHash, ':role' => $role]);
            $newAdminId = (int) $pdo->lastInsertId();

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = (new DateTime())->modify('+30 days')->format('Y-m-d H:i:s');

            $insertVerification = $pdo->prepare(
                'INSERT INTO email_verifications (admin_id, token_hash, expires_at) VALUES (:admin_id, :hash, :expires_at)'
            );
            $insertVerification->execute([':admin_id' => $newAdminId, ':hash' => $tokenHash, ':expires_at' => $expiresAt]);

            $newSetupLink = APP_URL . '/admin/setup-account.php?token=' . $token;
            $newAdminName = $name;
        }
    }
}

// --- Rolle wechseln (Owner <-> Mitglied) - kein Passwort-Feld noetig wie bei
// echten Loeschvorgaengen, aber der letzte verbleibende Owner darf sich nicht
// selbst degradieren/degradiert werden, sonst waere niemand mehr fuer
// Newsletter/Empfaengerlisten/Benutzerverwaltung berechtigt.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_role') {
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $targetStmt = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
    $targetStmt->execute([':id' => $targetId]);
    $targetRole = $targetStmt->fetchColumn();

    if ($targetRole === 'owner' && admins_count_owners($pdo) <= 1) {
        $error = 'Das geht nicht - es muss immer mindestens einen Owner geben.';
    } elseif ($targetRole !== false) {
        $newRole = $targetRole === 'owner' ? 'member' : 'owner';
        $pdo->prepare('UPDATE admins SET role = :role WHERE id = :id')->execute([':role' => $newRole, ':id' => $targetId]);
        header('Location: ' . BASE_PATH . '/admin/users.php');
        exit;
    }
}

// --- Loeschen - Passwort-Bestaetigung erforderlich, gleiches Muster wie ueberall
// sonst im Admin-Bereich. Weder das eigene Konto noch der letzte Owner duerfen
// geloescht werden.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $targetId = (int) ($_POST['user_id'] ?? 0);

    if ($targetId === $adminId) {
        header('Location: ' . BASE_PATH . '/admin/users.php?delete_error=self');
        exit;
    }
    if (!verify_admin_password($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
        header('Location: ' . BASE_PATH . '/admin/users.php?delete_error=password');
        exit;
    }
    $targetStmt = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
    $targetStmt->execute([':id' => $targetId]);
    $targetRole = $targetStmt->fetchColumn();
    if ($targetRole === 'owner' && admins_count_owners($pdo) <= 1) {
        header('Location: ' . BASE_PATH . '/admin/users.php?delete_error=lastowner');
        exit;
    }

    $pdo->prepare('DELETE FROM admins WHERE id = :id')->execute([':id' => $targetId]);
    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

$deleteErrorMessages = [
    'password' => 'Falsches Passwort — nichts wurde gelöscht.',
    'self' => 'Das eigene Konto kann hier nicht gelöscht werden.',
    'lastowner' => 'Das geht nicht - es muss immer mindestens einen Owner geben.',
];
$deleteError = $deleteErrorMessages[$_GET['delete_error'] ?? ''] ?? null;

$showCreateForm = $error !== null || $newSetupLink !== null;

$allAdmins = $pdo->query(
    "SELECT id, name, email, role, totp_enabled, email_verified_at, created_at
     FROM admins
     ORDER BY role = 'owner' DESC, name ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Benutzerverwaltung – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Benutzerverwaltung</h1>
    <p style="font-size:0.9rem;color:#666;">Neue Admin-Konten anlegen und bestehende verwalten. Es gibt bewusst kein öffentliches Anmeldeformular - neue Konten entstehen nur hier, der Einrichtungslink wird dann persönlich weitergegeben (WhatsApp, E-Mail o. ä.), nicht automatisch verschickt.</p>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if ($deleteError): ?>
        <p class="error text-center"><?= htmlspecialchars($deleteError, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if ($newSetupLink): ?>
        <div class="stats-baseline-box">
            <p>Konto für <strong><?= htmlspecialchars($newAdminName, ENT_QUOTES) ?></strong> angelegt. Einrichtungslink (30 Tage gültig, persönlich weitergeben - <strong>nicht</strong> automatisch verschickt):</p>
        </div>
        <label>Einrichtungslink
            <input type="text" readonly value="<?= htmlspecialchars($newSetupLink, ENT_QUOTES) ?>" onclick="this.select();">
        </label>
    <?php endif; ?>

    <button type="button" class="button" data-show-create-form="create-form" style="<?= $showCreateForm ? 'display:none;' : '' ?>">+ Konto anlegen</button>
    <div id="create-form" style="<?= $showCreateForm ? '' : 'display:none;' ?>">
        <button type="button" class="button-secondary" data-hide-create-form="create-form">- Konto anlegen</button>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <label>Name
                <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES) ?>">
            </label>
            <label>E-Mail-Adresse
                <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>">
            </label>
            <label>Rolle
                <select name="role">
                    <option value="member" <?= ($_POST['role'] ?? 'member') === 'member' ? 'selected' : '' ?>>Mitglied</option>
                    <option value="owner" <?= ($_POST['role'] ?? '') === 'owner' ? 'selected' : '' ?>>Owner</option>
                </select>
            </label>
            <p style="font-size:0.85rem;color:#666;">Mitglied: normaler Zugriff auf Inhalte/Statistiken. Owner: zusätzlich Newsletter, Empfängerlisten und Benutzerverwaltung.</p>
            <div class="button-row">
                <button type="submit">Konto anlegen</button>
            </div>
        </form>
    </div>

    <h2>Bestehende Konten</h2>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>E-Mail bestätigt</th><th>2FA</th><th>Angelegt</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($allAdmins as $admin): ?>
            <tr>
                <td><?= htmlspecialchars($admin['name'], ENT_QUOTES) ?><?= (int) $admin['id'] === $adminId ? ' <span class="badge">Du</span>' : '' ?></td>
                <td><?= htmlspecialchars($admin['email'], ENT_QUOTES) ?></td>
                <td><?= $admin['role'] === 'owner' ? 'Owner' : 'Mitglied' ?></td>
                <td><?= $admin['email_verified_at'] !== null ? 'Ja' : 'Ausstehend' ?></td>
                <td><?= $admin['totp_enabled'] ? 'Aktiv' : 'Aus' ?></td>
                <td><?= htmlspecialchars(date('d.m.Y', strtotime($admin['created_at'])), ENT_QUOTES) ?></td>
                <td>
                    <div class="actions">
                        <form method="post" onsubmit="return false;">
                            <input type="hidden" name="action" value="toggle_role">
                            <input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>">
                            <button type="submit" class="button-secondary"><?= $admin['role'] === 'owner' ? 'Zu Mitglied machen' : 'Zu Owner machen' ?></button>
                        </form>
                        <?php if ((int) $admin['id'] !== $adminId): ?>
                            <form method="post" onsubmit="return false;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>">
                                <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Das Konto von „<?= htmlspecialchars(addslashes($admin['name']), ENT_QUOTES) ?>“ wird dauerhaft gelöscht.')">Löschen</button>
                            </form>
                        <?php endif; ?>
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
