<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;
use Suedsalat\Mailer;

Auth::startSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = normalize_email((string) $_POST['email']);
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT id, name FROM admins WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $admin = $stmt->fetch();

    // Session merkt sich, fuer wen gerade ein Reset laeuft - reset-password.php
    // fragt dadurch nur noch den Code ab, nicht nochmal die E-Mail-Adresse.
    unset($_SESSION['pending_reset_verified_id']);
    $_SESSION['pending_reset_masked_email'] = mask_email_for_display($email);
    $_SESSION['pending_reset_admin_id'] = $admin ? (int) $admin['id'] : null;

    if ($admin) {
        // Kurzer, per Hand eintippbarer Zahlencode statt eines Links im
        // Anhang - laesst sich direkt auf der Seite eingeben, ohne zwischen
        // E-Mail-App und Browser hin- und herzuwechseln zu muessen.
        $code = (string) random_int(100000, 999999);
        $codeHash = hash('sha256', $code);
        $expiresAt = (new DateTime())->modify('+' . PASSWORD_RESET_TTL_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        $insert = $pdo->prepare(
            'INSERT INTO password_resets (admin_id, token_hash, expires_at) VALUES (:admin_id, :token_hash, :expires_at)'
        );
        $insert->execute([
            ':admin_id' => $admin['id'],
            ':token_hash' => $codeHash,
            ':expires_at' => $expiresAt,
        ]);

        try {
            Mailer::send(
                $email,
                $admin['name'],
                'Dein Freischaltungscode – Südsalat',
                "<p>Hallo {$admin['name']},</p>
                 <p>Dein Freischaltungscode zum Zurücksetzen deines Passworts lautet:</p>
                 <p style=\"font-size:28px;font-weight:bold;letter-spacing:4px;\">$code</p>
                 <p>Gib ihn auf der Seite \"Neues Passwort vergeben\" ein. Der Code ist " . PASSWORD_RESET_TTL_MINUTES . " Minuten gültig.</p>
                 <p>Falls du das nicht angefordert hast, ignoriere diese E-Mail.</p>"
            );
        } catch (\Throwable $e) {
            error_log('Mailversand fehlgeschlagen: ' . $e->getMessage());
        }
    }

    header('Location: ' . BASE_PATH . '/admin/reset-password.php');
    exit;
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
    <form method="post">
        <label>E-Mail
            <input type="text" inputmode="email" autocomplete="email" name="email" required autofocus>
        </label>
        <div class="button-row">
            <button type="submit">Code anfordern</button>
            <a class="button button-secondary" style="margin-bottom:0;" href="<?= BASE_PATH ?>/admin/login.php">Zurück zum Login</a>
        </div>
    </form>
</main>
</body>
</html>
