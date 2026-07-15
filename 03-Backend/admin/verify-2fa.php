<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;
use Suedsalat\Totp;

Auth::startSession();

$pendingAdminId = $_SESSION['pending_2fa_admin_id'] ?? null;
if ($pendingAdminId === null) {
    header('Location: ' . BASE_PATH . '/admin/login.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = :id');
    $stmt->execute([':id' => $pendingAdminId]);
    $admin = $stmt->fetch();

    if ($admin && Totp::verify($admin['totp_secret'], (string) $_POST['code'])) {
        unset($_SESSION['pending_2fa_admin_id']);
        Auth::login((int) $admin['id']);
        header('Location: ' . BASE_PATH . '/admin/dashboard.php');
        exit;
    }
    $error = 'Der Code ist ungueltig oder abgelaufen.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Zwei-Faktor-Bestätigung – Südsalat</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<header class="admin-header">
    <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Südsalat">
    <p>APP-Administrationsbereich</p>
</header>
<main class="auth-box">
    <h1>Bestätigungscode</h1>
    <p>Bitte gib den 6-stelligen Code aus deiner Authenticator-App ein.</p>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <form method="post">
        <label>Code
            <input type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus>
        </label>
        <button type="submit">Bestätigen</button>
    </form>
</main>
</body>
</html>
