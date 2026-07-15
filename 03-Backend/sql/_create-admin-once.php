<?php
declare(strict_types=1);

// Einmaliges Setup-Skript: legt ein Admin-Konto an (Ersatz fuer scripts/create-admin.php,
// da auf diesem Strato-Paket kein SSH-Shell-Zugriff verfuegbar ist).
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-setup-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

$name = trim((string) ($_GET['name'] ?? ''));
$email = trim((string) ($_GET['email'] ?? ''));

if ($name === '' || !str_contains($email, '@')) {
    die("FEHLER: name und email als Parameter erforderlich.\n");
}

try {
    $pdo = Database::connection();

    $existing = $pdo->prepare('SELECT id FROM admins WHERE email = :email');
    $existing->execute([':email' => $email]);
    if ($existing->fetch()) {
        die("FEHLER: Ein Konto mit dieser E-Mail existiert bereits.\n");
    }

    $placeholderHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (:name, :email, :hash)');
    $insert->execute([':name' => $name, ':email' => $email, ':hash' => $placeholderHash]);
    $adminId = (int) $pdo->lastInsertId();

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTime())->modify('+7 days')->format('Y-m-d H:i:s');

    $insertVerification = $pdo->prepare(
        'INSERT INTO email_verifications (admin_id, token_hash, expires_at) VALUES (:admin_id, :hash, :expires_at)'
    );
    $insertVerification->execute([':admin_id' => $adminId, ':hash' => $tokenHash, ':expires_at' => $expiresAt]);

    $setupLink = APP_URL . '/APP/admin/setup-account.php?token=' . $token;

    echo "Admin-Konto angelegt: $name <$email>\n";
    echo "Setup-Link (7 Tage gueltig, persoenlich verschicken):\n$setupLink\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
