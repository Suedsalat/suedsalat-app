<?php
declare(strict_types=1);

/**
 * Ablauftest Hoererkonto (App 2.0) gegen die lokale Testumgebung, siehe TESTUMGEBUNG.md.
 * Laeuft NUR mit SUEDSALAT_ENV_FILE (Testdatenbank + Mail-Fangordner), nie gegen live.
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/ListenerFlowTest.php
 *
 * Erwartet den PHP-Testserver auf APP_URL. Setzt die Konto-Tabellen am Anfang zurueck.
 */

if (getenv('SUEDSALAT_ENV_FILE') === false) {
    fwrite(STDERR, "Abbruch: nur mit SUEDSALAT_ENV_FILE (Testumgebung) ausfuehren.\n");
    exit(2);
}
require __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerContent;

$pdo = Database::connection();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'suedsalat_test') {
    fwrite(STDERR, "Abbruch: das ist nicht die Testdatenbank.\n");
    exit(2);
}

$failures = 0;
$checks = 0;
function check(bool $ok, string $what): void
{
    global $failures, $checks;
    $checks++;
    echo ($ok ? '  ok   ' : '  FEHL ') . $what . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

/** @return array{0:int,1:array<string,mixed>} */
function api(string $method, string $path, ?string $token = null, ?array $body = null, array $headers = []): array
{
    $ch = curl_init(APP_URL . $path);
    $h = ['Content-Type: application/json'];
    if ($token !== null) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge($h, $headers),
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($raw, true) ?? ['_raw' => $raw]];
}

function deviceToken(string $uuid): string
{
    [, $r] = api('POST', '/api/auth/device.php', null, ['device_uuid' => $uuid, 'platform' => 'android'], ['X-App-Secret: ' . APP_SECRET]);
    return (string) $r['access_token'];
}

/** Neueste abgefangene Mail an diese Adresse: [Betreff, HTML]. */
function lastMail(string $email): array
{
    $files = glob(MAIL_CAPTURE_DIR . '/*.html') ?: [];
    rsort($files);
    foreach ($files as $f) {
        $html = (string) file_get_contents($f);
        if (preg_match('/^<!-- An: .*<(.+?)> \| Betreff: (.*) -->/', $html, $m) && strcasecmp($m[1], $email) === 0) {
            return [$m[2], $html];
        }
    }
    return ['', ''];
}

function codeFrom(string $html): string
{
    return preg_match('/letter-spacing:4px[^>]*>(\d{6})</', $html, $m) ? $m[1] : '';
}

// --- Ausgangslage ---------------------------------------------------------------------------
foreach (glob(MAIL_CAPTURE_DIR . '/*.html') ?: [] as $f) {
    unlink($f);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['listener_login_codes', 'listeners', 'devices', 'refresh_tokens', 'rate_limits', 'tip_reviews', 'movie_tips', 'photos', 'feedback_messages'] as $t) {
    $pdo->exec("TRUNCATE TABLE {$t}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("INSERT IGNORE INTO admins (id, name, email, password_hash, role) VALUES (1, 'Thorsten', 'owner@test.local', 'x', 'owner')");

$tA = deviceToken('geraet-a');
$tB = deviceToken('geraet-b');
$mail = 'Angela@Example.org';
$mailNorm = 'angela@example.org';

echo "Registrierung\n";
[$s, $r] = api('GET', '/api/listener/me.php', $tA);
check($s === 200 && $r['listener'] === null, 'Gast: me liefert kein Konto');
[$s] = api('GET', '/api/listener/me.php');
check($s === 401, 'ohne Geraeteschluessel: 401');
[$s, $r] = api('POST', '/api/listener/register.php', $tA, ['first_name' => 'Angela', 'last_name' => 'Schmitt', 'email' => $mail, 'nickname' => 'Süd-Salat', 'accept_terms' => true]);
check($s === 422 && str_contains($r['error'], 'nicht möglich'), 'gesperrter Spitzname "Süd-Salat" abgelehnt');
[$s, $r] = api('POST', '/api/listener/register.php', $tA, ['first_name' => 'Angela', 'last_name' => 'Schmitt', 'email' => $mail, 'nickname' => 'Angela']);
check($s === 422 && str_contains($r['error'], 'Nutzungsbedingungen'), 'ohne Nutzungsbedingungen abgelehnt');
[$s, $r] = api('POST', '/api/listener/register.php', $tA, ['first_name' => 'Angela', 'last_name' => 'Schmitt', 'email' => $mail, 'nickname' => 'Angela', 'accept_terms' => true]);
check($s === 200 && $r['ok'] === true, 'Registrierung angefordert');
check($pdo->query('SELECT COUNT(*) FROM listeners')->fetchColumn() == 0, 'Konto entsteht erst nach dem Code');
[$subject, $html] = lastMail($mailNorm);
$code = codeFrom($html);
check(strlen($code) === 6 && str_contains($subject, 'Code'), 'Code-Mail mit 6-stelligem Code');
[$s, $r] = api('POST', '/api/listener/verify.php', $tA, ['email' => $mail, 'code' => '000000']);
check($s === 422 && str_contains($r['error'], 'stimmt nicht'), 'falscher Code abgelehnt');
[$s, $r] = api('POST', '/api/listener/verify.php', $tA, ['email' => $mail, 'code' => $code]);
check($s === 200 && ($r['created'] ?? false) && $r['listener']['nickname'] === 'Angela', 'richtiger Code legt Konto an');
[$s, $r] = api('POST', '/api/listener/verify.php', $tA, ['email' => $mail, 'code' => $code]);
check($s === 422, 'derselbe Code funktioniert kein zweites Mal');
[, $r] = api('GET', '/api/listener/me.php', $tA);
check(($r['listener']['nickname'] ?? null) === 'Angela', 'Geraet A ist angemeldet');
[$s] = api('POST', '/api/listener/register.php', $tA, ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'ANGELA@example.org', 'nickname' => 'Anders', 'accept_terms' => true]);
check($s === 409, 'gleiche Adresse (andere Schreibweise) kann nicht nochmal registrieren');
[$s, $r] = api('POST', '/api/listener/register.php', $tB, ['first_name' => 'Andere', 'last_name' => 'Person', 'email' => 'andere@example.org', 'nickname' => 'ANGELA', 'accept_terms' => true]);
check($s === 422 && str_contains($r['error'], 'vergeben'), 'Spitzname in anderer Schreibweise ist vergeben');

echo "Profil\n";
[$s, $r] = api('POST', '/api/listener/update.php', $tA, ['nickname' => 'Thorsten1']);
check($s === 422, 'Umbenennen in "Thorsten1" abgelehnt');
[$s, $r] = api('POST', '/api/listener/update.php', $tA, ['nickname' => 'Angie']);
check($s === 200 && $r['listener']['nickname'] === 'Angie', 'Umbenennen in "Angie"');

echo "Zweites Geraet, Abmelden\n";
[$s] = api('POST', '/api/listener/login.php', $tB, ['email' => 'niemand@example.org']);
check($s === 200, 'Anmeldung mit unbekannter Adresse: gleiche Antwort wie mit bekannter');
[$s] = api('POST', '/api/listener/login.php', $tB, ['email' => $mail]);
[, $html] = lastMail($mailNorm);
[$s, $r] = api('POST', '/api/listener/verify.php', $tB, ['email' => $mail, 'code' => codeFrom($html)]);
check($s === 200 && ($r['restored'] ?? true) === false, 'Anmeldung auf Geraet B');
api('POST', '/api/listener/logout.php', $tA);
[, $rA] = api('GET', '/api/listener/me.php', $tA);
[, $rB] = api('GET', '/api/listener/me.php', $tB);
check($rA['listener'] === null && ($rB['listener']['nickname'] ?? null) === 'Angie', 'Abmelden gilt nur fuer Geraet A');

echo "Beitraege und Rueckkehrfrist\n";
$lid = (int) $pdo->query("SELECT id FROM listeners WHERE email = '{$mailNorm}'")->fetchColumn();
$pdo->exec("INSERT INTO movie_tips (id, title, created_by, listener_id, image_kind, image_path, submitted_by_name)
            VALUES (1, 'Top Gun Maverick', 1, {$lid}, 'own', 'http://x/uploads/movie-tips/a.jpg', 'Angie'),
                   (2, 'Plakat-Film', 1, {$lid}, 'poster', 'http://x/uploads/movie-tips/b.jpg', 'Angie')");
$pdo->exec("INSERT INTO tip_reviews (tip_type, tip_id, rating, reviewer_name, listener_id, approved)
            VALUES ('movie_tip', 1, 5, 'Angie', {$lid}, 1)");
$name = fn () => $pdo->query('SELECT ' . ListenerContent::displayNameSql('t', 'submitted_by_name') . ' FROM movie_tips t '
    . ListenerContent::joinSql('t') . ' WHERE t.id = 1')->fetchColumn();
check($name() === 'Angie', 'Tipp zeigt live den Spitznamen');

[$s, $r] = api('POST', '/api/listener/delete.php', $tB, ['mode' => 'grace', 'delete_texts' => true, 'delete_photos' => true]);
check($s === 200, 'Loeschen mit Rueckkehrfrist');
[, $rB] = api('GET', '/api/listener/me.php', $tB);
check($rB['listener'] === null, 'danach auf allen Geraeten abgemeldet');
check($name() === 'Ehemaliges Mitglied', 'Tipp zeigt waehrend der Frist "Ehemaliges Mitglied"');
check($pdo->query('SELECT hidden_reason FROM tip_reviews WHERE listener_id = ' . $lid)->fetchColumn() === 'deletion', 'Rezension ausgeblendet (Texte loeschen)');
check($pdo->query('SELECT image_path FROM movie_tips WHERE id = 1')->fetchColumn() === null, 'eigenes Bild vom Tipp abgenommen');
check($pdo->query('SELECT image_path FROM movie_tips WHERE id = 2')->fetchColumn() !== null, 'Plakat bleibt am Tipp');
[$subject, $html] = lastMail($mailNorm);
check(str_contains($html, 'stillgelegt') && str_contains($html, date('d.m.Y', strtotime('+30 days'))), 'Bestaetigungsmail nennt das Datum der endgueltigen Loeschung');
[$subject, $html] = lastMail('owner@test.local');
check(str_contains($html, 'Top Gun Maverick') && !str_contains($html, 'Plakat-Film'), 'Thorsten bekommt Mail "Tipp ohne Bild" (nur fuer das eigene Foto)');

api('POST', '/api/listener/login.php', $tB, ['email' => $mail]);
[, $html] = lastMail($mailNorm);
[$s, $r] = api('POST', '/api/listener/verify.php', $tB, ['email' => $mail, 'code' => codeFrom($html)]);
check($s === 200 && ($r['restored'] ?? false) === true && $r['listener']['nickname'] === 'Angie', 'Anmeldung in der Frist stellt das Konto wieder her');
[$subject] = lastMail($mailNorm);
check(str_contains($subject, 'Willkommen zurück'), '"Willkommen zurueck"-Mail');
check($name() === 'Angie', 'Tipp zeigt wieder den Spitznamen');
check($pdo->query('SELECT hidden_at FROM tip_reviews WHERE listener_id = ' . $lid)->fetchColumn() === null, 'Rezension wieder sichtbar');
check($pdo->query('SELECT image_path FROM movie_tips WHERE id = 1')->fetchColumn() === 'http://x/uploads/movie-tips/a.jpg', 'eigenes Bild wieder am Tipp');

echo "Sofort endgueltig\n";
[$s, $r] = api('POST', '/api/listener/delete.php', $tB, ['mode' => 'now', 'delete_texts' => false, 'delete_photos' => false]);
check($s === 200 && ($r['code_sent'] ?? false), 'sofortige Loeschung fordert erst einen Code an');
check($pdo->query('SELECT COUNT(*) FROM listeners')->fetchColumn() == 1, 'ohne Code wird nichts geloescht');
[$subject, $html] = lastMail($mailNorm);
check(str_contains($subject, 'Kontolöschung bestätigen'), 'Code-Mail zur Bestaetigung');
[$s] = api('POST', '/api/listener/delete-confirm.php', $tB, ['code' => '111111']);
check($s === 422, 'falscher Code loescht nichts');
[$s, $r] = api('POST', '/api/listener/delete-confirm.php', $tB, ['code' => codeFrom($html)]);
check($s === 200 && ($r['deleted'] ?? false), 'richtiger Code loescht endgueltig');
check($pdo->query('SELECT COUNT(*) FROM listeners')->fetchColumn() == 0, 'Konto ist weg');
check($name() === 'Ehemaliges Mitglied', 'Tipp bleibt als "Ehemaliges Mitglied"');
check($pdo->query("SELECT reviewer_name FROM tip_reviews WHERE tip_id = 1")->fetchColumn() === 'Ehemaliges Mitglied', 'Rezension bleibt ohne Namen (Texte nicht geloescht)');
[$subject, $html] = lastMail($mailNorm);
check(str_contains($html, 'sofort und endgültig'), 'Bestaetigungsmail der endgueltigen Loeschung');
[$s, $r] = api('POST', '/api/listener/register.php', $tA, ['first_name' => 'Angela', 'last_name' => 'Schmitt', 'email' => $mail, 'nickname' => 'Angie', 'accept_terms' => true]);
check($s === 200, 'Neuregistrierung mit gleicher Adresse und altem Spitznamen moeglich');

echo "Cron-Pflege\n";
[, $html] = lastMail($mailNorm);
api('POST', '/api/listener/verify.php', $tA, ['email' => $mail, 'code' => codeFrom($html)]);
$lid = (int) $pdo->query("SELECT id FROM listeners WHERE email = '{$mailNorm}'")->fetchColumn();
Listener::requestDeletion($pdo, $lid, false, false);
$pdo->exec("UPDATE listeners SET deletion_final_at = DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE id = {$lid}");
$summary = Listener::runMaintenance($pdo);
[$subject] = lastMail($mailNorm);
check(str_contains($summary, '1 Erinnerung') && str_contains($subject, 'bald gelöscht'), 'Erinnerung drei Tage vor Fristende');
check(str_contains(Listener::runMaintenance($pdo), '0 Erinnerung'), 'Erinnerung kommt nur einmal');
$pdo->exec("UPDATE listeners SET deletion_final_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = {$lid}");
check(str_contains(Listener::runMaintenance($pdo), '1 endgueltig'), 'nach Fristende endgueltig geloescht');
check($pdo->query('SELECT COUNT(*) FROM listeners')->fetchColumn() == 0, 'Konto ist weg');

echo PHP_EOL . ($failures === 0 ? "ALLE {$checks} PRUEFUNGEN BESTANDEN" : "{$failures} VON {$checks} PRUEFUNGEN FEHLGESCHLAGEN") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
