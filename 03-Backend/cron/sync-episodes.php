<?php
declare(strict_types=1);

// Per Cronjob auf Strato alle 15 Minuten aufrufen, entweder als CLI-Skript
// (php /pfad/zu/03-Backend/cron/sync-episodes.php) oder - falls das
// Strato-Kundenpanel nur URL-Cronjobs anbietet - per HTTP-Aufruf mit Secret:
// https://.../APP/cron/sync-episodes.php?secret=... (siehe CRON_SECRET in .env).
// Liest den RSS-Feed neu ein, aktualisiert episodes_cache und stoesst bei
// neuen Folgen eine Push-Benachrichtigung an (siehe sendPushForNewEpisode()).

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;
use Suedsalat\FcmSender;

// Nur bei HTTP-Aufruf pruefen (CLI-Aufruf, z.B. per SSH-Cronjob, bleibt offen -
// dort kennt ohnehin nur der Server selbst den Aufrufpfad).
if (PHP_SAPI !== 'cli') {
    if (empty(CRON_SECRET) || !hash_equals(CRON_SECRET, (string) ($_GET['secret'] ?? ''))) {
        http_response_code(403);
        die('Forbidden');
    }
}

const RSS_FEED_URL = 'https://www.xn--sdsalat-n2a.eu/podcast.rss';

function fetchRssXml(string $url): ?SimpleXMLElement
{
    $context = stream_context_create(['http' => ['timeout' => 20]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        error_log("RSS-Feed konnte nicht geladen werden: $url");
        return null;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if ($xml === false) {
        error_log('RSS-Feed konnte nicht geparst werden.');
        return null;
    }
    return $xml;
}

function parseDuration(?string $raw): ?string
{
    return $raw !== null ? trim($raw) : null;
}

function sendPushForNewEpisode(string $title): void
{
    FcmSender::sendToAllDevices("Neue Folge: $title", 'Jetzt reinhören!');
}

// Haelt fest, wann fuer eine Folge die "Neue Folge"-Push rausging - Grundlage
// fuer die Push-Wirksamkeits-Auswertung (Wiedergaben kurz danach vs. spaeter).
function recordPushSent(PDO $pdo, string $guid): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO episode_push_sent (episode_guid, sent_at) VALUES (:guid, NOW())
         ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at)'
    );
    $stmt->execute([':guid' => $guid]);
}

$xml = fetchRssXml(RSS_FEED_URL);
if ($xml === null) {
    exit(1);
}

$pdo = Database::connection();
$namespaces = $xml->getNamespaces(true);
$itunes = $namespaces['itunes'] ?? 'http://www.itunes.com/dtds/podcast-1.0.dtd';

$insertStmt = $pdo->prepare(
    'INSERT IGNORE INTO episodes_cache (guid, title, description, audio_url, image_url, duration, pub_date)
     VALUES (:guid, :title, :description, :audio_url, :image_url, :duration, :pub_date)'
);
$existsStmt = $pdo->prepare('SELECT 1 FROM episodes_cache WHERE guid = :guid');

$newCount = 0;

foreach ($xml->channel->item as $item) {
    $guid = trim((string) $item->guid) ?: trim((string) $item->link);
    if ($guid === '') {
        continue;
    }

    $existsStmt->execute([':guid' => $guid]);
    $isNew = $existsStmt->fetchColumn() === false;

    $enclosure = $item->enclosure;
    $audioUrl = $enclosure !== null ? (string) $enclosure['url'] : '';
    if ($audioUrl === '') {
        continue;
    }

    $itunesNs = $item->children($itunes);
    $imageUrl = isset($itunesNs->image) ? (string) $itunesNs->image->attributes()['href'] : null;
    $duration = parseDuration(isset($itunesNs->duration) ? (string) $itunesNs->duration : null);

    $pubDate = strtotime((string) $item->pubDate);
    $pubDateSql = $pubDate !== false ? date('Y-m-d H:i:s', $pubDate) : date('Y-m-d H:i:s');

    $insertStmt->execute([
        ':guid' => $guid,
        ':title' => (string) $item->title,
        ':description' => (string) $item->description ?: null,
        ':audio_url' => $audioUrl,
        ':image_url' => $imageUrl,
        ':duration' => $duration,
        ':pub_date' => $pubDateSql,
    ]);

    if ($isNew && $insertStmt->rowCount() > 0) {
        $newCount++;
        sendPushForNewEpisode((string) $item->title);
        recordPushSent($pdo, $guid);
    }
}

echo "Sync abgeschlossen. Neue Folgen: $newCount" . PHP_EOL;

// Aufraeumen alter Auth-/Rate-Limit-Zeilen, damit diese Tabellen nicht unbegrenzt
// wachsen. Laeuft im selben 15-Min-Cronjob mit, ist aber unabhaengig vom RSS-Sync -
// ein Fehler hier darf den eigentlichen Sync oben nicht ungueltig machen.
try {
    // Rate-Limit-Fenster sind maximal 60 Minuten lang - 1 Tag Aufbewahrung ist reichlich Puffer.
    $pdo->exec('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    // Nur wirklich abgelaufene Refresh-Tokens loeschen, NICHT vorzeitig widerrufene -
    // die werden fuer die Wiederverwendungs-Erkennung (siehe RefreshToken::verifyAndRotate)
    // bis zu ihrem urspruenglichen Ablaufdatum gebraucht.
    $pdo->exec('DELETE FROM refresh_tokens WHERE expires_at < NOW()');
    // Login-Versuche nur fuer das 15-Minuten-Rate-Limit relevant (siehe Auth::isRateLimited) -
    // 30 Tage Aufbewahrung als grosszuegiger Puffer fuer eine manuelle Nachschau.
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 30 DAY)');
    // Loeschfristen aus der Datenschutzerklaerung (seiten/datenschutz.html, Abschnitt 2):
    // App-Installationen, die ein Jahr nicht mehr benutzt wurden, verlieren ihre Kennung.
    // last_seen_at wird bei jeder Token-Erneuerung aktualisiert (api/auth/refresh.php),
    // aktive Installationen sind also nie betroffen. Rezensionen bleiben erhalten,
    // tip_reviews.device_id wird per ON DELETE SET NULL nur entkoppelt.
    $pdo->exec('DELETE FROM devices WHERE COALESCE(last_seen_at, created_at) < (NOW() - INTERVAL 12 MONTH)');
    // Pseudonyme Zaehlwerte fuer "eindeutige Hoerer" nach 14 Monaten entfernen.
    $pdo->exec('DELETE FROM episode_unique_devices WHERE day < (CURDATE() - INTERVAL 14 MONTH)');
    echo 'Aufraeumen abgeschlossen.' . PHP_EOL;
} catch (\Throwable $e) {
    error_log('Cleanup alter Auth-Zeilen fehlgeschlagen: ' . $e->getMessage());
}

// Hoererkonten (App 2.0): Erinnerung drei Tage vor Ende der Rueckkehrfrist, endgueltige Loeschung
// danach, alte Anmelde-Codes wegraeumen. Eigener try-Block - ein Fehler hier darf weder den
// RSS-Sync noch das Aufraeumen oben beeinflussen.
try {
    echo \Suedsalat\Listener::runMaintenance($pdo) . PHP_EOL;
} catch (Throwable $e) {
    error_log('Pflege der Hoererkonten fehlgeschlagen: ' . $e->getMessage());
}
