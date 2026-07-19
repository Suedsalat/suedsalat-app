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

// Newsletter-Versand ist bewusst nur fuer den Owner (Thorsten) gedacht - nicht nur
// der Nav-Link ist versteckt, der direkte Aufruf der Seite wird hier serverseitig
// abgeblockt, sonst koennte ein Member die URL einfach direkt aufrufen.
if (!$isOwner) {
    header('Location: ' . BASE_PATH . '/admin/dashboard.php');
    exit;
}

// Die eigentliche Abonnenten-Liste und E-Mail-Vorlage liegen im separaten
// newsletter/-Ordner (Homepage-Root), nicht innerhalb von 03-Backend - historisch
// gewachsen, aeltere Komponente. Serverseitig per relativem Dateipfad erreichbar.
$newsletterDir = __DIR__ . '/../../newsletter';
$emailsFile = $newsletterDir . '/emails.txt';
$templateFile = $newsletterDir . '/email_template.html';
$abmeldeScriptUrl = 'https://www.xn--sdsalat-n2a.eu/newsletter/abmelden.php';

$defaultSubject = 'Eine neue Folge vom Südsalat Podcast ist da!';
$defaultHeadline = 'Es gibt eine neue Folge!';
// Vorausgefuellt bis zum Episoden-Praefix, damit nur noch die Nummer ergaenzt werden
// muss (Folgen sind durchgaengig 3-stellig, z.B. "episode034") statt jedes Mal die
// komplette URL einzutippen.
$defaultEpisodeLink = 'https://www.xn--sdsalat-n2a.eu#episode0';
$fromEmail = 'newsletter@xn--sdsalat-n2a.eu';
$fromName = 'Südsalat Podcast';
$delayMicrosec = 500000;

$allowedImageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$maxImageBytes = 8 * 1024 * 1024;

// Normalisiert/prueft eine gespeicherte Abonnenten-Adresse - identisch zur Logik in
// newsletter.php/confirm.php/abmelden.php/send_newsletter.php. Noetig, weil
// FILTER_VALIDATE_EMAIL Unicode im lokalen Teil (z.B. "müller@...") ablehnen wuerde,
// obwohl solche Adressen beim Double-Opt-In bereits korrekt bestaetigt wurden.
function normalize_recipient_email(string $email): ?string
{
    $email = trim($email);
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($email, Normalizer::FORM_C);
        if ($normalized !== false) {
            $email = $normalized;
        }
    }

    $atPos = strrpos($email, '@');
    if ($atPos === false) {
        return null;
    }
    $local = substr($email, 0, $atPos);
    $domain = substr($email, $atPos + 1);

    if (function_exists('idn_to_ascii')) {
        $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($asciiDomain !== false) {
            $domain = $asciiDomain;
        }
    }
    $domain = strtolower($domain);

    if (!preg_match('/^[\p{L}\p{N}.!#$%&\'*+\/=?^_`{|}~-]+$/u', $local)) {
        return null;
    }
    if (!filter_var('a@' . $domain, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    return $local . '@' . $domain;
}

function load_recipients(string $emailsFile): array
{
    if (!file_exists($emailsFile)) {
        return [];
    }
    $lines = file($emailsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $recipients = [];
    foreach ($lines as $line) {
        $parts = explode('|', trim($line));
        $candidate = normalize_recipient_email($parts[0] ?? '');
        if ($candidate !== null) {
            $recipients[] = $candidate;
        }
    }
    return $recipients;
}

// Baut aus dem Fliesstext-Feld (eine Leerzeile = neuer Absatz, einfacher Zeilenumbruch
// = <br>) den [EMAIL_BODY]-Ersatz, im selben Absatz-Stil wie die bisherigen fest
// eingetragenen Absaetze in email_template.html.
function build_email_body_html(string $bodyText): string
{
    $paragraphs = preg_split('/\R\s*\R/', trim($bodyText));
    $html = '';
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }
        $escaped = nl2br(htmlspecialchars($paragraph, ENT_QUOTES));
        $html .= '<p style="margin: 0 0 20px; font-size: 16px; line-height: 1.5; color: #102024;">' . $escaped . '</p>' . "\n";
    }
    return $html;
}

function build_email_headline_html(string $headline): string
{
    if ($headline === '') {
        return '';
    }
    return '<h2 style="margin: 0 0 20px; font-size: 22px; color: #102024; text-align: center;">'
        . htmlspecialchars($headline, ENT_QUOTES) . '</h2>';
}

function build_email_photo_html(?string $photoUrl): string
{
    if ($photoUrl === null || $photoUrl === '') {
        return '';
    }
    return '<img src="' . htmlspecialchars($photoUrl, ENT_QUOTES) . '" alt="" '
        . 'style="display:block;width:100%;max-width:560px;height:auto;margin:0 0 20px;border-radius:8px;">';
}

// Der "Jetzt reinhören"-Button erscheint nur, wenn ein Episoden-Link angegeben ist -
// wird das Feld im Formular komplett geleert, kann so auch ein allgemeiner Newsletter
// ohne Folgenbezug verschickt werden.
function build_episode_button_html(string $episodeLink): string
{
    if ($episodeLink === '') {
        return '';
    }
    $escapedLink = htmlspecialchars($episodeLink, ENT_QUOTES);
    return '<table border="0" align="center" cellpadding="0" cellspacing="0" style="margin: 25px auto;" role="presentation">'
        . '<tr><td align="center" bgcolor="#77B538" style="border-radius: 5px; background-color: #77B538; padding: 0;">'
        . '<a href="' . $escapedLink . '" target="_blank" style="display: inline-block; padding: 10px 20px; font-size: 16px; '
        . 'font-weight: bold; color: #ffffff; text-decoration: none; border-radius: 5px; line-height: 1.5;">Jetzt reinhören</a>'
        . '</td></tr></table>';
}

function render_email_html(string $templateFile, string $headline, string $episodeLink, string $bodyText, ?string $photoUrl): string
{
    $template = file_get_contents($templateFile);
    $search = ['[EMAIL_HEADLINE_BLOCK]', '[EMAIL_PHOTO]', '[EMAIL_BODY]', '[EPISODE_BUTTON]', '[UNSUBSCRIBE_LINK]'];
    $replace = [
        build_email_headline_html($headline),
        build_email_photo_html($photoUrl),
        build_email_body_html($bodyText),
        build_episode_button_html($episodeLink),
        '#', // Platzhalter fuer die Vorschau - der echte Abmeldelink wird erst pro Empfaenger im Versand gesetzt.
    ];
    return str_replace($search, $replace, $template);
}

$error = null;
$action = $_POST['action'] ?? null;

if (!file_exists($templateFile)) {
    $error = "E-Mail-Vorlage nicht gefunden: $templateFile";
    $action = null;
}

// --- STUFE 2: ECHTER VERSAND (nur per POST mit action=send) ---
if ($action === 'send') {
    $subject = trim((string) ($_POST['subject'] ?? $defaultSubject));
    $headline = trim((string) ($_POST['headline'] ?? $defaultHeadline));
    $episodeLink = trim((string) ($_POST['episode_link'] ?? ''));
    $bodyText = (string) ($_POST['body_text'] ?? '');
    $photoUrl = trim((string) ($_POST['photo_url'] ?? '')) ?: null;

    $recipients = load_recipients($emailsFile);
    $template = file_get_contents($templateFile);

    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
    $headers .= "From: " . "=?UTF-8?B?" . base64_encode($fromName) . "?= <" . $fromEmail . ">" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";

    $headlineHtml = build_email_headline_html($headline);
    $photoHtml = build_email_photo_html($photoUrl);
    $bodyHtml = build_email_body_html($bodyText);
    $episodeButtonHtml = build_episode_button_html($episodeLink);

    $countSent = 0;
    $countFailed = 0;

    echo '<!doctype html><html lang="de"><head><meta charset="UTF-8"><title>Newsletter wird verschickt</title>'
        . '<link rel="stylesheet" href="' . BASE_PATH . '/admin/assets/admin.css"></head><body><main class="content-box">';
    echo '<h1>Versand läuft (' . count($recipients) . ' Empfänger)...</h1><ul>';

    if (ob_get_level() === 0) {
        ob_start();
    }

    foreach ($recipients as $toEmail) {
        $unsubscribeLink = $abmeldeScriptUrl . '?email=' . urlencode($toEmail);

        $finalContent = str_replace('[EMAIL_HEADLINE_BLOCK]', $headlineHtml, $template);
        $finalContent = str_replace('[EMAIL_PHOTO]', $photoHtml, $finalContent);
        $finalContent = str_replace('[EMAIL_BODY]', $bodyHtml, $finalContent);
        $finalContent = str_replace('[EPISODE_BUTTON]', $episodeButtonHtml, $finalContent);
        $finalContent = str_replace('[UNSUBSCRIBE_LINK]', $unsubscribeLink, $finalContent);

        if (@mail($toEmail, $encodedSubject, $finalContent, $headers)) {
            echo "<li style='color: green;'>Gesendet an: " . htmlspecialchars($toEmail) . "</li>";
            $countSent++;
            usleep($delayMicrosec);
        } else {
            echo "<li style='color: red;'>Fehler beim Senden an: " . htmlspecialchars($toEmail) . "</li>";
            $countFailed++;
            usleep(100000);
        }

        ob_flush();
        flush();
    }

    echo '</ul>';
    echo "<h2>Versand abgeschlossen:</h2><p>Gesendet: <strong>$countSent</strong> | Fehlgeschlagen: <strong>$countFailed</strong></p>";
    echo '<a class="button" href="' . BASE_PATH . '/admin/newsletter.php">Zurück zum Newsletter-Formular</a> ';
    echo '<a class="button" href="' . BASE_PATH . '/admin/dashboard.php">Zum Dashboard</a>';
    echo '</main></body></html>';
    exit;
}

// --- STUFE 1: VORSCHAU (POST mit action=preview) ---
$previewHtml = null;
$recipientCount = null;
if ($action === 'preview') {
    $subject = trim((string) ($_POST['subject'] ?? $defaultSubject)) ?: $defaultSubject;

    // Jedes optionale Modul (Ueberschrift, Folgen-Link, Foto) hat eine eigene Checkbox
    // im Formular - nur bei angehaktem Kaestchen wird der zugehoerige Wert uebernommen,
    // sonst bleibt das Modul komplett weg (leerer String = "aus" fuer die build_*_html()-
    // Funktionen). So laesst sich der Newsletter modular zusammenstellen.
    $useHeadline = isset($_POST['use_headline']);
    $headline = $useHeadline ? trim((string) ($_POST['headline'] ?? $defaultHeadline)) : '';

    $useEpisodeLink = isset($_POST['use_episode_link']);
    $episodeLink = $useEpisodeLink ? trim((string) ($_POST['episode_link'] ?? '')) : '';

    $usePhoto = isset($_POST['use_photo']);

    $bodyText = trim((string) ($_POST['body_text'] ?? ''));
    $photoUrl = null;

    if ($bodyText === '') {
        $error = 'Bitte einen Text für die Newsletter-Mail eingeben.';
    }

    if ($error === null && $usePhoto && !empty($_FILES['photo']['name'])) {
        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Foto-Upload fehlgeschlagen.';
        } else {
            $mime = mime_content_type($file['tmp_name']);
            if (!isset($allowedImageTypes[$mime])) {
                $error = 'Nur JPG, PNG oder WebP sind als Foto erlaubt.';
            } elseif ($file['size'] > $maxImageBytes) {
                $error = 'Foto ist zu groß (max. 8 MB).';
            } else {
                $newsletterUploadDir = UPLOAD_DIR . '/newsletter';
                if (!is_dir($newsletterUploadDir)) {
                    mkdir($newsletterUploadDir, 0755, true);
                }
                $filename = bin2hex(random_bytes(16)) . '.' . $allowedImageTypes[$mime];
                move_uploaded_file($file['tmp_name'], $newsletterUploadDir . '/' . $filename);
                $photoUrl = UPLOAD_URL_BASE . '/newsletter/' . $filename;
            }
        }
    }

    if ($error === null) {
        $recipients = load_recipients($emailsFile);
        $recipientCount = count($recipients);
        $previewHtml = render_email_html($templateFile, $headline, $episodeLink, $bodyText, $photoUrl);
    }
} else {
    $subject = $defaultSubject;
    $headline = $defaultHeadline;
    $episodeLink = $defaultEpisodeLink;
    $bodyText = '';
    $photoUrl = null;
    // Anfangszustand der Modul-Checkboxen: Ueberschrift und Folgen-Link meist gewuenscht
    // (typischer Fall: neue Folge), Foto ist die Ausnahme und startet daher abgehakt-frei.
    $useHeadline = true;
    $useEpisodeLink = true;
    $usePhoto = false;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://www.xn--sdsalat-n2a.eu/favicon.png">
    <title>Newsletter versenden – Südsalat Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<header class="admin-header">
    <img src="<?= BASE_PATH ?>/admin/assets/img/logo.png?v=<?= @filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="Südsalat">
    <p>APP-Administrationsbereich</p>
</header>
<nav class="admin-nav">
    <a href="<?= BASE_PATH ?>/admin/dashboard.php">Dashboard</a>
    <a href="<?= BASE_PATH ?>/admin/feedback.php">Aktivitäten</a>
    <a class="nav-gap" href="<?= BASE_PATH ?>/admin/events.php">Termine</a>
    <a href="<?= BASE_PATH ?>/admin/gallery.php">Galerie</a>
    <a href="<?= BASE_PATH ?>/admin/movie-tips.php">Kino</a>
    <?php if ($isOwner): ?><a href="<?= BASE_PATH ?>/admin/newsletter.php">Newsletter</a><?php endif; ?>
    <a href="<?= BASE_PATH ?>/admin/change-password.php">Passwort ändern</a>
    <a href="<?= BASE_PATH ?>/admin/logout.php">Abmelden (<span id="logout-countdown" data-timeout-seconds="<?= ADMIN_IDLE_TIMEOUT_MINUTES * 60 ?>"></span>)</a>
</nav>
<main class="content-box">
    <h1>Newsletter versenden</h1>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php if ($previewHtml !== null): ?>
        <h2>Vorschau</h2>
        <p><strong><?= $recipientCount ?></strong> gültige Empfänger in der Liste. Nichts wird verschickt, bevor du unten aktiv auf "Jetzt senden" klickst.</p>
        <iframe srcdoc="<?= htmlspecialchars($previewHtml, ENT_QUOTES) ?>" style="width:100%;height:500px;border:1px solid #ccc;border-radius:8px;background:#fff;"></iframe>

        <form method="post" style="margin-top:16px;">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="subject" value="<?= htmlspecialchars($subject, ENT_QUOTES) ?>">
            <input type="hidden" name="headline" value="<?= htmlspecialchars($headline, ENT_QUOTES) ?>">
            <input type="hidden" name="episode_link" value="<?= htmlspecialchars($episodeLink, ENT_QUOTES) ?>">
            <input type="hidden" name="body_text" value="<?= htmlspecialchars($bodyText, ENT_QUOTES) ?>">
            <?php if ($photoUrl !== null): ?>
                <input type="hidden" name="photo_url" value="<?= htmlspecialchars($photoUrl, ENT_QUOTES) ?>">
            <?php endif; ?>
            <div class="button-row">
                <button type="submit">Jetzt an <?= $recipientCount ?> Empfänger senden</button>
                <a class="button" href="<?= BASE_PATH ?>/admin/newsletter.php">Abbrechen / neu bearbeiten</a>
            </div>
        </form>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="preview">
            <label>Betreff
                <input type="text" name="subject" value="<?= htmlspecialchars($subject, ENT_QUOTES) ?>" required>
            </label>

            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                <input type="checkbox" id="chk_headline" name="use_headline" <?= $useHeadline ? 'checked' : '' ?> style="width:auto;">
                Überschrift anzeigen
            </label>
            <div id="field_headline">
                <label>Überschrift in der Mail
                    <input type="text" name="headline" value="<?= htmlspecialchars($headline, ENT_QUOTES) ?>">
                </label>
            </div>

            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                <input type="checkbox" id="chk_episode" name="use_episode_link" <?= $useEpisodeLink ? 'checked' : '' ?> style="width:auto;">
                Link zur Folge einbauen
            </label>
            <div id="field_episode">
                <label>Episoden-Link (nur Nummer ergänzen)
                    <input type="text" name="episode_link" value="<?= htmlspecialchars($episodeLink, ENT_QUOTES) ?>">
                </label>
            </div>

            <label>Text der Newsletter-Mail
                <textarea name="body_text" rows="8" required><?= htmlspecialchars($bodyText, ENT_QUOTES) ?></textarea>
            </label>

            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                <input type="checkbox" id="chk_photo" name="use_photo" <?= $usePhoto ? 'checked' : '' ?> style="width:auto;">
                Foto einbinden
            </label>
            <div id="field_photo">
                <label>Foto (max. 8 MB, JPG/PNG/WebP)
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                </label>
            </div>

            <p style="font-size:0.85rem;color:#666;">Logo und Fußzeile (Impressum/Datenschutz/Abmelden) der Vorlage bleiben immer unverändert. Nicht angehakte Module (Überschrift/Folgen-Link/Foto) erscheinen gar nicht erst im Newsletter.</p>
            <div class="button-row">
                <button type="submit">Vorschau anzeigen</button>
            </div>
        </form>
        <script>
            // Blendet die zu einer Checkbox gehoerenden Felder ein/aus - rein optisch,
            // die eigentliche Entscheidung (Modul an/aus) trifft serverseitig ohnehin
            // die Checkbox selbst (siehe admin/newsletter.php use_headline/use_episode_link/use_photo).
            (function () {
                function bind(checkboxId, fieldId) {
                    var checkbox = document.getElementById(checkboxId);
                    var field = document.getElementById(fieldId);
                    if (!checkbox || !field) return;
                    function update() { field.style.display = checkbox.checked ? '' : 'none'; }
                    checkbox.addEventListener('change', update);
                    update();
                }
                bind('chk_headline', 'field_headline');
                bind('chk_episode', 'field_episode');
                bind('chk_photo', 'field_photo');
            })();
        </script>
    <?php endif; ?>
</main>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
</body>
</html>
