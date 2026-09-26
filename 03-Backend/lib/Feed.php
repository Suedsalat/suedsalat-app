<?php
declare(strict_types=1);

namespace Suedsalat;

use PDO;
use RuntimeException;

/**
 * Die RSS-Datei (podcast.rss) lesen und schreiben - fuer die Admin-Seite "Folgen".
 *
 * Der Kopf der Datei (Podcast-Angaben, Kategorien, Bild usw.) bleibt Zeichen fuer Zeichen, nur
 * <lastBuildDate> wird aktualisiert. Die Folgen werden einheitlich neu geschrieben - die Kennung
 * (<guid>) einer Folge aendert sich dabei NIE, sonst hielten App und Podcast-Apps sie fuer neu.
 *
 * Kapitel stehen als Zeilen "00:00 Titel" am Ende der Beschreibung (unter "Kapitel:") - so liest
 * sie Spotify, die App und die Homepage (Homepage::splitChapters).
 *
 * Vor jedem Speichern wird die neue Datei komplett geprueft; die alte Fassung landet in
 * rss_versions und laesst sich wiederherstellen.
 */
final class Feed
{
    private const ITUNES_NS = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    public const DEFAULT_AUDIO_BASE = 'https://www.xn--sdsalat-n2a.eu/episodes/';
    private const KEEP_VERSIONS = 30;

    // -------------------------------------------------------------------------------------------
    // Lesen
    // -------------------------------------------------------------------------------------------

    /**
     * @return array{head:string, tail:string, crlf:bool, items:list<array<string,mixed>>}
     */
    public static function parse(string $xml): array
    {
        Homepage::parseFeed($xml); // wirft bei kaputter Datei eine verstaendliche Meldung
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

        $first = strpos($xml, '<item>');
        $last = strrpos($xml, '</item>');
        $head = substr($xml, 0, (int) $first);
        $head = rtrim((string) preg_replace('/\s*<!--\s*Episode[^>]*-->\s*$/u', '', $head));
        $tail = ltrim(substr($xml, (int) $last + strlen('</item>')));

        $items = [];
        foreach ($doc->channel->item as $item) {
            $title = trim((string) preg_replace('/\s+/u', ' ', (string) $item->title));
            preg_match('/^Episode\s+(\d+)\s*:?\s*(.*)$/u', $title, $m);
            [$text, $chapters] = self::splitDescription((string) $item->description);
            $guidAttrs = '';
            foreach ($item->guid->attributes() ?? [] as $name => $value) {
                $guidAttrs .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES) . '"';
            }
            $it = $item->children(self::ITUNES_NS);
            $items[] = [
                'number' => (int) $m[1],
                'name' => trim($m[2]),
                'text' => $text,
                'chapters' => $chapters,
                'subtitle' => trim((string) preg_replace('/\s+/u', ' ', (string) $it->subtitle)),
                'pubDate' => trim((string) $item->pubDate),
                'url' => trim((string) $item->enclosure['url']),
                'length' => trim((string) $item->enclosure['length']),
                'type' => trim((string) $item->enclosure['type']) ?: 'audio/mpeg',
                'guid' => trim((string) $item->guid),
                'guidAttrs' => $guidAttrs,
                'link' => trim((string) $item->link),
                'duration' => trim((string) $it->duration),
                'explicit' => trim((string) $it->explicit) ?: 'no',
            ];
        }
        usort($items, static fn ($a, $b) => $a['number'] <=> $b['number']);
        return ['head' => $head, 'tail' => $tail, 'crlf' => str_contains($xml, "\r\n"), 'items' => $items];
    }

    /**
     * Beschreibung in Text (Zeilen bleiben erhalten) und Kapitel trennen.
     * @return array{0:string, 1:list<array{start:int, title:string}>}
     */
    public static function splitDescription(string $description): array
    {
        [, $found] = Homepage::splitChapters($description);
        $lines = array_map('trim', preg_split('/\R/u', $description) ?: []);
        if ($found !== []) {
            // Kapitelzeilen und die Ueberschrift "Kapitel:" gehoeren nicht zum Text.
            $lines = array_filter($lines, static fn ($l) => !preg_match('/^(?:\d{1,2}:)?\d{1,2}:\d{2}\s+\S/u', $l)
                && !preg_match('/^Kapitel\s*:?$/iu', $l));
        }
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
        $chapters = array_map(static fn ($c) => ['start' => $c['start'], 'title' => $c['title']], $found);
        return [$text, $chapters];
    }

    // -------------------------------------------------------------------------------------------
    // Kapitel aus dem Formular
    // -------------------------------------------------------------------------------------------

    /**
     * Kapitel aus dem Textfeld ("00:00 Begruessung" je Zeile). Leeres Feld = keine Kapitel.
     * @return array{chapters:list<array{start:int, title:string}>, errors:list<string>}
     */
    public static function chaptersFromInput(string $input, ?int $durationSeconds): array
    {
        $chapters = [];
        $errors = [];
        foreach (preg_split('/\R/u', $input) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^Kapitel\s*:?$/iu', $line)) {
                continue;
            }
            if (!preg_match('/^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})\s*[-–:]?\s*(.+)$/u', $line, $m) || (int) $m[3] > 59) {
                $errors[] = 'Zeile ' . ($i + 1) . ': „' . $line . '“ – bitte im Format „12:34 Titel“ (oder „1:02:03 Titel“).';
                continue;
            }
            $start = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
            $chapters[] = ['start' => $start, 'title' => trim($m[4]), 'line' => $i + 1];
        }
        if ($errors !== [] || $chapters === []) {
            return ['chapters' => [], 'errors' => $errors];
        }
        if (count($chapters) < 2) {
            $errors[] = 'Bitte mindestens zwei Kapitel angeben (oder das Feld leer lassen).';
        }
        if ($chapters[0]['start'] !== 0) {
            $errors[] = 'Das erste Kapitel muss bei 00:00 beginnen.';
        }
        for ($i = 1; $i < count($chapters); $i++) {
            if ($chapters[$i]['start'] <= $chapters[$i - 1]['start']) {
                $errors[] = 'Zeile ' . $chapters[$i]['line'] . ': Die Zeiten müssen von oben nach unten größer werden.';
            }
        }
        if ($durationSeconds !== null) {
            foreach ($chapters as $c) {
                if ($c['start'] >= $durationSeconds) {
                    $errors[] = 'Zeile ' . $c['line'] . ': ' . Mp3Info::format($c['start']) . ' liegt hinter dem Ende der Folge (' . Mp3Info::format($durationSeconds) . ').';
                }
            }
        }
        $clean = array_map(static fn ($c) => ['start' => $c['start'], 'title' => $c['title']], $chapters);
        return ['chapters' => $errors === [] ? $clean : [], 'errors' => $errors];
    }

    /** Fuer das Textfeld im Formular. */
    public static function chaptersToInput(array $chapters): string
    {
        return implode("\n", array_map(static fn ($c) => Mp3Info::format($c['start']) . ' ' . $c['title'], $chapters));
    }

    /** "Episode 12: Titel" -> Sekunden aus <itunes:duration>, null wenn unbekannt. */
    public static function durationToSeconds(string $duration): ?int
    {
        if (!preg_match('/^\d+(:\d{1,2}){0,2}$/', trim($duration))) {
            return null;
        }
        $seconds = 0;
        foreach (explode(':', trim($duration)) as $part) {
            $seconds = $seconds * 60 + (int) $part;
        }
        return $seconds;
    }

    // -------------------------------------------------------------------------------------------
    // Schreiben
    // -------------------------------------------------------------------------------------------

    public static function render(array $feed): string
    {
        $head = (string) preg_replace(
            '#<lastBuildDate>.*?</lastBuildDate>#s',
            '<lastBuildDate>' . gmdate('D, d M Y H:i:s') . ' GMT</lastBuildDate>',
            $feed['head']
        );
        $items = $feed['items'];
        usort($items, static fn ($a, $b) => $a['number'] <=> $b['number']);
        $out = rtrim($head) . "\n\n";
        foreach ($items as $item) {
            $out .= self::renderItem($item);
        }
        $out .= "\n  " . ltrim($feed['tail']);
        $out = str_replace("\r\n", "\n", $out);
        return $feed['crlf'] ? str_replace("\n", "\r\n", $out) : $out;
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function renderItem(array $item): string
    {
        $description = $item['text'];
        if ($item['chapters'] !== []) {
            $description .= "\n\nKapitel:\n" . implode("\n", array_map(
                static fn ($c) => Mp3Info::format($c['start']) . ' ' . $c['title'],
                $item['chapters']
            ));
        }
        $description = implode("\n", array_map(static fn ($l) => $l === '' ? '' : '        ' . $l, explode("\n", $description)));
        $cdata = str_replace(']]>', ']]]]><![CDATA[>', $description);
        $title = 'Episode ' . $item['number'] . ($item['name'] !== '' ? ': ' . $item['name'] : '');

        return "    <!-- Episode {$item['number']} -->\n"
            . "    <item>\n"
            . '      <title>' . self::x($title) . "</title>\n"
            . "      <description><![CDATA[\n{$cdata}\n      ]]></description>\n"
            . ($item['subtitle'] !== '' ? '      <itunes:subtitle>' . self::x($item['subtitle']) . "</itunes:subtitle>\n" : '')
            . '      <pubDate>' . self::x($item['pubDate']) . "</pubDate>\n"
            . "      <enclosure\n"
            . '        url="' . self::x($item['url']) . "\"\n"
            . '        length="' . self::x((string) $item['length']) . "\"\n"
            . '        type="' . self::x($item['type']) . "\" />\n"
            . '      <guid' . $item['guidAttrs'] . '>' . self::x($item['guid']) . "</guid>\n"
            . '      <link>' . self::x($item['link']) . "</link>\n"
            . ($item['duration'] !== '' ? '      <itunes:duration>' . self::x($item['duration']) . "</itunes:duration>\n" : '')
            . '      <itunes:explicit>' . self::x($item['explicit']) . "</itunes:explicit>\n"
            . "    </item>\n\n";
    }

    // -------------------------------------------------------------------------------------------
    // Datei und Fassungen
    // -------------------------------------------------------------------------------------------

    public static function path(): ?string
    {
        $dir = Homepage::directory();
        return $dir !== null ? $dir . '/podcast.rss' : null;
    }

    public static function read(): string
    {
        $path = self::path();
        $xml = $path !== null ? @file_get_contents($path) : false;
        if ($xml === false) {
            throw new RuntimeException('podcast.rss wurde nicht gefunden.');
        }
        return $xml;
    }

    /**
     * Neue Fassung pruefen und schreiben; die bisherige wird als Fassung gemerkt.
     * Wirft RuntimeException, wenn die neue Datei nicht einwandfrei ist - dann bleibt alles, wie es war.
     */
    public static function write(PDO $pdo, string $xml, string $note, int $adminId): void
    {
        Homepage::parseFeed($xml);
        $path = self::path();
        if ($path === null) {
            throw new RuntimeException('Die RSS-Datei lässt sich nur im Live-Bereich bearbeiten.');
        }
        $old = @file_get_contents($path);
        if ($old === $xml) {
            return;
        }
        if ($old !== false) {
            $pdo->prepare('INSERT INTO rss_versions (content, note, admin_id) VALUES (:c, :n, :a)')
                ->execute([':c' => $old, ':n' => mb_substr($note, 0, 200), ':a' => $adminId]);
            $pdo->exec('DELETE FROM rss_versions WHERE id NOT IN (SELECT id FROM (SELECT id FROM rss_versions ORDER BY id DESC LIMIT ' . self::KEEP_VERSIONS . ') keep)');
        }
        $tmp = $path . '.neu';
        if (file_put_contents($tmp, $xml) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('podcast.rss konnte nicht geschrieben werden.');
        }
    }

    /** MP3-Dateien im Ordner episodes/, die noch zu keiner Folge gehoeren (neueste zuerst). */
    public static function unusedAudioFiles(array $items): array
    {
        $dir = Homepage::directory();
        if ($dir === null) {
            return [];
        }
        $used = array_map(static fn ($i) => basename(parse_url($i['url'], PHP_URL_PATH) ?: ''), $items);
        $files = array_map('basename', glob($dir . '/episodes/*.mp3') ?: []);
        $free = array_values(array_diff($files, $used));
        rsort($free);
        return $free;
    }

    /** Grundadresse der Audiodateien wie bei den bisherigen Folgen. */
    public static function audioBase(array $items): string
    {
        $last = end($items);
        if ($last && preg_match('#^(https?://.+/)[^/]+$#', $last['url'], $m)) {
            return $m[1];
        }
        return self::DEFAULT_AUDIO_BASE;
    }
}
