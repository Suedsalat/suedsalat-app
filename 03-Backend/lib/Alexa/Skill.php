<?php
declare(strict_types=1);

namespace Suedsalat\Alexa;

use PDO;

/**
 * Der Alexa-Skill "Südsalat" ("Alexa, öffne Südsalat"): Folgen abspielen, weiterhoeren, Kapitel
 * springen, Neuigkeiten vorlesen. Nimmt die bereits geprueften Anfragen von alexa/index.php und
 * liefert die Antwort als Array (wird dort zu JSON).
 *
 * Folgen und Kapitel kommen aus der podcast.rss (dieselbe Quelle wie App und Homepage).
 * Die Hoerstelle merkt sich der Server pro Alexa-Konto - nur als Pruefwert der anonymen
 * Alexa-Kennung, ohne Namen (Tabelle alexa_positions, nach 12 Monaten ohne Nutzung geloescht).
 */
final class Skill
{
    private const COVER = 'https://www.xn--sdsalat-n2a.eu/logo/suedsalat_podcast_cover.jpg';
    private const SUBTITLE = 'Südsalat – Themen aus dem Leben';
    private const TOKEN_PREFIX = 'suedsalat-ep-';

    /** @var array<int, array<string,mixed>> Folgen nach Nummer */
    private array $episodes = [];

    /** Pruefwert des Alexa-Kontos der laufenden Anfrage (null = unbekannt). */
    private ?string $user = null;

    /**
     * Manche Geraete (und die Alexa-App) melden die Wiedergabestelle immer als 0. Dann rechnet der
     * Server selbst: Start-Stelle + seit dem Start vergangene Zeit. Beim Anhalten per Sprache laeuft
     * die Wiedergabe schon ein paar Sekunden nicht mehr, bis der Befehl hier ankommt - so viel abziehen.
     */
    private const VOICE_DELAY_MS = 3000;

    /** Unter 30 Sekunden lohnt sich "weiterhoeren" nicht. */
    private const MIN_RESUME_MS = 30000;

    /** Angebote in der Sitzung, auf die "ja"/"weiter" antworten. */
    private const OFFER_RESUME = 'weiterhoeren';
    private const OFFER_LATEST = 'neueste';

    /** @param list<array<string,mixed>> $episodes aus Feed::parse()['items'] */
    public function __construct(private readonly PDO $pdo, array $episodes)
    {
        foreach ($episodes as $e) {
            $this->episodes[(int) $e['number']] = $e;
        }
        ksort($this->episodes);
    }

    /** @param array<string,mixed> $req */
    public function handle(array $req): array
    {
        $type = (string) ($req['request']['type'] ?? '');
        $user = $this->userHash($req);
        $this->user = $user;
        // Lehnt Amazon eine Antwort ab, kommt der Grund in einer eigenen Nachricht hinterher - aufheben.
        if (isset($req['request']['error']) || $type === 'System.ExceptionEncountered') {
            self::logProblem($req);
        }

        return match (true) {
            $type === 'LaunchRequest' => $this->launch($user),
            $type === 'IntentRequest' => $this->intent($req, $user),
            str_starts_with($type, 'AudioPlayer.') => $this->audioEvent($req, $user),
            str_starts_with($type, 'PlaybackController.') => $this->controller($req, $user),
            default => self::empty(), // SessionEndedRequest, System.ExceptionEncountered
        };
    }

    // -------------------------------------------------------------------------------------------
    // Gespraech
    // -------------------------------------------------------------------------------------------

    private function launch(?string $user): array
    {
        $saved = $user !== null ? $this->savedPosition($user) : null;
        if ($saved !== null) {
            $e = $this->episodes[$saved['episode']];
            // Als Frage: "ja", "weiter" und "mach weiter" setzen dann fort (siehe OFFER_*).
            return self::ask('Willkommen bei Südsalat! Du hast ' . $this->name($e) . ' ' . self::spokenTime($saved['offset'])
                . ' unterbrochen. Soll ich dort weitermachen?',
                'Soll ich weitermachen? Sag „ja“, oder „neueste Folge“.', self::OFFER_RESUME);
        }
        $latest = $this->latest();
        $intro = $latest !== null ? ' Die neueste Folge ist ' . $this->name($latest) . '.' : '';
        return self::ask('Willkommen bei Südsalat – Themen aus dem Leben!' . $intro
            . ' Sag „spiel die neueste Folge“, „spiel Folge“ mit einer Nummer, oder frag „was gibt es Neues?“.',
            'Was möchtest du hören?');
    }

    private function intent(array $req, ?string $user): array
    {
        $name = (string) ($req['request']['intent']['name'] ?? '');
        // Hat Alexa gerade etwas angeboten ("Soll ich dort weitermachen?"), sind "ja", "weiter" und
        // "mach weiter" die Antwort darauf - "weiter" hiesse fuer Alexa sonst "naechster Titel".
        $offer = (string) ($req['session']['attributes']['angebot'] ?? '');
        if ($offer !== '' && in_array($name, ['AMAZON.YesIntent', 'AMAZON.NextIntent', 'AMAZON.ResumeIntent', 'WeiterhoerenIntent'], true)) {
            return $offer === self::OFFER_LATEST ? $this->playLatest($user) : $this->resume($req, $user, true, true);
        }
        return match ($name) {
            'AMAZON.YesIntent' => self::ask('Was möchtest du hören? Sag zum Beispiel „spiel die neueste Folge“.', 'Was möchtest du hören?'),
            'AMAZON.NoIntent' => self::ask('Okay. Sag „spiel die neueste Folge“, „spiel Folge“ mit einer Nummer, oder „stopp“.', 'Was möchtest du hören?'),
            'NeuesteFolgeIntent' => $this->playLatest($user),
            'FolgeSpielenIntent' => $this->playNumber($req, $user),
            'AMAZON.ResumeIntent', 'WeiterhoerenIntent' => $this->resume($req, $user, true),
            'AMAZON.PauseIntent' => $this->stop($req, $user),
            'AMAZON.StopIntent', 'AMAZON.CancelIntent' => $this->isPlaying($req) ? $this->stop($req, $user) : self::tell('Bis bald bei Südsalat!'),
            'AMAZON.NextIntent' => $this->next($req, $user, true, true),
            'AMAZON.PreviousIntent' => $this->previous($req, $user, true, true),
            'NaechstesKapitelIntent' => $this->next($req, $user, true, false),
            'VorigesKapitelIntent' => $this->previous($req, $user, true, false),
            'AMAZON.StartOverIntent' => $this->startOver($req, $user),
            'KapitelIntent' => $this->currentChapter($req),
            'NeuigkeitenIntent' => $this->news(),
            'AMAZON.LoopOnIntent', 'AMAZON.LoopOffIntent', 'AMAZON.ShuffleOnIntent', 'AMAZON.ShuffleOffIntent', 'AMAZON.RepeatIntent'
                => self::tell('Das kann Südsalat leider nicht. Du kannst aber „nächstes Kapitel“ oder „nächste Folge“ sagen.'),
            'AMAZON.HelpIntent', 'AMAZON.NavigateHomeIntent' => self::ask(
                'Mit Südsalat hörst du unseren Podcast. Sag „spiel die neueste Folge“, „spiel Folge zwölf“, „mach weiter“, '
                . '„nächstes Kapitel“, „welches Kapitel läuft“ oder „was gibt es Neues?“. Was möchtest du?', 'Was möchtest du hören?'),
            default => self::ask('Das habe ich nicht verstanden. Sag zum Beispiel „spiel die neueste Folge“ oder „was gibt es Neues?“.',
                'Was möchtest du hören?'),
        };
    }

    private function playLatest(?string $user): array
    {
        $latest = $this->latest();
        if ($latest === null) {
            return self::tell('Gerade finde ich keine Folgen. Bitte versuch es später noch einmal.');
        }
        return $this->playEpisode($latest, $user);
    }

    private function playNumber(array $req, ?string $user): array
    {
        $value = (string) ($req['request']['intent']['slots']['nummer']['value'] ?? '');
        $number = ctype_digit($value) ? (int) $value : 0;
        $range = $this->episodes !== [] ? array_key_first($this->episodes) . ' bis ' . array_key_last($this->episodes) : '';
        if ($number === 0) {
            return self::ask("Welche Folge möchtest du hören? Es gibt die Folgen {$range}.", 'Sag zum Beispiel „Folge zwölf“.');
        }
        if (!isset($this->episodes[$number])) {
            return self::ask("Folge {$number} gibt es nicht. Es gibt die Folgen {$range}. Welche möchtest du?", 'Welche Folge möchtest du hören?');
        }
        return $this->playEpisode($this->episodes[$number], $user);
    }

    /** Spielt eine Folge - wurde sie schon angehoert, ab der gemerkten Stelle. */
    private function playEpisode(array $e, ?string $user): array
    {
        $saved = $user !== null ? $this->savedPosition($user) : null;
        if ($saved !== null && $saved['episode'] === (int) $e['number']) {
            return $this->play($e, $saved['offset'], 'Weiter geht es mit ' . $this->name($e) . ' ' . self::spokenTime($saved['offset'])
                . '. Sag „von vorn“, um neu zu beginnen.');
        }
        return $this->play($e, 0, 'Hier ist ' . $this->name($e) . '.');
    }

    /** $fromSaved: genau die in der Begruessung angebotene, gemerkte Stelle nehmen. */
    private function resume(array $req, ?string $user, bool $withSpeech, bool $fromSaved = false): array
    {
        [$e, $offset] = $fromSaved ? [null, 0] : $this->playing($req);
        if ($e === null && $user !== null && ($saved = $this->savedPosition($user)) !== null) {
            $e = $this->episodes[$saved['episode']];
            $offset = $saved['offset'];
        }
        if ($e === null) {
            return $withSpeech ? self::ask('Du hast noch nichts angefangen. Sag „spiel die neueste Folge“.', 'Was möchtest du hören?') : self::empty();
        }
        return $this->play($e, $offset, null);
    }

    private function stop(array $req, ?string $user): array
    {
        [$e, $offset, $reported] = $this->playing($req);
        if ($e !== null && $user !== null) {
            $this->remember((int) $e['number'], $reported ? $offset : max(0, $offset - self::VOICE_DELAY_MS), false);
        }
        return ['version' => '1.0', 'response' => ['directives' => [['type' => 'AudioPlayer.Stop']], 'shouldEndSession' => true]];
    }

    private function startOver(array $req, ?string $user): array
    {
        [$e] = $this->playing($req);
        if ($e === null && $user !== null && ($saved = $this->savedPosition($user)) !== null) {
            $e = $this->episodes[$saved['episode']];
        }
        return $e !== null ? $this->play($e, 0, null) : self::ask('Welche Folge möchtest du von vorn hören?', 'Sag zum Beispiel „spiel die neueste Folge“.');
    }

    /**
     * "Weiter": zum naechsten Kapitel; ohne (weiteres) Kapitel zur naechsten Folge, wenn $toEpisode.
     */
    private function next(array $req, ?string $user, bool $withSpeech, bool $toEpisode): array
    {
        [$e, $offset] = $this->playing($req);
        if ($e === null) {
            return $withSpeech ? self::ask('Gerade läuft nichts. Sag „spiel die neueste Folge“.', 'Was möchtest du hören?') : self::empty();
        }
        foreach ($e['chapters'] as $c) {
            if ($c['start'] * 1000 > $offset + 1000) {
                return $this->play($e, $c['start'] * 1000, $withSpeech ? 'Kapitel: ' . $c['title'] . '.' : null);
            }
        }
        $following = $this->episodes[(int) $e['number'] + 1] ?? null;
        if ($toEpisode && $following !== null) {
            return $this->play($following, 0, $withSpeech ? 'Hier ist ' . $this->name($following) . '.' : null);
        }
        if (!$withSpeech) {
            return self::empty();
        }
        return self::tell($e['chapters'] === [] && !$toEpisode ? 'Diese Folge hat keine Kapitel.'
            : ($toEpisode ? 'Das ist schon die neueste Folge.' : 'Das ist schon das letzte Kapitel.'), false);
    }

    /**
     * "Zurueck": an den Anfang des laufenden Kapitels, direkt nach einem Kapitelanfang ins vorige -
     * wie in der App. Ohne Kapitel zur vorigen Folge, wenn $toEpisode.
     */
    private function previous(array $req, ?string $user, bool $withSpeech, bool $toEpisode): array
    {
        [$e, $offset] = $this->playing($req);
        if ($e === null) {
            return $withSpeech ? self::ask('Gerade läuft nichts. Sag „spiel die neueste Folge“.', 'Was möchtest du hören?') : self::empty();
        }
        $chapters = $e['chapters'];
        if ($chapters !== []) {
            $index = 0;
            foreach ($chapters as $i => $c) {
                if ($c['start'] * 1000 <= $offset) {
                    $index = $i;
                }
            }
            $target = ($offset - $chapters[$index]['start'] * 1000 > 3000 || $index === 0) ? $chapters[$index] : $chapters[$index - 1];
            return $this->play($e, $target['start'] * 1000, $withSpeech ? 'Kapitel: ' . $target['title'] . '.' : null);
        }
        $before = $this->episodes[(int) $e['number'] - 1] ?? null;
        if ($toEpisode && $before !== null) {
            return $this->play($before, 0, $withSpeech ? 'Hier ist ' . $this->name($before) . '.' : null);
        }
        return $withSpeech ? self::tell($toEpisode ? 'Das ist schon die erste Folge.' : 'Diese Folge hat keine Kapitel.', false) : self::empty();
    }

    private function currentChapter(array $req): array
    {
        [$e, $offset] = $this->playing($req);
        if ($e === null) {
            return self::ask('Gerade läuft nichts. Sag „spiel die neueste Folge“.', 'Was möchtest du hören?');
        }
        $current = null;
        foreach ($e['chapters'] as $c) {
            if ($c['start'] * 1000 <= $offset) {
                $current = $c;
            }
        }
        return self::tell($current !== null
            ? 'Gerade läuft ' . $this->name($e) . ', Kapitel „' . $current['title'] . '“.'
            : 'Gerade läuft ' . $this->name($e) . '. Diese Folge hat keine Kapitel.', false);
    }

    private function news(): array
    {
        $parts = [];
        $event = $this->pdo->query('SELECT title, event_date, event_time FROM events WHERE event_date >= CURDATE()
                                    ORDER BY event_date, event_time LIMIT 1')->fetch();
        $parts[] = $event
            ? 'Die nächste Veranstaltung: ' . $event['title'] . ', ' . self::spokenDate((string) $event['event_date'])
                . ($event['event_time'] ? ' um ' . self::spokenClock((string) $event['event_time']) : '') . '.'
            : 'Gerade steht keine Veranstaltung an.';
        $movie = $this->pdo->query('SELECT title FROM movie_tips ORDER BY created_at DESC, id DESC LIMIT 1')->fetchColumn();
        if ($movie) {
            $parts[] = 'Unser neuester Filmtipp: ' . $movie . '.';
        }
        $location = $this->pdo->query('SELECT name, location FROM location_tips ORDER BY created_at DESC, id DESC LIMIT 1')->fetch();
        if ($location) {
            $parts[] = 'Und der neueste Locationtipp: ' . $location['name'] . ($location['location'] ? ' in ' . $location['location'] : '') . '.';
        }
        $latest = $this->latest();
        if ($latest !== null) {
            $parts[] = 'Die neueste Folge ist ' . $this->name($latest) . '. Soll ich sie abspielen?';
            return self::ask(implode(' ', $parts), 'Soll ich die neueste Folge abspielen? Sag „ja“ oder „nein“.', self::OFFER_LATEST);
        }
        return self::ask(implode(' ', $parts), 'Was möchtest du hören?');
    }

    // -------------------------------------------------------------------------------------------
    // Ereignisse vom Abspielgeraet (ohne Sprachausgabe)
    // -------------------------------------------------------------------------------------------

    private function audioEvent(array $req, ?string $user): array
    {
        $type = (string) $req['request']['type'];
        $token = (string) ($req['request']['token'] ?? '');
        $offset = (int) ($req['request']['offsetInMilliseconds'] ?? 0);
        $e = $this->fromToken($token);
        if ($e === null || $user === null) {
            return self::empty();
        }
        switch ($type) {
            case 'AudioPlayer.PlaybackStarted':
                $row = $this->row();
                if ($offset > 0 || $row === null || (int) $row['episode_number'] !== (int) $e['number']) {
                    $this->remember((int) $e['number'], $offset, true);
                } else {
                    // Geraet meldet 0: angeforderte Stelle behalten, nur den echten Startzeitpunkt setzen.
                    $this->pdo->prepare('UPDATE alexa_positions SET playing_since = NOW(), updated_at = NOW() WHERE user_hash = :u')
                        ->execute([':u' => $user]);
                }
                return self::empty();
            case 'AudioPlayer.PlaybackStopped':
                $row = $this->row();
                if ($offset > 0) {
                    $this->remember((int) $e['number'], $offset, false);
                } elseif ($row !== null && $row['playing_since'] !== null && (int) $row['episode_number'] === (int) $e['number']) {
                    // Nicht per Sprache angehalten (sonst waere playing_since schon leer): ohne Abzug.
                    $this->remember((int) $e['number'], $this->estimate($row), false);
                }
                return self::empty();
            case 'AudioPlayer.PlaybackFinished':
                $this->pdo->prepare('DELETE FROM alexa_positions WHERE user_hash = :u AND episode_number = :n')
                    ->execute([':u' => $user, ':n' => (int) $e['number']]);
                return self::empty();
            case 'AudioPlayer.PlaybackNearlyFinished':
                // Wie beim Nachhoeren am Stueck: danach die naechste Folge einreihen.
                $following = $this->episodes[(int) $e['number'] + 1] ?? null;
                return $following !== null ? $this->play($following, 0, null, 'ENQUEUE', $token) : self::empty();
            default: // PlaybackFailed u. a.
                error_log('Alexa: ' . $type . ' ' . json_encode($req['request']['error'] ?? null));
                return self::empty();
        }
    }

    /** Tasten am Geraet oder in der Alexa-App - Antwort ohne Sprachausgabe. */
    private function controller(array $req, ?string $user): array
    {
        return match ((string) $req['request']['type']) {
            'PlaybackController.PlayCommandIssued' => $this->resume($req, $user, false),
            'PlaybackController.PauseCommandIssued' => $this->stop($req, $user),
            'PlaybackController.NextCommandIssued' => $this->next($req, $user, false, true),
            'PlaybackController.PreviousCommandIssued' => $this->previous($req, $user, false, true),
            default => self::empty(),
        };
    }

    // -------------------------------------------------------------------------------------------
    // Hilfen
    // -------------------------------------------------------------------------------------------

    private function latest(): ?array
    {
        return $this->episodes !== [] ? $this->episodes[array_key_last($this->episodes)] : null;
    }

    private function name(array $e): string
    {
        return 'Folge ' . $e['number'] . ($e['name'] !== '' ? ', „' . $e['name'] . '“' : '');
    }

    /**
     * Laufende (oder zuletzt angehaltene) Folge und Stelle in ms. Meldet das Geraet 0, wird die Stelle
     * aus dem Gemerkten berechnet. Drittes Feld: true = vom Geraet gemeldet, false = berechnet.
     * @return array{0: ?array, 1: int, 2: bool}
     */
    private function playing(array $req): array
    {
        $player = $req['context']['AudioPlayer'] ?? [];
        $e = $this->fromToken((string) ($player['token'] ?? ''));
        $offset = (int) ($player['offsetInMilliseconds'] ?? 0);
        if ($e === null || $offset > 0) {
            return [$e, $offset, true];
        }
        $row = $this->row();
        if ($row !== null && (int) $row['episode_number'] === (int) $e['number']) {
            return [$e, $this->estimate($row), false];
        }
        return [$e, 0, true];
    }

    /** @return array<string,mixed>|null Gemerkte Zeile des aktuellen Kontos */
    private function row(): ?array
    {
        if ($this->user === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT episode_number, offset_ms, playing_since,
                                            TIMESTAMPDIFF(SECOND, playing_since, NOW()) AS seit
                                     FROM alexa_positions WHERE user_hash = :u');
        $stmt->execute([':u' => $this->user]);
        return $stmt->fetch() ?: null;
    }

    /** Gemerkte Stelle plus die seit dem Start vergangene Zeit (wenn gerade laeuft). */
    private function estimate(array $row): int
    {
        $offset = (int) $row['offset_ms'];
        if ($row['playing_since'] !== null) {
            $offset += max(0, (int) $row['seit']) * 1000;
        }
        $e = $this->episodes[(int) $row['episode_number']] ?? null;
        $length = $e !== null ? self::durationMs((string) ($e['duration'] ?? '')) : null;
        return $length !== null ? min($offset, $length) : $offset;
    }

    private static function durationMs(string $duration): ?int
    {
        if (!preg_match('/^\d+(:\d{1,2}){0,2}$/', trim($duration))) {
            return null;
        }
        $seconds = 0;
        foreach (explode(':', trim($duration)) as $part) {
            $seconds = $seconds * 60 + (int) $part;
        }
        return $seconds * 1000;
    }

    /** Folge und Stelle merken; $running: laeuft ab jetzt (Startzeitpunkt setzen) oder steht. */
    private function remember(int $episode, int $offset, bool $running): void
    {
        if ($this->user === null) {
            return;
        }
        $this->pdo->prepare('INSERT INTO alexa_positions (user_hash, episode_number, offset_ms, playing_since, updated_at)
                             VALUES (:u, :n, :o, ' . ($running ? 'NOW()' : 'NULL') . ', NOW())
                             ON DUPLICATE KEY UPDATE episode_number = VALUES(episode_number), offset_ms = VALUES(offset_ms),
                                playing_since = VALUES(playing_since), updated_at = NOW()')
            ->execute([':u' => $this->user, ':n' => $episode, ':o' => max(0, $offset)]);
    }

    private function isPlaying(array $req): bool
    {
        return ($req['context']['AudioPlayer']['playerActivity'] ?? '') === 'PLAYING';
    }

    private function fromToken(string $token): ?array
    {
        if (!str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }
        return $this->episodes[(int) substr($token, strlen(self::TOKEN_PREFIX))] ?? null;
    }

    /** Anonyme Alexa-Kennung nur als Pruefwert speichern. */
    private function userHash(array $req): ?string
    {
        $id = $req['context']['System']['user']['userId'] ?? $req['session']['user']['userId'] ?? null;
        return is_string($id) && $id !== '' ? hash_hmac('sha256', 'alexa|' . $id, (string) APP_SECRET) : null;
    }

    /** @return array{episode:int, offset:int}|null */
    private function savedPosition(string $user): ?array
    {
        $stmt = $this->pdo->prepare('SELECT episode_number, offset_ms FROM alexa_positions WHERE user_hash = :u');
        $stmt->execute([':u' => $user]);
        $row = $stmt->fetch();
        if (!$row || !isset($this->episodes[(int) $row['episode_number']]) || (int) $row['offset_ms'] < self::MIN_RESUME_MS) {
            return null;
        }
        return ['episode' => (int) $row['episode_number'], 'offset' => (int) $row['offset_ms']];
    }

    public static function spokenTime(int $ms): string
    {
        $minutes = intdiv($ms, 60000);
        if ($minutes < 1) {
            return 'am Anfang';
        }
        if ($minutes < 60) {
            return 'bei Minute ' . $minutes;
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return 'bei ' . $h . ($h === 1 ? ' Stunde' : ' Stunden') . ($m > 0 ? ' und ' . $m . ($m === 1 ? ' Minute' : ' Minuten') : '');
    }

    /** "Donnerstag, 2. Oktober" - ohne intl (fehlt auf Strato). */
    public static function spokenDate(string $date): string
    {
        $t = strtotime($date);
        $days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        $months = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        return $days[(int) date('w', $t)] . ', ' . (int) date('j', $t) . '. ' . $months[(int) date('n', $t)];
    }

    /** "19 Uhr" bzw. "19 Uhr 30". */
    public static function spokenClock(string $time): string
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);
        return $h . ' Uhr' . ($m > 0 ? ' ' . $m : '');
    }

    // -------------------------------------------------------------------------------------------
    // Antworten
    // -------------------------------------------------------------------------------------------

    private function play(array $e, int $offsetMs, ?string $speech, string $behavior = 'REPLACE_ALL', ?string $previousToken = null): array
    {
        // Merken, ab wo die Folge startet - Grundlage, falls das Geraet die Stelle nicht meldet.
        // Eingereihte Folgen starten erst spaeter (PlaybackStarted).
        if ($behavior === 'REPLACE_ALL' && $this->user !== null) {
            $this->remember((int) $e['number'], $offsetMs, true);
        }
        $stream = ['url' => $e['url'], 'token' => self::TOKEN_PREFIX . $e['number'], 'offsetInMilliseconds' => max(0, $offsetMs)];
        if ($previousToken !== null) {
            $stream['expectedPreviousToken'] = $previousToken;
        }
        $response = [
            'directives' => [[
                'type' => 'AudioPlayer.Play',
                'playBehavior' => $behavior,
                'audioItem' => [
                    'stream' => $stream,
                    'metadata' => [
                        'title' => 'Episode ' . $e['number'] . ($e['name'] !== '' ? ': ' . $e['name'] : ''),
                        'subtitle' => self::SUBTITLE,
                        'art' => ['sources' => [['url' => self::COVER]]],
                    ],
                ],
            ]],
            'shouldEndSession' => true,
        ];
        if ($speech !== null) {
            $response['outputSpeech'] = ['type' => 'PlainText', 'text' => $speech];
        }
        return ['version' => '1.0', 'response' => $response];
    }

    /** $offer: was Alexa gerade angeboten hat - die naechste Antwort ("ja", "weiter") bezieht sich darauf. */
    private static function ask(string $text, string $reprompt, ?string $offer = null): array
    {
        $out = ['version' => '1.0', 'response' => [
            'outputSpeech' => ['type' => 'PlainText', 'text' => $text],
            'reprompt' => ['outputSpeech' => ['type' => 'PlainText', 'text' => $reprompt]],
            'shouldEndSession' => false,
        ]];
        if ($offer !== null) {
            $out['sessionAttributes'] = ['angebot' => $offer];
        }
        return $out;
    }

    /** Sagen und fertig. $end = false: Sitzung nicht beenden-Kennzeichen weglassen (Wiedergabe laeuft weiter). */
    private static function tell(string $text, bool $end = true): array
    {
        $response = ['outputSpeech' => ['type' => 'PlainText', 'text' => $text]];
        if ($end) {
            $response['shouldEndSession'] = true;
        }
        return ['version' => '1.0', 'response' => $response];
    }

    /** Die letzten 20 Fehlermeldungen von Amazon, ohne Nutzerkennung (cron/*.json ist von aussen gesperrt). */
    public static function logProblem(array $req): void
    {
        $file = dirname(__DIR__, 2) . '/cron/alexa-meldungen.json';
        $list = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $list[] = [
            'zeit' => date('Y-m-d H:i:s'),
            'typ' => $req['request']['type'] ?? null,
            'grund' => $req['request']['reason'] ?? null,
            'fehler' => $req['request']['error'] ?? null,
            'anfrage' => $req['request']['cause'] ?? null,
        ];
        @file_put_contents($file, json_encode(array_slice($list, -20), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private static function empty(): array
    {
        return ['version' => '1.0', 'response' => new \stdClass()];
    }
}
