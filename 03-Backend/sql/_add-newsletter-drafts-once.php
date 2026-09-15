<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt eine Tabelle fuer speicherbare
// Newsletter-Vorlagen an (im Gegensatz zu newsletter_sends, das den
// tatsaechlichen Versandverlauf protokolliert - Entwuerfe hier werden nie
// verschickt, sondern nur als Ausgangspunkt fuer einen spaeteren echten
// Newsletter vorgehalten, siehe admin/newsletter.php "Als Vorlage speichern").
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-newsletterdrafts-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();
    $pdo->exec(
        "CREATE TABLE newsletter_drafts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            headline VARCHAR(255) NULL,
            episode_link VARCHAR(255) NULL,
            body_text TEXT NOT NULL,
            use_headline TINYINT(1) NOT NULL DEFAULT 1,
            use_episode_link TINYINT(1) NOT NULL DEFAULT 1,
            from_email VARCHAR(255) NULL,
            target VARCHAR(50) NOT NULL DEFAULT 'all',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_draft_name (name)
        )"
    );
    echo "OK: newsletter_drafts angelegt.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
