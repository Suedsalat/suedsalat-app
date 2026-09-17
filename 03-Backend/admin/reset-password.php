<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;

Auth::startSession();

$pdo = Database::connection();
$error = null;

$maskedEmail = $_SESSION['pending_reset_masked_email'] ?? null;
$pendingAdminId = $_SESSION['pending_reset_admin_id'] ?? null;

if ($maskedEmail === null) {
    // Kein laufender Reset in dieser Session (z.B. Seite direkt aufgerufen,
    // oder Sitzung abgelaufen) - kann nur ueber "Passwort vergessen" neu starten.
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
        <h1>Neues Passwort</h1>
        <p class="error">Es läuft gerade kein Passwort-Reset. Bitte fordere zuerst einen Code an.</p>
        <p><a href="<?= BASE_PATH ?>/admin/forgot-password.php">Code anfordern</a></p>
    </main>
    </body>
    </html>
    <?php
    exit;
}

// Sucht einen noch gueltigen, unbenutzten Reset-Code fuer den in der Session
// hinterlegten Admin - der Code allein reicht nicht, er muss zum zuvor per
// E-Mail-Adresse angeforderten Konto passen.
function find_valid_reset(PDO $pdo, ?int $adminId, string $code): ?array
{
    if ($adminId === null || $code === '') {
        return null;
    }
    $codeHash = hash('sha256', $code);
    $stmt = $pdo->prepare(
        'SELECT id, admin_id FROM password_resets
         WHERE admin_id = :admin_id AND token_hash = :hash AND used_at IS NULL AND expires_at > NOW()'
    );
    $stmt->execute([':admin_id' => $adminId, ':hash' => $codeHash]);
    return $stmt->fetch() ?: null;
}

$verifiedResetId = $_SESSION['pending_reset_verified_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code']) && $verifiedResetId === null) {
    // Stufe 1: Code eingegeben.
    $code = trim((string) $_POST['code']);
    $reset = find_valid_reset($pdo, $pendingAdminId, $code);
    if ($reset === null) {
        $error = 'Der Code ist ungültig oder abgelaufen. Bitte prüfe deine Eingabe oder fordere einen neuen Code an.';
    } else {
        $_SESSION['pending_reset_verified_id'] = $reset['id'];
        header('Location: ' . BASE_PATH . '/admin/reset-password.php');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && $verifiedResetId !== null) {
    // Stufe 2: neues Passwort - Reset-Zeile nochmal frisch pruefen (nicht dem
    // Session-Wert blind vertrauen, koennte zwischenzeitlich abgelaufen sein).
    $stmt = $pdo->prepare(
        'SELECT id, admin_id FROM password_resets WHERE id = :id AND admin_id = :admin_id AND used_at IS NULL AND expires_at > NOW()'
    );
    $stmt->execute([':id' => $verifiedResetId, ':admin_id' => $pendingAdminId]);
    $reset = $stmt->fetch();

    if ($reset === null) {
        $error = 'Der Code ist abgelaufen. Bitte fordere einen neuen an.';
        unset($_SESSION['pending_reset_verified_id']);
        $verifiedResetId = null;
    } else {
        $password = (string) $_POST['password'];
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($password) < 10) {
            $error = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id')->execute([
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $reset['admin_id'],
            ]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')->execute([':id' => $reset['id']]);
            $pdo->commit();

            unset($_SESSION['pending_reset_masked_email'], $_SESSION['pending_reset_admin_id'], $_SESSION['pending_reset_verified_id']);

            // Direkt einloggen - Passwort ist ja gerade erst mit vollem
            // Nachweis (Code aus der E-Mail) bestaetigt worden.
            Auth::login((int) $reset['admin_id']);
            header('Location: ' . BASE_PATH . '/admin/dashboard.php');
            exit;
        }
    }
}

$verifiedResetId = $_SESSION['pending_reset_verified_id'] ?? null;
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
    <?php if ($verifiedResetId !== null): ?>
        <h1>Neues Passwort vergeben</h1>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>Neues Passwort
                <input type="password" name="password" minlength="10" required autofocus>
            </label>
            <label>Passwort wiederholen
                <input type="password" name="password_confirm" minlength="10" required>
            </label>
            <button type="submit">Anmelden</button>
        </form>
    <?php else: ?>
        <h1>Freischaltungscode eingeben</h1>
        <p style="font-size:0.9rem;color:#666;">Es wurde eine E-Mail mit einem sechsstelligen Code an <strong><?= htmlspecialchars($maskedEmail, ENT_QUOTES) ?></strong> geschickt. Der Code ist <?= PASSWORD_RESET_TTL_MINUTES ?> Minuten gültig.</p>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>Code eingeben
                <input type="text" inputmode="numeric" autocomplete="one-time-code" name="code" maxlength="6" pattern="\d{6}" required autofocus>
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
