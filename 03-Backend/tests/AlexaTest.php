<?php
declare(strict_types=1);

/**
 * Alexa-Skill "Südsalat": Echtheitspruefung der Anfragen (mit eigener Test-Zertifizierungsstelle)
 * und die Antworten des Skills ueber den Endpunkt alexa/index.php.
 * Braucht in der .env.test ALEXA_SKILL_ID=amzn1.ask.skill.test-suedsalat und ALEXA_SKIP_SIGNATURE=true
 * (wirkt nur in der Testumgebung) sowie HOMEPAGE_DIR auf den Testordner.
 *
 *   SUEDSALAT_ENV_FILE=D:/Suedsalat-Testumgebung/.env.test php tests/AlexaTest.php
 */

require __DIR__ . '/TestHelpers.php';

use Suedsalat\Alexa\RequestVerifier;
use Suedsalat\Alexa\Skill;
use Suedsalat\Feed;

const SKILL = 'amzn1.ask.skill.test-suedsalat';

if (ALEXA_SKILL_ID !== SKILL || !str_contains(HOMEPAGE_DIR, 'Suedsalat-Testumgebung')) {
    fwrite(STDERR, "Abbruch: ALEXA_SKILL_ID/HOMEPAGE_DIR der Testumgebung fehlen.\n");
    exit(2);
}
resetTables($pdo, ['alexa_positions', 'events', 'movie_tips', 'location_tips']);

// ------------------------------------------------------------------------------------------------
echo "Echtheitspruefung\n";
// ------------------------------------------------------------------------------------------------
$cnf = tempnam(sys_get_temp_dir(), 'cnf');
file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n"
    . "[leaf]\nbasicConstraints=CA:FALSE\nsubjectAltName=DNS:echo-api.amazon.com\n[fremd]\nbasicConstraints=CA:FALSE\nsubjectAltName=DNS:boese.example.org\n");
$opts = static fn (string $section) => ['config' => $cnf, 'digest_alg' => 'sha256', 'x509_extensions' => $section, 'private_key_bits' => 2048];
$makeCa = static function () use ($opts): array {
    $key = openssl_pkey_new($opts('ca'));
    $csr = openssl_csr_new(['commonName' => 'Test CA'], $key, $opts('ca'));
    $cert = openssl_csr_sign($csr, null, $key, 30, $opts('ca'), 1);
    openssl_x509_export($cert, $pem);
    return [$key, $cert, $pem];
};
[$caKey, $caCert, $caPem] = $makeCa();
$caFile = tempnam(sys_get_temp_dir(), 'ca');
file_put_contents($caFile, $caPem);
$makeLeaf = static function (string $section) use ($opts, $caKey, $caCert): array {
    $key = openssl_pkey_new($opts($section));
    $csr = openssl_csr_new(['commonName' => 'echo-api.amazon.com'], $key, $opts($section));
    $cert = openssl_csr_sign($csr, $caCert, $caKey, 30, $opts($section), 2);
    openssl_x509_export($cert, $pem);
    return [$key, $pem];
};
[$leafKey, $leafPem] = $makeLeaf('leaf');
$url = 'https://s3.amazonaws.com/echo.api/echo-api-cert.pem';
$body = json_encode(['version' => '1.0', 'session' => ['application' => ['applicationId' => SKILL]],
    'request' => ['type' => 'LaunchRequest', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')]]);
$sign = static function (string $b, $key): string {
    openssl_sign($b, $sig, $key, OPENSSL_ALGO_SHA256);
    return base64_encode($sig);
};
$verifier = static fn (string $pem, string $ca, ?int $now = null) => new RequestVerifier($ca, static fn () => $pem, $now);

check($verifier($leafPem . $caPem, $caFile)->verify($body, $url, $sign($body, $leafKey), SKILL) === null, 'echte Anfrage wird angenommen');
foreach ([
    'http://s3.amazonaws.com/echo.api/echo-api-cert.pem' => 'http statt https',
    'https://notamazon.com/echo.api/echo-api-cert.pem' => 'fremder Server',
    'https://s3.amazonaws.com/EcHo.aPi/echo-api-cert.pem' => 'Pfad mit falscher Schreibweise',
    'https://s3.amazonaws.com/invalid.path/echo-api-cert.pem' => 'falscher Pfad',
    'https://s3.amazonaws.com/echo.api/../invalid.path/echo-api-cert.pem' => 'Pfad mit ../',
    'https://s3.amazonaws.com:563/echo.api/echo-api-cert.pem' => 'falscher Port',
] as $bad => $what) {
    check(!RequestVerifier::isValidCertUrl($bad), 'Zertifikats-Adresse abgelehnt: ' . $what);
}
check(RequestVerifier::isValidCertUrl('https://S3.AMAZONAWS.COM:443/echo.api/../echo.api/echo-api-cert.pem'), 'erlaubte Schreibweise (Grossbuchstaben, Port 443, ../ innerhalb) angenommen');
$v = $verifier($leafPem . $caPem, $caFile);
check($v->verify(str_replace('LaunchRequest', 'IntentRequest', $body), $url, $sign($body, $leafKey), SKILL) === 'Signatur passt nicht', 'veraenderter Text: abgelehnt');
[$fremdKey, $fremdPem] = $makeLeaf('fremd');
check($verifier($fremdPem . $caPem, $caFile)->verify($body, $url, $sign($body, $fremdKey), SKILL) === 'Zertifikat gehört nicht zu echo-api.amazon.com', 'Zertifikat fuer anderen Namen: abgelehnt');
[, , $andereCa] = $makeCa();
$andereCaFile = tempnam(sys_get_temp_dir(), 'ca2');
file_put_contents($andereCaFile, $andereCa);
check($verifier($leafPem . $caPem, $andereCaFile)->verify($body, $url, $sign($body, $leafKey), SKILL) === 'Zertifikatskette nicht vertrauenswürdig', 'nicht vertrauenswuerdige Zertifizierungsstelle: abgelehnt');
check($verifier($leafPem . $caPem, $caFile, time() + 400)->verify($body, $url, $sign($body, $leafKey), SKILL) === 'Zeitstempel zu alt', 'Anfrage aelter als 150 Sekunden: abgelehnt');
check($verifier($leafPem . $caPem, $caFile, time() + 90 * 86400)->verify($body, $url, $sign($body, $leafKey), SKILL) === 'Zertifikat abgelaufen oder noch nicht gültig', 'abgelaufenes Zertifikat: abgelehnt');
check($v->verify($body, $url, $sign($body, $leafKey), 'amzn1.ask.skill.anderer') === 'Anfrage gilt einem anderen Skill', 'falsche Skill-ID: abgelehnt');
check($v->verify($body, $url, 'kein-base64!!', SKILL) === 'Signatur passt nicht', 'kaputte Signatur: abgelehnt');

// ------------------------------------------------------------------------------------------------
echo "Antworten\n";
// ------------------------------------------------------------------------------------------------
$feed = static function (): string {
    $items = '';
    foreach ([1 => [], 2 => [], 3 => ["00:00 Begrüßung", "10:00 Urlaub", "40:00 Tschüss"]] as $n => $kap) {
        $desc = "Beschreibung {$n}." . ($kap ? "\n\nKapitel:\n" . implode("\n", $kap) : '');
        $items .= "<item><title>Episode {$n}: Titel {$n}</title><description><![CDATA[{$desc}]]></description>"
            . '<pubDate>' . gmdate(DATE_RSS, strtotime("2026-09-0{$n} 18:00 UTC")) . '</pubDate>'
            . "<enclosure url=\"https://www.xn--sdsalat-n2a.eu/episodes/ep{$n}.mp3\" length=\"1\" type=\"audio/mpeg\" />"
            . "<guid>https://www.xn--sdsalat-n2a.eu/episodes/ep{$n}.mp3</guid><itunes:duration>50:00</itunes:duration></item>\n";
    }
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss version=\"2.0\" xmlns:itunes=\"http://www.itunes.com/dtds/podcast-1.0.dtd\"><channel><title>T</title>\n{$items}</channel></rss>\n";
};
file_put_contents(HOMEPAGE_DIR . '/podcast.rss', $feed());

$user = 'amzn1.ask.account.GEHEIM123';
$ask = static function (string $type, array $extra = [], ?array $player = null) use ($user): array {
    $req = ['version' => '1.0',
        'session' => ['application' => ['applicationId' => SKILL], 'user' => ['userId' => $user]],
        'context' => ['System' => ['application' => ['applicationId' => SKILL], 'user' => ['userId' => $user]]],
        'request' => ['type' => $type, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')] + $extra];
    if ($player !== null) {
        $req['context']['AudioPlayer'] = $player;
    }
    $ch = curl_init(APP_URL . '/alexa/');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => json_encode($req),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $r = json_decode((string) curl_exec($ch), true) ?? [];
    curl_close($ch);
    return $r['response'] ?? [];
};
$intent = static fn (string $name, array $slots = [], ?array $player = null) => $ask('IntentRequest', ['intent' => ['name' => $name, 'slots' => $slots]], $player);
$speech = static fn (array $r): string => (string) ($r['outputSpeech']['text'] ?? '');
$stream = static fn (array $r): array => $r['directives'][0]['audioItem']['stream'] ?? [];
$playing = static fn (int $n, int $ms, string $activity = 'PLAYING') => ['token' => 'suedsalat-ep-' . $n, 'offsetInMilliseconds' => $ms, 'playerActivity' => $activity];

$ch = curl_init(APP_URL . '/alexa/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
check(curl_getinfo($ch, CURLINFO_HTTP_CODE) === 405, 'GET wird abgelehnt');
curl_close($ch);

$r = $ask('LaunchRequest');
check(str_contains($speech($r), 'Willkommen bei Südsalat') && str_contains($speech($r), 'Folge 3, „Titel 3“') && ($r['shouldEndSession'] ?? null) === false, 'Begruessung nennt die neueste Folge und wartet auf Antwort');

$r = $intent('NeuesteFolgeIntent');
check(($r['directives'][0]['type'] ?? '') === 'AudioPlayer.Play' && $stream($r)['url'] === 'https://www.xn--sdsalat-n2a.eu/episodes/ep3.mp3'
    && $stream($r)['token'] === 'suedsalat-ep-3' && $stream($r)['offsetInMilliseconds'] === 0, 'neueste Folge: spielt Folge 3 von vorn');
check(($r['directives'][0]['audioItem']['metadata']['title'] ?? '') === 'Episode 3: Titel 3', 'Titel fuer Echo-Geraete mit Bildschirm');
$r = $intent('FolgeSpielenIntent', ['nummer' => ['name' => 'nummer', 'value' => '2']]);
check($stream($r)['token'] === 'suedsalat-ep-2', 'Folge 2 per Nummer');
$r = $intent('FolgeSpielenIntent', ['nummer' => ['name' => 'nummer', 'value' => '99']]);
check(str_contains($speech($r), 'Folge 99 gibt es nicht') && str_contains($speech($r), '1 bis 3') && !isset($r['directives']), 'unbekannte Nummer: freundliche Rueckfrage');
$r = $intent('FolgeSpielenIntent', ['nummer' => ['name' => 'nummer']]);
check(str_contains($speech($r), 'Welche Folge'), 'ohne Nummer: Rueckfrage');

echo "Weiterhoeren\n";
$ask('AudioPlayer.PlaybackStopped', ['token' => 'suedsalat-ep-3', 'offsetInMilliseconds' => 725000]);
$gespeichert = $pdo->query('SELECT * FROM alexa_positions')->fetchAll();
check(count($gespeichert) === 1 && (int) $gespeichert[0]['episode_number'] === 3 && (int) $gespeichert[0]['offset_ms'] === 725000, 'Stelle beim Anhalten gemerkt');
check(!str_contains(json_encode($gespeichert), 'GEHEIM123') && strlen($gespeichert[0]['user_hash']) === 64, 'Alexa-Kennung nur als Pruefwert gespeichert');
$r = $ask('LaunchRequest');
check(str_contains($speech($r), 'Folge 3, „Titel 3“ bei Minute 12 unterbrochen'), 'Begruessung bietet das Weiterhoeren an');
$r = $intent('WeiterhoerenIntent');
check($stream($r)['token'] === 'suedsalat-ep-3' && $stream($r)['offsetInMilliseconds'] === 725000, '„weiter“ nach Tagen: an der gemerkten Stelle');
$r = $intent('NeuesteFolgeIntent');
check($stream($r)['offsetInMilliseconds'] === 725000 && str_contains($speech($r), 'von vorn'), 'angefangene Folge erneut gewaehlt: ab der Stelle, mit Hinweis auf „von vorn“');
$r = $intent('AMAZON.StartOverIntent', [], $playing(3, 725000));
check($stream($r)['offsetInMilliseconds'] === 0, '„von vorn“');
$r = $intent('AMAZON.PauseIntent', [], $playing(3, 900000));
check(($r['directives'][0]['type'] ?? '') === 'AudioPlayer.Stop' && (int) $pdo->query('SELECT offset_ms FROM alexa_positions')->fetchColumn() === 900000, 'Pause haelt an und merkt die Stelle');
$r = $intent('AMAZON.ResumeIntent', [], $playing(3, 900000, 'PAUSED'));
check($stream($r)['offsetInMilliseconds'] === 900000, 'Fortsetzen nach Pause');
$ask('AudioPlayer.PlaybackFinished', ['token' => 'suedsalat-ep-3', 'offsetInMilliseconds' => 3000000]);
check((int) $pdo->query('SELECT COUNT(*) FROM alexa_positions')->fetchColumn() === 0, 'zu Ende gehoert: Stelle vergessen');
$r = $ask('AudioPlayer.PlaybackNearlyFinished', ['token' => 'suedsalat-ep-2', 'offsetInMilliseconds' => 2990000]);
check(($r['directives'][0]['playBehavior'] ?? '') === 'ENQUEUE' && $stream($r)['token'] === 'suedsalat-ep-3'
    && $stream($r)['expectedPreviousToken'] === 'suedsalat-ep-2' && !isset($r['outputSpeech']), 'kurz vor Ende: naechste Folge eingereiht, ohne Ansage');

echo "Kapitel\n";
$r = $intent('AMAZON.NextIntent', [], $playing(3, 65000));
check($stream($r)['offsetInMilliseconds'] === 600000 && $speech($r) === 'Kapitel: Urlaub.', 'weiter: naechstes Kapitel');
$r = $intent('AMAZON.PreviousIntent', [], $playing(3, 602000));
check($stream($r)['offsetInMilliseconds'] === 0, 'zurueck kurz nach Kapitelbeginn: voriges Kapitel');
$r = $intent('AMAZON.PreviousIntent', [], $playing(3, 700000));
check($stream($r)['offsetInMilliseconds'] === 600000, 'zurueck mitten im Kapitel: an dessen Anfang');
$r = $intent('AMAZON.NextIntent', [], $playing(3, 2500000));
check(!isset($r['directives']) && str_contains($speech($r), 'schon die neueste Folge'), 'nach dem letzten Kapitel der neuesten Folge: Hinweis');
$r = $intent('AMAZON.NextIntent', [], $playing(1, 5000));
check($stream($r)['token'] === 'suedsalat-ep-2', 'Folge ohne Kapitel: weiter zur naechsten Folge');
$r = $intent('NaechstesKapitelIntent', [], $playing(1, 5000));
check(str_contains($speech($r), 'keine Kapitel') && !isset($r['directives']), '„naechstes Kapitel“ ohne Kapitel: Hinweis, Folge laeuft weiter');
$r = $intent('KapitelIntent', [], $playing(3, 700000));
check(str_contains($speech($r), 'Kapitel „Urlaub“') && !isset($r['directives']) && !isset($r['shouldEndSession']), '„welches Kapitel laeuft“: Ansage, Wiedergabe laeuft danach weiter');
$r = $ask('PlaybackController.NextCommandIssued', [], $playing(3, 65000));
check($stream($r)['offsetInMilliseconds'] === 600000 && !isset($r['outputSpeech']), 'Taste „weiter“ am Geraet: Kapitel, ohne Ansage');

echo "Neuigkeiten und Rest\n";
$pdo->exec("INSERT INTO events (title, event_date, event_time, created_by, submitted_by_name) VALUES
            ('Altes Fest', DATE_SUB(CURDATE(), INTERVAL 3 DAY), NULL, 1, 'Südsalat'),
            ('Stadtfest', '2030-10-03', '19:30:00', 1, 'Südsalat')");
$pdo->exec("INSERT INTO movie_tips (title, created_by, created_at, submitted_by_name) VALUES ('Alter Film', 1, '2026-01-01', 'Südsalat'), ('Neuer Film', 1, '2026-09-01', 'Südsalat')");
$pdo->exec("INSERT INTO location_tips (name, location, created_by, submitted_by_name) VALUES ('Eisdiele', 'Weilerswist', 1, 'Südsalat')");
$r = $intent('NeuigkeitenIntent');
check(str_contains($speech($r), 'Die nächste Veranstaltung: Stadtfest, Donnerstag, 3. Oktober um 19 Uhr 30.'), 'naechste Veranstaltung mit Wochentag und Uhrzeit');
check(str_contains($speech($r), 'Filmtipp: Neuer Film') && str_contains($speech($r), 'Eisdiele in Weilerswist') && !str_contains($speech($r), 'Altes Fest'), 'neuester Film- und Locationtipp, vergangene Veranstaltung nicht');
$r = $intent('AMAZON.ShuffleOnIntent');
check(str_contains($speech($r), 'kann Südsalat leider nicht'), 'Zufallswiedergabe: freundlich abgelehnt');
$r = $intent('AMAZON.StopIntent');
check($speech($r) === 'Bis bald bei Südsalat!' && ($r['shouldEndSession'] ?? null) === true, 'Stopp im Gespraech: Verabschiedung');
$r = $intent('AMAZON.HelpIntent');
check(str_contains($speech($r), 'welches Kapitel läuft') && ($r['shouldEndSession'] ?? null) === false, 'Hilfe nennt die Befehle');
$r = $ask('SessionEndedRequest');
check($r === [], 'Sitzungsende: leere Antwort');

echo "Hilfsfunktionen\n";
check(Skill::spokenTime(20000) === 'am Anfang' && Skill::spokenTime(725000) === 'bei Minute 12' && Skill::spokenTime(3900000) === 'bei 1 Stunde und 5 Minuten', 'Zeit zum Vorlesen');
check(Feed::parse($feed())['items'][2]['chapters'][1]['title'] === 'Urlaub', 'Kapitel kommen aus der RSS-Datei');

$pdo->exec("UPDATE alexa_positions SET updated_at = DATE_SUB(NOW(), INTERVAL 13 MONTH)");
$ask('AudioPlayer.PlaybackStopped', ['token' => 'suedsalat-ep-2', 'offsetInMilliseconds' => 100000]);
$pdo->exec("INSERT INTO alexa_positions VALUES ('" . str_repeat('a', 64) . "', 1, 50000, DATE_SUB(NOW(), INTERVAL 13 MONTH))");
\Suedsalat\Listener::runMaintenance($pdo);
check((int) $pdo->query('SELECT COUNT(*) FROM alexa_positions')->fetchColumn() === 1, 'Hoerstellen nach 12 Monaten ohne Nutzung geloescht');

finish();
