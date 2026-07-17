<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: legt die Tabellen devices, refresh_tokens und
// rate_limits an (anonyme Geraete-Tokens fuer die App-API, siehe lib/Jwt.php,
// lib/RefreshToken.php, lib/RateLimiter.php, lib/ApiAuth.php).
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-apiauth-2026-temp';
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
        "CREATE TABLE IF NOT EXISTS devices (
          id INT PRIMARY KEY AUTO_INCREMENT,
          device_uuid VARCHAR(64) NOT NULL UNIQUE,
          platform ENUM('ios','android') NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_seen_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "OK: Tabelle devices existiert.\n";

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS refresh_tokens (
          id INT PRIMARY KEY AUTO_INCREMENT,
          token_hash VARCHAR(255) NOT NULL,
          subject_type ENUM('device') NOT NULL DEFAULT 'device',
          subject_id INT NOT NULL,
          expires_at DATETIME NOT NULL,
          revoked_at DATETIME NULL,
          replaced_by_id INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_used_at DATETIME NULL,
          INDEX token_hash_idx (token_hash),
          INDEX subject_idx (subject_type, subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "OK: Tabelle refresh_tokens existiert.\n";

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rate_limits (
          id INT PRIMARY KEY AUTO_INCREMENT,
          bucket VARCHAR(50) NOT NULL,
          ip_address VARCHAR(45) NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX bucket_ip_idx (bucket, ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "OK: Tabelle rate_limits existiert.\n";

    foreach (['devices', 'refresh_tokens', 'rate_limits'] as $table) {
        $columns = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
        echo "Spalten $table: " . implode(', ', $columns) . "\n";
    }
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
