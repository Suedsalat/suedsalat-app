<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = null;
$success = false;

if ($token === '') {
    http_response_code(400);
    die('Kein Token angegeben.');
}

$tokenHash = hash('sha256', $token);
$pdo = Database::connection();

$stmt = $pdo->prepare(
    'SELECT pr.id, pr.admin_id FROM password_resets pr
     WHERE pr.token_hash = :hash AND pr.used_at IS NULL AND pr.expires_at > NOW()'
);
$stmt->execute([':hash' => $tokenHash]);
$reset = $stmt->fetch();

if (!$reset) {
    http_response_code(400);
    die('Dieser Link ist ungueltig oder abgelaufen. Bitte fordere einen neuen an.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $password = (string) $_POST['password'];
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 10) {
        $error = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } else {
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id');
        $update->execute([
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $reset['admin_id'],
        ]);
        $markUsed = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
        $markUsed->execute([':id' => $reset['id']]);
        $pdo->commit();

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
    <title>Neues Passwort – Südsalat</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<header class="admin-header">
    <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Südsalat">
    <p>APP-Administrationsbereich</p>
</header>
<main class="auth-box">
    <h1>Neues Passwort setzen</h1>
    <?php if ($success): ?>
        <p class="info">Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.</p>
        <p><a href="<?= BASE_PATH ?>/admin/login.php">Zum Login</a></p>
    <?php else: ?>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
            <label>Neues Passwort
                <input type="password" name="password" minlength="10" required autofocus>
            </label>
            <label>Passwort bestätigen
                <input type="password" name="password_confirm" minlength="10" required>
            </label>
            <button type="submit">Passwort speichern</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
