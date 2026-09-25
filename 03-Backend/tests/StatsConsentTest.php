<?php
declare(strict_types=1);

/**
 * Statistik nur mit Einwilligung (App 2.0, § 25 TDDDG): ohne Zustimmung wird nichts gezaehlt,
 * Widerruf wirkt sofort, jede Entscheidung ist mit Textfassung nachweisbar.
 * Nur gegen die Testumgebung:
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/StatsConsentTest.php
 */

require __DIR__ . '/TestHelpers.php';

use Suedsalat\StatsConsent;

resetTables($pdo, ['listener_login_codes', 'listeners', 'devices', 'refresh_tokens', 'rate_limits', 'statistics_consents',
    'screen_views', 'episode_play_counts', 'episode_play_platform', 'episode_unique_devices', 'episode_play_milestones']);
$pdo->exec("INSERT IGNORE INTO episodes_cache (guid, title, audio_url, pub_date) VALUES ('test-folge-1', 'Episode 1: Test', 'https://x.invalid/1.mp3', NOW())");

$V = StatsConsent::TEXT_VERSION;
$sum = fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$view = fn (string $token) => api('POST', '/api/track-view.php', $token, null, [], ['screen' => 'episodes']);
$play = fn (string $token) => api('POST', '/api/track-episode-play.php', $token, null, [], ['episode_guid' => 'test-folge-1']);
$milestone = fn (string $token) => api('POST', '/api/track-episode-milestone.php', $token, null, [], ['episode_guid' => 'test-folge-1', 'tier' => '5min']);
$consent = fn (string $token, array $body) => api('POST', '/api/statistics-consent.php', $token, $body);

$gast = deviceToken('stat-gast');

echo "Ohne Entscheidung\n";
[$s, $r] = api('GET', '/api/statistics-consent.php', $gast);
check($s === 200 && $r['consent'] === null && $r['needs_decision'] === true && $r['current_version'] === $V, 'neues Geraet: App soll fragen');
[$s, $r] = $view($gast);
check($s === 200 && ($r['counted'] ?? null) === false, 'Bereichsaufruf wird nicht gezaehlt');
$play($gast);
$milestone($gast);
check($sum('SELECT COALESCE(SUM(count),0) FROM screen_views') + $sum('SELECT COALESCE(SUM(count),0) FROM episode_play_counts')
    + $sum('SELECT COUNT(*) FROM episode_play_platform') + $sum('SELECT COUNT(*) FROM episode_unique_devices')
    + $sum('SELECT COUNT(*) FROM episode_play_milestones') === 0, 'nichts in irgendeiner Statistiktabelle');
[$s] = api('POST', '/api/track-view.php', null, null, [], ['screen' => 'episodes']);
check($sum('SELECT COALESCE(SUM(count),0) FROM screen_views') === 0, 'ohne Token (alte App) wird nichts gezaehlt');

echo "Eingaben\n";
[$s] = api('GET', '/api/statistics-consent.php');
check($s === 401, 'ohne Token: 401');
[$s] = $consent($gast, ['decision' => 'vielleicht', 'text_version' => $V]);
check($s === 422, 'unbekannte Entscheidung abgelehnt');
[$s] = $consent($gast, ['decision' => 'granted']);
check($s === 422, 'ohne Textfassung abgelehnt');
check($sum('SELECT COUNT(*) FROM statistics_consents') === 0, 'ungueltige Eingaben hinterlassen keinen Nachweis');

echo "Zustimmung\n";
[$s, $r] = $consent($gast, ['decision' => 'granted', 'text_version' => $V]);
check($s === 200 && $r['consent'] === 'granted' && $r['needs_decision'] === false, 'Zustimmung gespeichert');
$log = $pdo->query('SELECT * FROM statistics_consents')->fetch();
check($log['decision'] === 'granted' && $log['text_version'] === $V && $log['platform'] === 'android' && $log['listener_id'] === null,
    'Nachweis mit Textfassung und Plattform, Gast ohne Konto');
[, $r] = $view($gast);
check(($r['counted'] ?? true) === true && $sum("SELECT SUM(count) FROM screen_views WHERE screen = 'episodes'") === 1, 'Bereichsaufruf gezaehlt');
$play($gast);
$play($gast);
$milestone($gast);
check($sum('SELECT SUM(count) FROM episode_play_counts') === 2, 'Wiedergaben gezaehlt');
check($sum("SELECT SUM(count) FROM episode_play_platform WHERE platform = 'android'") === 2, 'Plattform gezaehlt');
check($sum('SELECT COUNT(*) FROM episode_unique_devices') === 1, 'eindeutige Hoerer: zweimal gehoert = einmal gezaehlt');
check(strlen((string) $pdo->query('SELECT device_hash FROM episode_unique_devices')->fetchColumn()) === 64, 'nur Einweg-Hash gespeichert');
check($sum('SELECT SUM(count) FROM episode_play_milestones') === 1, 'Hoerdauer-Stufe gezaehlt');

echo "Widerruf\n";
[, $r] = $consent($gast, ['decision' => 'denied', 'text_version' => $V]);
check($r['consent'] === 'denied' && $r['needs_decision'] === false, 'Widerruf gespeichert, App fragt nicht erneut');
$view($gast);
$play($gast);
check($sum('SELECT SUM(count) FROM screen_views') === 1 && $sum('SELECT SUM(count) FROM episode_play_counts') === 2, 'nach Widerruf wird sofort nichts mehr gezaehlt');
check($sum('SELECT COUNT(*) FROM statistics_consents') === 2, 'beide Entscheidungen im Nachweis');

echo "Neue Textfassung\n";
$alt = deviceToken('stat-alt');
[, $r] = $consent($alt, ['decision' => 'granted', 'text_version' => '2025-01']);
check($r['needs_decision'] === true, 'Zustimmung zu alter Fassung: App fragt erneut');

echo "Registrierte\n";
$hoerer = deviceToken('stat-hoerer');
registerListener($hoerer, 'statistik@example.org', 'Zaehlbar');
$consent($hoerer, ['decision' => 'granted', 'text_version' => $V]);
check($pdo->query("SELECT listener_id FROM statistics_consents ORDER BY id DESC LIMIT 1")->fetchColumn() !== null, 'Nachweis nennt das Konto');
$zweitgeraet = deviceToken('stat-hoerer-2');
api('POST', '/api/listener/login.php', $zweitgeraet, ['email' => 'statistik@example.org']);
api('POST', '/api/listener/verify.php', $zweitgeraet, ['email' => 'statistik@example.org', 'code' => codeFrom(lastMail('statistik@example.org')[1])]);
[, $r] = api('GET', '/api/statistics-consent.php', $zweitgeraet);
check($r['consent'] === null && $r['needs_decision'] === true, 'Einwilligung gilt pro Installation, nicht pro Konto');

echo "Admin\n";
deviceToken('stat-nie-gefragt');
$summary = StatsConsent::summary($pdo);
check($summary['granted'] === 2 && $summary['denied'] === 1 && $summary['not_asked'] === 2, 'Zahlen: zugestimmt / abgelehnt / nicht gefragt');
$session = adminSession(1);
[$s, $html] = page($session, '/admin/statistics.php');
check($s === 200 && clean($html) && str_contains($html, 'Einwilligung in die Statistik') && preg_match('/<strong>2<\/strong> zugestimmt/', $html) === 1,
    'Statistikseite zeigt die Zahlen');
check(!str_contains($html, 'statistik@example.org') && !str_contains($html, 'Zaehlbar'), 'keine Namen oder Adressen');
@unlink(session_save_path() . '/sess_' . $session);

finish();
