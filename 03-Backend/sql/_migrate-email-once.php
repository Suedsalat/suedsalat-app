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
$admins = $pdo->query('SELECT id, email FROM admins')->fetchAll();

foreach ($admins as $a) {
    $canonical = normalize_email($a['email']);
    if ($canonical !== $a['email']) {
        $stmt = $pdo->prepare('UPDATE admins SET email = :new WHERE id = :id');
        $stmt->execute([':new' => $canonical, ':id' => $a['id']]);
        echo "Migriert: {$a['email']} -> $canonical\n";
    } else {
        echo "Bereits kanonisch: {$a['email']}\n";
    }
}
