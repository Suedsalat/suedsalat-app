<?php
declare(strict_types=1);

$secret = 'suedsalat-setup-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Mailer;

header('Content-Type: text/plain; charset=utf-8');

echo "SMTP_HOST=" . SMTP_HOST . "\n";
echo "SMTP_PORT=" . SMTP_PORT . "\n";
echo "SMTP_ENCRYPTION=" . SMTP_ENCRYPTION . "\n";
echo "SMTP_USER=" . SMTP_USER . "\n";
echo "SMTP_FROM_ADDRESS=" . SMTP_FROM_ADDRESS . "\n\n";

try {
    Mailer::send('thorsten@südsalat.eu', 'Thorsten', 'Test-Mail vom Backend', '<p>Das ist eine Testmail.</p>');
    echo "OK: Mail wurde ohne Fehler an PHPMailer->send() uebergeben.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . get_class($e) . ": " . $e->getMessage() . "\n";
}
