<?php
declare(strict_types=1);

/**
 * Kontoloeschung ueber die Website (konto-loeschen.php, Pflicht fuer Google Play, gilt fuer alle):
 * Code per Mail, keine Auskunft ueber fremde Adressen, Stilllegen oder sofort, Mengengrenzen.
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/WebLoeschenTest.php
 */

require __DIR__ . '/TestHelpers.php';

resetTables($pdo, ['listener_login_codes', 'listener_hidden', 'content_reports', 'gallery_comments', 'listeners', 'devices',
    'refresh_tokens', 'rate_limits', 'tip_reviews', 'movie_tips']);
$pdo->exec("INSERT INTO movie_tips (id, title, created_by, submitted_by_name) VALUES (1, 'Testfilm', 1, 'Südsalat')");

$tok = deviceToken('w-inga');
registerListener($tok, 'inga@example.org', 'Inga');
api('POST', '/api/submit-review.php', $tok, null, [], ['tip_type' => 'movie_tip', 'tip_id' => '1', 'rating' => '4', 'review_text' => 'Schöner Film.']);
$konto = fn () => $pdo->query("SELECT * FROM listeners WHERE email = 'inga@example.org'")->fetch();
$web = fn (array $post) => page('', '/konto-loeschen.php', $post)[1];
$mails = fn () => count(glob(MAIL_CAPTURE_DIR . '/*.html') ?: []);

echo "Seite\n";
[$s, $html] = page('', '/konto-loeschen.php');
check($s === 200 && str_contains($html, 'Was gelöscht wird') && str_contains($html, 'Einstellungen › Mein Konto › Konto löschen'), 'Seite erklaert Ablauf, Umfang und den Weg in der App');
check(clean($html), 'keine PHP-Fehler');
$html = $web(['aktion' => 'code', 'email' => 'kein-at-zeichen']);
check(str_contains($html, 'gültige E-Mail-Adresse'), 'ungueltige Adresse abgelehnt');

echo "Code anfordern\n";
$vorher = $mails();
$fremd = $web(['aktion' => 'code', 'email' => 'niemand@example.org']);
check($mails() === $vorher, 'unbekannte Adresse: keine Mail');
$html = $web(['aktion' => 'code', 'email' => 'Inga@Example.org']);
[$betreff, $mail] = lastMail('inga@example.org');
check(str_contains($betreff, 'Kontolöschung bestätigen') && str_contains($mail, 'auf unserer Website'), 'bekannte Adresse: Code-Mail fuer die Website');
check(str_replace('niemand@example.org', 'X', $fremd) === str_replace('inga@example.org', 'X', $html), 'Antwort verraet nicht, ob es ein Konto gibt');
$code = codeFrom($mail);

echo "Code pruefen\n";
[, $r] = api('POST', '/api/listener/verify.php', deviceToken('w-fremd'), ['email' => 'inga@example.org', 'code' => $code]);
check(($r['ok'] ?? false) !== true, 'Loesch-Code taugt nicht zum Anmelden in der App');
$html = $web(['aktion' => 'loeschen', 'email' => 'inga@example.org', 'code' => $code === '111111' ? '222222' : '111111', 'art' => 'sofort']);
check(str_contains($html, 'Der Code stimmt nicht') && $konto() !== false, 'falscher Code: nichts passiert');

echo "Stilllegen\n";
$html = $web(['aktion' => 'loeschen', 'email' => 'inga@example.org', 'code' => $code, 'art' => 'stilllegen', 'texte' => '1']);
$l = $konto();
check(str_contains($html, 'Dein Konto ist stillgelegt') && $l['deletion_requested_at'] !== null, 'Konto stillgelegt');
check((int) $l['deletion_delete_texts'] === 1 && (int) $l['deletion_delete_photos'] === 0, 'Auswahl uebernommen');
check(str_contains($html, date('d.m.Y', strtotime((string) $l['deletion_final_at']))), 'Seite nennt das Loeschdatum');
check(lastMail('inga@example.org')[0] === 'Dein Konto bei Südsalat', 'Bestaetigung per Mail');
[, $r] = api('GET', '/api/tip-reviews.php?tip_type=movie_tip&tip_id=1', deviceToken('w-gast'));
check(($r['review_count'] ?? -1) === 0, 'Rezension in der Frist ausgeblendet');
check((int) $pdo->query('SELECT COUNT(*) FROM devices WHERE listener_id IS NOT NULL')->fetchColumn() === 0, 'auf allen Geraeten abgemeldet');
$html = $web(['aktion' => 'loeschen', 'email' => 'inga@example.org', 'code' => $code, 'art' => 'stilllegen']);
check(str_contains($html, 'abgelaufen'), 'Code nur einmal gueltig');

$pdo->exec("UPDATE listeners SET deletion_final_at = DATE_ADD(NOW(), INTERVAL 10 DAY) WHERE email = 'inga@example.org'");
$web(['aktion' => 'code', 'email' => 'inga@example.org']);
$html = $web(['aktion' => 'loeschen', 'email' => 'inga@example.org', 'code' => codeFrom(lastMail('inga@example.org')[1]), 'art' => 'stilllegen']);
check(str_contains($html, date('d.m.Y', strtotime('+10 days'))), 'erneutes Stilllegen verlaengert die Frist nicht');

echo "Sofort endgueltig\n";
$web(['aktion' => 'code', 'email' => 'inga@example.org']);
$html = $web(['aktion' => 'loeschen', 'email' => 'inga@example.org', 'code' => codeFrom(lastMail('inga@example.org')[1]), 'art' => 'sofort', 'texte' => '1']);
check(str_contains($html, 'endgültig gelöscht') && $konto() === false, 'Konto endgueltig geloescht');
check((int) $pdo->query('SELECT COUNT(*) FROM tip_reviews')->fetchColumn() === 0, 'Rezension wie gewaehlt mitgeloescht');
check((int) $pdo->query("SELECT COUNT(*) FROM listener_login_codes WHERE email = 'inga@example.org'")->fetchColumn() === 0, 'keine Codes mehr zur Adresse');

echo "Mengengrenze\n";
for ($i = 0; $i < 5; $i++) {
    $web(['aktion' => 'code', 'email' => 'viel@example.org']);
}
$html = $web(['aktion' => 'code', 'email' => 'viel@example.org']);
check(str_contains($html, 'zu viele Codes'), 'hoechstens 5 Codes pro Adresse und Stunde');

finish();
