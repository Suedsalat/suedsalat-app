<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;
use Suedsalat\Totp;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$stmt = $pdo->prepare('SELECT * FROM admins WHERE id = :id');
$stmt->execute([':id' => $adminId]);
$admin = $stmt->fetch();

if ($admin['totp_enabled']) {
    header('Location: ' . BASE_PATH . '/admin/dashboard.php');
    exit;
}

Auth::startSession();
if (empty($_SESSION['pending_totp_secret'])) {
    $_SESSION['pending_totp_secret'] = Totp::generateSecret();
}
$secret = $_SESSION['pending_totp_secret'];
$otpAuthUri = Totp::getOtpAuthUri($secret, $admin['email']);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    if (Totp::verify($secret, (string) $_POST['code'])) {
        $update = $pdo->prepare('UPDATE admins SET totp_secret = :secret, totp_enabled = 1 WHERE id = :id');
        $update->execute([':secret' => $secret, ':id' => $adminId]);
        unset($_SESSION['pending_totp_secret']);
        header('Location: ' . BASE_PATH . '/admin/dashboard.php');
        exit;
    }
    $error = 'Der Code ist ungueltig. Bitte pruefe Zeit/Sekret in der Authenticator-App.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Zwei-Faktor-Authentifizierung einrichten – Südsalat</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<header class="admin-header">
    <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Südsalat">
    <p>APP-Administrationsbereich</p>
</header>
<main class="auth-box">
    <h1>Zwei-Faktor-Authentifizierung einrichten</h1>
    <p>Füge dieses Konto in deiner Authenticator-App (z.&nbsp;B. Google oder Microsoft Authenticator) manuell hinzu:</p>
    <p><strong>Konto:</strong> <?= htmlspecialchars($admin['email'], ENT_QUOTES) ?><br>
       <strong>Geheimer Schlüssel:</strong> <code><?= htmlspecialchars($secret, ENT_QUOTES) ?></code></p>
    <p>Alternativ kann diese Setup-URI in Apps eingefügt werden, die einen Import per Link/QR unterstützen:</p>
    <p><code style="word-break:break-all;"><?= htmlspecialchars($otpAuthUri, ENT_QUOTES) ?></code></p>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <form method="post">
        <label>Bestätigungscode aus der App
            <input type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus>
        </label>
        <button type="submit">2FA aktivieren</button>
    </form>
</main>
</body>
</html>
