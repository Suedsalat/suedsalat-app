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
    if (empty(CRON_SECRET) || ($_GET['secret'] ?? '') !== CRON_SECRET) {
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
    }
}

echo "Sync abgeschlossen. Neue Folgen: $newCount" . PHP_EOL;
