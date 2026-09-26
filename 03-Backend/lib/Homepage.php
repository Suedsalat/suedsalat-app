<?php
declare(strict_types=1);

namespace Suedsalat;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Homepage aus der RSS-Datei (podcast.rss) erzeugen.
 *
 * In index.html wird NUR der Bereich zwischen den beiden Markierungen ersetzt - alles andere
 * (Kopf, App-Knopf, Newsletter, Fusszeile, Cookie-Banner) bleibt, wie Thorsten es von Hand pflegt.
 *
 * Aufteilung: Die Folgen des laufenden Zehners stehen einzeln oben (neueste zuerst). Ist ein Zehner
 * voll und die naechste Folge erschienen, wandert er in ein Archiv mit gekuerzten Texten. Die ersten
 * beiden Archive (1-7 aus 2025, 8-19) bleiben wie gehabt, ab 20-29 gilt die Zehner-Regel.
 *
 * Kurztext fuers Archiv: <itunes:subtitle> der Folge, sonst automatisch der erste Satz.
 * Kapitel: Zeilen der Form "00:00 Begruessung" in der Beschreibung (so liest sie auch Spotify) -
 * sie erscheinen unter dem Player als anklickbare Liste und nicht im Beschreibungstext.
 */
final class Homepage
{
    public const MARK_START = '<!-- FOLGEN-ANFANG: wird automatisch aus podcast.rss erzeugt - hier nichts von Hand aendern -->';
    public const MARK_END = '<!-- FOLGEN-ENDE -->';

    /** Erste Folge jedes Archivs; danach geht es in Zehnern weiter (30, 40, ...). */
    private const ARCHIVE_STARTS = [1, 8, 20];

    private const ITUNES_NS = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    private const SHORT_MAX = 220;

    // -------------------------------------------------------------------------------------------
    // RSS lesen
    // -------------------------------------------------------------------------------------------

    /**
     * Folgen aus der RSS-Datei, aufsteigend nach Nummer. Wirft RuntimeException mit einer
     * verstaendlichen Meldung (inkl. Zeilennummer), wenn die Datei kaputt ist.
     *
     * @return list<array{number:int, title:string, text:string, chapters:list<array{start:int, label:string, title:string}>, subtitle:?string, audio:string, date:DateTimeImmutable}>
     */
    public static function parseFeed(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false || !isset($doc->channel)) {
            $e = $errors[0] ?? null;
            throw new RuntimeException($e !== null
                ? 'Die RSS-Datei ist fehlerhaft (Zeile ' . $e->line . '): ' . trim($e->message)
                : 'Die RSS-Datei ist fehlerhaft oder leer.');
        }

        $episodes = [];
        foreach ($doc->channel->item as $item) {
            $title = trim(preg_replace('/\s+/u', ' ', (string) $item->title) ?? '');
            if (!preg_match('/^Episode\s+(\d+)\s*:?\s*/u', $title, $m)) {
                throw new RuntimeException('In der RSS-Datei gibt es eine Folge ohne „Episode <Nummer>“ im Titel: „' . $title . '“.');
            }
            $number = (int) $m[1];
            if (isset($episodes[$number])) {
                throw new RuntimeException("Episode {$number} steht zweimal in der RSS-Datei.");
            }
            $url = trim((string) ($item->enclosure['url'] ?? ''));
            if ($url === '') {
                throw new RuntimeException("Bei Episode {$number} fehlt die Audiodatei (<enclosure url=…>).");
            }
            $pub = trim((string) $item->pubDate);
            $date = $pub !== '' ? DateTimeImmutable::createFromFormat(DATE_RSS, $pub) : false;
            if ($date === false) {
                $date = $pub !== '' ? date_create_immutable($pub) : false;
            }
            if ($date === false) {
                throw new RuntimeException("Bei Episode {$number} ist das Datum (<pubDate>) nicht lesbar: „{$pub}“.");
            }
            [$text, $chapters] = self::splitChapters((string) $item->description);
            $subtitle = trim(preg_replace('/\s+/u', ' ', (string) $item->children(self::ITUNES_NS)->subtitle) ?? '');

            $episodes[$number] = [
                'number' => $number,
                'title' => $title,
                'text' => $text,
                'chapters' => $chapters,
                'subtitle' => $subtitle !== '' ? $subtitle : null,
                'audio' => self::relativeAudio($url),
                // Datum wie im Feed geschrieben (UTC) - so stand es bisher auch auf der Seite.
                'date' => $date->setTimezone(new DateTimeZone('UTC')),
            ];
        }
        if ($episodes === []) {
            throw new RuntimeException('Die RSS-Datei enthält keine Folgen.');
        }
        ksort($episodes);
        return array_values($episodes);
    }

    /**
     * Beschreibung in Fliesstext und Kapitel trennen. Kapitel = Zeilen "0:00 Titel", "12:34 Titel"
     * oder "1:02:03 Titel"; erst ab zwei solchen Zeilen gilt es als Kapitelliste.
     *
     * @return array{0:string, 1:list<array{start:int, label:string, title:string}>}
     */
    public static function splitChapters(string $description): array
    {
        $lines = preg_split('/\R/u', $description) ?: [];
        $chapters = [];
        $textLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})\s+[-–:]?\s*(.+)$/u', $line, $m)) {
                $seconds = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
                $chapters[] = ['start' => $seconds, 'label' => self::timeLabel($seconds), 'title' => trim($m[4])];
            } elseif (!preg_match('/^Kapitel\s*:?$/iu', $line)) {
                $textLines[] = $line;
            }
        }
        if (count($chapters) < 2) {
            // Einzelne Zeitangabe ist kein Kapitelverzeichnis - dann alles als Text.
            return [self::collapse($description), []];
        }
        usort($chapters, static fn ($a, $b) => $a['start'] <=> $b['start']);
        return [self::collapse(implode(' ', $textLines)), $chapters];
    }

    private static function collapse(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    private static function timeLabel(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }

    /** Audiodateien der eigenen Seite relativ verlinken ("episodes/…"), fremde absolut. */
    private static function relativeAudio(string $url): string
    {
        if (preg_match('#^https?://(www\.)?(xn--sdsalat-n2a\.eu|südsalat\.eu)/(.+)$#iu', $url, $m)) {
            return $m[3];
        }
        return $url;
    }

    /** Text fuers Archiv: Kurztext aus der RSS, sonst erster Satz, hoechstens ~220 Zeichen. */
    public static function shortText(array $episode): string
    {
        if ($episode['subtitle'] !== null) {
            return $episode['subtitle'];
        }
        $text = $episode['text'];
        // Erster Satz - aber nicht bei zu kurzen Anfaengen wie "Hallo!".
        if (preg_match('/^(.{40,}?[.!?])(\s|$)/u', $text, $m)) {
            $text = $m[1];
        }
        if (mb_strlen($text) <= self::SHORT_MAX) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::SHORT_MAX);
        $space = mb_strrpos($cut, ' ');
        return rtrim(mb_substr($cut, 0, $space !== false ? $space : self::SHORT_MAX), " ,;:–-") . ' …';
    }

    // -------------------------------------------------------------------------------------------
    // Aufteilen und HTML bauen
    // -------------------------------------------------------------------------------------------

    /**
     * Abgeschlossene Archive fuer die hoechste Folgennummer, neuestes zuerst.
     * @return list<array{from:int, to:int}>
     */
    public static function archiveRanges(int $maxNumber): array
    {
        $starts = self::ARCHIVE_STARTS;
        while (end($starts) + 10 <= $maxNumber) {
            $starts[] = end($starts) + 10;
        }
        $ranges = [];
        for ($i = 0; $i + 1 < count($starts); $i++) {
            // Ein Archiv ist fertig, sobald die erste Folge des naechsten erschienen ist.
            if ($maxNumber >= $starts[$i + 1]) {
                $ranges[] = ['from' => $starts[$i], 'to' => $starts[$i + 1] - 1];
            }
        }
        return array_reverse($ranges);
    }

    /** Der komplette Bereich zwischen den Markierungen (ohne die Markierungen). */
    public static function renderEpisodes(array $episodes): string
    {
        $max = max(array_column($episodes, 'number'));
        $ranges = self::archiveRanges($max);
        $archived = $ranges !== [] ? $ranges[0]['to'] : 0;

        $out = "\n  <!-- ==========================================\n       AKTUELLE EPISODEN (HAUPTFEED)\n       ========================================== -->\n";
        foreach (array_reverse($episodes) as $e) {
            if ($e['number'] > $archived) {
                $out .= self::renderSingle($e);
            }
        }
        $archiveNumber = count($ranges);
        foreach ($ranges as $range) {
            $inRange = array_values(array_filter($episodes, static fn ($e) => $e['number'] >= $range['from'] && $e['number'] <= $range['to']));
            if ($inRange !== []) {
                $out .= self::renderArchive($archiveNumber, $range, array_reverse($inRange));
            }
            $archiveNumber--;
        }
        if (array_filter($episodes, static fn ($e) => $e['chapters'] !== []) !== []) {
            $out .= self::chapterAssets();
        }
        return $out . "\n";
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function id(array $e): string
    {
        return sprintf('%03d', $e['number']);
    }

    private static function renderSingle(array $e): string
    {
        $id = self::id($e);
        $n = $e['number'];
        $audio = self::e($e['audio']);
        return "\n<!-- Episode {$n} -->\n"
            . "  <div class=\"episodes\" id=\"episode{$id}\">\n"
            . "    <article class=\"episode-card\">\n"
            . '      <h2>' . self::e($e['title']) . "</h2>\n"
            . '      <p>' . self::e($e['text']) . "</p>\n"
            . "      <audio controls data-episode=\"Episode_{$id}\">\n"
            . "        <source src=\"{$audio}\" type=\"audio/mpeg\" />\n"
            . "        Dein Browser unterstützt das Abspielen von Audio nicht.\n"
            . "      </audio>\n"
            . self::renderChapters($e, '      ')
            . "      <a class=\"download-button\" data-episode=\"Episode_{$id}\"\n"
            . "         href=\"{$audio}\" download\n"
            . "         aria-label=\"Episode {$n} herunterladen\">Download Episode {$n}</a>\n"
            . "    </article>\n"
            . "  </div>\n";
    }

    private static function renderArchive(int $number, array $range, array $episodes): string
    {
        $newest = $episodes[0];
        $oldest = $episodes[count($episodes) - 1];
        $out = "\n\n  <!-- ==========================================\n"
            . "       ARCHIV {$number}: Episoden {$range['from']} - {$range['to']}\n"
            . "       ========================================== -->\n"
            . " <div class=\"archive-group\">\n"
            . "  <div class=\"archive-box\">\n\n"
            . "    <div class=\"archive-header\">\n"
            . "      <div class=\"archive-header-wrapper\">\n"
            . "        <img src=\"logo/archive-icon.png\" alt=\"Archiv Icon\">\n"
            . '        <h2 class="archive-title">Alle Folgen vom ' . $oldest['date']->format('d.m.Y') . ' bis zum ' . $newest['date']->format('d.m.Y') . "</h2>\n"
            . "      </div>\n"
            . "      <p style=\"margin-bottom: 15px; font-size: 0.95rem; text-align: center;\">Episoden {$range['from']} bis {$range['to']}</p>\n"
            . "      <button class=\"button archive-toggle-button\" aria-expanded=\"false\">\n"
            . "        Archiv öffnen ▼\n"
            . "      </button>\n"
            . "    </div>\n\n"
            . "    <div class=\"archive-content\" aria-hidden=\"true\" style=\"display: none;\">\n\n"
            . "      <div class=\"episodes\">\n";
        foreach ($episodes as $e) {
            $id = self::id($e);
            $n = $e['number'];
            $audio = self::e($e['audio']);
            $out .= "        <!-- Episode {$n} -->\n"
                . "        <article class=\"episode-card\" id=\"episode{$id}\">\n"
                . '          <h2>' . self::e($e['title']) . "</h2>\n"
                . '          <p>' . self::e(self::shortText($e)) . "</p>\n"
                . "          <audio controls preload=\"none\" data-episode=\"Episode_{$id}\">\n"
                . "            <source src=\"{$audio}\" type=\"audio/mpeg\" />\n"
                . "            Dein Browser unterstützt das Abspielen von Audio nicht.\n"
                . "          </audio>\n"
                . self::renderChapters($e, '          ')
                . "          <a class=\"download-button\" data-episode=\"Episode_{$id}\"\n"
                . "             href=\"{$audio}\" download\n"
                . "             aria-label=\"Episode {$n} herunterladen\">Download Episode {$n}</a>\n"
                . "        </article>\n\n";
        }
        return $out
            . "      </div>\n\n"
            . "      <div class=\"archive-footer-box\">\n"
            . "        <button class=\"button archive-close-button\">Archiv schließen ▲</button>\n"
            . "      </div>\n"
            . "    </div>\n"
            . "  </div>\n"
            . " </div>\n";
    }

    private static function renderChapters(array $e, string $indent): string
    {
        if ($e['chapters'] === []) {
            return '';
        }
        $out = "{$indent}<details class=\"kapitel\">\n{$indent}  <summary>Kapitel (" . count($e['chapters']) . ")</summary>\n{$indent}  <ol>\n";
        foreach ($e['chapters'] as $c) {
            $out .= "{$indent}    <li data-start=\"{$c['start']}\"><button type=\"button\" class=\"kapitel-sprung\">"
                . self::e($c['label']) . '</button> ' . self::e($c['title']) . "</li>\n";
        }
        return $out . "{$indent}  </ol>\n{$indent}</details>\n";
    }

    /** Aussehen und Sprung-Logik der Kapitellisten - nur eingebaut, wenn es Kapitel gibt. */
    private static function chapterAssets(): string
    {
        return <<<'HTML'

  <style>
    .kapitel { margin: 10px 0 14px; text-align: left; }
    .kapitel summary { cursor: pointer; font-weight: 600; color: #77B538; }
    .kapitel ol { list-style: none; margin: 8px 0 0; padding: 0; }
    .kapitel li { padding: 3px 0; }
    .kapitel li.aktuell { font-weight: 700; }
    .kapitel-sprung { font: inherit; font-variant-numeric: tabular-nums; margin-right: 8px; padding: 1px 8px;
      border: 1px solid #77B538; border-radius: 6px; background: transparent; color: inherit; cursor: pointer; }
    .kapitel li.aktuell .kapitel-sprung { background: #77B538; color: #fff; }
  </style>
  <script>
    // Kapitel: Klick springt im Player der Folge an die Stelle; das laufende Kapitel wird markiert.
    document.addEventListener('click', function (ev) {
      var knopf = ev.target.closest('.kapitel-sprung');
      if (!knopf) return;
      var audio = knopf.closest('.episode-card').querySelector('audio');
      var start = Number(knopf.parentElement.dataset.start);
      function los() { audio.currentTime = start; audio.play(); }
      if (audio.readyState > 0) { los(); } else { audio.addEventListener('loadedmetadata', los, { once: true }); audio.load(); }
    });
    document.querySelectorAll('.kapitel').forEach(function (liste) {
      var audio = liste.closest('.episode-card').querySelector('audio');
      var eintraege = Array.prototype.slice.call(liste.querySelectorAll('li'));
      audio.addEventListener('timeupdate', function () {
        var jetzt = audio.currentTime, aktiv = null;
        eintraege.forEach(function (li) { if (Number(li.dataset.start) <= jetzt) aktiv = li; });
        eintraege.forEach(function (li) { li.classList.toggle('aktuell', li === aktiv); });
      });
    });
  </script>
HTML;
    }

    // -------------------------------------------------------------------------------------------
    // index.html aktualisieren
    // -------------------------------------------------------------------------------------------

    /** Ordner mit index.html und podcast.rss, oder null (dann ist die Automatik aus). */
    public static function directory(): ?string
    {
        // defined(): laeuft auch mit einer aelteren config.php, die HOMEPAGE_DIR noch nicht kennt.
        $configured = defined('HOMEPAGE_DIR') ? (string) constant('HOMEPAGE_DIR') : '';
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }
        // Nur das Live-Backend (Ordner APP) schreibt automatisch in die Homepage daneben -
        // der Testbereich (APP-test) und die lokale Testumgebung nie, ausser ausdruecklich gesetzt.
        return basename(dirname(__DIR__)) === 'APP' ? dirname(__DIR__, 2) : null;
    }

    /**
     * index.html neu erzeugen, wenn sich etwas geaendert hat.
     * @return array{status:string, message:string}  status: updated | unchanged | error | off (nicht eingerichtet)
     */
    public static function update(?string $dir = null): array
    {
        $dir ??= self::directory();
        if ($dir === null) {
            return ['status' => 'off', 'message' => 'Automatik ist hier abgeschaltet (nur im Live-Ordner APP aktiv).'];
        }
        try {
            $rss = @file_get_contents($dir . '/podcast.rss');
            if ($rss === false) {
                throw new RuntimeException('podcast.rss wurde nicht gefunden.');
            }
            $episodes = self::parseFeed($rss);
            $indexPath = $dir . '/index.html';
            $html = @file_get_contents($indexPath);
            if ($html === false) {
                throw new RuntimeException('index.html wurde nicht gefunden.');
            }
            $start = strpos($html, self::MARK_START);
            $end = strpos($html, self::MARK_END);
            if ($start === false || $end === false || $end < $start) {
                // Noch nicht eingerichtet (z. B. alte index.html hochgeladen) - kein Fehler, keine Mail.
                return ['status' => 'off', 'message' => 'In index.html fehlen die Markierungen FOLGEN-ANFANG/FOLGEN-ENDE – die Folgen werden dort nicht automatisch erzeugt.'];
            }
            $block = self::renderEpisodes($episodes);
            // Zeilenenden wie in der Datei (index.html hat CRLF).
            if (str_contains($html, "\r\n")) {
                $block = str_replace("\n", "\r\n", $block);
            }
            $before = substr($html, 0, $start + strlen(self::MARK_START));
            $after = substr($html, $end);
            $new = $before . $block . $after;
            if ($new === $html) {
                return ['status' => 'unchanged', 'message' => count($episodes) . ' Folgen, Homepage ist aktuell.'];
            }
            // Probe: jede Folge genau einmal auf der Seite, sonst lieber nichts schreiben.
            foreach ($episodes as $e) {
                if (substr_count($new, 'id="episode' . self::id($e) . '"') !== 1) {
                    throw new RuntimeException("Episode {$e['number']} wäre nicht genau einmal auf der Seite – nichts geändert.");
                }
            }
            $tmp = $indexPath . '.neu';
            if (file_put_contents($tmp, $new) === false || !rename($tmp, $indexPath)) {
                @unlink($tmp);
                throw new RuntimeException('index.html konnte nicht geschrieben werden.');
            }
            return ['status' => 'updated', 'message' => count($episodes) . ' Folgen, Homepage neu erzeugt.'];
        } catch (RuntimeException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------------------------
    // Cronjob: Stand merken, bei neuem Fehler Thorsten per Mail Bescheid geben
    // -------------------------------------------------------------------------------------------

    private static function statusFile(): string
    {
        // .json ist ueber die .htaccess von aussen gesperrt.
        return dirname(__DIR__) . '/cron/homepage-status.json';
    }

    /** @return array{status?:string, message?:string, at?:string, updated_at?:string} */
    public static function lastStatus(): array
    {
        $raw = @file_get_contents(self::statusFile());
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /** Aus dem Cronjob und dem Admin-Knopf: aktualisieren, Stand merken, bei neuem Fehler mailen. */
    public static function run(PDO $pdo): array
    {
        $result = self::update();
        if ($result['status'] === 'off' && self::directory() === null) {
            return $result;
        }
        $last = self::lastStatus();
        $now = date('Y-m-d H:i:s');
        $state = ['status' => $result['status'], 'message' => $result['message'], 'at' => $now,
            'updated_at' => $result['status'] === 'updated' ? $now : ($last['updated_at'] ?? null)];
        @file_put_contents(self::statusFile(), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $newError = $result['status'] === 'error'
            && (($last['status'] ?? null) !== 'error' || ($last['message'] ?? null) !== $result['message']);
        if ($newError) {
            self::mailOwner($pdo, $result['message']);
        }
        return $result;
    }

    private static function mailOwner(PDO $pdo, string $message): void
    {
        $body = '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;">Die Homepage konnte nicht aus der RSS-Datei erzeugt werden:</p>'
            . '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;"><strong>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p style="margin:0;font-size:16px;line-height:1.5;">Die bisherige Homepage bleibt unverändert online. Sobald die RSS-Datei wieder in Ordnung ist, aktualisiert sie sich beim nächsten Durchlauf (alle 15 Minuten) von selbst. Die App liest dieselbe Datei – bitte zeitnah beheben.</p>';
        foreach ($pdo->query("SELECT name, email FROM admins WHERE role = 'owner'")->fetchAll() as $admin) {
            try {
                Mailer::send((string) $admin['email'], (string) $admin['name'], 'Homepage: Fehler in der RSS-Datei', render_branded_email_html('RSS-Datei prüfen', $body));
            } catch (\Throwable $e) {
                error_log('Homepage-Fehlermail fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }
}
