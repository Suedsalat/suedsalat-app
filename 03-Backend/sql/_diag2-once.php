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

echo "function_exists('normalize_input'): " . (function_exists('normalize_input') ? 'JA' : 'NEIN') . "\n";
if (function_exists('normalize_input')) {
    echo "Test normalize_input: " . normalize_input('test') . "\n";
}

echo "\n--- login_attempts (letzte 10) ---\n";
$pdo = Database::connection();
$attempts = $pdo->query('SELECT * FROM login_attempts ORDER BY id DESC LIMIT 10')->fetchAll();
foreach ($attempts as $a) {
    echo json_encode($a, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n--- password_resets (letzte 10) ---\n";
$resets = $pdo->query('SELECT id, admin_id, expires_at, used_at FROM password_resets ORDER BY id DESC LIMIT 10')->fetchAll();
foreach ($resets as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\nDatei-Zeitstempel config.php: " . date('Y-m-d H:i:s', filemtime(__DIR__ . '/../config/config.php')) . "\n";
echo "Datei-Zeitstempel login.php: " . date('Y-m-d H:i:s', filemtime(__DIR__ . '/../admin/login.php')) . "\n";
echo "opcache.enable: " . ini_get('opcache.enable') . "\n";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo "opcache aktiv: " . ($status ? 'JA' : 'NEIN') . "\n";
}
