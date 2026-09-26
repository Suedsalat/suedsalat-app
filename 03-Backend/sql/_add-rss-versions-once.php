<?php
declare(strict_types=1);

// Vorab-Migration fuer die Admin-Seite "Folgen" (admin/episodes.php), damit Thorsten schon vor dem
// 2.0-Release Kapitel nachtragen kann: nur die Tabelle rss_versions (fruehere Fassungen der
// podcast.rss). Die 2.0-Migration legt sie ebenfalls an und ueberspringt sie dann einfach.
// Beliebig oft ausfuehrbar. Nach Gebrauch UNBEDINGT vom Server loeschen.

$secret = 'suedsalat-rssversions-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = \Suedsalat\Database::connection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS rss_versions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        content MEDIUMTEXT NOT NULL,
        note VARCHAR(200) NOT NULL DEFAULT '',
        admin_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: rss_versions vorhanden.\nFertig.\n";
} catch (\Throwable $e) {
    echo 'FEHLER: ' . $e->getMessage() . "\n";
}
