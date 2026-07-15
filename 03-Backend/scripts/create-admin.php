<?php
declare(strict_types=1);

// Einmalig auf der Kommandozeile ausfuehren, um ein neues Admin-Konto anzulegen:
//   php scripts/create-admin.php "Thorsten" "thorsten@example.com"
// Gibt einen Setup-Link aus, der einmalig per E-Mail an die Person geschickt wird.
// Kein oeffentliches Registrierungsformular vorhanden (siehe Technik-Plan.md).

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Nur per Kommandozeile ausfuehrbar.');
}

[$_, $name, $email] = $argv + [null, null, null];

if (!$name || !$email) {
    fwrite(STDERR, "Verwendung: php create-admin.php \"Name\" \"email@example.com\"\n");
    exit(1);
}

$name = normalize_input($name);
$email = normalize_email($email);

$pdo = Database::connection();

$existing = $pdo->prepare('SELECT id FROM admins WHERE email = :email');
$existing->execute([':email' => $email]);
if ($existing->fetch()) {
    fwrite(STDERR, "Ein Konto mit dieser E-Mail existiert bereits.\n");
    exit(1);
}

// Temporaeres Zufalls-Passwort speichern (wird beim Setup ueberschrieben).
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

$setupLink = APP_URL . '/admin/setup-account.php?token=' . $token;

echo "Admin-Konto angelegt: $name <$email>\n";
echo "Setup-Link (7 Tage gueltig, persoenlich verschicken):\n$setupLink\n";
