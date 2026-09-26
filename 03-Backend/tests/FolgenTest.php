<?php
declare(strict_types=1);

/**
 * Folgen im Admin-Bereich (admin/episodes.php, lib/Feed.php, lib/Mp3Info.php): neue Folge aus einer
 * per FTP hochgeladenen MP3, Kapitel auch fuer alte Folgen, Kennungen bleiben, fruehere Fassungen,
 * und der Cronjob zieht Aenderungen bestehender Folgen in die App nach (ohne Push).
 * Arbeitet mit einer Kopie der echten U:/Web/podcast.rss im Testordner HOMEPAGE_DIR.
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/FolgenTest.php
 */

require __DIR__ . '/TestHelpers.php';

use Suedsalat\Feed;
use Suedsalat\Homepage;
use Suedsalat\Mp3Info;

const ADMIN_PASSWORD = 'geheim-test-123';

if (!str_contains(HOMEPAGE_DIR, 'Suedsalat-Testumgebung') || !is_file('U:/Web/podcast.rss')) {
    fwrite(STDERR, "Abbruch: HOMEPAGE_DIR muss auf den Testordner zeigen, und U:/Web/podcast.rss muss erreichbar sein.\n");
    exit(2);
}
resetTables($pdo, ['rss_versions', 'episodes_cache', 'episode_push_sent']);
$pdo->exec("INSERT INTO admins (id, name, email, password_hash, role) VALUES (2, 'Jenny', 'jenny@test.local', 'x', 'member')
            ON DUPLICATE KEY UPDATE role = 'member'");
$pdo->prepare('UPDATE admins SET password_hash = :h, totp_enabled = 0 WHERE id IN (1, 2)')
    ->execute([':h' => password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT)]);
$owner = adminSession(1);

$dir = HOMEPAGE_DIR;
@mkdir($dir . '/episodes');
array_map('unlink', glob($dir . '/episodes/*') ?: []);
copy('U:/Web/podcast.rss', $dir . '/podcast.rss');
file_put_contents($dir . '/index.html', "<html>\r\n<main>\r\n" . Homepage::MARK_START . "\r\n" . Homepage::MARK_END . "\r\n</main>\r\n</html>\r\n");
Homepage::update();
$original = (string) file_get_contents($dir . '/podcast.rss');
$rss = static fn (): string => (string) file_get_contents(HOMEPAGE_DIR . '/podcast.rss');
$item = static function (int $n) use ($rss): array {
    foreach (Feed::parse($rss())['items'] as $it) {
        if ($it['number'] === $n) {
            return $it;
        }
    }
    return [];
};

// MP3 mit konstanter Bitrate (MPEG1 Layer III, 128 kbit/s, 44,1 kHz): 120 Sekunden = 1.920.000 Byte.
$frame = "\xFF\xFB\x90\x64" . str_repeat("\x00", 413);
$mp3 = str_repeat($frame, intdiv(1920000, 417)) . str_repeat("\x00", 1920000 % 417);
file_put_contents($dir . '/episodes/26-10-01_Suedsalat_episode038.mp3', $mp3);
file_put_contents($dir . '/episodes/kaputt.mp3', 'kein Ton');

echo "MP3-Laenge\n";
check(Mp3Info::durationSeconds($dir . '/episodes/26-10-01_Suedsalat_episode038.mp3') === 120, 'konstante Bitrate: 120 Sekunden');
check(Mp3Info::durationSeconds($dir . '/episodes/kaputt.mp3') === null, 'keine MP3: null');
check(Mp3Info::format(3723) === '1:02:03' && Mp3Info::format(125) === '02:05', 'Schreibweise wie im Feed');

echo "Seite\n";
[$s, , $loc] = page(adminSession(2), '/admin/episodes.php');
check($s === 302 && str_contains($loc, 'dashboard.php'), 'Jenny kommt nicht auf die Seite');
[$s, $html] = page($owner, '/admin/episodes.php');
check($s === 200 && clean($html) && substr_count($html, '/admin/episodes.php?edit=') === 37, 'Liste mit allen 37 Folgen');
[, $html] = page($owner, '/admin/episodes.php?neu=1');
check(str_contains($html, 'value="38"') && str_contains($html, '26-10-01_Suedsalat_episode038.mp3') && !str_contains($html, '>26-09-24_Suedsalat_episode037.mp3<'), 'Neue Folge: Nummer 38 vorbelegt, nur unbenutzte MP3s zur Auswahl');

$neu = ['action' => 'save', 'original' => 'new', 'number' => '38', 'name' => 'Herbst & Blätter', 'text' => "Erster Absatz der neuen Folge.\n\nZweiter Absatz.",
    'subtitle' => 'Kurz: Herbst, Blätter und mehr.', 'date' => '2026-10-01T18:30', 'file' => '26-10-01_Suedsalat_episode038.mp3',
    'chapters' => "00:00 Begrüßung\n0:45 Hauptteil\n1:30 Tschüss"];

echo "Pruefungen vor dem Speichern\n";
$fehler = static function (array $post) use ($owner, $rss, $original): string {
    [, $html] = page($owner, '/admin/episodes.php', $post);
    return $rss() === $original ? $html : 'DATEI GEAENDERT';
};
check(str_contains($fehler(['file' => 'gibtsnicht.mp3'] + $neu), 'Bitte die MP3-Datei auswählen'), 'fehlende MP3 abgelehnt');
check(str_contains($fehler(['file' => 'kaputt.mp3'] + $neu), 'keine lesbare MP3'), 'kaputte MP3 abgelehnt');
check(str_contains($fehler(['number' => '37'] + $neu), 'Episode 37 gibt es schon'), 'doppelte Nummer abgelehnt');
check(str_contains($fehler(['chapters' => "00:10 Start\n0:45 Mitte"] + $neu), 'muss bei 00:00:00 beginnen'), 'Kapitel ohne 00:00 abgelehnt');
check(str_contains($fehler(['chapters' => "00:00 Start\n1:00 B\n0:50 C"] + $neu), 'größer werden'), 'Kapitel nicht aufsteigend abgelehnt');
check(str_contains($fehler(['chapters' => "00:00 Start\n2:30 Nach dem Ende"] + $neu), 'hinter dem Ende der Folge'), 'Kapitel hinter dem Ende abgelehnt');
check(str_contains($fehler(['chapters' => "00:00 Start\nirgendwas"] + $neu), 'Zeile 2'), 'unlesbare Kapitelzeile mit Zeilennummer');
check(str_contains($fehler(['chapters' => '00:00 Nur eins'] + $neu), 'mindestens zwei Kapitel'), 'ein einzelnes Kapitel abgelehnt');
[, $html] = page($owner, '/admin/episodes.php', ['name' => ''] + $neu);
check(str_contains($html, 'Herbst, Blätter und mehr') && str_contains($html, 'Bitte einen Titel angeben'), 'bei Fehlern bleiben die Eingaben erhalten');

echo "Neue Folge\n";
[$s, , $loc] = page($owner, '/admin/episodes.php', $neu);
$f = $item(38);
$url = 'https://www.xn--sdsalat-n2a.eu/episodes/26-10-01_Suedsalat_episode038.mp3';
check($s === 302 && str_contains($loc, 'angelegt=38') && str_contains($loc, 'homepage=updated'), 'angelegt, Homepage sofort aktualisiert');
check($f['name'] === 'Herbst & Blätter' && $f['url'] === $url && $f['guid'] === $url && $f['link'] === $url, 'Titel, Datei, Kennung und Link');
check($f['duration'] === '02:00' && $f['length'] === '1920000', 'Laenge und Dateigroesse vom Server ermittelt');
check($f['pubDate'] === 'Thu, 01 Oct 2026 16:30:00 GMT', 'Datum als deutsche Zeit eingegeben, in GMT gespeichert');
check($f['subtitle'] === 'Kurz: Herbst, Blätter und mehr.' && $f['text'] === "Erster Absatz der neuen Folge.\n\nZweiter Absatz.", 'Kurztext und Absaetze bleiben');
check(array_column($f['chapters'], 'start') === [0, 45, 90], 'Kapitel gespeichert');
check(str_contains($rss(), "Kapitel:\r\n        00:00 Begrüßung\r\n        00:45 Hauptteil"), 'Kapitel stehen fuer Spotify als Zeilen in der Beschreibung');
check(str_contains($rss(), '<title>Episode 38: Herbst &amp; Blätter</title>'), 'Sonderzeichen sauber maskiert');
check(str_contains((string) file_get_contents($dir . '/index.html'), 'id="episode038"'), 'Homepage zeigt die neue Folge');
check((int) $pdo->query('SELECT COUNT(*) FROM rss_versions')->fetchColumn() === 1
    && $pdo->query('SELECT content FROM rss_versions')->fetchColumn() === $original, 'bisherige Datei als fruehere Fassung aufgehoben');
$alle = Feed::parse($rss())['items'];
check(count($alle) === 38 && array_column($alle, 'number') === range(1, 38), 'alle anderen Folgen noch da, aufsteigend sortiert');

echo "Kapitel fuer alte Folgen\n";
$alt20 = $item(20);
page($owner, '/admin/episodes.php', ['action' => 'save', 'original' => '20', 'number' => '20', 'name' => $alt20['name'], 'text' => $alt20['text'],
    'subtitle' => $alt20['subtitle'], 'date' => date_create_immutable($alt20['pubDate'])->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i'),
    'file' => basename($alt20['url']), 'chapters' => "00:00 Begrüßung\n12:00 Ostern\n40:00 Ausblick"]);
$neu20 = $item(20);
check(count($neu20['chapters']) === 3 && $neu20['guid'] === $alt20['guid'] && $neu20['url'] === $alt20['url'] && $neu20['duration'] === $alt20['duration'], 'Folge 20: Kapitel da, Kennung, Datei und Laenge unveraendert');
check($neu20['pubDate'] === $alt20['pubDate'] && $neu20['text'] === $alt20['text'] && $neu20['subtitle'] === $alt20['subtitle'], 'Datum, Text und Kurztext unveraendert');
check(str_contains((string) file_get_contents($dir . '/index.html'), '<li data-start="720"><button type="button" class="kapitel-sprung">12:00</button> Ostern</li>'), 'Homepage: Kapitel auch im Archiv unter dem Player');
[, $html] = page($owner, '/admin/episodes.php?edit=20');
check(str_contains($html, "00:00 Begrüßung\n12:00 Ostern\n40:00 Ausblick"), 'beim Bearbeiten stehen die Kapitel wieder im Feld');

$alt18 = $item(18);
page($owner, '/admin/episodes.php', ['action' => 'save', 'original' => '18', 'number' => '18', 'name' => $alt18['name'] . ' (neu)', 'text' => $alt18['text'],
    'subtitle' => $alt18['subtitle'], 'date' => date_create_immutable($alt18['pubDate'])->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i'),
    'file' => basename($alt18['url']), 'chapters' => '']);
check($item(18)['guid'] === $alt18['guid'] && $alt18['guid'] !== $alt18['url'], 'Folge 18 behaelt ihre abweichende Kennung');

// Folge 38 bekommt nachtraeglich eine andere MP3 (z. B. neu geschnitten): Kennung bleibt.
copy($dir . '/episodes/26-10-01_Suedsalat_episode038.mp3', $dir . '/episodes/26-10-02_Suedsalat_episode038_neu.mp3');
page($owner, '/admin/episodes.php', ['action' => 'save', 'original' => '38', 'file' => '26-10-02_Suedsalat_episode038_neu.mp3', 'chapters' => ''] + $neu);
$f = $item(38);
check(str_ends_with($f['url'], '038_neu.mp3') && $f['guid'] === $url, 'andere MP3 fuer bestehende Folge: Datei neu, Kennung bleibt');

echo "Fruehere Fassung\n";
$vorher = $rss();
$version = (int) $pdo->query("SELECT id FROM rss_versions WHERE note = 'Neue Folge 38'")->fetchColumn();
page($owner, '/admin/episodes.php', ['restore_id' => (string) $version, 'confirm_password' => 'falsch']);
check($rss() === $vorher, 'falsches Passwort: nichts wiederhergestellt');
page($owner, '/admin/episodes.php', ['restore_id' => (string) $version, 'confirm_password' => ADMIN_PASSWORD]);
check($rss() === $original, 'wiederhergestellt: Datei wie vor Folge 38');
check(!str_contains((string) file_get_contents($dir . '/index.html'), 'id="episode038"'), 'Homepage zieht mit');
check($pdo->query('SELECT content FROM rss_versions ORDER BY id DESC LIMIT 1')->fetchColumn() === $vorher, 'der Stand vor dem Wiederherstellen ist ebenfalls aufgehoben');

echo "App uebernimmt Aenderungen bestehender Folgen\n";
foreach (Feed::parse($rss())['items'] as $it) {
    $pdo->prepare('INSERT INTO episodes_cache (guid, title, description, audio_url, pub_date) VALUES (:g, :t, :d, :a, NOW())')
        ->execute([':g' => $it['guid'], ':t' => 'ALTER TITEL', ':d' => 'alt', ':a' => $it['url']]);
}
$alt20 = $item(20);
$feed = Feed::parse($rss());
foreach ($feed['items'] as &$it) {
    if ($it['number'] === 20) {
        $it['chapters'] = [['start' => 0, 'title' => 'Anfang'], ['start' => 600, 'title' => 'Mitte']];
    }
}
unset($it);
file_put_contents($dir . '/podcast.rss', Feed::render($feed));
$ausgabe = (string) shell_exec('php ' . escapeshellarg(dirname(__DIR__) . '/cron/sync-episodes.php') . ' 2>&1');
$zeile = $pdo->prepare('SELECT title, description FROM episodes_cache WHERE guid = :g');
$zeile->execute([':g' => $alt20['guid']]);
$cache = $zeile->fetch();
check(str_contains($ausgabe, 'Neue Folgen: 0, aktualisiert: 37'), 'Cronjob: 37 Folgen aktualisiert, keine neue');
check($cache['title'] === 'Episode 20: ' . $alt20['name'] && str_contains((string) $cache['description'], '10:00 Mitte'), 'Titel und Kapitel kommen in der App an');
check((int) $pdo->query('SELECT COUNT(*) FROM episode_push_sent')->fetchColumn() === 0, 'keine Push-Nachricht fuer geaenderte Folgen');

array_map('unlink', glob($dir . '/episodes/*') ?: []);
@unlink(dirname(__DIR__) . '/cron/homepage-status.json');
finish();
