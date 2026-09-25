<?php
declare(strict_types=1);

/**
 * Pruefkonto fuer die Store-Pruefung (Apple/Google): fester Code fuer genau eine Adresse,
 * Grenzen bleiben, Beitraege sieht nur das Pruefkonto selbst, seine Meldungen blenden nichts aus.
 * Braucht REVIEW_LOGIN_EMAIL=pruefung@example.org und REVIEW_LOGIN_CODE=424242 in der .env.test.
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/PruefkontoTest.php
 */

require __DIR__ . '/TestHelpers.php';

if (REVIEW_LOGIN_EMAIL !== 'pruefung@example.org' || REVIEW_LOGIN_CODE !== '424242') {
    fwrite(STDERR, "Abbruch: REVIEW_LOGIN_EMAIL/REVIEW_LOGIN_CODE fehlen in der .env.test.\n");
    exit(2);
}

resetTables($pdo, ['listener_login_codes', 'listener_hidden', 'content_reports', 'gallery_comments', 'listeners', 'devices',
    'refresh_tokens', 'rate_limits', 'tip_reviews', 'movie_tips', 'photos']);
$pdo->exec("INSERT INTO movie_tips (id, title, created_by, submitted_by_name) VALUES (1, 'Testfilm', 1, 'Südsalat')");
$pdo->exec("INSERT INTO photos (id, image_path, description, created_by, submitted_by_name) VALUES
            (1, 'https://x.invalid/1.jpg', 'Foto eins', 1, 'Südsalat')");

$gast = deviceToken('p-gast');
$pruefer = deviceToken('p-pruefer');
$tok = [];
foreach (['Anna', 'Bea', 'Carl'] as $n) {
    $tok[$n] = deviceToken('p-' . $n);
    registerListener($tok[$n], strtolower($n) . '@example.org', $n);
}

echo "Fester Code\n";
[$s] = api('POST', '/api/listener/register.php', $pruefer, [
    'first_name' => 'App', 'last_name' => 'Prüfung', 'email' => 'Pruefung@Example.org', 'nickname' => 'App-Prüfung', 'accept_terms' => true]);
check($s === 200, 'Pruefkonto kann sich registrieren (Grossschreibung egal)');
check(codeFrom(lastMail('pruefung@example.org')[1]) === '424242', 'Mail enthaelt den festen Code');
[$s, $r] = api('POST', '/api/listener/verify.php', $pruefer, ['email' => 'pruefung@example.org', 'code' => '424242']);
check($s === 200 && ($r['created'] ?? false) === true, 'fester Code legt das Konto an');
check((int) $pdo->query("SELECT review_account FROM listeners WHERE email = 'pruefung@example.org'")->fetchColumn() === 1, 'Konto ist als Pruefkonto markiert');
check((int) $pdo->query("SELECT SUM(review_account) FROM listeners")->fetchColumn() === 1, 'normale Konten sind es nicht');

api('POST', '/api/listener/logout.php', $pruefer);
api('POST', '/api/listener/login.php', $pruefer, ['email' => 'pruefung@example.org']);
[$s] = api('POST', '/api/listener/verify.php', $pruefer, ['email' => 'pruefung@example.org', 'code' => '424242']);
check($s === 200, 'Anmelden mit festem Code');

$annaGeraet = deviceToken('p-anna-2');
api('POST', '/api/listener/login.php', $annaGeraet, ['email' => 'anna@example.org']);
[$s] = api('POST', '/api/listener/verify.php', $annaGeraet, ['email' => 'anna@example.org', 'code' => '424242']);
check($s === 422 && codeFrom(lastMail('anna@example.org')[1]) !== '424242', 'normale Konten: fester Code gilt nicht');

echo "Grenzen bleiben\n";
$zweit = deviceToken('p-pruefer-2');
api('POST', '/api/listener/login.php', $zweit, ['email' => 'pruefung@example.org']);
for ($i = 0; $i < 5; $i++) {
    api('POST', '/api/listener/verify.php', $zweit, ['email' => 'pruefung@example.org', 'code' => '111111']);
}
[$s, $r] = api('POST', '/api/listener/verify.php', $zweit, ['email' => 'pruefung@example.org', 'code' => '424242']);
check($s === 422 && str_contains($r['error'] ?? '', 'Fehlversuche'), 'nach 5 Fehlversuchen hilft auch der feste Code nicht');
[$s] = api('POST', '/api/listener/verify.php', $zweit, ['email' => 'pruefung@example.org', 'code' => '424242']);
check($s === 422, 'ohne neuen Code bleibt es gesperrt');

echo "Bestehendes Konto wird zum Pruefkonto\n";
$pdo->exec("UPDATE listeners SET review_account = 0 WHERE email = 'pruefung@example.org'");
api('POST', '/api/listener/login.php', $zweit, ['email' => 'pruefung@example.org']);
api('POST', '/api/listener/verify.php', $zweit, ['email' => 'pruefung@example.org', 'code' => '424242']);
check((int) $pdo->query("SELECT review_account FROM listeners WHERE email = 'pruefung@example.org'")->fetchColumn() === 1, 'Anmeldung setzt die Markierung');

echo "Rezensionen sieht nur das Pruefkonto\n";
$review = fn (string $token, string $rating, string $text) => api('POST', '/api/submit-review.php', $token, null, [], [
    'tip_type' => 'movie_tip', 'tip_id' => '1', 'rating' => $rating, 'review_text' => $text]);
[$s] = $review($pruefer, '1', 'Testrezension der App-Prüfung');
check($s === 200, 'Pruefkonto kann rezensieren');
$review($tok['Anna'], '5', 'Richtig guter Film.');
$rezensionen = function (string $token): array {
    [, $r] = api('GET', '/api/tip-reviews.php?tip_type=movie_tip&tip_id=1', $token);
    return $r;
};
$r = $rezensionen($pruefer);
check(in_array('App-Prüfung', array_column($r['reviews'], 'reviewer_name'), true), 'Pruefkonto sieht seine Rezension');
$r = $rezensionen($gast);
check(array_column($r['reviews'], 'reviewer_name') === ['Anna'], 'Gast sieht sie nicht');
check($r['review_count'] === 1 && (float) $r['avg_rating'] === 5.0, 'Durchschnitt und Anzahl ohne Pruefkonto');
$r = $rezensionen($tok['Bea']);
check(array_column($r['reviews'], 'reviewer_name') === ['Anna'], 'andere Hoerer sehen sie nicht');
[, $r] = api('GET', '/api/movie-tips.php', $gast);
check((int) $r[0]['review_count'] === 1 && (float) $r[0]['avg_rating'] === 5.0, 'Filmtipp-Liste: Durchschnitt ohne Pruefkonto');

echo "Kommentare sieht nur das Pruefkonto\n";
[$s] = api('POST', '/api/gallery-comments.php', $pruefer, ['photo_id' => 1, 'text' => 'Testkommentar der App-Prüfung']);
check($s === 200, 'Pruefkonto kann kommentieren');
api('POST', '/api/gallery-comments.php', $tok['Bea'], ['photo_id' => 1, 'text' => 'Schönes Foto!']);
[, $r] = api('GET', '/api/gallery-comments.php?photo_id=1', $pruefer);
check(count($r['comments']) === 2, 'Pruefkonto sieht beide Kommentare');
[, $r] = api('GET', '/api/gallery-comments.php?photo_id=1', $gast);
check(array_column($r['comments'], 'comment_text') === ['Schönes Foto!'], 'Gast sieht nur den echten Kommentar');
[, $r] = api('GET', '/api/gallery.php', $tok['Carl']);
check((int) $r[0]['comment_count'] === 1, 'Galerie zaehlt den Pruefkommentar fuer andere nicht');
[, $r] = api('GET', '/api/gallery.php', $pruefer);
check((int) $r[0]['comment_count'] === 2, 'fuer das Pruefkonto schon');

echo "Meldungen des Pruefkontos blenden nichts aus\n";
$rid = (int) $pdo->query("SELECT id FROM tip_reviews WHERE review_text = 'Richtig guter Film.'")->fetchColumn();
$report = fn (string $token) => api('POST', '/api/report.php', $token, ['content_type' => 'review', 'content_id' => $rid, 'category' => 'spam']);
[$s] = $report($pruefer);
check($s === 200, 'Pruefkonto kann melden');
check(str_contains(lastMail('owner@test.local')[0], 'Neue Meldung'), 'Admins bekommen die Meldung');
$report($tok['Bea']);
$report($tok['Carl']);
check(in_array('Anna', array_column($rezensionen($gast)['reviews'], 'reviewer_name'), true), 'Pruefkonto + zwei Hoerer: noch sichtbar');

echo "Admin-Bereich\n";
[, $html] = page(adminSession(1), '/admin/listeners.php');
check(str_contains($html, 'Prüfkonto (Apple/Google)'), 'Hoererkonten-Liste kennzeichnet das Pruefkonto');
check(substr_count($html, 'Prüfkonto (Apple/Google)') === 1, 'nur dieses eine');

finish();
