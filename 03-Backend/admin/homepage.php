<?php
declare(strict_types=1);

// Homepage aus der RSS-Datei (lib/Homepage.php): Stand, "Jetzt aktualisieren" und eine Uebersicht,
// welche Folge einzeln bzw. in welchem Archiv steht und welcher Kurztext dort erscheint.
// Nur fuer den Owner (Thorsten) - er pflegt RSS-Datei und Homepage.

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;
use Suedsalat\Homepage;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$currentAdminRole = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
$currentAdminRole->execute([':id' => $adminId]);
$isOwner = $currentAdminRole->fetchColumn() === 'owner';
if (!$isOwner) {
    header('Location: ' . BASE_PATH . '/admin/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $result = Homepage::run($pdo);
    header('Location: ' . BASE_PATH . '/admin/homepage.php?ergebnis=' . urlencode($result['status']));
    exit;
}

$dir = Homepage::directory();
$status = Homepage::lastStatus();
$episodes = [];
$feedError = null;
if ($dir !== null) {
    try {
        $rss = @file_get_contents($dir . '/podcast.rss');
        $episodes = $rss !== false ? Homepage::parseFeed($rss) : [];
    } catch (\RuntimeException $e) {
        $feedError = $e->getMessage();
    }
}
$ranges = $episodes !== [] ? Homepage::archiveRanges(max(array_column($episodes, 'number'))) : [];
$placeOf = static function (int $n) use ($ranges): string {
    foreach ($ranges as $i => $r) {
        if ($n >= $r['from'] && $n <= $r['to']) {
            return 'Archiv ' . (count($ranges) - $i) . ' (' . $r['from'] . '–' . $r['to'] . ')';
        }
    }
    return 'einzeln oben';
};
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$ergebnis = [
    'updated' => ['info', 'Homepage wurde neu erzeugt.'],
    'unchanged' => ['info', 'Nichts zu tun – die Homepage ist schon aktuell.'],
    'error' => ['error', 'Fehler – siehe Stand unten. Die bisherige Homepage bleibt online.'],
    'off' => ['info', 'Automatik ist hier nicht eingerichtet – siehe Stand unten.'],
][$_GET['ergebnis'] ?? ''] ?? null;
$fmt = static fn (?string $dt): string => $dt ? date('d.m.Y, H:i', strtotime($dt)) . ' Uhr' : '–';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Homepage – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Homepage</h1>
    <p style="font-size:0.9rem;color:#666;">Die Folgen auf der Homepage entstehen automatisch aus <code>podcast.rss</code> – alle 15 Minuten prüft der Server, ob sich etwas geändert hat. Die Folgen des laufenden Zehners stehen einzeln oben; ist ein Zehner voll und die nächste Folge erschienen, wandert er ins Archiv. Im Archiv steht der Kurztext (<code>&lt;itunes:subtitle&gt;</code> in der RSS-Datei), fehlt er, der erste Satz der Beschreibung. Alles außerhalb der Markierungen in <code>index.html</code> bleibt, wie du es von Hand pflegst.</p>

    <?php if ($ergebnis): ?><p class="<?= $ergebnis[0] ?>"><?= $e($ergebnis[1]) ?></p><?php endif; ?>

    <h2>Stand</h2>
    <?php if ($dir === null): ?>
        <p>Die Automatik läuft nur im Live-Bereich (Ordner <code>APP</code>), nicht hier.</p>
    <?php else: ?>
        <p>
            Letzte Prüfung: <?= $e($fmt($status['at'] ?? null)) ?><br>
            Letzte Änderung der Homepage: <?= $e($fmt($status['updated_at'] ?? null)) ?><br>
            Ergebnis: <?php if (($status['status'] ?? '') === 'error'): ?><strong class="error"><?= $e($status['message'] ?? '') ?></strong><?php else: ?><?= $e($status['message'] ?? 'noch nicht gelaufen') ?><?php endif; ?>
        </p>
        <form method="post">
            <input type="hidden" name="action" value="run">
            <button type="submit">Jetzt aktualisieren</button>
        </form>
    <?php endif; ?>

    <?php if ($feedError !== null): ?>
        <p class="error">RSS-Datei: <?= $e($feedError) ?></p>
    <?php elseif ($episodes !== []): ?>
        <h2>So stehen die Folgen auf der Homepage</h2>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Folge</th><th>Wo</th><th>Text im Archiv</th><th>Kapitel</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($episodes) as $ep): ?>
                <tr>
                    <td><strong><?= $e($ep['title']) ?></strong><br><small><?= $e($ep['date']->format('d.m.Y')) ?></small></td>
                    <td><?= $e($placeOf($ep['number'])) ?></td>
                    <td><?= $e(Homepage::shortText($ep)) ?><br><small><?= $ep['subtitle'] !== null ? 'Kurztext aus der RSS-Datei' : 'automatisch gekürzt – eigener Kurztext fehlt' ?></small></td>
                    <td><?= count($ep['chapters']) ?: '–' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/sidebar-close.php'; ?>
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
</body>
</html>
