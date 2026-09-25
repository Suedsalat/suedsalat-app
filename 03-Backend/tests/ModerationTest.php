<?php
declare(strict_types=1);

/**
 * Moderation (App 2.0): Melden, automatisches Ausblenden, Nutzer ausblenden, Wortfilter.
 * Siehe Konzept-2.0-Hoererkonto.md, Abschnitt 5. Nur gegen die Testumgebung:
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/ModerationTest.php
 */

require __DIR__ . '/TestHelpers.php';

use Suedsalat\Moderation;

resetTables($pdo, ['listener_login_codes', 'listener_hidden', 'content_reports', 'listeners', 'devices', 'refresh_tokens',
    'rate_limits', 'tip_reviews', 'movie_tips', 'photos', 'feedback_messages']);
$pdo->exec("INSERT INTO admins (id, name, email, password_hash, role) VALUES (2, 'Jenny', 'jenny@test.local', 'x', 'member')
            ON DUPLICATE KEY UPDATE email = VALUES(email)");
$pdo->exec("INSERT INTO movie_tips (id, title, created_by, submitted_by_name) VALUES (1, 'Testfilm', 1, 'Südsalat')");

$gast = deviceToken('gast');
$tok = [];
foreach (['Autor', 'Bea', 'Carl', 'Dora'] as $name) {
    $tok[$name] = deviceToken('geraet-' . $name);
    registerListener($tok[$name], strtolower($name) . '@example.org', $name);
}
$review = fn (string $token, string $text) => api('POST', '/api/submit-review.php', $token, null, [], [
    'tip_type' => 'movie_tip', 'tip_id' => '1', 'rating' => '3', 'review_text' => $text]);
$report = fn (string $token, string $type, int $id, string $cat = 'insult', ?string $text = null) =>
    api('POST', '/api/report.php', $token, ['content_type' => $type, 'content_id' => $id, 'category' => $cat, 'text' => $text]);
$sichtbar = function (string $token): array {
    [, $r] = api('GET', '/api/tip-reviews.php?tip_type=movie_tip&tip_id=1', $token);
    return array_column($r['reviews'] ?? [], 'reviewer_name');
};

echo "Wortfilter\n";
[$s, $r] = $review($tok['Autor'], 'Der Regisseur ist ein Arschloch');
check($s === 422 && str_contains($r['error'] ?? '', 'Beleidigungen'), 'Rezension mit Beleidigung abgelehnt');
[$s, $r] = api('POST', '/api/listener/update.php', $tok['Autor'], ['nickname' => 'Wichser99']);
check($s === 422, 'Spitzname mit Beleidigung abgelehnt');
[$s] = $review($tok['Autor'], 'Starker Film, nur das Ende war etwas lang.');
check($s === 200, 'normale Rezension angenommen');
$rid = (int) $pdo->query('SELECT id FROM tip_reviews')->fetchColumn();

echo "Melden\n";
[$s, $r] = $report($gast, 'review', $rid, 'unbekannt');
check($s === 422, 'ohne gueltigen Grund abgelehnt');
[$s, $r] = $report($gast, 'review', $rid, 'insult', 'Finde ich daneben');
check($s === 200 && ($r['already_reported'] ?? true) === false, 'Gast darf melden');
[$subject, $html] = lastMail('owner@test.local');
check(str_contains($subject, 'Neue Meldung') && str_contains($html, 'Beleidigung/Hass') && str_contains($html, 'Finde ich daneben'), 'Admins bekommen Mail mit Grund und Text');
check(lastMail('jenny@test.local')[0] !== '', 'auch Jenny bekommt die Mail');
[, $r] = $report($gast, 'review', $rid);
check(($r['already_reported'] ?? false) === true, 'doppelte Meldung wird nicht gezaehlt');
foreach (['g2', 'g3', 'g4'] as $g) {
    $report(deviceToken($g), 'review', $rid);
}
check(in_array('Autor', $sichtbar($gast), true), 'Gast-Meldungen blenden nicht aus (auch vier nicht)');
$report($tok['Bea'], 'review', $rid);
$report($tok['Carl'], 'review', $rid);
check(in_array('Autor', $sichtbar($gast), true), 'zwei registrierte Meldungen: noch sichtbar');
[, $r] = $report($tok['Dora'], 'review', $rid);
check(!in_array('Autor', $sichtbar($gast), true), 'dritte registrierte Meldung: sofort fuer alle ausgeblendet');
check(str_contains(lastMail('owner@test.local')[0], 'Beitrag ausgeblendet'), 'Mail meldet das automatische Ausblenden');
[$s] = $report($tok['Bea'], 'review', $rid);
check($s === 404, 'ausgeblendeter Beitrag kann nicht weiter gemeldet werden');

echo "Entscheidung durch Thorsten\n";
Moderation::dismiss($pdo, 'review', $rid, 1);
check(in_array('Autor', $sichtbar($gast), true), 'Zurueckweisen: Rezension wieder sichtbar');
check((int) $pdo->query("SELECT COUNT(*) FROM content_reports WHERE status = 'open'")->fetchColumn() === 0, 'alle Meldungen dazu erledigt');
$report($tok['Bea'], 'review', $rid);
Moderation::removeContent($pdo, 'review', $rid, 1);
check((int) $pdo->query("SELECT COUNT(*) FROM tip_reviews WHERE id = {$rid}")->fetchColumn() === 0, 'Entfernen: Rezension geloescht');

echo "Tipps werden nie automatisch ausgeblendet\n";
foreach (['Bea', 'Carl', 'Dora'] as $n) {
    $report($tok[$n], 'movie_tip', 1, 'other');
}
[, $r] = api('GET', '/api/movie-tips.php', $gast);
check(count($r) === 1, 'Filmtipp nach drei Meldungen weiter sichtbar');
check((int) $pdo->query("SELECT COUNT(*) FROM content_reports WHERE content_type = 'movie_tip' AND status = 'open'")->fetchColumn() === 3, 'Meldungen liegen in der Liste');

echo "Nutzer ausblenden\n";
$review($tok['Autor'], 'Zweiter Versuch, diesmal freundlich.');
$rid = (int) $pdo->query('SELECT id FROM tip_reviews')->fetchColumn();
[$s, $r] = api('POST', '/api/listener/hide-author.php', $tok['Autor'], ['content_type' => 'review', 'content_id' => $rid]);
check($s === 422, 'sich selbst ausblenden geht nicht');
[$s] = api('POST', '/api/listener/hide-author.php', $gast, ['content_type' => 'review', 'content_id' => $rid]);
check($s === 401, 'Gaeste koennen niemanden ausblenden');
[$s] = api('POST', '/api/listener/hide-author.php', $tok['Bea'], ['content_type' => 'review', 'content_id' => $rid]);
check($s === 200, 'Bea blendet den Autor aus');
check(!in_array('Autor', $sichtbar($tok['Bea']), true), 'Bea sieht die Rezension nicht mehr');
check(in_array('Autor', $sichtbar($tok['Carl']), true), 'Carl sieht sie weiterhin');
[, $r] = api('GET', '/api/tip-reviews.php?tip_type=movie_tip&tip_id=1', $tok['Bea']);
check($r['review_count'] === 1, 'Durchschnitt/Anzahl bleibt fuer alle gleich');
[, $r] = api('GET', '/api/listener/hidden-users.php', $tok['Bea']);
check(array_column($r['hidden_users'] ?? [], 'nickname') === ['Autor'], 'Liste der ausgeblendeten Nutzer');
$autorId = (int) $r['hidden_users'][0]['id'];
api('POST', '/api/listener/hidden-users.php', $tok['Bea'], ['listener_id' => $autorId]);
check(in_array('Autor', $sichtbar($tok['Bea']), true), 'wieder eingeblendet');

echo "Eigene Beitraege erkennen\n";
[, $r] = api('GET', '/api/tip-reviews.php?tip_type=movie_tip&tip_id=1', $tok['Autor']);
check(($r['reviews'][0]['is_own'] ?? null) === true, 'Autor: eigene Rezension ist is_own');
[, $r] = api('GET', '/api/tip-reviews.php?tip_type=movie_tip&tip_id=1', $tok['Carl']);
check(($r['reviews'][0]['is_own'] ?? null) === false && !array_key_exists('listener_id', $r['reviews'][0]), 'andere: is_own false, keine Kontonummer');
[, $r] = api('GET', '/api/tip-reviews.php?tip_type=movie_tip&tip_id=1', $gast);
check(($r['reviews'][0]['is_own'] ?? null) === false, 'Gast: is_own false');

echo "Gesperrte Hoerer\n";
$pdo->exec("UPDATE listeners SET blocked_at = NOW() WHERE nickname = 'Dora'");
[$s] = $report($tok['Dora'], 'review', $rid);
check($s === 403, 'gesperrt: kann nicht melden');

finish();
