<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: newsletter_drafts.target war VARCHAR(50),
// zu kurz fuer den neuen "single:<email>"-Zielgruppen-Wert (Einzelversand an
// eine E-Mail-Adresse, siehe admin/newsletter.php resolve_target_post_value())
// - eine laengere Adresse haette sonst abgeschnitten werden koennen.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-widentarget-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    $pdo->exec("ALTER TABLE newsletter_drafts MODIFY target VARCHAR(255) NOT NULL DEFAULT 'all'");
    echo "OK: newsletter_drafts.target auf VARCHAR(255) erweitert.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
