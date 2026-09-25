<?php
declare(strict_types=1);

/**
 * Admin-Bereich fuer App 2.0: Seiten "Hoererkonten" und "Meldungen", Dashboard-Hinweise,
 * Bildart und schreibgeschuetztes "Tipp von". Ruft die Seiten mit echter Admin-Anmeldung
 * (Sitzungsdatei) im lokalen Testserver auf. Nur gegen die Testumgebung:
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/AdminPagesTest.php
 */

require __DIR__ . '/TestHelpers.php';

use Suedsalat\Moderation;

const ADMIN_PASSWORD = 'geheim-test-123';

resetTables($pdo, ['listener_login_codes', 'listener_hidden', 'content_reports', 'listeners', 'devices', 'refresh_tokens',
    'rate_limits', 'tip_reviews', 'movie_tips', 'location_tips', 'photos', 'feedback_messages']);
$pdo->exec("INSERT INTO admins (id, name, email, password_hash, role) VALUES (2, 'Jenny', 'jenny@test.local', 'x', 'member')
            ON DUPLICATE KEY UPDATE role = 'member'");
// Beide mit gueltigem Passwort - Jenny darf dann nur an ihrer Rolle scheitern, nicht am Passwort.
$pdo->prepare('UPDATE admins SET password_hash = :h, totp_enabled = 0 WHERE id IN (1, 2)')
    ->execute([':h' => password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT)]);

$logFile = dirname((string) getenv('SUEDSALAT_ENV_FILE')) . '/php-server.log';
$logStart = is_file($logFile) ? filesize($logFile) : 0;

// --- Testdaten -------------------------------------------------------------------------
$tok = [];
foreach (['Autor', 'Bea', 'Carl', 'Dora'] as $name) {
    $tok[$name] = deviceToken('admin-geraet-' . $name);
    registerListener($tok[$name], strtolower($name) . '@example.org', $name);
}
$autorId = (int) $pdo->query("SELECT id FROM listeners WHERE nickname = 'Autor'")->fetchColumn();

$pdo->exec("INSERT INTO movie_tips (id, title, created_by, submitted_by_name, listener_id, image_kind, image_path)
            VALUES (1, 'Hörerfilm', 1, 'Autor alt', {$autorId}, 'poster', NULL),
                   (2, 'Eigener Film', 1, 'Südsalat', NULL, 'own', NULL)");
$pdo->exec("INSERT INTO location_tips (id, name, location, created_by, submitted_by_name, listener_id, image_kind,
                image_path, hidden_image_path, image_removed_at)
            VALUES (5, 'Café ohne Bild', 'Köln', 1, 'Autor', {$autorId}, 'own', NULL, 'https://x.invalid/a.jpg', NOW())");

api('POST', '/api/submit-review.php', $tok['Autor'], null, [], [
    'tip_type' => 'movie_tip', 'tip_id' => '2', 'rating' => '4', 'review_text' => 'Schöner Film, gerne mehr davon.']);
$rid = (int) $pdo->query('SELECT id FROM tip_reviews')->fetchColumn();
foreach (['Bea', 'Carl', 'Dora'] as $n) {
    api('POST', '/api/report.php', $tok[$n], ['content_type' => 'review', 'content_id' => $rid, 'category' => 'spam', 'text' => 'Werbung von ' . $n]);
}
api('POST', '/api/report.php', deviceToken('admin-gast'), ['content_type' => 'movie_tip', 'content_id' => 1, 'category' => 'other']);

$owner = adminSession(1);
$jenny = adminSession(2);

echo "Seiten laden ohne Fehler\n";
foreach (['dashboard.php', 'reports.php', 'listeners.php', 'movie-tips.php?edit=1', 'movie-tips.php?edit=2',
          'location-tips.php?edit=5', 'events.php', 'gallery.php', 'feedback.php', 'tip-reviews.php'] as $p) {
    [$s, $html] = page($owner, '/admin/' . $p);
    check($s === 200 && clean($html) && str_contains($html, '</html>'), $p);
}

echo "Seitenleiste und Dashboard\n";
[, $html] = page($owner, '/admin/dashboard.php');
check(str_contains($html, 'Hörerkonten') && str_contains($html, 'Meldungen (2)'), 'Menue: Hörerkonten und Meldungen mit Zahl');
check(str_contains($html, '1 davon ist automatisch ausgeblendet'), 'Dashboard nennt automatisch ausgeblendete Beitraege');
check(str_contains($html, 'id="tips-without-image"') && str_contains($html, '„Café ohne Bild“'), 'Dashboard zeigt Tipp ohne Bild');

echo "Meldungen\n";
[, $html] = page($owner, '/admin/reports.php');
check(str_contains($html, 'Automatisch ausgeblendet') && str_contains($html, 'Werbung von Carl'), 'Rezension mit Gruenden und Status');
check(str_contains($html, 'movie-tips.php?edit=1'), 'Filmtipp: Link zum Bearbeiten statt Entfernen');
check(str_contains($html, 'listeners.php#listener-' . $autorId), 'Link zum Hörerkonto des Verfassers');

page($owner, '/admin/reports.php', ['action' => 'dismiss', 'content_type' => 'review', 'content_id' => (string) $rid]);
check($pdo->query("SELECT hidden_at FROM tip_reviews WHERE id = {$rid}")->fetchColumn() === null, 'In Ordnung: Rezension wieder sichtbar');

api('POST', '/api/report.php', $tok['Bea'], ['content_type' => 'review', 'content_id' => $rid, 'category' => 'insult']);
[, , $loc] = page($owner, '/admin/reports.php', ['action' => 'remove', 'content_type' => 'review', 'content_id' => (string) $rid,
    'reason' => 'Werbung für einen Shop', 'confirm_password' => 'falsch']);
check(str_contains($loc, 'delete_error') && (int) $pdo->query("SELECT COUNT(*) FROM tip_reviews WHERE id = {$rid}")->fetchColumn() === 1,
    'Entfernen mit falschem Passwort: nichts passiert');
page($owner, '/admin/reports.php', ['action' => 'remove', 'content_type' => 'review', 'content_id' => (string) $rid,
    'reason' => 'Werbung für einen Shop', 'confirm_password' => ADMIN_PASSWORD]);
check((int) $pdo->query("SELECT COUNT(*) FROM tip_reviews WHERE id = {$rid}")->fetchColumn() === 0, 'Entfernen: Rezension geloescht');
[$subject, $mail] = lastMail('autor@example.org');
check(str_contains($subject, 'Beitrag wurde entfernt') && str_contains($mail, 'Werbung für einen Shop'), 'Verfasser bekommt Mail mit Grund');
[, $html] = page($owner, '/admin/reports.php');
check(str_contains($html, 'Entfernt') && str_contains($html, 'In Ordnung'), 'Erledigte Meldungen stehen unten');
page($owner, '/admin/reports.php', ['action' => 'remove', 'content_type' => 'movie_tip', 'content_id' => '1', 'confirm_password' => ADMIN_PASSWORD]);
check((int) $pdo->query('SELECT COUNT(*) FROM movie_tips WHERE id = 1')->fetchColumn() === 1, 'Tipps lassen sich hier nicht entfernen');

echo "Tipp ohne Bild\n";
page($owner, '/admin/dashboard.php', ['action' => 'dismiss_image_notice', 'table' => 'location_tips', 'id' => '5']);
[, $html] = page($owner, '/admin/dashboard.php');
check(!str_contains($html, 'id="tips-without-image"') && !str_contains($html, '„Café ohne Bild“'), 'Ohne Bild lassen: Hinweis verschwindet');
page($owner, '/admin/dashboard.php', ['action' => 'dismiss_image_notice', 'table' => 'admins', 'id' => '1']);
check(true, 'unbekannte Tabelle wird ignoriert (kein Fehler)');

echo "Tipp von und Bildart\n";
[, $html] = page($owner, '/admin/movie-tips.php?edit=1');
check(preg_match('/value="Autor" readonly disabled/', $html) === 1, 'kontogebundener Tipp: Name live und schreibgeschuetzt');
check(str_contains($html, 'Wessen Bild ist das?') && preg_match('/value="poster"[^>]*checked/', $html) === 1, 'Bildart-Auswahl mit gespeichertem Wert');
[, $html] = page($owner, '/admin/movie-tips.php?edit=2');
check(!str_contains($html, 'Wessen Bild ist das?') && !str_contains($html, 'readonly disabled'), 'eigener Tipp: freies Namensfeld, keine Bildart');

page($owner, '/admin/movie-tips.php', ['edit_id' => '1', 'title' => 'Hörerfilm', 'submitted_by_name' => 'Autor alt', 'image_kind' => 'own']);
$row = $pdo->query('SELECT image_kind, submitted_by_name FROM movie_tips WHERE id = 1')->fetch();
check($row['image_kind'] === 'own' && $row['submitted_by_name'] === 'Autor alt', 'Bildart gespeichert, Rueckfall-Name bleibt');
page($owner, '/admin/movie-tips.php', ['edit_id' => '2', 'title' => 'Eigener Film', 'submitted_by_name' => 'Südsalat', 'image_kind' => 'poster']);
check($pdo->query('SELECT image_kind FROM movie_tips WHERE id = 2')->fetchColumn() === 'own', 'ohne Konto wird keine Bildart gesetzt');

echo "Hörerkonten\n";
[, $html] = page($jenny, '/admin/listeners.php');
check(str_contains($html, 'Autor') && !str_contains($html, 'value="block"'), 'Jenny sieht die Konten, aber keinen Sperren-Knopf');
page($jenny, '/admin/listeners.php', ['action' => 'block', 'listener_id' => (string) $autorId, 'reason' => 'x', 'confirm_password' => ADMIN_PASSWORD]);
check($pdo->query("SELECT blocked_at FROM listeners WHERE id = {$autorId}")->fetchColumn() === null, 'Jenny kann nicht sperren');
page($jenny, '/admin/listeners.php', ['action' => 'rename', 'listener_id' => (string) $autorId, 'nickname' => 'Autorin']);
check($pdo->query("SELECT nickname FROM listeners WHERE id = {$autorId}")->fetchColumn() === 'Autorin', 'Jenny kann umbenennen');
[, $html] = page($owner, '/admin/listeners.php', ['action' => 'rename', 'listener_id' => (string) $autorId, 'nickname' => 'Südsalat']);
check($pdo->query("SELECT nickname FROM listeners WHERE id = {$autorId}")->fetchColumn() === 'Autorin' && str_contains($html, 'class="error"'), 'reservierter Name wird abgelehnt');

page($owner, '/admin/listeners.php', ['action' => 'block', 'listener_id' => (string) $autorId, 'reason' => 'Wiederholt Werbung', 'confirm_password' => 'falsch']);
check($pdo->query("SELECT blocked_at FROM listeners WHERE id = {$autorId}")->fetchColumn() === null, 'Sperren mit falschem Passwort: nichts passiert');
page($owner, '/admin/listeners.php', ['action' => 'block', 'listener_id' => (string) $autorId, 'reason' => 'Wiederholt Werbung', 'confirm_password' => ADMIN_PASSWORD]);
check($pdo->query("SELECT blocked_reason FROM listeners WHERE id = {$autorId}")->fetchColumn() === 'Wiederholt Werbung', 'Owner sperrt mit Grund');
[$subject, $mail] = lastMail('autor@example.org');
check(str_contains($subject, 'gesperrt') && str_contains($mail, 'Wiederholt Werbung'), 'Hörer bekommt Sperr-Mail mit Grund');
[$s] = api('POST', '/api/submit-review.php', $tok['Autor'], null, [], ['tip_type' => 'movie_tip', 'tip_id' => '2', 'rating' => '3', 'review_text' => 'Noch eine']);
check($s === 403, 'gesperrt: keine Rezension mehr');
page($owner, '/admin/listeners.php', ['action' => 'unblock', 'listener_id' => (string) $autorId]);
check($pdo->query("SELECT blocked_at FROM listeners WHERE id = {$autorId}")->fetchColumn() === null
    && str_contains(lastMail('autor@example.org')[0], 'aufgehoben'), 'Entsperren mit Mail');

echo "Protokoll des Testservers\n";
$log = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $logStart) : '';
$problems = array_filter(explode("\n", $log), static fn ($l) => preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $l));
check($problems === [], 'keine PHP-Warnungen' . ($problems ? ': ' . implode(' | ', array_slice($problems, 0, 3)) : ''));

foreach ([$owner, $jenny] as $sid) {
    @unlink(session_save_path() . '/sess_' . $sid);
}
finish();
