<?php
declare(strict_types=1);

// Einmaliges Diagnose-Skript: gibt den image_path der neuesten Sprachnachricht aus.
// Nach Gebrauch UNBEDINGT loeschen.

$secret = 'suedsalat-checkaudio-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

$pdo = Database::connection();
$rows = $pdo->query(
    "SELECT id, type, image_path, media_type, consent_publish, created_at FROM feedback_messages ORDER BY created_at DESC LIMIT 8"
)->fetchAll();

foreach ($rows as $row) {
    echo json_encode($row) . "\n";
}
if (!$rows) {
    echo "Keine Sprachnachrichten gefunden.\n";
}
