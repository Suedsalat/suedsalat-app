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

echo "intl extension geladen: " . (extension_loaded('intl') ? 'JA' : 'NEIN') . "\n\n";

$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT email, HEX(email) as email_hex FROM admins WHERE id = 1');
$stmt->execute();
$row = $stmt->fetch();
echo "Gespeicherte E-Mail: {$row['email']}\n";
echo "Hex (gespeichert):   {$row['email_hex']}\n\n";

$nfc = 'thorsten@südsalat.eu'; // wie im PHP-Quelltext, NFC erwartet
echo "Hex (Skript-String, NFC-Vermutung): " . bin2hex($nfc) . "\n";

if (extension_loaded('intl')) {
    $nfd = Normalizer::normalize($nfc, Normalizer::FORM_D);
    echo "Hex (NFD-Variante):                 " . bin2hex($nfd) . "\n";
    echo "\nIst gespeicherte E-Mail NFC? " . (Normalizer::isNormalized($row['email'], Normalizer::FORM_C) ? 'JA' : 'NEIN') . "\n";
    echo "Ist gespeicherte E-Mail NFD? " . (Normalizer::isNormalized($row['email'], Normalizer::FORM_D) ? 'JA' : 'NEIN') . "\n";
}
