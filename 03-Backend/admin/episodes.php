<?php
declare(strict_types=1);

// Folgen pflegen (nur Owner): neue Folge anlegen, bestehende bearbeiten (auch Kapitel nachtragen).
// Schreibt podcast.rss (lib/Feed.php) - daraus lesen App, Spotify & Co. und die Homepage.
// Die MP3 laedt Thorsten weiter per FTP in episodes/; hier wird sie nur ausgewaehlt.

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Auth;
use Suedsalat\Database;
use Suedsalat\Feed;
use Suedsalat\Homepage;
use Suedsalat\Mp3Info;

$adminId = Auth::requireLogin();
$pdo = Database::connection();

$currentAdminRole = $pdo->prepare('SELECT role FROM admins WHERE id = :id');
$currentAdminRole->execute([':id' => $adminId]);
$isOwner = $currentAdminRole->fetchColumn() === 'owner';
if (!$isOwner) {
    header('Location: ' . BASE_PATH . '/admin/dashboard.php');
    exit;
}

$berlin = new DateTimeZone('Europe/Berlin');
$errors = [];
$form = null;          // Formularwerte bei Fehlern
$feed = null;
$loadError = null;
try {
    $feed = Feed::path() !== null ? Feed::parse(Feed::read()) : null;
} catch (\RuntimeException $e) {
    $loadError = $e->getMessage();
}
$episodesDir = Homepage::directory() !== null ? Homepage::directory() . '/episodes' : null;

// ------------------------------------------------------------------------------------------------
// Speichern
// ------------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save' && $feed !== null) {
    $original = (string) ($_POST['original'] ?? 'new');
    $form = [
        'original' => $original,
        'number' => trim((string) ($_POST['number'] ?? '')),
        'name' => trim(normalize_input((string) ($_POST['name'] ?? ''))),
        'text' => trim(str_replace("\r\n", "\n", normalize_input((string) ($_POST['text'] ?? '')))),
        'subtitle' => trim((string) preg_replace('/\s+/u', ' ', normalize_input((string) ($_POST['subtitle'] ?? '')))),
        'date' => trim((string) ($_POST['date'] ?? '')),
        'file' => basename((string) ($_POST['file'] ?? '')),
        'chapters' => str_replace("\r\n", "\n", (string) ($_POST['chapters'] ?? '')),
    ];
    $items = $feed['items'];
    $index = null;
    foreach ($items as $i => $it) {
        if ($original !== 'new' && (string) $it['number'] === $original) {
            $index = $i;
        }
    }
    if ($original !== 'new' && $index === null) {
        $errors[] = 'Diese Folge gibt es in der RSS-Datei nicht mehr.';
    }
    $number = ctype_digit($form['number']) ? (int) $form['number'] : 0;
    if ($number < 1) {
        $errors[] = 'Bitte eine Folgennummer angeben.';
    }
    foreach ($items as $i => $it) {
        if ($it['number'] === $number && $i !== $index) {
            $errors[] = "Episode {$number} gibt es schon.";
        }
    }
    if ($form['name'] === '') {
        $errors[] = 'Bitte einen Titel angeben.';
    }
    if ($form['text'] === '') {
        $errors[] = 'Bitte eine Beschreibung angeben.';
    }
    if (mb_strlen($form['subtitle']) > 300) {
        $errors[] = 'Der Kurztext fürs Archiv ist zu lang (höchstens 300 Zeichen).';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $form['date'], $berlin);
    if ($date === false) {
        $errors[] = 'Bitte Datum und Uhrzeit der Veröffentlichung angeben.';
    }

    $old = $index !== null ? $items[$index] : null;
    $oldFile = $old !== null ? basename(parse_url($old['url'], PHP_URL_PATH) ?: '') : null;
    $fileChanged = $form['file'] !== $oldFile;
    $durationSeconds = $old !== null ? Feed::durationToSeconds($old['duration']) : null;
    if ($fileChanged) {
        $path = $episodesDir !== null ? $episodesDir . '/' . $form['file'] : '';
        if (!preg_match('/^[\w.-]+\.mp3$/i', $form['file']) || !is_file($path)) {
            $errors[] = 'Bitte die MP3-Datei auswählen (vorher per FTP in den Ordner episodes/ laden).';
        } else {
            $durationSeconds = Mp3Info::durationSeconds($path);
            if ($durationSeconds === null) {
                $errors[] = 'Die Datei ' . $form['file'] . ' ist keine lesbare MP3.';
            }
        }
    }
    $chapterResult = Feed::chaptersFromInput($form['chapters'], $durationSeconds);
    $errors = array_merge($errors, $chapterResult['errors']);

    if ($errors === []) {
        $item = $old ?? ['guid' => '', 'guidAttrs' => '', 'type' => 'audio/mpeg', 'explicit' => 'no'];
        $item['number'] = $number;
        $item['name'] = $form['name'];
        $item['text'] = $form['text'];
        $item['subtitle'] = $form['subtitle'];
        $item['chapters'] = $chapterResult['chapters'];
        $item['pubDate'] = $date->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' GMT';
        if ($fileChanged) {
            $url = Feed::audioBase($items) . rawurlencode($form['file']);
            $item['url'] = $url;
            $item['link'] = $url;
            $item['length'] = (string) filesize($episodesDir . '/' . $form['file']);
            $item['duration'] = Mp3Info::format((int) $durationSeconds);
            // Die Kennung einer bestehenden Folge bleibt immer gleich - sonst waere sie fuer
            // App und Podcast-Apps eine neue Folge (mit Push-Nachricht).
            if ($old === null) {
                $item['guid'] = $url;
            }
        }
        if ($index === null) {
            $feed['items'][] = $item;
        } else {
            $feed['items'][$index] = $item;
        }
        try {
            $note = ($old === null ? 'Neue Folge ' : 'Geändert: Folge ') . $number;
            Feed::write($pdo, Feed::render($feed), $note, $adminId);
            $home = Homepage::run($pdo);
            $flag = $old === null ? 'angelegt' : 'gespeichert';
            header('Location: ' . BASE_PATH . "/admin/episodes.php?{$flag}={$number}&homepage=" . urlencode($home['status']) . "#folge-{$number}");
            exit;
        } catch (\RuntimeException $e) {
            $errors[] = 'Nicht gespeichert: ' . $e->getMessage();
        }
    }
}

// ------------------------------------------------------------------------------------------------
// Fruehere Fassung wiederherstellen (mit Passwort/2FA wie beim Loeschen)
// ------------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_id'])) {
    if (!verify_admin_delete_confirmation($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
        header('Location: ' . BASE_PATH . '/admin/episodes.php?delete_error=1#fassungen');
        exit;
    }
    $stmt = $pdo->prepare('SELECT content, created_at FROM rss_versions WHERE id = :id');
    $stmt->execute([':id' => (int) $_POST['restore_id']]);
    $version = $stmt->fetch();
    try {
        if ($version === false) {
            throw new \RuntimeException('Diese Fassung gibt es nicht mehr.');
        }
        Feed::write($pdo, (string) $version['content'], 'Vor dem Wiederherstellen der Fassung vom ' . date('d.m.Y H:i', strtotime((string) $version['created_at'])), $adminId);
        Homepage::run($pdo);
        header('Location: ' . BASE_PATH . '/admin/episodes.php?wiederhergestellt=1');
        exit;
    } catch (\RuntimeException $e) {
        $errors[] = 'Nicht wiederhergestellt: ' . $e->getMessage();
    }
}

// ------------------------------------------------------------------------------------------------
// Anzeige
// ------------------------------------------------------------------------------------------------
$items = $feed['items'] ?? [];
$editNumber = $form['original'] ?? (isset($_GET['neu']) ? 'new' : (string) ($_GET['edit'] ?? ''));
$editing = null;
if ($editNumber === 'new') {
    $maxNumber = $items !== [] ? max(array_column($items, 'number')) : 0;
    $unused = Feed::unusedAudioFiles($items);
    $editing = $form ?? [
        'original' => 'new', 'number' => (string) ($maxNumber + 1), 'name' => '', 'text' => '', 'subtitle' => '',
        'date' => (new DateTimeImmutable('now', $berlin))->format('Y-m-d\TH:i'), 'file' => $unused[0] ?? '', 'chapters' => '',
    ];
} elseif ($editNumber !== '') {
    foreach ($items as $it) {
        if ((string) $it['number'] === $editNumber) {
            $pub = date_create_immutable($it['pubDate']);
            $editing = $form ?? [
                'original' => (string) $it['number'], 'number' => (string) $it['number'], 'name' => $it['name'],
                'text' => $it['text'], 'subtitle' => $it['subtitle'],
                'date' => $pub ? $pub->setTimezone($berlin)->format('Y-m-d\TH:i') : '',
                'file' => basename(parse_url($it['url'], PHP_URL_PATH) ?: ''),
                'chapters' => Feed::chaptersToInput($it['chapters']),
            ];
        }
    }
}
$fileOptions = [];
if ($editing !== null) {
    $fileOptions = Feed::unusedAudioFiles($items);
    if ($editing['file'] !== '' && !in_array($editing['file'], $fileOptions, true)) {
        array_unshift($fileOptions, $editing['file']);
    }
}
$versions = $pdo->query('SELECT v.id, v.note, v.created_at, a.name FROM rss_versions v LEFT JOIN admins a ON a.id = v.admin_id
                         ORDER BY v.id DESC LIMIT 10')->fetchAll();

$homepageNote = [
    'updated' => ' Die Homepage ist aktualisiert.',
    'unchanged' => ' Die Homepage war schon aktuell.',
    'error' => ' Achtung: Die Homepage konnte nicht aktualisiert werden (siehe Seite „Homepage“).',
    'off' => '',
][$_GET['homepage'] ?? 'off'] ?? '';
$notice = isset($_GET['angelegt']) ? 'Episode ' . (int) $_GET['angelegt'] . ' ist angelegt. Die App übernimmt sie innerhalb von 15 Minuten und schickt dann die Push-Nachricht „Neue Folge“.' . $homepageNote
    : (isset($_GET['gespeichert']) ? 'Episode ' . (int) $_GET['gespeichert'] . ' ist gespeichert. Die App übernimmt die Änderung innerhalb von 15 Minuten (ohne Push-Nachricht).' . $homepageNote
    : (isset($_GET['wiederhergestellt']) ? 'Die frühere Fassung ist wiederhergestellt.' : null));
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Folgen – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Folgen</h1>
    <p style="font-size:0.9rem;color:#666;">Hier pflegst du die Podcast-Folgen. Gespeichert wird in <code>podcast.rss</code> – daraus lesen Spotify, Apple &amp; Co., die App und die Homepage. Die MP3 lädst du wie gewohnt per FTP in den Ordner <code>episodes/</code> und wählst sie hier aus; Länge und Dateigröße ermittelt der Server selbst.</p>

    <?php if ($notice): ?><p class="info"><?= $e($notice) ?></p><?php endif; ?>
    <?php if (isset($_GET['delete_error'])): ?><p class="error text-center">Falscher Code – nichts wurde wiederhergestellt.</p><?php endif; ?>
    <?php if ($loadError): ?><p class="error">RSS-Datei: <?= $e($loadError) ?> – bitte per FTP prüfen oder unten eine frühere Fassung wiederherstellen.</p><?php endif; ?>
    <?php if ($feed === null && $loadError === null): ?><p>Die Folgen lassen sich nur im Live-Bereich bearbeiten.</p><?php endif; ?>

    <?php if ($editing !== null): ?>
        <h2 id="formular"><?= $editing['original'] === 'new' ? 'Neue Folge anlegen' : 'Episode ' . $e($editing['original']) . ' bearbeiten' ?></h2>
        <?php if ($errors !== []): ?>
            <div class="error"><?php foreach ($errors as $err): ?><p style="margin:4px 0;"><?= $e($err) ?></p><?php endforeach; ?></div>
        <?php endif; ?>
        <form method="post" action="<?= BASE_PATH ?>/admin/episodes.php#formular">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="original" value="<?= $e($editing['original']) ?>">
            <label>Nummer <input type="number" name="number" min="1" required value="<?= $e($editing['number']) ?>" style="max-width:8em;"></label>
            <label>Titel (ohne „Episode …:“ davor) <input type="text" name="name" maxlength="200" required value="<?= $e($editing['name']) ?>"></label>
            <label>Beschreibung <textarea name="text" rows="8" required><?= $e($editing['text']) ?></textarea></label>
            <label>Kurztext fürs Archiv auf der Homepage (optional, ein bis zwei Sätze – leer: automatisch der erste Satz)
                <textarea name="subtitle" rows="2" maxlength="300"><?= $e($editing['subtitle']) ?></textarea></label>
            <label>Veröffentlicht am <input type="datetime-local" name="date" required value="<?= $e($editing['date']) ?>" style="max-width:16em;"></label>
            <label>MP3-Datei im Ordner episodes/
                <select name="file" required>
                    <?php if ($fileOptions === []): ?><option value="">– keine neue MP3 gefunden, bitte erst per FTP hochladen –</option><?php endif; ?>
                    <?php foreach ($fileOptions as $f): ?>
                        <option value="<?= $e($f) ?>" <?= $f === $editing['file'] ? 'selected' : '' ?>><?= $e($f) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Kapitel (optional) – eine Zeile pro Kapitel, z. B. „00:00:00 Begrüßung“, „00:12:34 Urlaub“, „01:02:03 Verabschiedung“
                <textarea name="chapters" rows="8" placeholder="00:00:00 Begrüßung&#10;00:04:15 Was diese Woche los war&#10;00:31:40 Filmtipp"><?= $e($editing['chapters']) ?></textarea></label>
            <p style="font-size:0.85rem;color:#666;margin-top:-6px;">Das erste Kapitel beginnt bei 00:00:00, mindestens zwei Kapitel. Die kurze Schreibweise „04:15“ geht auch. Tipp: Spotify zeigt bei deinen Folgen automatisch erzeugte Kapitel – die kannst du hier hineinkopieren und die Titel anpassen. Die Kapitel erscheinen in der App, bei Spotify und auf der Homepage.</p>
            <div class="actions">
                <button type="submit"><?= $editing['original'] === 'new' ? 'Folge anlegen' : 'Speichern' ?></button>
                <a class="button button-secondary" href="<?= BASE_PATH ?>/admin/episodes.php">Abbrechen</a>
            </div>
        </form>
    <?php elseif ($feed !== null): ?>
        <a class="button" href="<?= BASE_PATH ?>/admin/episodes.php?neu=1#formular">+ Neue Folge anlegen</a>
    <?php endif; ?>

    <?php if ($items !== []): ?>
        <h2>Alle Folgen</h2>
        <div class="table-scroll">
        <table>
            <thead><tr><th>Folge</th><th>Veröffentlicht</th><th>Länge</th><th>Kapitel</th><th>Kurztext</th><th>Aktionen</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($items) as $it): ?>
                <?php $pub = date_create_immutable($it['pubDate']); ?>
                <tr id="folge-<?= (int) $it['number'] ?>">
                    <td><strong>Episode <?= (int) $it['number'] ?>: <?= $e($it['name']) ?></strong></td>
                    <td><?= $pub ? $e($pub->setTimezone($berlin)->format('d.m.Y, H:i')) : '–' ?></td>
                    <td><?= $e($it['duration'] ?: '–') ?></td>
                    <td><?= count($it['chapters']) ?: '–' ?></td>
                    <td><?= $it['subtitle'] !== '' ? 'ja' : '–' ?></td>
                    <td><a class="button" href="<?= BASE_PATH ?>/admin/episodes.php?edit=<?= (int) $it['number'] ?>#formular">Bearbeiten</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if ($versions !== []): ?>
        <h2 id="fassungen">Frühere Fassungen der RSS-Datei</h2>
        <p style="font-size:0.9rem;color:#666;">Vor jedem Speichern wird die bisherige Datei aufgehoben (die letzten 30). Wiederherstellen ersetzt die aktuelle Datei – die aktuelle landet dabei ebenfalls hier.</p>
        <table>
            <thead><tr><th>Stand vor</th><th>Zeitpunkt</th><th>Von</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($versions as $v): ?>
                <tr>
                    <td><?= $e($v['note']) ?></td>
                    <td><?= $e(date('d.m.Y, H:i', strtotime((string) $v['created_at']))) ?> Uhr</td>
                    <td><?= $e($v['name'] ?? '–') ?></td>
                    <td>
                        <form method="post" onsubmit="return false;">
                            <input type="hidden" name="restore_id" value="<?= (int) $v['id'] ?>">
                            <button type="button" class="button-secondary" onclick="requestDelete(this.form, 'Die RSS-Datei wird auf diesen Stand zurückgesetzt. Die aktuelle Fassung bleibt als frühere Fassung erhalten.')">Wiederherstellen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/confirm-modal.php'; ?>
<?php require __DIR__ . '/partials/sidebar-close.php'; ?>
<script src="<?= BASE_PATH ?>/admin/assets/confirm-delete.js?v=<?= @filemtime(__DIR__ . '/assets/confirm-delete.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
</body>
</html>
