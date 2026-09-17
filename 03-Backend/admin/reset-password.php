<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

$pdo = Database::connection();
$error = null;
$success = false;
$codeVerified = false;

$email = normalize_email((string) ($_POST['email'] ?? ''));
$code = trim((string) ($_POST['code'] ?? ''));

// Sucht einen noch gueltigen, unbenutzten Reset-Code fuer genau diese
// E-Mail-Adresse - Code UND E-Mail muessen zusammenpassen (nicht nur der
// Code fuer sich), sonst koennte ein erratener/abgefangener Code fuer ein
// fremdes Konto durchprobiert werden.
function find_valid_reset(PDO $pdo, string $email, string $code): ?array
{
    if ($email === '' || $code === '') {
        return null;
    }
    $codeHash = hash('sha256', $code);
    $stmt = $pdo->prepare(
        'SELECT pr.id, pr.admin_id FROM password_resets pr
         JOIN admins a ON a.id = pr.admin_id
         WHERE a.email = :email AND pr.token_hash = :hash AND pr.used_at IS NULL AND pr.expires_at > NOW()'
    );
    $stmt->execute([':email' => $email, ':hash' => $codeHash]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reset = find_valid_reset($pdo, $email, $code);

    if (isset($_POST['password'])) {
        // Stufe 2: Code wurde bereits geprueft, jetzt zusammen mit dem neuen
        // Passwort abgeschickt - nochmal frisch pruefen statt dem versteckten
        // Formularfeld blind zu vertrauen (Code koennte zwischenzeitlich
        // abgelaufen/schon benutzt worden sein).
        if ($reset === null) {
            $error = 'Der Code ist ungültig oder abgelaufen. Bitte fordere einen neuen an.';
        } else {
            $password = (string) $_POST['password'];
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
            if (strlen($password) < 10) {
                $error = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
                $codeVerified = true;
            } elseif ($password !== $passwordConfirm) {
                $error = 'Die Passwörter stimmen nicht überein.';
                $codeVerified = true;
            } else {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id')->execute([
                    ':hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':id' => $reset['admin_id'],
                ]);
                $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')->execute([':id' => $reset['id']]);
                $pdo->commit();
                $success = true;
            }
        }
    } else {
        // Stufe 1: nur E-Mail + Code abgeschickt - pruefen und bei Erfolg
        // direkt die Maske fuer das neue Passwort zeigen.
        if ($reset === null) {
            $error = 'Der Code ist ungültig oder abgelaufen. Bitte prüfe deine Eingabe oder fordere einen neuen Code an.';
        } else {
            $codeVerified = true;
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
    <title>Neues Passwort – Südsalat</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<header class="admin-header">
    <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Südsalat">
    <p>APP-Administrationsbereich</p>
</header>
<main class="auth-box">
    <?php if ($success): ?>
        <h1>Neues Passwort</h1>
        <p class="info">Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.</p>
        <p><a href="<?= BASE_PATH ?>/admin/login.php">Zum Login</a></p>
    <?php elseif ($codeVerified): ?>
        <h1>Neues Passwort vergeben</h1>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">
            <input type="hidden" name="code" value="<?= htmlspecialchars($code, ENT_QUOTES) ?>">
            <label>Neues Passwort
                <input type="password" name="password" minlength="10" required autofocus>
            </label>
            <label>Passwort bestätigen
                <input type="password" name="password_confirm" minlength="10" required>
            </label>
            <button type="submit">Passwort speichern</button>
        </form>
    <?php else: ?>
        <h1>Freischaltungscode eingeben</h1>
        <p style="font-size:0.9rem;color:#666;">Du hast per E-Mail einen 6-stelligen Code bekommen (gültig <?= (int) round(PASSWORD_RESET_TTL_MINUTES / 60) ?> Stunden).</p>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>E-Mail
                <input type="text" inputmode="email" autocomplete="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required autofocus>
            </label>
            <label>Code
                <input type="text" inputmode="numeric" autocomplete="one-time-code" name="code" maxlength="6" pattern="\d{6}" required>
            </label>
            <div class="button-row">
                <button type="submit">Code prüfen</button>
                <a class="button button-secondary" style="margin-bottom:0;" href="<?= BASE_PATH ?>/admin/forgot-password.php">Neuen Code anfordern</a>
            </div>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
