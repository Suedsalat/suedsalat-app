<?php
declare(strict_types=1);

/**
 * Kommentare unter Galerie-Fotos (App 2.0, Etappe 7): nur Registrierte, sofort sichtbar,
 * Spitzname live, Melden/Ausblenden, Admin-Loeschen mit Grund, Kontoloeschung.
 * Nur gegen die Testumgebung:
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/CommentsTest.php
 */

require __DIR__ . '/TestHelpers.php';

use Suedsalat\Listener;
use Suedsalat\ListenerContent;
use Suedsalat\Moderation;

resetTables($pdo, ['listener_login_codes', 'listener_hidden', 'content_reports', 'gallery_comments', 'listeners', 'devices',
    'refresh_tokens', 'rate_limits', 'photos']);
$pdo->exec("INSERT INTO admins (id, name, email, password_hash, role) VALUES (2, 'Jenny', 'jenny@test.local', 'x', 'member')
            ON DUPLICATE KEY UPDATE role = 'member'");
$pdo->exec("INSERT INTO photos (id, image_path, description, created_by, submitted_by_name) VALUES
            (1, 'https://x.invalid/1.jpg', 'Foto eins', 1, 'Südsalat'), (2, 'https://x.invalid/2.jpg', 'Foto zwei', 1, 'Südsalat')");

$gast = deviceToken('k-gast');
$tok = [];
foreach (['Anna', 'Bea', 'Carl', 'Dora'] as $n) {
    $tok[$n] = deviceToken('k-' . $n);
    registerListener($tok[$n], strtolower($n) . '@example.org', $n);
}
$schreibe = fn (string $t, int $foto, string $text) => api('POST', '/api/gallery-comments.php', $t, ['photo_id' => $foto, 'text' => $text]);
$liste = function (string $t, int $foto): array {
    [, $r] = api('GET', '/api/gallery-comments.php?photo_id=' . $foto, $t);
    return $r['comments'] ?? [];
};

echo "Schreiben\n";
[$s] = $schreibe($gast, 1, 'Schönes Foto!');
check($s === 401, 'Gaeste koennen nicht kommentieren');
[$s, $r] = $schreibe($tok['Anna'], 1, '');
check($s === 422, 'leerer Kommentar abgelehnt');
[$s] = $schreibe($tok['Anna'], 1, str_repeat('x', 1001));
check($s === 422, 'zu langer Kommentar abgelehnt');
[$s, $r] = $schreibe($tok['Anna'], 1, 'Du Arschloch');
check($s === 422 && str_contains($r['error'] ?? '', 'Beleidigungen'), 'Beleidigung abgelehnt');
[$s] = $schreibe($tok['Anna'], 99, 'Wo ist das?');
check($s === 404, 'unbekanntes Foto abgelehnt');
[$s, $r] = api('POST', '/api/gallery-comments.php', $tok['Anna'], ['photo_id' => 1, 'text' => 'Toller Abend war das!', 'author_name' => 'Südsalat']);
check($s === 200 && ($r['count'] ?? 0) === 1, 'Kommentar angelegt');
$kid = (int) ($r['id'] ?? 0);
[$betreff, $html] = lastMail('owner@test.local');
check(str_contains($betreff, 'Neuer Kommentar') && str_contains($html, 'Toller Abend war das!') && str_contains($html, 'gallery.php?edit=1'), 'Admins bekommen Mail mit Link zum Foto');
check(lastMail('jenny@test.local')[0] !== '', 'auch Jenny bekommt die Mail');

echo "Lesen\n";
$k = $liste($gast, 1);
check(count($k) === 1 && $k[0]['author_name'] === 'Anna', 'sofort sichtbar, auch fuer Gaeste - Name aus dem Konto, nicht aus der Eingabe');
check($k[0]['is_own'] === false && !array_key_exists('listener_id', $k[0]), 'Gast: nicht eigener Kommentar, keine Kontonummer');
check($liste($tok['Anna'], 1)[0]['is_own'] === true, 'Anna: eigener Kommentar erkannt');
check($liste($gast, 2) === [], 'andere Fotos ohne Kommentare');
[, $fotos] = api('GET', '/api/gallery.php', $gast);
$anzahl = array_column($fotos, 'comment_count', 'id');
check(($anzahl[1] ?? null) === 1 && ($anzahl[2] ?? null) === 0, 'Galerie liefert die Anzahl je Foto');
api('POST', '/api/listener/update.php', $tok['Anna'], ['nickname' => 'Annabell']);
check($liste($gast, 1)[0]['author_name'] === 'Annabell', 'neuer Spitzname erscheint sofort beim Kommentar');

echo "Eigenen Kommentar loeschen\n";
[$s] = api('POST', '/api/gallery-comments.php', $tok['Bea'], ['action' => 'delete', 'comment_id' => $kid]);
check($s === 404 && count($liste($gast, 1)) === 1, 'fremden Kommentar loeschen geht nicht');
[, $r] = $schreibe($tok['Bea'], 1, 'Da war ich auch!');
$bea = (int) $r['id'];
[$s] = api('POST', '/api/gallery-comments.php', $tok['Bea'], ['action' => 'delete', 'comment_id' => $bea]);
check($s === 200 && count($liste($gast, 1)) === 1, 'eigenen Kommentar geloescht');

echo "Melden und Ausblenden\n";
[$s] = api('POST', '/api/report.php', $gast, ['content_type' => 'comment', 'content_id' => $kid, 'category' => 'spam']);
check($s === 200, 'Gast kann Kommentar melden');
foreach (['Bea', 'Carl'] as $n) {
    api('POST', '/api/report.php', $tok[$n], ['content_type' => 'comment', 'content_id' => $kid, 'category' => 'insult']);
}
check(count($liste($gast, 1)) === 1, 'zwei registrierte Meldungen: noch sichtbar');
api('POST', '/api/report.php', $tok['Dora'], ['content_type' => 'comment', 'content_id' => $kid, 'category' => 'insult']);
check($liste($gast, 1) === [], 'dritte registrierte Meldung: fuer alle ausgeblendet');
[, $fotos] = api('GET', '/api/gallery.php', $gast);
check(array_column($fotos, 'comment_count', 'id')[1] === 0, 'ausgeblendete Kommentare zaehlen nicht');
Moderation::dismiss($pdo, 'comment', $kid, 1);
check(count($liste($gast, 1)) === 1, 'Zurueckweisen: wieder sichtbar');

[$s] = api('POST', '/api/listener/hide-author.php', $tok['Carl'], ['content_type' => 'comment', 'content_id' => $kid]);
check($s === 200 && $liste($tok['Carl'], 1) === [] && count($liste($tok['Dora'], 1)) === 1, 'Carl blendet Annabell aus, Dora sieht sie weiter');

echo "Gesperrt\n";
$pdo->exec("UPDATE listeners SET blocked_at = NOW(), blocked_reason = 'Test' WHERE nickname = 'Dora'");
[$s] = $schreibe($tok['Dora'], 1, 'Darf ich noch?');
check($s === 403, 'gesperrt: kein Kommentar');
$pdo->exec("UPDATE listeners SET blocked_at = NULL, blocked_reason = NULL WHERE nickname = 'Dora'");

echo "Admin entfernt mit Grund\n";
[, $r] = $schreibe($tok['Dora'], 2, 'Kauft alle bei meinem Shop!');
$dora = (int) $r['id'];
Moderation::removeContent($pdo, 'comment', $dora, 1, 'Werbung');
check((int) $pdo->query("SELECT COUNT(*) FROM gallery_comments WHERE id = {$dora}")->fetchColumn() === 0, 'Kommentar geloescht');
[$betreff, $html] = lastMail('dora@example.org');
check(str_contains($betreff, 'entfernt') && str_contains($html, 'einen Kommentar') && str_contains($html, 'Werbung'), 'Verfasserin bekommt Mail mit Grund („einen Kommentar“)');

echo "Kontoloeschung\n";
$annaId = (int) $pdo->query("SELECT id FROM listeners WHERE nickname = 'Annabell'")->fetchColumn();
Listener::requestDeletion($pdo, $annaId, true, false);
ListenerContent::hideForDeletion($pdo, $annaId, true, false);
check($liste($tok['Dora'], 1) === [], 'Rueckkehrfrist mit „Meine Texte loeschen“: Kommentar ausgeblendet');
Listener::restore($pdo, $annaId);
ListenerContent::restoreAfterReturn($pdo, $annaId);
check(count($liste($tok['Dora'], 1)) === 1, 'Rueckkehr: Kommentar wieder da');

ListenerContent::finalizeDeletion($pdo, $annaId, false, false);
Listener::deleteFinally($pdo, $annaId);
$k = $liste($tok['Dora'], 1);
check(count($k) === 1 && $k[0]['author_name'] === ListenerContent::FORMER_MEMBER, 'ohne Haekchen: Kommentar bleibt als „Ehemaliges Mitglied“');

[, $r] = $schreibe($tok['Bea'], 2, 'Noch ein Kommentar');
$beaId = (int) $pdo->query("SELECT id FROM listeners WHERE nickname = 'Bea'")->fetchColumn();
ListenerContent::finalizeDeletion($pdo, $beaId, true, false);
Listener::deleteFinally($pdo, $beaId);
check($liste($tok['Dora'], 2) === [], 'mit „Meine Texte loeschen“: Kommentar endgueltig weg');

echo "Foto weg, Kommentare weg\n";
$pdo->exec('DELETE FROM photos WHERE id = 1');
check((int) $pdo->query('SELECT COUNT(*) FROM gallery_comments WHERE photo_id = 1')->fetchColumn() === 0, 'Kommentare fallen mit dem Foto weg');

finish();
