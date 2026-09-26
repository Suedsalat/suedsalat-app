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
            return self::ask('Willkommen bei Südsalat! Du hast ' . $this->name($e) . ' ' . self::spokenTime($saved['offset'])
                . ' unterbrochen. Sag „mach weiter“, um dort weiterzuhören, oder „neueste Folge“.',
                'Sag „mach weiter“ oder „neueste Folge“.');
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
        return match ($name) {
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
            return self::play($e, $saved['offset'], 'Weiter geht es mit ' . $this->name($e) . ' ' . self::spokenTime($saved['offset'])
                . '. Sag „von vorn“, um neu zu beginnen.');
        }
        return self::play($e, 0, 'Hier ist ' . $this->name($e) . '.');
    }

    private function resume(array $req, ?string $user, bool $withSpeech): array
    {
        [$e, $offset] = $this->playing($req);
        if ($e === null && $user !== null && ($saved = $this->savedPosition($user)) !== null) {
            $e = $this->episodes[$saved['episode']];
            $offset = $saved['offset'];
        }
        if ($e === null) {
            return $withSpeech ? self::ask('Du hast noch nichts angefangen. Sag „spiel die neueste Folge“.', 'Was möchtest du hören?') : self::empty();
        }
        return self::play($e, $offset, null);
    }

    private function stop(array $req, ?string $user): array
    {
        [$e, $offset] = $this->playing($req);
        if ($e !== null && $user !== null) {
            $this->savePosition($user, (int) $e['number'], $offset);
        }
        return ['version' => '1.0', 'response' => ['directives' => [['type' => 'AudioPlayer.Stop']], 'shouldEndSession' => true]];
    }

    private function startOver(array $req, ?string $user): array
    {
        [$e] = $this->playing($req);
        if ($e === null && $user !== null && ($saved = $this->savedPosition($user)) !== null) {
            $e = $this->episodes[$saved['episode']];
        }
        return $e !== null ? self::play($e, 0, null) : self::ask('Welche Folge möchtest du von vorn hören?', 'Sag zum Beispiel „spiel die neueste Folge“.');
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
                return self::play($e, $c['start'] * 1000, $withSpeech ? 'Kapitel: ' . $c['title'] . '.' : null);
            }
        }
        $following = $this->episodes[(int) $e['number'] + 1] ?? null;
        if ($toEpisode && $following !== null) {
            return self::play($following, 0, $withSpeech ? 'Hier ist ' . $this->name($following) . '.' : null);
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
            return self::play($e, $target['start'] * 1000, $withSpeech ? 'Kapitel: ' . $target['title'] . '.' : null);
        }
        $before = $this->episodes[(int) $e['number'] - 1] ?? null;
        if ($toEpisode && $before !== null) {
            return self::play($before, 0, $withSpeech ? 'Hier ist ' . $this->name($before) . '.' : null);
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
            $parts[] = 'Die neueste Folge ist ' . $this->name($latest) . '. Soll ich sie abspielen? Sag „spiel die neueste Folge“.';
        }
        return self::ask(implode(' ', $parts), 'Sag „spiel die neueste Folge“ oder „stopp“.');
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
            case 'AudioPlayer.PlaybackStopped':
                $this->savePosition($user, (int) $e['number'], $offset);
                return self::empty();
            case 'AudioPlayer.PlaybackFinished':
                $this->pdo->prepare('DELETE FROM alexa_positions WHERE user_hash = :u AND episode_number = :n')
                    ->execute([':u' => $user, ':n' => (int) $e['number']]);
                return self::empty();
            case 'AudioPlayer.PlaybackNearlyFinished':
                // Wie beim Nachhoeren am Stueck: danach die naechste Folge einreihen.
                $following = $this->episodes[(int) $e['number'] + 1] ?? null;
                return $following !== null ? self::play($following, 0, null, 'ENQUEUE', $token) : self::empty();
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

    /** @return array{0: ?array, 1: int} laufende (oder zuletzt pausierte) Folge und Stelle in ms */
    private function playing(array $req): array
    {
        $player = $req['context']['AudioPlayer'] ?? [];
        $e = $this->fromToken((string) ($player['token'] ?? ''));
        return [$e, (int) ($player['offsetInMilliseconds'] ?? 0)];
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
        if (!$row || !isset($this->episodes[(int) $row['episode_number']])) {
            return null;
        }
        return ['episode' => (int) $row['episode_number'], 'offset' => (int) $row['offset_ms']];
    }

    private function savePosition(string $user, int $episode, int $offset): void
    {
        // Ganz am Anfang lohnt sich das Merken nicht.
        if ($offset < 30000) {
            $offset = 0;
        }
        $this->pdo->prepare('INSERT INTO alexa_positions (user_hash, episode_number, offset_ms, updated_at) VALUES (:u, :n, :o, NOW())
                             ON DUPLICATE KEY UPDATE episode_number = VALUES(episode_number), offset_ms = VALUES(offset_ms), updated_at = NOW()')
            ->execute([':u' => $user, ':n' => $episode, ':o' => max(0, $offset)]);
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

    private static function play(array $e, int $offsetMs, ?string $speech, string $behavior = 'REPLACE_ALL', ?string $previousToken = null): array
    {
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

    private static function ask(string $text, string $reprompt): array
    {
        return ['version' => '1.0', 'response' => [
            'outputSpeech' => ['type' => 'PlainText', 'text' => $text],
            'reprompt' => ['outputSpeech' => ['type' => 'PlainText', 'text' => $reprompt]],
            'shouldEndSession' => false,
        ]];
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

    private static function empty(): array
    {
        return ['version' => '1.0', 'response' => new \stdClass()];
    }
}
