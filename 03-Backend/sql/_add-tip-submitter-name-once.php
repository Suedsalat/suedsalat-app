<?php
declare(strict_types=1);

// Einmaliges Migrations-Skript: Film- und Locationtipps, Veranstaltungen und Galerie-Fotos
// zeigen kuenftig einheitlich, von wem sie kommen ("Tipp von Angela", "Tipp von Suedsalat"),
// statt dass nur eingereichte den Namen vorne in der Beschreibung tragen ("von Angela: ...").
//
// 1. Neue Spalte submitted_by_name in movie_tips, location_tips, events und photos.
// 2. Bestehende Tipps fuellen:
//    - aus einem Feedback uebernommen: Name aus dem Beschreibungs-Praefix "von X: ",
//      ersatzweise aus feedback_messages.sender_name; "Anonym" ergibt keinen Namen,
//    - selbst angelegt: OWN_CONTENT_NAME ("Suedsalat"), egal ob Thorsten oder Jenny.
// 3. Das Praefix "von X: " aus der Beschreibung entfernen, sonst stuende der Name doppelt.
// 4. Eigene Rezensionen ohne Namen (ohne App-Installation) bekommen ebenfalls "Suedsalat".
//
// Erst zusammen mit App-Version 2.0.0 ausfuehren (die App zeigt den Namen erst ab
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

    foreach (['movie_tips', 'location_tips', 'events', 'photos'] as $table) {
        $column = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'submitted_by_name'")->fetch();
        if ($column) {
            echo "OK: {$table}.submitted_by_name existiert bereits.\n";
        } else {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN submitted_by_name VARCHAR(100) NULL");
            echo "OK: {$table}.submitted_by_name hinzugefuegt.\n";
        }

        $rows = $pdo->query(
            "SELECT t.id, t.description, t.created_via_feedback_id, f.sender_name
             FROM {$table} t
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
                $name = OWN_CONTENT_NAME;
            }
            $update->execute([':name' => $name, ':description' => $description, ':id' => $row['id']]);
            printf("  %s #%d: %s%s\n", $table, $row['id'], $name ?? '(kein Name)',
                $description !== $row['description'] ? '  [Praefix entfernt]' : '');
        }
    }

    // Rezensionen, die Thorsten oder Jenny im Admin-Bereich ohne Namen eingetragen haben
    // (erkennbar an fehlender App-Installation), heissen kuenftig ebenfalls "Suedsalat".
    $reviews = $pdo->prepare(
        "UPDATE tip_reviews SET reviewer_name = :name
         WHERE device_id IS NULL AND (reviewer_name IS NULL OR reviewer_name = '')"
    );
    $reviews->execute([':name' => OWN_CONTENT_NAME]);
    echo '  tip_reviews: ' . $reviews->rowCount() . ' eigene Rezension(en) ohne Namen -> ' . OWN_CONTENT_NAME . "\n";

    // 5. Was Thorsten oder Jenny selbst ueber die App eingeschickt haben, steht unter ihrem Namen
    //    ("Foto von Thorsten", "Foto von dat Dschenni") - kuenftig ebenfalls "Suedsalat".
    //    Vergleich ueber die vereinfachte Form (klein, ohne Leerzeichen/Punkte), wiederholbar.
    $eigene = ['thorsten', 'jenny', 'datdschenni', 'thorstenkoch', 'jennyfourate', 'thorstenk', 'jennyf'];
    $platzhalter = implode(', ', array_fill(0, count($eigene), '?'));
    $vereinfacht = static fn (string $spalte): string =>
        "LOWER(REPLACE(REPLACE(REPLACE(TRIM({$spalte}), ' ', ''), '.', ''), '-', ''))";
    foreach (['movie_tips' => 'submitted_by_name', 'location_tips' => 'submitted_by_name', 'events' => 'submitted_by_name',
              'photos' => 'submitted_by_name', 'tip_reviews' => 'reviewer_name'] as $table => $spalte) {
        $stmt = $pdo->prepare("UPDATE {$table} SET {$spalte} = ? WHERE {$vereinfacht($spalte)} IN ({$platzhalter})");
        $stmt->execute(array_merge([OWN_CONTENT_NAME], $eigene));
        echo "  {$table}: " . $stmt->rowCount() . ' Beitrag/Beitraege von Thorsten/Jenny -> ' . OWN_CONTENT_NAME . "\n";
    }

    echo "Fertig.\n";
} catch (\Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}
