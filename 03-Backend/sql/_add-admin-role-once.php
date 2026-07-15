<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: ergaenzt role in admins und setzt Thorstens
// Konto auf 'owner' (einzige Rolle mit Recht, Admin-Aktivitaeten zu loeschen).
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-adminrole-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    $column = $pdo->query("SHOW COLUMNS FROM admins LIKE 'role'")->fetch();
    if ($column) {
        echo "OK: Spalte role existiert bereits.\n";
    } else {
        $pdo->exec("ALTER TABLE admins ADD COLUMN role ENUM('owner','member') NOT NULL DEFAULT 'member' AFTER email_verified_at");
        echo "OK: Spalte role hinzugefuegt.\n";
    }

    $email = normalize_email('thorsten@südsalat.eu');
    $update = $pdo->prepare("UPDATE admins SET role = 'owner' WHERE email = :email");
    $update->execute([':email' => $email]);
    echo "OK: " . $update->rowCount() . " Konto(s) auf 'owner' gesetzt (thorsten@südsalat.eu).\n";

    $rows = $pdo->query('SELECT email, role FROM admins')->fetchAll();
    foreach ($rows as $row) {
        echo "  {$row['email']}: {$row['role']}\n";
    }
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
