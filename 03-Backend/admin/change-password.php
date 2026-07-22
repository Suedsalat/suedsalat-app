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

$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

    $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = :id');
    $stmt->execute([':id' => $adminId]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($currentPassword, $admin['password_hash'])) {
        $error = 'Das aktuelle Passwort ist nicht korrekt.';
    } elseif (strlen($newPassword) < 10) {
        $error = 'Das neue Passwort muss mindestens 10 Zeichen lang sein.';
    } elseif ($newPassword !== $newPasswordConfirm) {
        $error = 'Die neuen Passwörter stimmen nicht überein.';
    } else {
        $update = $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
        $update->execute([
            ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => $adminId,
        ]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Passwort ändern – Südsalat Admin</title>
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
<main class="auth-box">
    <h1>Passwort ändern</h1>

    <?php if ($success): ?>
        <p class="info">Dein Passwort wurde geändert.</p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <form method="post">
        <label>Aktuelles Passwort
            <input type="password" name="current_password" required autofocus>
        </label>
        <label>Neues Passwort
            <input type="password" name="new_password" minlength="10" required>
        </label>
        <label>Neues Passwort bestätigen
            <input type="password" name="new_password_confirm" minlength="10" required>
        </label>
        <button type="submit">Passwort speichern</button>
    </form>
</main>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
</body>
</html>
