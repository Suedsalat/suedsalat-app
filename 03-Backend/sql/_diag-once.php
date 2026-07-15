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

echo "--- admins ---\n";
$admins = $pdo->query('SELECT id, name, email, email_verified_at, totp_enabled, LEFT(password_hash,10) AS hash_prefix, created_at FROM admins')->fetchAll();
foreach ($admins as $a) {
    echo json_encode($a, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n--- login_attempts (letzte 20) ---\n";
$attempts = $pdo->query('SELECT * FROM login_attempts ORDER BY id DESC LIMIT 20')->fetchAll();
foreach ($attempts as $a) {
    echo json_encode($a, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n--- Aktuell gesperrt? ---\n";
foreach ($admins as $a) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts la
         JOIN admins ad ON ad.id = la.admin_id
         WHERE ad.email = :email AND la.succeeded = 0 AND la.attempted_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute([':email' => $a['email']]);
    $count = (int) $stmt->fetchColumn();
    echo "{$a['email']}: $count Fehlversuche in den letzten 15 Min (Sperre ab 5)\n";
}
