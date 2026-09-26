<?php
declare(strict_types=1);

/**
 * Homepage aus der RSS-Datei (lib/Homepage.php): Aufteilung einzeln/Archiv, Kurztexte, Kapitel,
 * nur der markierte Bereich wird ersetzt, kaputte RSS aendert nichts und meldet sich einmal per Mail.
 * Schreibt nur in HOMEPAGE_DIR der Testumgebung (D:/Suedsalat-Testumgebung/homepage).
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/HomepageTest.php
 */

require __DIR__ . '/TestHelpers.php';

use Suedsalat\Homepage;

const ADMIN_PASSWORD = 'geheim-test-123';

if (!str_contains(HOMEPAGE_DIR, 'Suedsalat-Testumgebung')) {
    fwrite(STDERR, "Abbruch: HOMEPAGE_DIR muss auf den Testordner zeigen.\n");
    exit(2);
}
resetTables($pdo, []);
$pdo->exec("INSERT INTO admins (id, name, email, password_hash, role) VALUES (2, 'Jenny', 'jenny@test.local', 'x', 'member')
            ON DUPLICATE KEY UPDATE role = 'member'");
$dir = HOMEPAGE_DIR;
@unlink(dirname(__DIR__) . '/cron/homepage-status.json');

/** RSS mit Folgen 1..$bis (aelteste zuerst wie bei Thorsten), optional mit Kurztext/Kapiteln. */
function feed(int $bis, array $kurz = [], array $kapitel = []): string
{
    $items = '';
    for ($n = 1; $n <= $bis; $n++) {
        $datum = gmdate(DATE_RSS, strtotime('2025-11-20 23:00:00 UTC') + ($n - 1) * 7 * 86400);
        $datei = sprintf('episodes/x_episode%03d.mp3', $n);
        $text = "Folge {$n} ist eine richtig schöne Folge mit vielen Themen & Geschichten. Danach kommt noch ein zweiter Satz.";
        if (isset($kapitel[$n])) {
            $text .= "\n" . implode("\n", $kapitel[$n]);
        }
        $items .= "    <item>\r\n      <title>Episode {$n}: Titel {$n}</title>\r\n      <description><![CDATA[\r\n        {$text}\r\n      ]]></description>\r\n"
            . (isset($kurz[$n]) ? "      <itunes:subtitle>" . htmlspecialchars($kurz[$n], ENT_XML1) . "</itunes:subtitle>\r\n" : '')
            . "      <pubDate>{$datum}</pubDate>\r\n      <enclosure url=\"https://www.xn--sdsalat-n2a.eu/{$datei}\" length=\"1\" type=\"audio/mpeg\" />\r\n    </item>\r\n";
    }
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n<rss version=\"2.0\" xmlns:itunes=\"http://www.itunes.com/dtds/podcast-1.0.dtd\">\r\n  <channel>\r\n    <title>Test</title>\r\n{$items}  </channel>\r\n</rss>\r\n";
}

$kopf = "<!DOCTYPE html>\r\n<html>\r\n<body>\r\n<header>Kopf mit App-Knopf</header>\r\n<main>\r\n";
$fuss = "\r\n</main>\r\n<footer>Handgepflegte Fusszeile</footer>\r\n</body>\r\n</html>\r\n";
$index = static fn (): string => (string) file_get_contents(HOMEPAGE_DIR . '/index.html');
$setze = static function (string $rss, bool $marken = true) use ($dir, $kopf, $fuss): void {
    file_put_contents($dir . '/podcast.rss', $rss);
    file_put_contents($dir . '/index.html', $kopf . ($marken ? Homepage::MARK_START . "\r\nALTE FOLGEN\r\n" . Homepage::MARK_END : 'Folgen von Hand') . $fuss);
};

echo "Archive\n";
$bereiche = static fn (int $n): string => implode(' ', array_map(static fn ($r) => $r['from'] . '-' . $r['to'], Homepage::archiveRanges($n)));
check($bereiche(7) === '', 'bis Folge 7: noch kein Archiv');
check($bereiche(8) === '1-7', 'ab Folge 8: Archiv 1-7 (wie bisher)');
check($bereiche(19) === '1-7', 'Folge 19: 8-19 noch offen');
check($bereiche(37) === '20-29 8-19 1-7', 'Folge 37: drei Archive wie heute auf der Seite');
check($bereiche(39) === '20-29 8-19 1-7', 'Folge 39: 30-39 noch einzeln');
check($bereiche(40) === '30-39 20-29 8-19 1-7', 'Folge 40: 30-39 wandert ins Archiv');

echo "Kurztext und Kapitel\n";
[$text, $kap] = Homepage::splitChapters("Wir reden über alles.\nKapitel:\n00:00 Begrüßung\n12:30 Urlaub\n1:02:03 – Verabschiedung");
check($text === 'Wir reden über alles.', 'Kapitelzeilen und „Kapitel:“ aus dem Text entfernt');
check(array_column($kap, 'start') === [0, 750, 3723] && $kap[2]['title'] === 'Verabschiedung' && $kap[2]['label'] === '1:02:03', 'Kapitel mit Sekunden und Titel');
[$text, $kap] = Homepage::splitChapters("Ab 12:30 Uhr geht es los.\nSonst nichts.");
check($kap === [] && str_contains($text, '12:30'), 'einzelne Uhrzeit ist kein Kapitel');
$ep = ['subtitle' => null, 'text' => 'Kurz. ' . str_repeat('Ein sehr langer Satz ohne Ende ', 20)];
check(str_ends_with(Homepage::shortText($ep), ' …') && mb_strlen(Homepage::shortText($ep)) <= 222, 'ohne Kurztext: lang wird am Wortende gekuerzt');
check(Homepage::shortText(['subtitle' => null, 'text' => 'Das ist der erste richtige Satz der Beschreibung hier. Und der zweite.']) === 'Das ist der erste richtige Satz der Beschreibung hier.', 'ohne Kurztext: erster Satz');
check(Homepage::shortText(['subtitle' => 'Mein Kurztext', 'text' => 'egal']) === 'Mein Kurztext', 'Kurztext aus der RSS hat Vorrang');

echo "Fehlerhafte RSS\n";
$fehler = static function (string $xml): string {
    try {
        Homepage::parseFeed($xml);
        return '';
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
};
check(str_contains($fehler(str_replace('</title>', '</titel>', feed(3))), 'Zeile'), 'kaputtes XML: Meldung mit Zeilennummer');
check(str_contains($fehler(str_replace('Episode 2:', 'Folge 2:', feed(3))), 'ohne „Episode'), 'Titel ohne Episodennummer');
check(str_contains($fehler(str_replace('Episode 3:', 'Episode 2:', feed(3))), 'zweimal'), 'doppelte Nummer');
check(str_contains($fehler(preg_replace('/<enclosure[^>]*>/', '', feed(2), 1)), 'Audiodatei'), 'fehlende Audiodatei');

echo "index.html erzeugen\n";
$setze(feed(37, [5 => 'Handgeschriebener Kurztext für Folge 5 & Co.'], [37 => ['00:00 Begrüßung', '10:00 Hauptteil', '40:00 Tschüss']]));
$r = Homepage::update();
$html = $index();
check($r['status'] === 'updated', 'Status: neu erzeugt');
check(str_starts_with($html, $kopf . Homepage::MARK_START) && str_ends_with($html, Homepage::MARK_END . $fuss), 'Kopf und Fusszeile unveraendert, nur der Bereich ersetzt');
check(!str_contains($html, 'ALTE FOLGEN'), 'alter Inhalt zwischen den Markierungen ist weg');
check(!preg_match('/(?<!\r)\n/', $html), 'Zeilenenden bleiben Windows-Stil (CRLF)');
for ($n = 1, $alleEinmal = true; $n <= 37; $n++) {
    $alleEinmal = $alleEinmal && substr_count($html, sprintf('id="episode%03d"', $n)) === 1;
}
check($alleEinmal, 'jede Folge genau einmal');
check(strpos($html, 'id="episode037"') < strpos($html, 'id="episode030"') && strpos($html, 'id="episode030"') < strpos($html, 'ARCHIV 3'), 'Folgen 37 bis 30 einzeln oben, neueste zuerst');
check(substr_count($html, 'class="archive-group"') === 3 && strpos($html, 'ARCHIV 3: Episoden 20 - 29') < strpos($html, 'ARCHIV 1: Episoden 1 - 7'), 'drei Archive, neuestes zuerst');
check(str_contains($html, 'Alle Folgen vom 20.11.2025 bis zum ' . gmdate('d.m.Y', strtotime('2025-11-20 23:00 UTC') + 6 * 7 * 86400)), 'Archiv-Zeitraum aus den Daten');
check(str_contains($html, '<p>Handgeschriebener Kurztext für Folge 5 &amp; Co.</p>'), 'Archiv nutzt den Kurztext (sauber maskiert)');
check(str_contains($html, '<p>Folge 6 ist eine richtig schöne Folge mit vielen Themen &amp; Geschichten.</p>'), 'ohne Kurztext: erster Satz im Archiv');
check(str_contains($html, 'Danach kommt noch ein zweiter Satz.</p>'), 'einzelne Folgen mit ganzem Text');
check(str_contains($html, 'src="episodes/x_episode037.mp3"') && str_contains($html, 'href="episodes/x_episode037.mp3" download'), 'Player und Download zeigen auf dieselbe Datei');
check(str_contains($html, '<li data-start="600"><button type="button" class="kapitel-sprung">10:00</button> Hauptteil</li>'), 'Kapitel unter dem Player von Folge 37');
check(!str_contains($html, '00:00 Begrüßung'), 'Kapitelzeilen stehen nicht im Beschreibungstext');
check(substr_count($html, '<script>') === 1 && substr_count($html, 'class="kapitel"') === 1, 'Kapitel-Skript genau einmal');
check(Homepage::update()['status'] === 'unchanged', 'zweiter Lauf: nichts zu tun');

$setze(feed(40));
Homepage::update();
$html = $index();
check(str_contains($html, 'ARCHIV 4: Episoden 30 - 39') && strpos($html, 'id="episode040"') < strpos($html, 'ARCHIV 4'), 'Folge 40 erscheint: 30-39 ins Archiv, 40 einzeln oben');
check(!str_contains($html, '<script>'), 'ohne Kapitel kein Kapitel-Skript');

echo "Sicherheit\n";
$setze(feed(37));
Homepage::update();
$gut = $index();
file_put_contents($dir . '/podcast.rss', str_replace('</item>', '</itm>', feed(38)));
$r = Homepage::update();
check($r['status'] === 'error' && $index() === $gut, 'kaputte RSS: Homepage bleibt unveraendert');
$setze(feed(37), false);
$vorher = $index();
check(Homepage::update()['status'] === 'off' && $index() === $vorher, 'ohne Markierungen: nichts anfassen');

echo "Cronjob und Fehlermail\n";
$setze(feed(37));
Homepage::run($pdo);
file_put_contents($dir . '/podcast.rss', str_replace('</item>', '</itm>', feed(37)));
Homepage::run($pdo);
[$betreff, $mail] = lastMail('owner@test.local');
check(str_contains($betreff, 'Fehler in der RSS-Datei') && str_contains($mail, 'Zeile'), 'Thorsten bekommt eine Mail mit der Fehlerzeile');
check(lastMail('jenny@test.local')[0] === '', 'Jenny bekommt keine');
$anzahl = count(glob(MAIL_CAPTURE_DIR . '/*.html') ?: []);
Homepage::run($pdo);
check(count(glob(MAIL_CAPTURE_DIR . '/*.html') ?: []) === $anzahl, 'derselbe Fehler meldet sich nur einmal');
file_put_contents($dir . '/podcast.rss', feed(37));
check(Homepage::run($pdo)['status'] !== 'error' && (Homepage::lastStatus()['status'] ?? '') !== 'error', 'behoben: Status wieder gruen');

echo "Admin-Seite\n";
$pdo->prepare('UPDATE admins SET password_hash = :h, totp_enabled = 0 WHERE id IN (1, 2)')
    ->execute([':h' => password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT)]);
[$s, , $loc] = page(adminSession(2), '/admin/homepage.php');
check($s === 302 && str_contains($loc, 'dashboard.php'), 'Jenny kommt nicht auf die Seite');
$owner = adminSession(1);
file_put_contents($dir . '/podcast.rss', feed(37, [5 => 'Kurztext fünf']));
[$s, , $loc] = page($owner, '/admin/homepage.php', ['action' => 'run']);
check($s === 302 && str_contains($loc, 'ergebnis=updated'), '„Jetzt aktualisieren“ erzeugt die Homepage');
[$s, $html] = page($owner, '/admin/homepage.php');
check($s === 200 && clean($html) && str_contains($html, 'Kurztext fünf') && str_contains($html, 'Archiv 1 (1–7)') && str_contains($html, 'einzeln oben'), 'Uebersicht zeigt Platz und Kurztext');
check(str_contains($html, 'automatisch gekürzt – eigener Kurztext fehlt'), 'fehlende Kurztexte sind gekennzeichnet');

echo "Echte RSS-Datei\n";
if (is_file('U:/Web/podcast.rss')) {
    $echt = Homepage::parseFeed((string) file_get_contents('U:/Web/podcast.rss'));
    check(count($echt) >= 37 && count(array_filter($echt, static fn ($e) => $e['subtitle'] !== null)) >= 29, 'U:/Web/podcast.rss liest sich, Kurztexte 1-29 vorhanden');
}

@unlink(dirname(__DIR__) . '/cron/homepage-status.json');
finish();
