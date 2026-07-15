<?php
declare(strict_types=1);

$secret = 'suedsalat-setup-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

$email = 'thorsten@südsalat.eu';
$newPassword = 'Suedsalat-Temp-9247';

$pdo = Database::connection();
$stmt = $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE email = :email');
$stmt->execute([
    ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    ':email' => $email,
]);

echo $stmt->rowCount() === 1
    ? "OK: Passwort fuer $email wurde gesetzt.\n"
    : "FEHLER: Kein Konto mit dieser E-Mail gefunden (rowCount=" . $stmt->rowCount() . ").\n";
