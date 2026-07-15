<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Mailer;

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = normalize_email((string) $_POST['email']);
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT id, name FROM admins WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $admin = $stmt->fetch();

    if ($admin) {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTime())->modify('+' . PASSWORD_RESET_TTL_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        $insert = $pdo->prepare(
            'INSERT INTO password_resets (admin_id, token_hash, expires_at) VALUES (:admin_id, :token_hash, :expires_at)'
        );
        $insert->execute([
            ':admin_id' => $admin['id'],
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);

        $resetLink = APP_URL . '/admin/reset-password.php?token=' . $token;
        try {
            Mailer::send(
                $email,
                $admin['name'],
                'Passwort zuruecksetzen – Südsalat',
                "<p>Hallo {$admin['name']},</p>
                 <p>Klicke auf den folgenden Link, um dein Passwort zurueckzusetzen (gueltig " . PASSWORD_RESET_TTL_MINUTES . " Minuten):</p>
                 <p><a href=\"$resetLink\">$resetLink</a></p>
                 <p>Falls du das nicht angefordert hast, ignoriere diese E-Mail.</p>"
            );
        } catch (\Throwable $e) {
            error_log('Mailversand fehlgeschlagen: ' . $e->getMessage());
        }
    }

    // Immer dieselbe Meldung, unabhaengig davon ob die E-Mail existiert (kein Enumerations-Leck).
    $message = 'Falls die E-Mail-Adresse bekannt ist, wurde ein Link zum Zuruecksetzen verschickt.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Passwort vergessen – Südsalat</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<header class="admin-header">
    <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Südsalat">
    <p>APP-Administrationsbereich</p>
</header>
<main class="auth-box">
    <h1>Passwort vergessen</h1>
    <?php if ($message): ?>
        <p class="info"><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
        <a class="button" href="<?= BASE_PATH ?>/admin/login.php">Zurück zum Login</a>
    <?php else: ?>
        <form method="post">
            <label>E-Mail
                <input type="text" inputmode="email" autocomplete="email" name="email" required autofocus>
            </label>
            <div class="button-row">
                <button type="submit">Link anfordern</button>
                <a class="button" href="<?= BASE_PATH ?>/admin/login.php">Zurück zum Login</a>
            </div>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
