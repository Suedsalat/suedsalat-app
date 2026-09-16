<?php
declare(strict_types=1);

// TEMPORÄRE Seite nur für die Google-Play-Testphase - zeigt, wo die Tester in
// der App geklickt haben, für einen frei wählbaren Zeitraum. Bewusst als
// eigene, separate Datei statt in dashboard.php/statistics.php eingebaut,
// damit sie nach der Testphase einfach wieder gelöscht werden kann, ohne an
// den bestehenden Statistik-Seiten etwas rückgängig machen zu müssen.

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

// Nur fuer den Owner (Thorsten) gedacht - genau wie beim Newsletter (siehe
// admin/newsletter.php) wird der direkte Aufruf hier auch serverseitig
// abgeblockt, nicht nur ueber die fehlende Nav-Verlinkung.
$currentAdminRole = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
$currentAdminRole->execute([':id' => $adminId]);
if ($currentAdminRole->fetchColumn() !== 'owner') {
    header('Location: ' . BASE_PATH . '/admin/dashboard.php');
    exit;
}

$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$conditions = [];
$params = [];
if ($dateFrom !== '') {
    $conditions[] = 'day >= :day_from';
    $params[':day_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = 'day <= :day_to';
    $params[':day_to'] = $dateTo;
}
$whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
// Fuer den LEFT JOIN unten als ON-Bedingung statt WHERE gebraucht - sonst
// wuerden Datums-gefilterte Folgen ohne Wiedergabe im Zeitraum durch die
// WHERE-Klausel komplett rausfallen statt mit 0 angezeigt zu werden.
$joinConditionSql = $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);

$screenLabels = [
    'start' => 'Start',
    'episodes' => 'Folgen',
    'events' => 'Veranstaltungen',
    'movie_tips' => 'Filmtipps',
    'location_tips' => 'Locationtipps',
    'gallery' => 'Galerie',
    'feedback' => 'Feedback',
];

$stmt = $pdo->prepare("SELECT screen, SUM(count) AS total FROM screen_views$whereSql GROUP BY screen");
$stmt->execute($params);
$views = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $pdo->prepare(
    "SELECT e.title, COALESCE(SUM(c.count), 0) AS total_plays
     FROM episodes_cache e
     LEFT JOIN episode_play_counts c ON c.episode_guid = e.guid$joinConditionSql
     GROUP BY e.guid, e.title
     ORDER BY total_plays DESC, e.pub_date DESC"
);
$stmt->execute($params);
$episodePlays = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Testphase-Übersicht – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<?php
// Eigene, kurze Sidebar statt der gemeinsamen partials/sidebar-open.php -
// diese Seite ist bewusst nirgends sonst verlinkt (siehe Kommentar oben),
// die gemeinsame Sidebar wuerde den Testphase-Link auf jeder Admin-Seite
// zeigen, was genau der ungewollten Sichtbarkeit entspraeche.
?>
<div class="admin-shell">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle">
        <span class="bars"><span></span><span></span><span></span></span>
        Menü
    </button>
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
    <nav class="admin-sidebar" id="admin-sidebar">
        <div class="sidebar-brand">
            <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Südsalat">
            <span>APP-Administrationsbereich</span>
        </div>
        <a href="<?= BASE_PATH ?>/admin/dashboard.php">Dashboard</a>
        <a href="<?= BASE_PATH ?>/admin/statistics.php">Statistiken</a>
        <a class="is-active" href="<?= BASE_PATH ?>/admin/testphase.php">Testphase</a>
        <a href="<?= BASE_PATH ?>/admin/logout.php">Abmelden (<span id="logout-countdown" data-timeout-seconds="<?= ADMIN_IDLE_TIMEOUT_MINUTES * 60 ?>"></span>)</a>
    </nav>
    <div class="admin-main">
<main class="content-box">
    <h1>Testphase-Übersicht <span style="font-weight:normal;font-size:0.85rem;">(anonym, ohne Personenbezug)</span></h1>
    <p style="font-size:0.85rem;color:#666;">Temporäre Seite nur für die Google-Play-Testphase - kann danach wieder gelöscht werden.</p>

    <form method="get" class="statistics-filter">
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
            <a class="button button-secondary" style="margin-bottom:0;" href="<?= BASE_PATH ?>/admin/testphase.php">Zeitraum zurücksetzen</a>
        </div>
    </form>

    <h2>Wo geklickt wurde</h2>
    <div class="table-scroll">
    <table>
        <thead><tr><th>Bereich</th><th>Aufrufe im gewählten Zeitraum</th></tr></thead>
        <tbody>
        <?php foreach ($screenLabels as $key => $label): ?>
            <tr>
                <td><?= htmlspecialchars($label, ENT_QUOTES) ?></td>
                <td><?= (int) ($views[$key] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <h2>Folgen-Wiedergaben</h2>
    <div class="table-scroll">
    <table>
        <thead><tr><th>Folge</th><th>Wiedergaben im gewählten Zeitraum</th></tr></thead>
        <tbody>
        <?php foreach ($episodePlays as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['title'], ENT_QUOTES) ?></td>
                <td><?= (int) $row['total_plays'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</main>
    </div>
</div>
<script src="<?= BASE_PATH ?>/admin/assets/admin-sidebar.js?v=<?= @filemtime(__DIR__ . '/assets/admin-sidebar.js') ?>"></script>
</body>
</html>
