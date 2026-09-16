<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$currentAdminRole = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
$currentAdminRole->execute([':id' => $adminId]);
$isOwner = $currentAdminRole->fetchColumn() === 'owner';

// Alle Folgen fuer den Filter-Dropdown, neueste zuerst.
$allEpisodes = $pdo->query('SELECT guid, title, pub_date FROM episodes_cache ORDER BY pub_date DESC')->fetchAll();

$views = [
    'funnel' => 'Hördauer-Trichter',
    'hourly' => 'Tageszeit-Verteilung',
    'weekday' => 'Wochentag-Verteilung',
    'platform' => 'Plattform-Verteilung',
    'reach' => 'Reichweite vs. Wiedergaben',
    'completion' => 'Abschlussquote je Folge',
    'growth' => 'Wachstumstrend gesamt',
    'push' => 'Push-Wirksamkeit',
    'content' => 'Meistgehörte Folgen & ausgelöste Inhalte',
    'car' => 'Android Auto / CarPlay',
];

$view = $_GET['view'] ?? 'funnel';
if (!isset($views[$view])) {
    $view = 'funnel';
}
$selectedEpisode = trim((string) ($_GET['episode'] ?? ''));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));

// Nur echte Datumswerte durchlassen, sonst leer (= kein Filter in diese Richtung).
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

// Baut ein "AND day BETWEEN ... AND ..."-Fragment + zugehoerige Parameter,
// je nachdem welche der beiden Grenzen gesetzt sind. Wird an mehreren Stellen
// wiederverwendet (funnel/hourly/platform/growth/reach/content).
function buildDayFilter(string $column, string $from, string $to): array
{
    $conditions = [];
    $params = [];
    if ($from !== '') {
        $conditions[] = "$column >= :day_from";
        $params[':day_from'] = $from;
    }
    if ($to !== '') {
        $conditions[] = "$column <= :day_to";
        $params[':day_to'] = $to;
    }
    return [$conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions), $params];
}

[$dayFilterSql, $dayFilterParams] = buildDayFilter('day', $dateFrom, $dateTo);

$episodeFilterSql = $selectedEpisode !== '' ? ' AND episode_guid = :episode_guid' : '';
$episodeFilterParams = $selectedEpisode !== '' ? [':episode_guid' => $selectedEpisode] : [];

$tierLabels = [
    'start' => 'Gestartet',
    '5min' => 'Länger als 5 Minuten',
    '15min' => 'Länger als 15 Minuten',
    '25min' => 'Länger als 25 Minuten',
    '35min' => 'Länger als 35 Minuten',
    '45min' => 'Länger als 45 Minuten',
    'end' => 'Bis zum Ende',
];

$funnelRows = [];
$hourlyRows = [];
$weekdayRows = [];
$platformRows = [];
$reachRows = [];
$completionRows = [];
$growthRows = [];
$pushRows = [];
$contentRows = [];
$carContextRows = [];

if ($view === 'funnel') {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(count), 0) FROM episode_play_counts WHERE 1=1 $episodeFilterSql $dayFilterSql"
    );
    $stmt->execute([...$episodeFilterParams, ...$dayFilterParams]);
    $funnelRows['start'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT tier, SUM(count) AS total FROM episode_play_milestones WHERE 1=1 $episodeFilterSql $dayFilterSql GROUP BY tier"
    );
    $stmt->execute([...$episodeFilterParams, ...$dayFilterParams]);
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $tier => $total) {
        $funnelRows[$tier] = (int) $total;
    }
} elseif ($view === 'hourly') {
    $stmt = $pdo->prepare(
        "SELECT hour, SUM(count) AS total FROM episode_play_counts WHERE hour >= 0 $episodeFilterSql $dayFilterSql GROUP BY hour ORDER BY hour"
    );
    $stmt->execute([...$episodeFilterParams, ...$dayFilterParams]);
    $hourlyRows = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(count), 0) FROM episode_play_counts WHERE hour = -1 $episodeFilterSql $dayFilterSql"
    );
    $stmt->execute([...$episodeFilterParams, ...$dayFilterParams]);
    $hourlyUnknownCount = (int) $stmt->fetchColumn();
} elseif ($view === 'weekday') {
    // WEEKDAY() liefert 0=Montag..6=Sonntag - passt zur in Deutschland ueblichen
    // Wochenansicht (Woche beginnt montags), siehe auch das "growth"-Wochen-Grouping unten.
    $stmt = $pdo->prepare(
        "SELECT WEEKDAY(day) AS weekday, SUM(count) AS total FROM episode_play_counts
         WHERE 1=1 $episodeFilterSql $dayFilterSql GROUP BY weekday ORDER BY weekday"
    );
    $stmt->execute([...$episodeFilterParams, ...$dayFilterParams]);
    $weekdayRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} elseif ($view === 'platform') {
    $stmt = $pdo->prepare(
        "SELECT platform, SUM(count) AS total FROM episode_play_platform WHERE 1=1 $episodeFilterSql $dayFilterSql GROUP BY platform"
    );
    $stmt->execute([...$episodeFilterParams, ...$dayFilterParams]);
    $platformRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} elseif ($view === 'reach') {
    // $dayFilterSql nutzt bewusst den unqualifizierten Spaltennamen "day" - passt
    // hier in beiden Unterabfragen, da jede davon nur genau eine Tabelle
    // referenziert (episode_play_counts bzw. episode_unique_devices), also keine
    // Mehrdeutigkeit entsteht. PDO bindet :day_from/:day_to korrekt, auch wenn der
    // Platzhalter mehrfach im selben Query-Text vorkommt.
    $sql = "SELECT e.guid, e.title,
                COALESCE((SELECT SUM(pc.count) FROM episode_play_counts pc WHERE pc.episode_guid = e.guid $dayFilterSql), 0) AS total_plays,
                (SELECT COUNT(*) FROM episode_unique_devices ud WHERE ud.episode_guid = e.guid $dayFilterSql) AS unique_device_days
            FROM episodes_cache e";
    if ($selectedEpisode !== '') {
        $sql .= ' WHERE e.guid = :episode_guid';
    }
    $sql .= ' ORDER BY total_plays DESC, e.pub_date DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$dayFilterParams, ...$episodeFilterParams]);
    $reachRows = $stmt->fetchAll();
} elseif ($view === 'completion') {
    $sql = "SELECT e.guid, e.title, e.duration,
                COALESCE((SELECT SUM(pc.count) FROM episode_play_counts pc WHERE pc.episode_guid = e.guid $dayFilterSql), 0) AS started,
                COALESCE((SELECT SUM(m.count) FROM episode_play_milestones m WHERE m.episode_guid = e.guid AND m.tier = 'end' $dayFilterSql), 0) AS finished
            FROM episodes_cache e";
    if ($selectedEpisode !== '') {
        $sql .= ' WHERE e.guid = :episode_guid';
    }
    $sql .= ' HAVING started > 0 ORDER BY (finished / started) DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$dayFilterParams, ...$episodeFilterParams]);
    $completionRows = $stmt->fetchAll();
} elseif ($view === 'growth') {
    $stmt = $pdo->prepare(
        "SELECT DATE_SUB(day, INTERVAL WEEKDAY(day) DAY) AS week_start, SUM(count) AS total
         FROM episode_play_counts WHERE 1=1 $episodeFilterSql $dayFilterSql
         GROUP BY week_start ORDER BY week_start ASC"
    );
    $stmt->execute([...$episodeFilterParams, ...$dayFilterParams]);
    $growthRows = $stmt->fetchAll();
} elseif ($view === 'push') {
    $sql = "SELECT e.guid, e.title, ps.sent_at,
                COALESCE((SELECT SUM(pc.count) FROM episode_play_counts pc
                    WHERE pc.episode_guid = e.guid AND pc.day BETWEEN DATE(ps.sent_at) AND DATE(ps.sent_at) + INTERVAL 2 DAY), 0) AS plays_within_3_days,
                COALESCE((SELECT SUM(pc.count) FROM episode_play_counts pc
                    WHERE pc.episode_guid = e.guid AND pc.day > DATE(ps.sent_at) + INTERVAL 2 DAY), 0) AS plays_later
            FROM episode_push_sent ps
            JOIN episodes_cache e ON e.guid = ps.episode_guid";
    if ($selectedEpisode !== '') {
        $sql .= ' WHERE e.guid = :episode_guid';
    }
    $sql .= ' ORDER BY ps.sent_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($episodeFilterParams);
    $pushRows = $stmt->fetchAll();
} elseif ($view === 'content') {
    $sql = "SELECT e.guid, e.title,
                COALESCE((SELECT SUM(pc.count) FROM episode_play_counts pc WHERE pc.episode_guid = e.guid $dayFilterSql), 0) AS total_plays,
                (SELECT COUNT(*) FROM feedback_messages WHERE episode_guid = e.guid) AS feedback_count,
                (SELECT COUNT(*) FROM movie_tips WHERE episode_guid = e.guid)
                    + (SELECT COUNT(*) FROM location_tips WHERE episode_guid = e.guid)
                    + (SELECT COUNT(*) FROM events WHERE episode_guid = e.guid) AS related_content_count
            FROM episodes_cache e";
    if ($selectedEpisode !== '') {
        $sql .= ' WHERE e.guid = :episode_guid';
    }
    $sql .= ' ORDER BY total_plays DESC, e.pub_date DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$dayFilterParams, ...$episodeFilterParams]);
    $contentRows = $stmt->fetchAll();
} elseif ($view === 'car') {
    $stmt = $pdo->prepare(
        "SELECT context, SUM(count) AS total FROM episode_play_car_context
         WHERE 1=1 $episodeFilterSql $dayFilterSql GROUP BY context"
    );
    $stmt->execute([...$episodeFilterParams, ...$dayFilterParams]);
    $carContextRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$episodeShortLabel = static function (string $title): string {
    return preg_match('/^(Episode\s+\d+)/i', $title, $matches) ? $matches[1] : $title;
};
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Statistiken – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Statistiken <span style="font-weight:normal;font-size:0.85rem;">(anonym, ohne Personenbezug)</span></h1>

    <form method="get" class="statistics-filter">
        <div class="field-row">
            <label>Auswertung
                <select name="view">
                    <?php foreach ($views as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>" <?= $view === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Folge
                <select name="episode">
                    <option value="">Alle Folgen</option>
                    <?php foreach ($allEpisodes as $episode): ?>
                        <option value="<?= htmlspecialchars($episode['guid'], ENT_QUOTES) ?>" <?= $selectedEpisode === $episode['guid'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($episodeShortLabel($episode['title']), ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="field-row">
            <label>Von
                <input type="date" name="from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES) ?>">
            </label>
            <label>Bis
                <input type="date" name="to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES) ?>">
            </label>
        </div>
        <div class="button-row">
            <button type="submit">Anzeigen</button>
            <a class="button button-secondary" style="margin-bottom:0;" href="<?= BASE_PATH ?>/admin/statistics.php">Filter zurücksetzen</a>
        </div>
    </form>

    <?php if ($view === 'funnel'): ?>
        <h2>Hördauer-Trichter</h2>
        <p style="font-size:0.85rem;color:#666;">Jede Stufe zählt automatisch alle mit, die auch die höheren Stufen erreicht haben (z. B. sind alle "über 45 Minuten"-Hörer auch in "über 5 Minuten" enthalten) - so lässt sich der Abfall zwischen den Stufen ablesen.</p>

        <?php
            $startTotal = $funnelRows['start'] ?? 0;
            $tierKeys = array_keys($tierLabels);
            $stageCount = count($tierKeys);
            // Balkenhoehe je Stufe relativ zur groessten Stufe (= "Gestartet"),
            // nicht absolut - sonst waere der Trichter bei wenigen Wiedergaben
            // insgesamt nur eine duenne Linie statt die volle Zeichenflaeche
            // auszunutzen.
            $funnelMaxHeight = 150;
            $funnelCenterY = 90;
            $funnelSegmentWidth = 100;
            $funnelHeights = [];
            foreach ($tierKeys as $tier) {
                $count = $funnelRows[$tier] ?? 0;
                $funnelHeights[] = $startTotal > 0 ? ($count / $startTotal) * $funnelMaxHeight : 0;
            }
        ?>
        <div class="funnel-scroll">
        <div class="funnel-chart">
            <svg viewBox="0 0 <?= $stageCount * $funnelSegmentWidth ?> 180" preserveAspectRatio="none" class="funnel-svg" role="img" aria-label="Hördauer-Trichter, von links (Gestartet) nach rechts (Bis zum Ende) abnehmend">
                <?php foreach ($tierKeys as $i => $tier): ?>
                    <?php
                        $x0 = $i * $funnelSegmentWidth;
                        $x1 = $x0 + $funnelSegmentWidth;
                        $leftHalf = $funnelHeights[$i] / 2;
                        $rightHalf = ($i + 1 < $stageCount ? $funnelHeights[$i + 1] : $funnelHeights[$i]) / 2;
                        $points = sprintf(
                            '%d,%.1f %d,%.1f %d,%.1f %d,%.1f',
                            $x0, $funnelCenterY - $leftHalf,
                            $x1, $funnelCenterY - $rightHalf,
                            $x1, $funnelCenterY + $rightHalf,
                            $x0, $funnelCenterY + $leftHalf
                        );
                    ?>
                    <polygon points="<?= $points ?>" class="funnel-segment" style="--funnel-step: <?= $i + 1 ?>;"></polygon>
                <?php endforeach; ?>
            </svg>
            <div class="funnel-labels" style="grid-template-columns: repeat(<?= $stageCount ?>, 1fr);">
                <?php foreach ($tierKeys as $i => $tier): ?>
                    <?php $count = $funnelRows[$tier] ?? 0; ?>
                    <div class="funnel-label">
                        <strong><?= htmlspecialchars($tierLabels[$tier], ENT_QUOTES) ?></strong>
                        <span><?= $count ?></span>
                        <small><?= $startTotal > 0 ? number_format($count / $startTotal * 100, 1, ',', '.') . ' %' : '—' ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        </div>

        <div class="table-scroll">
        <table>
            <thead><tr><th>Stufe</th><th>Anzahl</th><th>Anteil an "Gestartet"</th></tr></thead>
            <tbody>
            <?php foreach ($tierLabels as $tier => $label): ?>
                <?php $count = $funnelRows[$tier] ?? 0; ?>
                <tr>
                    <td><?= htmlspecialchars($label, ENT_QUOTES) ?></td>
                    <td><?= $count ?></td>
                    <td><?= $startTotal > 0 ? number_format($count / $startTotal * 100, 1, ',', '.') . ' %' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

    <?php elseif ($view === 'hourly'): ?>
        <h2>Tageszeit-Verteilung</h2>
        <?php if ($hourlyUnknownCount > 0): ?>
            <p style="font-size:0.85rem;color:#666;"><?= $hourlyUnknownCount ?> Wiedergabe(n) ohne erfasste Uhrzeit (vor Einführung dieser Auswertung) sind hier nicht enthalten.</p>
        <?php endif; ?>
        <?php if (empty($hourlyRows)): ?>
            <p>Noch keine Daten mit erfasster Uhrzeit vorhanden.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Uhrzeit</th><th>Wiedergaben</th></tr></thead>
            <tbody>
            <?php foreach ($hourlyRows as $row): ?>
                <tr>
                    <td><?= sprintf('%02d:00–%02d:59', (int) $row['hour'], (int) $row['hour']) ?></td>
                    <td><?= (int) $row['total'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'weekday'): ?>
        <h2>Wochentag-Verteilung</h2>
        <?php if (empty($weekdayRows)): ?>
            <p>Noch keine Daten vorhanden.</p>
        <?php else: ?>
        <?php $weekdayLabels = [0 => 'Montag', 1 => 'Dienstag', 2 => 'Mittwoch', 3 => 'Donnerstag', 4 => 'Freitag', 5 => 'Samstag', 6 => 'Sonntag']; ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Wochentag</th><th>Wiedergaben</th></tr></thead>
            <tbody>
            <?php foreach ($weekdayLabels as $key => $label): ?>
                <tr>
                    <td><?= $label ?></td>
                    <td><?= (int) ($weekdayRows[$key] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'platform'): ?>
        <h2>Plattform-Verteilung</h2>
        <?php if (empty($platformRows)): ?>
            <p>Noch keine Daten vorhanden.</p>
        <?php else: ?>
        <?php $platformTotal = array_sum($platformRows); ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Plattform</th><th>Wiedergaben</th><th>Anteil</th></tr></thead>
            <tbody>
            <?php foreach (['ios' => 'iOS', 'android' => 'Android'] as $key => $label): ?>
                <?php $count = $platformRows[$key] ?? 0; ?>
                <tr>
                    <td><?= $label ?></td>
                    <td><?= $count ?></td>
                    <td><?= $platformTotal > 0 ? number_format($count / $platformTotal * 100, 1, ',', '.') . ' %' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'reach'): ?>
        <h2>Reichweite vs. Wiedergaben</h2>
        <p style="font-size:0.85rem;color:#666;">"Eindeutige Geräte" zählt an wie vielen Tagen jeweils unterschiedliche Geräte eine Folge gehört haben (Tag für Tag, nicht folgenübergreifend zusammengeführt) - so verzerrt ein einzelner Vielhörer die Zahl weniger stark als bei reinen Wiedergaben.</p>
        <?php if (empty($reachRows)): ?>
            <p>Noch keine Folgen im Cache.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Folge</th><th>Wiedergaben gesamt</th><th>Eindeutige Geräte (Tage summiert)</th></tr></thead>
            <tbody>
            <?php foreach ($reachRows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['title'], ENT_QUOTES) ?></td>
                    <td><?= (int) $row['total_plays'] ?></td>
                    <td><?= (int) $row['unique_device_days'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'completion'): ?>
        <h2>Abschlussquote je Folge</h2>
        <p style="font-size:0.85rem;color:#666;">Vergleicht alle Folgen danach, wie viel Prozent der Starter auch bis zum Ende dranbleiben - hilft z. B. zu sehen, ob kürzere Folgen eher komplett gehört werden als längere (Spalte "Länge").</p>
        <?php if (empty($completionRows)): ?>
            <p>Noch keine Wiedergaben erfasst.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Folge</th><th>Länge</th><th>Gestartet</th><th>Bis zum Ende</th><th>Abschlussquote</th></tr></thead>
            <tbody>
            <?php foreach ($completionRows as $row): ?>
                <?php $rate = $row['started'] > 0 ? $row['finished'] / $row['started'] * 100 : 0; ?>
                <tr>
                    <td><?= htmlspecialchars($row['title'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($row['duration'] ?? '—', ENT_QUOTES) ?></td>
                    <td><?= (int) $row['started'] ?></td>
                    <td><?= (int) $row['finished'] ?></td>
                    <td><?= number_format($rate, 1, ',', '.') ?> %</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'growth'): ?>
        <h2>Wachstumstrend gesamt</h2>
        <p style="font-size:0.85rem;color:#666;">Wiedergaben aller Folgen zusammen, gruppiert pro Woche (Wochenbeginn Montag).</p>
        <?php if (empty($growthRows)): ?>
            <p>Noch keine Daten vorhanden.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Woche ab</th><th>Wiedergaben</th></tr></thead>
            <tbody>
            <?php foreach ($growthRows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars(date('d.m.Y', strtotime($row['week_start'])), ENT_QUOTES) ?></td>
                    <td><?= (int) $row['total'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'push'): ?>
        <h2>Push-Wirksamkeit</h2>
        <p style="font-size:0.85rem;color:#666;">Vergleicht Wiedergaben in den ersten 3 Tagen nach der "Neue Folge"-Push-Benachrichtigung mit Wiedergaben danach. Nur Folgen, für die eine Push tatsächlich verschickt wurde, tauchen hier auf.</p>
        <?php if (empty($pushRows)): ?>
            <p>Noch keine Push-Benachrichtigung protokolliert.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Folge</th><th>Push verschickt</th><th>Wiedergaben ersten 3 Tage</th><th>Wiedergaben danach</th></tr></thead>
            <tbody>
            <?php foreach ($pushRows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['title'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($row['sent_at'])), ENT_QUOTES) ?></td>
                    <td><?= (int) $row['plays_within_3_days'] ?></td>
                    <td><?= (int) $row['plays_later'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'content'): ?>
        <h2>Meistgehörte Folgen &amp; ausgelöste Inhalte</h2>
        <p style="font-size:0.85rem;color:#666;">"Feedback" zählt Nutzer-Feedback, das explizit dieser Folge zugeordnet wurde. "Ausgelöste Inhalte" zählt zusätzlich Filmtipps/Locationtipps/Veranstaltungen, die auf diese Folge verweisen.</p>
        <?php if (empty($contentRows)): ?>
            <p>Noch keine Folgen im Cache.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Folge</th><th>Wiedergaben</th><th>Feedback</th><th>Ausgelöste Inhalte</th></tr></thead>
            <tbody>
            <?php foreach ($contentRows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['title'], ENT_QUOTES) ?></td>
                    <td><?= (int) $row['total_plays'] ?></td>
                    <td><?= (int) $row['feedback_count'] ?></td>
                    <td><?= (int) $row['related_content_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'car'): ?>
        <h2>Android Auto / CarPlay</h2>
        <p style="font-size:0.85rem;color:#666;">Zählt Wiedergaben, die laut App über Android Auto bzw. CarPlay liefen (erkannt über den aktiven Auto-Modus bzw. die Audio-Ausgabe-Route) - rein informativ, ohne Geräte-/Fahrzeugbezug. Ältere App-Versionen ohne diese Erkennung tauchen hier gar nicht auf.</p>
        <?php if (empty($carContextRows)): ?>
            <p>Noch keine Daten vorhanden.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Kontext</th><th>Wiedergaben</th></tr></thead>
            <tbody>
            <?php foreach (['android_auto' => 'Android Auto', 'carplay' => 'CarPlay'] as $key => $label): ?>
                <tr>
                    <td><?= $label ?></td>
                    <td><?= (int) ($carContextRows[$key] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/sidebar-close.php'; ?>
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/scroll-restore.js?v=<?= @filemtime(__DIR__ . '/assets/scroll-restore.js') ?>"></script>
</body>
</html>
