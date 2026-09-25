<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: Film- und Locationtipps zeigen kuenftig einheitlich,
// von wem sie kommen ("Tipp von Angela", "Tipp von Thorsten"), statt dass nur
// eingereichte Tipps den Namen vorne in der Beschreibung tragen ("von Angela: ...").
//
// 1. Neue Spalte submitted_by_name in movie_tips und location_tips.
// 2. Bestehende Tipps fuellen:
//    - aus einem Feedback uebernommen: Name aus dem Beschreibungs-Praefix "von X: ",
//      ersatzweise aus feedback_messages.sender_name; "Anonym" ergibt keinen Namen,
//    - selbst angelegt: Name des anlegenden Admins.
// 3. Das Praefix "von X: " aus der Beschreibung entfernen, sonst stuende der Name doppelt.
//
// Erst zusammen mit App-Version 1.3.7 ausfuehren (die App zeigt den Namen erst ab
// dann an - vorher wuerde er fuer die Tester einfach verschwinden).
// Laeuft beliebig oft gefahrlos: Spalte nur einmal, Fuellen nur wo noch leer.
// Nach Gebrauch UNBEDINGT loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-tipvon-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::connection();

    foreach (['movie_tips', 'location_tips'] as $table) {
        $column = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'submitted_by_name'")->fetch();
        if ($column) {
            echo "OK: {$table}.submitted_by_name existiert bereits.\n";
        } else {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN submitted_by_name VARCHAR(100) NULL AFTER created_via_feedback_id");
            echo "OK: {$table}.submitted_by_name hinzugefuegt.\n";
        }

        $rows = $pdo->query(
            "SELECT t.id, t.description, t.created_via_feedback_id, a.name AS admin_name, f.sender_name
             FROM {$table} t
             JOIN admins a ON a.id = t.created_by
             LEFT JOIN feedback_messages f ON f.id = t.created_via_feedback_id
             WHERE t.submitted_by_name IS NULL"
        )->fetchAll();

        $update = $pdo->prepare("UPDATE {$table} SET submitted_by_name = :name, description = :description WHERE id = :id");
        foreach ($rows as $row) {
            $description = $row['description'];
            if ($row['created_via_feedback_id'] !== null) {
                $name = trim((string) ($row['sender_name'] ?? ''));
                if ($description !== null && preg_match('/^von (.{1,100}?): /u', $description, $m)) {
                    $name = trim($m[1]);
                    $description = trim(mb_substr($description, mb_strlen($m[0]))) ?: null;
                }
                if ($name === '' || strcasecmp($name, 'Anonym') === 0) {
                    $name = null;
                }
            } else {
                $name = $row['admin_name'];
            }
            $update->execute([':name' => $name, ':description' => $description, ':id' => $row['id']]);
            printf("  %s #%d: %s%s\n", $table, $row['id'], $name ?? '(kein Name)',
                $description !== $row['description'] ? '  [Praefix entfernt]' : '');
        }
    }
    echo "Fertig.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
