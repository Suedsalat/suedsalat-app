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

$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT id, email, password_hash, email_verified_at FROM admins WHERE email = :email');
$stmt->execute([':email' => 'thorsten@südsalat.eu']);
$admin = $stmt->fetch();

if (!$admin) {
    die("FEHLER: kein Konto gefunden fuer thorsten@südsalat.eu\n");
}

echo "Konto gefunden: id={$admin['id']}, email_verified_at={$admin['email_verified_at']}\n";
echo "password_hash voll: {$admin['password_hash']}\n\n";

$testPw = 'Suedsalat-Temp-9247';
$result = password_verify($testPw, $admin['password_hash']);
echo "password_verify('$testPw', gespeicherter Hash) = " . ($result ? 'TRUE (korrekt)' : 'FALSE (Fehler!)') . "\n";

// Zusaetzlich: frischen Hash fuer denselben String erzeugen und direkt gegenpruefen (Sanity-Check der PHP-Umgebung).
$freshHash = password_hash($testPw, PASSWORD_DEFAULT);
echo "Sanity-Check (frischer Hash, gleiche Umgebung): " . (password_verify($testPw, $freshHash) ? 'TRUE' : 'FALSE') . "\n";

echo "\nPasswort-Laenge im Skript: " . strlen($testPw) . " Bytes\n";
echo "PASSWORD_DEFAULT-Algo: " . PASSWORD_DEFAULT . "\n";
echo "PHP-Version: " . PHP_VERSION . "\n";

echo "\n--- letzte 5 login_attempts ---\n";
$attempts = $pdo->query('SELECT * FROM login_attempts ORDER BY id DESC LIMIT 5')->fetchAll();
foreach ($attempts as $a) {
    echo json_encode($a, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n--- password_resets (letzte 5) ---\n";
$resets = $pdo->query('SELECT id, admin_id, expires_at, used_at FROM password_resets ORDER BY id DESC LIMIT 5')->fetchAll();
foreach ($resets as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
