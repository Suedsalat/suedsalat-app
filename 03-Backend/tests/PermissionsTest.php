<?php
declare(strict_types=1);

/**
 * Rechte-Test (App 2.0): Was duerfen Gaeste, Registrierte und gesperrte Hoerer?
 * Siehe Konzept-2.0-Hoererkonto.md, Abschnitt 1. Nur gegen die Testumgebung:
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/PermissionsTest.php
 */

require __DIR__ . '/TestHelpers.php';

resetTables($pdo, ['listener_login_codes', 'listeners', 'devices', 'refresh_tokens', 'rate_limits', 'tip_reviews', 'movie_tips', 'location_tips', 'feedback_messages']);
$pdo->exec("INSERT INTO movie_tips (id, title, created_by, submitted_by_name) VALUES (1, 'Testfilm', 1, 'Südsalat')");

$gast = deviceToken('gast');
$hoerer = deviceToken('hoerer');
registerListener($hoerer, 'inga@example.org', 'Inga');
$lid = (int) $pdo->query("SELECT id FROM listeners WHERE email = 'inga@example.org'")->fetchColumn();

$feedback = fn (string $token, array $fields) => api('POST', '/api/feedback.php', $token, null, [], $fields);
$review = fn (string $token, array $fields = []) => api('POST', '/api/submit-review.php', $token, null, [], array_merge(
    ['tip_type' => 'movie_tip', 'tip_id' => '1', 'rating' => '4', 'review_text' => 'Richtig gut!'], $fields));

echo "Gast\n";
[$s] = $feedback($gast, ['type' => 'allgemein', 'message' => 'Tolle Folge!', 'sender_name' => 'Gast Gustav']);
check($s === 200, 'Gast: schriftliches allgemeines Feedback erlaubt');
[$s] = $feedback($gast, ['type' => 'frage', 'message' => 'Wann kommt Folge 37?', 'sender_name' => 'Gast Gustav']);
check($s === 200, 'Gast: Frage erlaubt');
foreach (['kino_tipp', 'location_tipp', 'termin_tipp', 'foto_vorschlag', 'sprachnachricht'] as $typ) {
    [$s, $r] = $feedback($gast, ['type' => $typ, 'message' => 'x', 'sender_name' => 'Gast Gustav', 'suggested_date' => '2026-12-01']);
    check($s === 403, "Gast: {$typ} abgelehnt");
}
$datei = tempnam(sys_get_temp_dir(), 'bild') . '.jpg';
file_put_contents($datei, 'kein echtes Bild');
[$s, $r] = $feedback($gast, ['type' => 'allgemein', 'message' => 'mit Anhang', 'sender_name' => 'Gast Gustav', 'media' => new CURLFile($datei, 'image/jpeg')]);
check($s === 403 && str_contains($r['error'] ?? '', 'registrierter'), 'Gast: allgemeines Feedback mit Foto abgelehnt');
[$s, $r] = $review($gast, ['reviewer_name' => 'Gast Gustav']);
check($s === 403 && str_contains($r['error'] ?? '', 'registrierter'), 'Gast: Rezension abgelehnt');

echo "Registrierter Hoerer\n";
[$s] = $feedback($hoerer, ['type' => 'kino_tipp', 'message' => 'Unbedingt ansehen: Dune', 'sender_name' => 'Falscher Name']);
check($s === 200, 'Hoerer: Filmtipp erlaubt');
$fb = $pdo->query("SELECT sender_name, listener_id FROM feedback_messages WHERE type = 'kino_tipp'")->fetch();
check($fb['sender_name'] === 'Inga' && (int) $fb['listener_id'] === $lid, 'Hoerer: Name kommt aus dem Konto, nicht aus dem Formular; Einsendung gehoert dem Konto');
[$s] = $review($hoerer, ['reviewer_name' => 'Falscher Name']);
check($s === 200, 'Hoerer: Rezension erlaubt');
$rv = $pdo->query('SELECT reviewer_name, listener_id FROM tip_reviews')->fetch();
check($rv['reviewer_name'] === 'Inga' && (int) $rv['listener_id'] === $lid, 'Hoerer: Rezension mit Spitzname und Konto gespeichert');

echo "Anzeige\n";
[, $r] = api('GET', '/api/tip-reviews.php?tip_type=movie_tip&tip_id=1', $gast);
check(($r['reviews'][0]['reviewer_name'] ?? '') === 'Inga' && $r['review_count'] === 1, 'Rezension erscheint mit Spitznamen');
api('POST', '/api/listener/update.php', $hoerer, ['nickname' => 'Inga K']);
[, $r] = api('GET', '/api/tip-reviews.php?tip_type=movie_tip&tip_id=1', $gast);
check(($r['reviews'][0]['reviewer_name'] ?? '') === 'Inga K', 'neuer Spitzname erscheint sofort bei alten Beitraegen');
$pdo->exec("UPDATE tip_reviews SET hidden_at = NOW(), hidden_reason = 'reports'");
[, $r] = api('GET', '/api/tip-reviews.php?tip_type=movie_tip&tip_id=1', $gast);
check($r['review_count'] === 0 && $r['reviews'] === [] && $r['avg_rating'] === null, 'ausgeblendete Rezension: weder in der Liste noch im Durchschnitt');
[, $r] = api('GET', '/api/movie-tips.php', $gast);
check(($r[0]['review_count'] ?? -1) === 0, 'ausgeblendete Rezension zaehlt auch in der Tipp-Liste nicht');
$pdo->exec('UPDATE tip_reviews SET hidden_at = NULL, hidden_reason = NULL');

echo "Uebernehmen einer Einsendung (Admin)\n";
$fid = (int) $pdo->query("SELECT id FROM feedback_messages WHERE type = 'kino_tipp'")->fetchColumn();
$pdo->exec("INSERT INTO movie_tips (id, title, created_by, image_path) VALUES (2, 'Dune', 1, 'http://x/uploads/movie-tips/d.jpg')");
$pdo->exec("INSERT INTO location_tips (id, name, location, created_by, image_path) VALUES (1, 'Rossini', 'Merten', 1, 'http://x/uploads/location-tips/r.jpg')");
$pdo->exec("INSERT INTO location_tips (id, name, location, created_by) VALUES (2, 'Ohne Bild', 'Merten', 1)");
\Suedsalat\ListenerContent::adoptFromFeedback($pdo, 'movie_tips', 2, $fid);
\Suedsalat\ListenerContent::adoptFromFeedback($pdo, 'location_tips', 1, $fid);
\Suedsalat\ListenerContent::adoptFromFeedback($pdo, 'location_tips', 2, $fid);
$m = $pdo->query('SELECT listener_id, image_kind FROM movie_tips WHERE id = 2')->fetch();
check((int) $m['listener_id'] === $lid && $m['image_kind'] === 'poster', 'Filmtipp gehoert dem Einsender, Bild als Plakat voreingestellt');
$l = $pdo->query('SELECT listener_id, image_kind FROM location_tips WHERE id = 1')->fetch();
check((int) $l['listener_id'] === $lid && $l['image_kind'] === 'own', 'Locationtipp: Bild als eigenes Foto voreingestellt');
check($pdo->query('SELECT image_kind FROM location_tips WHERE id = 2')->fetchColumn() === null, 'ohne Bild wird keine Bildart gesetzt');
\Suedsalat\ListenerContent::adoptFromFeedback($pdo, 'location_tips', 1, $fid, 'poster');
check($pdo->query('SELECT image_kind FROM location_tips WHERE id = 1')->fetchColumn() === 'poster', 'Bildart laesst sich beim Uebernehmen waehlen');
[, $r] = api('GET', '/api/location-tips.php', $gast);
check(($r[0]['submitted_by_name'] ?? '') === 'Inga K', 'uebernommener Tipp zeigt den Spitznamen des Einsenders');

echo "Gesperrter Hoerer\n";
$pdo->exec("UPDATE listeners SET blocked_at = NOW(), blocked_reason = 'Test' WHERE id = {$lid}");
[, $r] = api('GET', '/api/listener/me.php', $hoerer);
check(($r['listener']['blocked'] ?? false) === true, 'gesperrt: App erfaehrt es ueber me');
[$s] = $review($hoerer);
check($s === 403, 'gesperrt: keine Rezension');
[$s] = $feedback($hoerer, ['type' => 'kino_tipp', 'message' => 'x']);
check($s === 403, 'gesperrt: kein Tipp');
[$s] = $feedback($hoerer, ['type' => 'allgemein', 'message' => 'Darf ich noch schreiben?']);
check($s === 200, 'gesperrt: allgemeines Feedback wie ein Gast weiter moeglich');

finish();
