<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;

Auth::startSession();

if (Auth::currentAdminId() !== null && empty($_SESSION['pending_2fa_admin_id'])) {
    header('Location: ' . BASE_PATH . '/admin/dashboard.php');
    exit;
}

$error = null;
$timedOut = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = normalize_email((string) $_POST['email']);
    $password = (string) ($_POST['password'] ?? '');
    $ip = Auth::clientIp();

    if (Auth::isRateLimited($email, $ip)) {
        $error = 'Zu viele Fehlversuche. Bitte in ' . LOGIN_LOCKOUT_MINUTES . ' Minuten erneut versuchen.';
    } else {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM admins WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch();

        $passwordOk = $admin && password_verify($password, $admin['password_hash']);
        Auth::recordLoginAttempt($admin['id'] ?? null, $ip, (bool) $passwordOk);

        if (!$passwordOk) {
            $error = 'E-Mail oder Passwort ist falsch.';
        } elseif ($admin['email_verified_at'] === null) {
            $error = 'Bitte bestaetige zuerst deine E-Mail-Adresse (Link wurde dir zugeschickt).';
        } else {
            // Passwort korrekt -> weiter zu 2FA (falls eingerichtet) oder direkt einloggen.
            if ($admin['totp_enabled']) {
                $_SESSION['pending_2fa_admin_id'] = (int) $admin['id'];
                header('Location: ' . BASE_PATH . '/admin/verify-2fa.php');
                exit;
            }
            Auth::login((int) $admin['id']);
            header('Location: ' . BASE_PATH . '/admin/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Admin-Login – Südsalat</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<header class="admin-header">
    <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Südsalat">
    <p>APP-Administrationsbereich</p>
</header>
<main class="auth-box">
    <h1>Admin-Login</h1>
    <?php if ($timedOut): ?>
        <p class="info text-center">Du wurdest wegen Inaktivität automatisch abgemeldet. Bitte melde dich erneut an.</p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <form method="post">
        <label>E-Mail
            <input type="text" inputmode="email" autocomplete="email" name="email" required autofocus>
        </label>
        <label>Passwort
            <input type="password" name="password" required>
        </label>
        <div class="button-row">
            <button type="submit">Anmelden</button>
            <a class="button" href="<?= BASE_PATH ?>/admin/forgot-password.php">Passwort vergessen?</a>
        </div>
    </form>
</main>
</body>
</html>
