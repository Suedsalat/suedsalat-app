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
$fromName = 'Südsalat Podcast';
$delayMicrosec = 500000;

// Zur Auswahl stehende Absenderadressen - bewusst eine feste Liste (kein Freitext),
// damit nicht versehentlich eine nicht existierende/falsch konfigurierte Adresse als
// Absender landet. "newsletter@" ist der bisherige Standard fuer den normalen
// Newsletter, "testphase@" ist fuer Mails an die Google-Play-Testergruppe gedacht.
$availableSenders = [
    'newsletter@xn--sdsalat-n2a.eu' => 'newsletter@südsalat.eu',
    'testphase@xn--sdsalat-n2a.eu' => 'testphase@südsalat.eu',
];
$defaultFromEmail = array_key_first($availableSenders);

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

// Loest die Formular-Auswahl "An wen senden?" auf: 'all' = die normale oeffentliche
// Abonnenten-Liste (emails.txt), 'list:<id>' = eine im Admin-Bereich gepflegte eigene
// Empfaengerliste (z.B. eine Testergruppe), 'single:<email1>,<email2>,...' = eine
// oder mehrere frei eingegebene Adressen ohne eigene Liste (siehe
// resolve_target_post_value()), siehe admin/newsletter-lists.php.
// Gibt ['recipients'=>string[], 'label'=>string] zurueck - das Label landet zu
// Dokumentationszwecken in newsletter_sends.recipient_list_name.
function resolve_newsletter_target(string $target, string $emailsFile, PDO $pdo): array
{
    if (str_starts_with($target, 'single:')) {
        $emailsPart = substr($target, 7);
        $emails = $emailsPart === '' ? [] : explode(',', $emailsPart);
        if ($emails === []) {
            return ['recipients' => [], 'label' => 'Einzelne Adresse(n)'];
        }
        $label = count($emails) === 1
            ? 'Einzelne Adresse (' . $emails[0] . ')'
            : 'Einzelne Adressen (' . implode(', ', $emails) . ')';
        return ['recipients' => $emails, 'label' => $label];
    }
    if (str_starts_with($target, 'list:')) {
        $listId = (int) substr($target, 5);
        $listStmt = $pdo->prepare('SELECT name FROM newsletter_lists WHERE id = :id');
        $listStmt->execute([':id' => $listId]);
        $listName = $listStmt->fetchColumn();
        if ($listName !== false) {
            $membersStmt = $pdo->prepare('SELECT email FROM newsletter_list_members WHERE list_id = :id ORDER BY email ASC');
            $membersStmt->execute([':id' => $listId]);
            return ['recipients' => $membersStmt->fetchAll(PDO::FETCH_COLUMN), 'label' => $listName];
        }
    }
    return ['recipients' => load_recipients($emailsFile), 'label' => 'Newsletter'];
}

// Liest den "target"-Wert aus dem Formular-POST. Normalfall: der Wert aus dem
// <select> (z.B. "all"/"list:3") wird 1:1 durchgereicht - auch dann, wenn er
// bereits als "single:<email1>,<email2>" aus einem vorherigen Schritt (Vorschau/
// Zurück zum Bearbeiten) als verstecktes Feld mitkommt. Nur bei der frischen
// Auswahl "single" aus dem Formular werden die separat eingegebenen Adressen
// (single_emails[], eine pro Zeile im Formular) normalisiert, dedupliziert und
// zusammengefuegt, damit ab dann wieder ein einzelner String durch alle
// folgenden Schritte gereicht werden kann, genau wie bei "list:<id>".
function resolve_target_post_value(array $post): string
{
    $raw = (string) ($post['target'] ?? 'all');
    if ($raw !== 'single') {
        return $raw;
    }
    $rawEmails = $post['single_emails'] ?? [];
    if (!is_array($rawEmails)) {
        $rawEmails = [$rawEmails];
    }
    $emails = [];
    foreach ($rawEmails as $rawEmail) {
        $normalized = normalize_recipient_email((string) $rawEmail);
        if ($normalized !== null && !in_array($normalized, $emails, true)) {
            $emails[] = $normalized;
        }
    }
    return 'single:' . implode(',', $emails);
}

// Erlaubte Formatierungs-Tags aus der kleinen Toolbar im Textfeld (Fett,
// Kursiv, Liste, Link) - alles andere (Skripte, Stile, eingefuegte Word-
// Formatierungen usw.) wird beim Speichern/Versenden entfernt, siehe
// sanitize_newsletter_body_html().
const NEWSLETTER_BODY_ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 'a', 'ul', 'ol', 'li', 'br', 'p'];

// Entfernt aus dem vom contenteditable-Feld kommenden HTML alles, was nicht in
// NEWSLETTER_BODY_ALLOWED_TAGS steht (Tag wird entfernt, Inhalt bleibt - z.B.
// wird aus einem eingefuegten <span style="..."> einfach nur der Text), und
// laesst bei <a> ausschliesslich ein http(s)/mailto-href stehen (alle anderen
// Attribute, z.B. onclick, fliegen raus). Bewusst per DOMDocument statt per
// eigenem Regex-Parsing - HTML per Regex zu saeubern ist notorisch fehleranfaellig.
function sanitize_newsletter_body_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    // Falls hier noch alte, als reiner Text gespeicherte Newsletter reinkommen
    // (vor Einfuehrung der Formatierungsleiste): echte Zeilenumbrueche wie
    // gehabt in <br> uebersetzen, bevor das Ganze als HTML geparst wird.
    $html = nl2br($html);

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div>' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $root = $doc->getElementsByTagName('div')->item(0);
    if ($root === null) {
        return '';
    }
    sanitize_newsletter_body_node($doc, $root);

    $result = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $result .= $doc->saveHTML($child);
    }
    return $result;
}

function sanitize_newsletter_body_node(DOMDocument $doc, DOMNode $node): void
{
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMComment) {
            $node->removeChild($child);
            continue;
        }
        if (!($child instanceof DOMElement)) {
            continue;
        }

        $tag = strtolower($child->tagName);
        if ($tag === 'script' || $tag === 'style') {
            $node->removeChild($child);
            continue;
        }

        sanitize_newsletter_body_node($doc, $child);

        if (!in_array($tag, NEWSLETTER_BODY_ALLOWED_TAGS, true)) {
            // Tag selbst entfernen, aber den (bereits bereinigten) Inhalt an
            // seiner Stelle stehen lassen statt ihn mit wegzuwerfen.
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
            continue;
        }

        $href = $tag === 'a' ? $child->getAttribute('href') : null;
        foreach (iterator_to_array($child->attributes ?? []) as $attr) {
            $child->removeAttribute($attr->name);
        }

        if ($tag === 'a') {
            $href = trim((string) $href);
            $isSafeScheme = $href !== '' && preg_match('#^(https?://|mailto:)#i', $href) === 1;
            if ($isSafeScheme) {
                $child->setAttribute('href', $href);
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener');
            } else {
                // Kein brauchbares/sicheres Ziel - Link-Tag verwerfen, Text bleibt.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
            }
        }
    }
}

// Baut aus dem Fliesstext-Feld den [EMAIL_BODY]-Ersatz. Das Feld kommt als
// (bereits um unerlaubte Tags bereinigtes, siehe sanitize_newsletter_body_html)
// HTML aus der kleinen Formatierungsleiste im Formular - Fett/Kursiv/Liste/Link
// werden 1:1 uebernommen.
function build_email_body_html(string $bodyText): string
{
    $sanitized = sanitize_newsletter_body_html($bodyText);
    return '<div style="margin: 0 0 20px; font-size: 16px; line-height: 1.5; color: #102024;">' . $sanitized . '</div>';
}

function build_email_headline_html(string $headline): string
{
    if ($headline === '') {
        return '';
    }
    return '<h2 style="margin: 0 0 20px; font-size: 22px; color: #102024; text-align: center;">'
        . htmlspecialchars($headline, ENT_QUOTES) . '</h2>';
}

// Begrenzt die vom Formular kommende Fotobreite auf einen sinnvollen Bereich
// (100-560px, Standard 560px = volle Vorlagenbreite).
function normalize_photo_width($value): int
{
    $width = (int) $value;
    if ($width <= 0) {
        return 560;
    }
    return max(100, min(560, $width));
}

// Nur 'left'/'center'/'right' zulassen, sonst Standard 'center'.
function normalize_photo_align($value): string
{
    return in_array($value, ['left', 'center', 'right'], true) ? $value : 'center';
}

function build_email_photo_html(?string $photoUrl, int $photoWidth = 560, string $photoAlign = 'center'): string
{
    if ($photoUrl === null || $photoUrl === '') {
        return '';
    }
    $margin = match ($photoAlign) {
        'left' => '0 auto 20px 0',
        'right' => '0 0 20px auto',
        default => '0 auto 20px auto',
    };
    return '<img src="' . htmlspecialchars($photoUrl, ENT_QUOTES) . '" alt="" '
        . 'style="display:block;width:100%;max-width:' . $photoWidth . 'px;height:auto;margin:' . $margin . ';border-radius:8px;">';
}

// Reiht die Bild-Blocks mehrerer Fotos direkt untereinander - jedes Foto behaelt
// seine eigene Breite/Ausrichtung (siehe build_email_photo_html), es gibt keinen
// gemeinsamen Rahmen o.ae. Erwartet ein Array aus ['url'=>string,'width'=>int,'align'=>string].
function build_email_photos_html(array $photos): string
{
    $html = '';
    foreach ($photos as $photo) {
        $html .= build_email_photo_html($photo['url'], $photo['width'], $photo['align']);
    }
    return $html;
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

function render_email_html(string $templateFile, string $headline, string $episodeLink, string $bodyText, array $photos): string
{
    $template = file_get_contents($templateFile);
    $search = ['[EMAIL_HEADLINE_BLOCK]', '[EMAIL_PHOTO]', '[EMAIL_BODY]', '[EPISODE_BUTTON]', '[UNSUBSCRIBE_LINK]'];
    $replace = [
        build_email_headline_html($headline),
        build_email_photos_html($photos),
        build_email_body_html($bodyText),
        build_episode_button_html($episodeLink),
        '#', // Platzhalter fuer die Vorschau - der echte Abmeldelink wird erst pro Empfaenger im Versand gesetzt.
    ];
    return str_replace($search, $replace, $template);
}

// Baut aus den bisherigen (existing_photos[]) und neu hochgeladenen (photos[])
// Formularfeldern die aktuelle Foto-Liste - genutzt sowohl bei der Vorschau als auch
// beim tatsaechlichen Versand, damit beide exakt dieselbe Liste sehen/verschicken.
function collect_photos_from_request(array $allowedImageTypes, int $maxImageBytes): array
{
    $photos = [];

    $existing = $_POST['existing_photos'] ?? [];
    foreach ($existing as $entry) {
        $url = trim((string) ($entry['url'] ?? ''));
        if ($url === '' || !empty($entry['remove'])) {
            continue;
        }
        $photos[] = [
            'url' => $url,
            'width' => normalize_photo_width($entry['width'] ?? 560),
            'align' => normalize_photo_align($entry['align'] ?? 'center'),
        ];
    }

    $uploaded = $_FILES['photos'] ?? null;
    if ($uploaded && is_array($uploaded['name'] ?? null)) {
        $newsletterUploadDir = UPLOAD_DIR . '/newsletter';
        foreach ($uploaded['name'] as $i => $name) {
            if ($name === '' || $uploaded['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($uploaded['error'][$i] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Foto-Upload fehlgeschlagen.');
            }
            $mime = mime_content_type($uploaded['tmp_name'][$i]);
            if (!isset($allowedImageTypes[$mime])) {
                throw new RuntimeException('Nur JPG, PNG oder WebP sind als Foto erlaubt.');
            }
            if ($uploaded['size'][$i] > $maxImageBytes) {
                throw new RuntimeException('Foto ist zu groß (max. 8 MB).');
            }
            if (!is_dir($newsletterUploadDir)) {
                mkdir($newsletterUploadDir, 0755, true);
            }
            $filename = bin2hex(random_bytes(16)) . '.' . $allowedImageTypes[$mime];
            move_uploaded_file($uploaded['tmp_name'][$i], $newsletterUploadDir . '/' . $filename);
            $photos[] = [
                'url' => UPLOAD_URL_BASE . '/newsletter/' . $filename,
                'width' => 560,
                'align' => 'center',
            ];
        }
    }

    return $photos;
}

// Rendert die Bearbeitungs-Zeilen fuer bereits vorhandene Fotos (Vorschau-Thumbnail,
// Breite, Ausrichtung, Entfernen-Haekchen) plus ein Datei-Feld zum Hinzufuegen
// weiterer Fotos. Wird sowohl im Verfassen-Formular als auch auf der Vorschau-Seite
// verwendet, damit die Groesse/Ausrichtung direkt an der Vorschau nachjustiert werden kann.
function render_photo_editor_fields(array $photos): void
{
    foreach ($photos as $i => $photo): ?>
        <div class="photo-editor-item" style="display:flex;gap:12px;align-items:flex-start;margin-bottom:12px;padding:10px;border:1px solid #ddd;border-radius:8px;">
            <img src="<?= htmlspecialchars($photo['url'], ENT_QUOTES) ?>" alt="" style="max-width:120px;max-height:120px;border-radius:6px;flex-shrink:0;">
            <input type="hidden" name="existing_photos[<?= $i ?>][url]" value="<?= htmlspecialchars($photo['url'], ENT_QUOTES) ?>">
            <div style="flex:1;min-width:180px;">
                <label>Breite in der Mail (in Pixel, 100–560)
                    <input type="number" name="existing_photos[<?= $i ?>][width]" min="100" max="560" value="<?= (int) $photo['width'] ?>">
                </label>
                <label>Ausrichtung
                    <select name="existing_photos[<?= $i ?>][align]">
                        <option value="left" <?= $photo['align'] === 'left' ? 'selected' : '' ?>>Linksbündig</option>
                        <option value="center" <?= $photo['align'] === 'center' ? 'selected' : '' ?>>Zentriert</option>
                        <option value="right" <?= $photo['align'] === 'right' ? 'selected' : '' ?>>Rechtsbündig</option>
                    </select>
                </label>
                <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                    <input type="checkbox" name="existing_photos[<?= $i ?>][remove]" value="1" style="width:auto;">
                    Dieses Foto entfernen
                </label>
            </div>
        </div>
    <?php endforeach;
    ?>
    <label>Weitere(s) Foto(s) hinzufügen (max. 8 MB je Foto, JPG/PNG/WebP)
        <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
    </label>
    <?php
}

$error = null;
$action = $_POST['action'] ?? null;

if (!file_exists($templateFile)) {
    $error = "E-Mail-Vorlage nicht gefunden: $templateFile";
    $action = null;
}

// --- VORLAGE LÖSCHEN (nur per POST mit action=delete_draft, gleiches
//     Passwort-Bestaetigungs-Muster wie ueberall sonst im Admin-Bereich) ---
if ($action === 'delete_draft') {
    if (!verify_admin_password($pdo, $adminId, (string) ($_POST['confirm_password'] ?? ''))) {
        header('Location: ' . BASE_PATH . '/admin/newsletter.php?delete_error=1');
        exit;
    }
    $draftId = (int) ($_POST['draft_id'] ?? 0);
    if ($draftId > 0) {
        $pdo->prepare('DELETE FROM newsletter_drafts WHERE id = :id')->execute([':id' => $draftId]);
    }
    header('Location: ' . BASE_PATH . '/admin/newsletter.php');
    exit;
}

// --- STUFE 2: ECHTER VERSAND (nur per POST mit action=send) ---
if ($action === 'send') {
    $subject = trim((string) ($_POST['subject'] ?? $defaultSubject));
    $headline = trim((string) ($_POST['headline'] ?? $defaultHeadline));
    $episodeLink = trim((string) ($_POST['episode_link'] ?? ''));
    $bodyText = (string) ($_POST['body_text'] ?? '');
    $photos = collect_photos_from_request($allowedImageTypes, $maxImageBytes);
    $target = resolve_target_post_value($_POST);
    $fromEmail = array_key_exists($_POST['from_email'] ?? '', $availableSenders) ? $_POST['from_email'] : $defaultFromEmail;

    $resolvedTarget = resolve_newsletter_target($target, $emailsFile, $pdo);
    $recipients = $resolvedTarget['recipients'];
    $targetLabel = $resolvedTarget['label'];
    $template = file_get_contents($templateFile);

    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
    $headers .= "From: " . "=?UTF-8?B?" . base64_encode($fromName) . "?= <" . $fromEmail . ">" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";

    $headlineHtml = build_email_headline_html($headline);
    $photoHtml = build_email_photos_html($photos);
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

    $sentToEmails = [];

    foreach ($recipients as $toEmail) {
        $unsubscribeLink = $abmeldeScriptUrl . '?email=' . urlencode($toEmail);

        $finalContent = str_replace('[EMAIL_HEADLINE_BLOCK]', $headlineHtml, $template);
        $finalContent = str_replace('[EMAIL_PHOTO]', $photoHtml, $finalContent);
        $finalContent = str_replace('[EMAIL_BODY]', $bodyHtml, $finalContent);
        $finalContent = str_replace('[EPISODE_BUTTON]', $episodeButtonHtml, $finalContent);
        $finalContent = str_replace('[UNSUBSCRIBE_LINK]', $unsubscribeLink, $finalContent);

        // 5. Parameter (-f ...) setzt den Envelope-Sender/Return-Path, nicht nur die
        // sichtbare "From:"-Kopfzeile - ohne den geht eine Unzustellbarkeits-
        // Meldung (Bounce) je nach Strato-Mailserver-Konfiguration an eine System-
        // Adresse statt an die im Formular gewaehlte Absenderadresse.
        if (@mail($toEmail, $encodedSubject, $finalContent, $headers, '-f' . $fromEmail)) {
            echo "<li style='color: green;'>Gesendet an: " . htmlspecialchars($toEmail) . "</li>";
            $countSent++;
            $sentToEmails[] = $toEmail;
            usleep($delayMicrosec);
        } else {
            echo "<li style='color: red;'>Fehler beim Senden an: " . htmlspecialchars($toEmail) . "</li>";
            $countFailed++;
            usleep(100000);
        }

        ob_flush();
        flush();
    }

    // photo_url/photo_width/photo_align in newsletter_sends bleiben ab jetzt leer -
    // Fotos werden fuer neue Sends ausschliesslich in newsletter_send_photos protokolliert
    // (siehe [[project-suedsalat-app]]-Update Mehrfachfotos). Alte Sends behalten ihre Werte
    // in diesen Spalten, siehe "Ansehen"-Abschnitt weiter unten fuer den Fallback.
    $logStmt = $pdo->prepare(
        'INSERT INTO newsletter_sends (subject, headline, episode_link, body_text, recipient_count, recipient_list_name, from_email, sent_by)
         VALUES (:subject, :headline, :episode_link, :body_text, :recipient_count, :recipient_list_name, :from_email, :sent_by)'
    );
    $logStmt->execute([
        ':subject' => $subject,
        ':headline' => $headline !== '' ? $headline : null,
        ':episode_link' => $episodeLink !== '' ? $episodeLink : null,
        ':body_text' => $bodyText,
        ':recipient_count' => $countSent,
        ':recipient_list_name' => $targetLabel,
        ':from_email' => $fromEmail,
        ':sent_by' => $adminId,
    ]);
    $newSendId = (int) $pdo->lastInsertId();
    if (!empty($sentToEmails)) {
        $recipientStmt = $pdo->prepare(
            'INSERT INTO newsletter_send_recipients (newsletter_send_id, email) VALUES (:send_id, :email)'
        );
        foreach ($sentToEmails as $sentEmail) {
            $recipientStmt->execute([':send_id' => $newSendId, ':email' => $sentEmail]);
        }
    }
    if (!empty($photos)) {
        $photoStmt = $pdo->prepare(
            'INSERT INTO newsletter_send_photos (newsletter_send_id, sort_order, photo_url, photo_width, photo_align)
             VALUES (:send_id, :sort_order, :photo_url, :photo_width, :photo_align)'
        );
        foreach ($photos as $i => $photo) {
            $photoStmt->execute([
                ':send_id' => $newSendId,
                ':sort_order' => $i,
                ':photo_url' => $photo['url'],
                ':photo_width' => $photo['width'],
                ':photo_align' => $photo['align'],
            ]);
        }
    }

    echo '</ul>';
    echo "<h2>Versand abgeschlossen:</h2><p>Gesendet: <strong>$countSent</strong> | Fehlgeschlagen: <strong>$countFailed</strong></p>";
    echo '<a class="button" href="' . BASE_PATH . '/admin/newsletter.php">Zurück zum Newsletter-Formular</a> ';
    echo '<a class="button" href="' . BASE_PATH . '/admin/dashboard.php">Zum Dashboard</a>';
    echo '</main></body></html>';
    exit;
}

// --- Alten Newsletter nur ansehen (GET mit view_id, read-only) ---
$viewingSend = null;
$viewingSendPhotos = [];
if ($action === null && isset($_GET['view_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM newsletter_sends WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['view_id']]);
    $viewingSend = $stmt->fetch() ?: null;

    if ($viewingSend !== null) {
        $photosStmt = $pdo->prepare('SELECT * FROM newsletter_send_photos WHERE newsletter_send_id = :id ORDER BY sort_order ASC');
        $photosStmt->execute([':id' => (int) $viewingSend['id']]);
        $viewingSendPhotos = array_map(
            static fn (array $row) => ['url' => $row['photo_url'], 'width' => (int) $row['photo_width'], 'align' => $row['photo_align']],
            $photosStmt->fetchAll()
        );
        // Fallback fuer Newsletter aus der Zeit vor Mehrfachfotos - deren einziges Foto
        // steckt noch in den alten Einzel-Spalten von newsletter_sends selbst.
        if (empty($viewingSendPhotos) && !empty($viewingSend['photo_url'])) {
            $viewingSendPhotos = [[
                'url' => $viewingSend['photo_url'],
                'width' => normalize_photo_width($viewingSend['photo_width'] ?? 560),
                'align' => normalize_photo_align($viewingSend['photo_align'] ?? 'center'),
            ]];
        }

        // Tatsaechlich verwendete Empfaenger-Adressen (siehe newsletter_send_recipients,
        // wird seit Einfuehrung dieser Funktion bei jedem Versand mitgeschrieben).
        $recipientsStmt = $pdo->prepare('SELECT email FROM newsletter_send_recipients WHERE newsletter_send_id = :id ORDER BY email ASC');
        $recipientsStmt->execute([':id' => (int) $viewingSend['id']]);
        $viewingSendRecipients = $recipientsStmt->fetchAll(PDO::FETCH_COLUMN);
        $viewingSendRecipientsExact = !empty($viewingSendRecipients);

        // Fallback fuer Sends von VOR dieser Funktion: bestmoegliche Rekonstruktion
        // ueber die aktuelle Listenmitgliedschaft - kann inzwischen abweichen, da
        // sich Listen seit dem Versand veraendert haben koennen (Hinweis dazu in der UI).
        if (!$viewingSendRecipientsExact) {
            $label = (string) ($viewingSend['recipient_list_name'] ?? '');
            if (preg_match('/^Einzelne Adressen? \((.+)\)$/', $label, $m)) {
                $viewingSendRecipients = array_map('trim', explode(',', $m[1]));
            } elseif ($label === 'Newsletter' || $label === '') {
                // Leeres Label = Sends von vor Einfuehrung der Listen-/Label-Spalte
                // (recipient_list_name) - damals gab es nur die oeffentliche
                // Newsletter-Liste als Ziel, also derselbe Fallback wie "Newsletter".
                $viewingSendRecipients = load_recipients($emailsFile);
            } elseif ($label !== '') {
                $listStmt = $pdo->prepare('SELECT id FROM newsletter_lists WHERE name = :name');
                $listStmt->execute([':name' => $label]);
                $listId = $listStmt->fetchColumn();
                if ($listId !== false) {
                    $membersStmt = $pdo->prepare('SELECT email FROM newsletter_list_members WHERE list_id = :id ORDER BY email ASC');
                    $membersStmt->execute([':id' => $listId]);
                    $viewingSendRecipients = $membersStmt->fetchAll(PDO::FETCH_COLUMN);
                }
            }
        }
    }
}

// --- STUFE 1: VORSCHAU (POST mit action=preview) ---
$previewHtml = null;
$recipientCount = null;
$reusedSend = null;
$loadedDraft = null;
$draftSaved = null;
if ($action === 'preview') {
    $subject = trim((string) ($_POST['subject'] ?? $defaultSubject)) ?: $defaultSubject;

    // Jedes optionale Modul (Ueberschrift, Folgen-Link) hat eine eigene Checkbox im
    // Formular - nur bei angehaktem Kaestchen wird der zugehoerige Wert uebernommen,
    // sonst bleibt das Modul komplett weg (leerer String = "aus" fuer die build_*_html()-
    // Funktionen). So laesst sich der Newsletter modular zusammenstellen. Fotos werden
    // dagegen einfach ueber die Liste $photos gesteuert - leer = kein Foto im Newsletter.
    $useHeadline = isset($_POST['use_headline']);
    $headline = $useHeadline ? trim((string) ($_POST['headline'] ?? $defaultHeadline)) : '';

    $useEpisodeLink = isset($_POST['use_episode_link']);
    $episodeLink = $useEpisodeLink ? trim((string) ($_POST['episode_link'] ?? '')) : '';

    $bodyText = trim((string) ($_POST['body_text'] ?? ''));
    $target = resolve_target_post_value($_POST);
    $selectedFromEmail = array_key_exists($_POST['from_email'] ?? '', $availableSenders) ? $_POST['from_email'] : $defaultFromEmail;

    if ($bodyText === '') {
        $error = 'Bitte einen Text für die Newsletter-Mail eingeben.';
    } elseif ($target === 'single:') {
        $error = 'Bitte mindestens eine gültige E-Mail-Adresse eingeben.';
    }

    $photos = [];
    if ($error === null) {
        try {
            $photos = collect_photos_from_request($allowedImageTypes, $maxImageBytes);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    if ($error === null) {
        $resolvedTarget = resolve_newsletter_target($target, $emailsFile, $pdo);
        $recipientCount = count($resolvedTarget['recipients']);
        $targetLabel = $resolvedTarget['label'];
        $previewHtml = render_email_html($templateFile, $headline, $episodeLink, $bodyText, $photos);
    }
} elseif ($action === 'save_draft') {
    // Speichert die aktuell im Formular stehenden Werte als benannte Vorlage
    // (newsletter_drafts) - bewusst ohne Fotos, dieselbe Entscheidung wie bei
    // "Für neuen Newsletter übernehmen" weiter unten (Fotos sollen bei jedem
    // Newsletter neu ausgewählt werden). Ein bereits vorhandener Name wird
    // ueberschrieben (UNIQUE KEY auf name + ON DUPLICATE KEY UPDATE), damit
    // sich eine Vorlage einfach aktualisieren laesst, ohne Duplikate anzulegen.
    $subject = trim((string) ($_POST['subject'] ?? '')) ?: $defaultSubject;
    $useHeadline = isset($_POST['use_headline']);
    $headline = $useHeadline ? trim((string) ($_POST['headline'] ?? '')) : '';
    $useEpisodeLink = isset($_POST['use_episode_link']);
    $episodeLink = $useEpisodeLink ? trim((string) ($_POST['episode_link'] ?? '')) : '';
    $bodyText = trim((string) ($_POST['body_text'] ?? ''));
    $target = resolve_target_post_value($_POST);
    $selectedFromEmail = array_key_exists($_POST['from_email'] ?? '', $availableSenders) ? $_POST['from_email'] : $defaultFromEmail;
    $photos = [];
    $draftName = trim((string) ($_POST['draft_name'] ?? ''));
    $currentDraftName = $draftName;

    if ($draftName === '') {
        $error = 'Bitte einen Namen für die Vorlage eingeben.';
    } else {
        $draftStmt = $pdo->prepare(
            'INSERT INTO newsletter_drafts (name, subject, headline, episode_link, body_text, use_headline, use_episode_link, from_email, target)
             VALUES (:name, :subject, :headline, :episode_link, :body_text, :use_headline, :use_episode_link, :from_email, :target)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), headline = VALUES(headline), episode_link = VALUES(episode_link),
                body_text = VALUES(body_text), use_headline = VALUES(use_headline), use_episode_link = VALUES(use_episode_link),
                from_email = VALUES(from_email), target = VALUES(target)'
        );
        $draftStmt->execute([
            ':name' => $draftName,
            ':subject' => $subject,
            ':headline' => $headline !== '' ? $headline : null,
            ':episode_link' => $episodeLink !== '' ? $episodeLink : null,
            ':body_text' => $bodyText,
            ':use_headline' => $useHeadline ? 1 : 0,
            ':use_episode_link' => $useEpisodeLink ? 1 : 0,
            ':from_email' => $selectedFromEmail,
            ':target' => $target,
        ]);
        $draftSaved = $draftName;
    }
} elseif ($action === 'edit_again') {
    // Von der Vorschau zurueck zum Bearbeiten (siehe "Zurueck zum Bearbeiten"-Button
    // dort) - alle eingegebenen Werte 1:1 uebernehmen, damit nichts verloren geht und
    // nicht der komplette Newsletter neu angelegt werden muss, nur weil die Vorschau
    // noch nicht passt.
    $subject = trim((string) ($_POST['subject'] ?? '')) ?: $defaultSubject;
    $headline = trim((string) ($_POST['headline'] ?? ''));
    $episodeLink = trim((string) ($_POST['episode_link'] ?? ''));
    $bodyText = (string) ($_POST['body_text'] ?? '');
    $useHeadline = isset($_POST['use_headline']);
    $useEpisodeLink = isset($_POST['use_episode_link']);
    $target = resolve_target_post_value($_POST);
    $selectedFromEmail = array_key_exists($_POST['from_email'] ?? '', $availableSenders) ? $_POST['from_email'] : $defaultFromEmail;
    $photos = [];
    foreach ($_POST['existing_photos'] ?? [] as $entry) {
        $url = trim((string) ($entry['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $photos[] = [
            'url' => $url,
            'width' => normalize_photo_width($entry['width'] ?? 560),
            'align' => normalize_photo_align($entry['align'] ?? 'center'),
        ];
    }
} else {
    // Aus einem alten Newsletter uebernehmen (siehe "Fuer neuen Newsletter
    // uebernehmen" in der Liste unten) - befuellt das Formular mit den
    // damaligen Werten, damit Thorsten sie als Ausgangspunkt anpassen kann,
    // statt jedes Mal bei Null anzufangen.
    $reuseId = isset($_GET['reuse_id']) ? (int) $_GET['reuse_id'] : null;
    if ($reuseId) {
        $stmt = $pdo->prepare('SELECT * FROM newsletter_sends WHERE id = :id');
        $stmt->execute([':id' => $reuseId]);
        $reusedSend = $stmt->fetch() ?: null;
    }

    // Eine gespeicherte Vorlage laden (siehe "Vorlage laden" oben im Formular) -
    // hat Vorrang vor reuse_id, da beide ueber GET kommen aber nie gleichzeitig
    // benutzt werden.
    $loadDraftId = isset($_GET['load_draft_id']) && $_GET['load_draft_id'] !== '' ? (int) $_GET['load_draft_id'] : null;
    $loadedDraft = null;
    if ($loadDraftId) {
        $stmt = $pdo->prepare('SELECT * FROM newsletter_drafts WHERE id = :id');
        $stmt->execute([':id' => $loadDraftId]);
        $loadedDraft = $stmt->fetch() ?: null;
    }

    if ($loadedDraft) {
        // Bewusst auch hier ohne Fotos, siehe Hinweis bei action=save_draft.
        $subject = $loadedDraft['subject'];
        $headline = $loadedDraft['headline'] ?? '';
        $episodeLink = $loadedDraft['episode_link'] ?? '';
        $bodyText = $loadedDraft['body_text'];
        $photos = [];
        $useHeadline = (bool) $loadedDraft['use_headline'];
        $useEpisodeLink = (bool) $loadedDraft['use_episode_link'];
        $target = $loadedDraft['target'] ?? 'all';
        $selectedFromEmail = array_key_exists($loadedDraft['from_email'] ?? '', $availableSenders) ? $loadedDraft['from_email'] : $defaultFromEmail;
        $currentDraftName = $loadedDraft['name'];
    } elseif ($reusedSend) {
        $subject = $reusedSend['subject'];
        $headline = $reusedSend['headline'] ?? '';
        $episodeLink = $reusedSend['episode_link'] ?? '';
        $bodyText = $reusedSend['body_text'];

        // Foto(s) des alten Sends werden jetzt mit uebernommen (Thorstens
        // ausdruecklicher Wunsch) - ueber den bestehenden Foto-Editor lassen sie
        // sich direkt hier anpassen/entfernen/ersetzen, ganz wie bei "Fotos
        // zurechtruecken" in der Vorschau. Gleicher Fallback wie bei "Ansehen"
        // fuer sehr alte Sends von vor der Mehrfachfoto-Tabelle.
        $photosStmt = $pdo->prepare('SELECT * FROM newsletter_send_photos WHERE newsletter_send_id = :id ORDER BY sort_order ASC');
        $photosStmt->execute([':id' => (int) $reusedSend['id']]);
        $photos = array_map(
            static fn (array $row) => ['url' => $row['photo_url'], 'width' => (int) $row['photo_width'], 'align' => $row['photo_align']],
            $photosStmt->fetchAll()
        );
        if (empty($photos) && !empty($reusedSend['photo_url'])) {
            $photos = [[
                'url' => $reusedSend['photo_url'],
                'width' => normalize_photo_width($reusedSend['photo_width'] ?? 560),
                'align' => normalize_photo_align($reusedSend['photo_align'] ?? 'center'),
            ]];
        }

        $useHeadline = $headline !== '';
        $useEpisodeLink = $episodeLink !== '';
        $target = 'all';
        $selectedFromEmail = $defaultFromEmail;
    } else {
        $subject = $defaultSubject;
        $headline = $defaultHeadline;
        $episodeLink = $defaultEpisodeLink;
        $bodyText = '';
        $photos = [];
        // Anfangszustand der Modul-Checkboxen: Ueberschrift und Folgen-Link meist gewuenscht
        // (typischer Fall: neue Folge).
        $useHeadline = true;
        $useEpisodeLink = true;
        $target = 'all';
        $selectedFromEmail = $defaultFromEmail;
    }
}

$currentDraftName ??= '';

$customLists = $pdo->query('SELECT id, name FROM newsletter_lists ORDER BY name ASC')->fetchAll();
$drafts = $pdo->query('SELECT id, name, updated_at FROM newsletter_drafts ORDER BY updated_at DESC')->fetchAll();

// Formular standardmaessig eingeklappt, ausser nach einem Fehler, mit
// uebernommenen Werten aus einem alten Newsletter/einer Vorlage, oder beim
// Zurueckspringen aus der Vorschau - dann direkt offen.
$showCreateForm = $error !== null || $reusedSend !== null || $loadedDraft !== null
    || $action === 'edit_again' || $action === 'save_draft';
$showPhotosSection = !empty($photos);

$pastSends = $pdo->query(
    'SELECT ns.*, a.name AS sent_by_name
     FROM newsletter_sends ns
     LEFT JOIN admins a ON a.id = ns.sent_by
     ORDER BY ns.sent_at DESC'
)->fetchAll();
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
<?php require __DIR__ . '/partials/sidebar-open.php'; ?>
<main class="content-box">
    <h1>Newsletter versenden</h1>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if (isset($_GET['delete_error'])): ?>
        <p class="error text-center">Falsches Passwort — nichts wurde gelöscht.</p>
    <?php endif; ?>

    <?php if ($viewingSend !== null): ?>
        <h2>Vorschau: <?= htmlspecialchars($viewingSend['subject'], ENT_QUOTES) ?></h2>
        <p>Verschickt am <?= htmlspecialchars(date('d.m.Y', strtotime($viewingSend['sent_at'])), ENT_QUOTES) ?> um <?= htmlspecialchars(date('H:i', strtotime($viewingSend['sent_at'])), ENT_QUOTES) ?> Uhr an <strong><?= (int) $viewingSend['recipient_count'] ?></strong> Empfänger.
            <?php if (!empty($viewingSendRecipients)): ?>
                <button type="button" class="button-secondary" style="margin-bottom:0;margin-left:8px;padding:4px 10px;font-size:0.85rem;" data-show-create-form="recipients-list-<?= (int) $viewingSend['id'] ?>">Empfänger anzeigen</button>
            <?php endif; ?>
        </p>
        <?php if (!empty($viewingSendRecipients)): ?>
            <div id="recipients-list-<?= (int) $viewingSend['id'] ?>" style="display:none;margin-bottom:16px;">
                <button type="button" class="button-secondary" style="margin-bottom:8px;" data-hide-create-form="recipients-list-<?= (int) $viewingSend['id'] ?>">Empfänger ausblenden</button>
                <?php if (!$viewingSendRecipientsExact): ?>
                    <p style="font-size:0.85rem;color:#666;">Für diesen Versand wurde die Empfängerliste nicht mit gespeichert (vor Einführung dieser Funktion) - unten steht stattdessen die <strong>aktuelle</strong> Mitgliedschaft der damaligen Zielgruppe, die inzwischen abweichen kann.</p>
                <?php endif; ?>
                <div class="table-scroll" style="max-height:300px;overflow-y:auto;">
                    <ul style="margin:0;padding-left:20px;">
                        <?php foreach ($viewingSendRecipients as $recipientEmail): ?>
                            <li><?= htmlspecialchars($recipientEmail, ENT_QUOTES) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
        <iframe srcdoc="<?= htmlspecialchars(render_email_html($templateFile, $viewingSend['headline'] ?? '', $viewingSend['episode_link'] ?? '', $viewingSend['body_text'], $viewingSendPhotos), ENT_QUOTES) ?>" style="width:100%;height:500px;border:1px solid #ccc;border-radius:8px;background:#fff;"></iframe>
        <div class="button-row" style="margin-top:16px;">
            <a class="button" href="<?= BASE_PATH ?>/admin/newsletter.php?reuse_id=<?= (int) $viewingSend['id'] ?>">Für neuen Newsletter übernehmen</a>
            <a class="button" href="<?= BASE_PATH ?>/admin/newsletter.php">Zurück</a>
        </div>
    <?php elseif ($previewHtml !== null): ?>
        <h2>Vorschau</h2>
        <p>Zielgruppe: <strong><?= htmlspecialchars($targetLabel, ENT_QUOTES) ?></strong> — <strong><?= $recipientCount ?></strong> gültige Empfänger. Absender: <strong><?= htmlspecialchars($availableSenders[$selectedFromEmail], ENT_QUOTES) ?></strong>. Nichts wird verschickt, bevor du unten aktiv auf "Jetzt senden" klickst.</p>
        <iframe srcdoc="<?= htmlspecialchars($previewHtml, ENT_QUOTES) ?>" style="width:100%;height:500px;border:1px solid #ccc;border-radius:8px;background:#fff;"></iframe>

        <?php if (!empty($photos)): ?>
        <h3 style="margin-top:24px;">Fotos zurechtrücken</h3>
        <p style="font-size:0.85rem;color:#666;">Breite/Ausrichtung anpassen und auf "Vorschau aktualisieren" klicken, um das Ergebnis oben zu sehen - es wird dabei noch nichts verschickt.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="preview">
            <input type="hidden" name="subject" value="<?= htmlspecialchars($subject, ENT_QUOTES) ?>">
            <input type="hidden" name="headline" value="<?= htmlspecialchars($headline, ENT_QUOTES) ?>">
            <input type="hidden" name="episode_link" value="<?= htmlspecialchars($episodeLink, ENT_QUOTES) ?>">
            <input type="hidden" name="body_text" value="<?= htmlspecialchars($bodyText, ENT_QUOTES) ?>">
            <input type="hidden" name="target" value="<?= htmlspecialchars($target, ENT_QUOTES) ?>">
            <input type="hidden" name="from_email" value="<?= htmlspecialchars($selectedFromEmail, ENT_QUOTES) ?>">
            <?php if ($useHeadline): ?><input type="hidden" name="use_headline" value="1"><?php endif; ?>
            <?php if ($useEpisodeLink): ?><input type="hidden" name="use_episode_link" value="1"><?php endif; ?>
            <?php render_photo_editor_fields($photos); ?>
            <div class="button-row">
                <button type="submit" class="button-secondary">Vorschau aktualisieren</button>
            </div>
        </form>
        <?php endif; ?>

        <div class="button-row" style="margin-top:16px;">
            <form method="post">
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="subject" value="<?= htmlspecialchars($subject, ENT_QUOTES) ?>">
                <input type="hidden" name="headline" value="<?= htmlspecialchars($headline, ENT_QUOTES) ?>">
                <input type="hidden" name="episode_link" value="<?= htmlspecialchars($episodeLink, ENT_QUOTES) ?>">
                <input type="hidden" name="body_text" value="<?= htmlspecialchars($bodyText, ENT_QUOTES) ?>">
                <input type="hidden" name="target" value="<?= htmlspecialchars($target, ENT_QUOTES) ?>">
            <input type="hidden" name="from_email" value="<?= htmlspecialchars($selectedFromEmail, ENT_QUOTES) ?>">
                <?php foreach ($photos as $i => $photo): ?>
                    <input type="hidden" name="existing_photos[<?= $i ?>][url]" value="<?= htmlspecialchars($photo['url'], ENT_QUOTES) ?>">
                    <input type="hidden" name="existing_photos[<?= $i ?>][width]" value="<?= (int) $photo['width'] ?>">
                    <input type="hidden" name="existing_photos[<?= $i ?>][align]" value="<?= htmlspecialchars($photo['align'], ENT_QUOTES) ?>">
                <?php endforeach; ?>
                <button type="submit">Jetzt an <?= $recipientCount ?> Empfänger senden</button>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="edit_again">
                <input type="hidden" name="subject" value="<?= htmlspecialchars($subject, ENT_QUOTES) ?>">
                <input type="hidden" name="headline" value="<?= htmlspecialchars($headline, ENT_QUOTES) ?>">
                <input type="hidden" name="episode_link" value="<?= htmlspecialchars($episodeLink, ENT_QUOTES) ?>">
                <input type="hidden" name="body_text" value="<?= htmlspecialchars($bodyText, ENT_QUOTES) ?>">
                <input type="hidden" name="target" value="<?= htmlspecialchars($target, ENT_QUOTES) ?>">
            <input type="hidden" name="from_email" value="<?= htmlspecialchars($selectedFromEmail, ENT_QUOTES) ?>">
                <?php if ($useHeadline): ?><input type="hidden" name="use_headline" value="1"><?php endif; ?>
                <?php if ($useEpisodeLink): ?><input type="hidden" name="use_episode_link" value="1"><?php endif; ?>
                <?php foreach ($photos as $i => $photo): ?>
                    <input type="hidden" name="existing_photos[<?= $i ?>][url]" value="<?= htmlspecialchars($photo['url'], ENT_QUOTES) ?>">
                    <input type="hidden" name="existing_photos[<?= $i ?>][width]" value="<?= (int) $photo['width'] ?>">
                    <input type="hidden" name="existing_photos[<?= $i ?>][align]" value="<?= htmlspecialchars($photo['align'], ENT_QUOTES) ?>">
                <?php endforeach; ?>
                <button type="submit" class="button-secondary" style="margin-bottom:0;">Zurück zum Bearbeiten</button>
            </form>
            <a class="button" href="<?= BASE_PATH ?>/admin/newsletter.php">Ganz neu anfangen</a>
        </div>
    <?php else: ?>
        <?php if ($draftSaved !== null): ?>
            <p style="color:#2e7d32;font-weight:bold;">Vorlage „<?= htmlspecialchars($draftSaved, ENT_QUOTES) ?>" gespeichert.</p>
        <?php endif; ?>
        <button type="button" class="button" data-show-create-form="create-form" style="<?= $showCreateForm ? 'display:none;' : '' ?>">+ Newsletter verfassen</button>
        <?php if (!empty($drafts)): ?>
        <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin:12px 0;">
            <label style="flex:1;min-width:200px;">Vorlage laden
                <select name="load_draft_id">
                    <option value="">– Vorlage wählen –</option>
                    <?php foreach ($drafts as $draft): ?>
                        <option value="<?= (int) $draft['id'] ?>" <?= $currentDraftName === $draft['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($draft['name'], ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button-secondary">Laden</button>
        </form>
        <?php endif; ?>
        <div id="create-form" style="<?= $showCreateForm ? '' : 'display:none;' ?>">
        <button type="button" class="button-secondary" data-hide-create-form="create-form">- Newsletter verfassen</button>
        <?php if ($reusedSend): ?>
            <p style="font-size:0.9rem;color:#666;">Betreff, Überschrift, Folgen-Link, Text und Foto(s) wurden aus dem gewählten Newsletter übernommen – im Foto-Bereich unten kannst du sie entfernen, ersetzen oder weitere hinzufügen.</p>
        <?php endif; ?>
        <?php if ($loadedDraft): ?>
            <p style="font-size:0.9rem;color:#666;">Vorlage „<?= htmlspecialchars($loadedDraft['name'], ENT_QUOTES) ?>" geladen. Etwaige Fotos werden bewusst <strong>nicht</strong> mit übernommen – bei Bedarf bitte neu hochladen.</p>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <label>Betreff
                <input type="text" name="subject" value="<?= htmlspecialchars($subject, ENT_QUOTES) ?>" required>
            </label>

            <?php
                $targetIsSingle = str_starts_with($target, 'single:');
                $singleEmailValues = $targetIsSingle ? explode(',', substr($target, 7)) : [];
                if ($singleEmailValues === []) {
                    $singleEmailValues = [''];
                }
            ?>
            <label>An wen senden?
                <select name="target" id="target_select">
                    <option value="all" <?= $target === 'all' ? 'selected' : '' ?>>Newsletter</option>
                    <?php foreach ($customLists as $list): ?>
                        <option value="list:<?= (int) $list['id'] ?>" <?= $target === 'list:' . $list['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($list['name'], ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="single" <?= $targetIsSingle ? 'selected' : '' ?>>Einzelne E-Mail-Adresse</option>
                </select>
            </label>
            <?php if (empty($customLists)): ?>
                <p style="font-size:0.85rem;color:#666;">Noch keine eigene Liste angelegt — das geht unter <a href="<?= BASE_PATH ?>/admin/newsletter-lists.php">Empfängerlisten</a>.</p>
            <?php endif; ?>
            <div id="field_single_email" style="<?= $targetIsSingle ? '' : 'display:none;' ?>">
                <label>E-Mail-Adresse(n)</label>
                <div id="single_email_rows">
                    <?php foreach ($singleEmailValues as $email): ?>
                        <div class="single-email-row" style="display:flex;gap:8px;align-items:center;max-width:50%;margin-bottom:8px;">
                            <input type="email" name="single_emails[]" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" style="flex:1;margin-top:0;">
                            <button type="button" class="button-secondary" data-remove-email-row title="Diese Zeile entfernen" style="margin-bottom:0;padding:6px 12px;">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="add_single_email_row" class="button" style="margin-bottom:16px;">+ Weitere Adresse</button>
            </div>

            <label>Absender
                <select name="from_email">
                    <?php foreach ($availableSenders as $address => $displayLabel): ?>
                        <option value="<?= htmlspecialchars($address, ENT_QUOTES) ?>" <?= $selectedFromEmail === $address ? 'selected' : '' ?>>
                            <?= htmlspecialchars($displayLabel, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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

            <label>Text der Newsletter-Mail</label>
            <div id="body_text_toolbar" class="richtext-toolbar">
                <button type="button" data-cmd="bold" title="Fett"><strong>F</strong></button>
                <button type="button" data-cmd="italic" title="Kursiv"><em>K</em></button>
                <button type="button" data-cmd="insertUnorderedList" title="Liste mit Punkten">• Liste</button>
                <button type="button" data-cmd="insertOrderedList" title="Nummerierte Liste">1. Liste</button>
                <button type="button" data-cmd="link" title="Link einfügen">🔗 Link</button>
            </div>
            <div id="body_text_editor" class="richtext-editor" contenteditable="true"><?= sanitize_newsletter_body_html($bodyText) ?></div>
            <!-- Kein "required" hier: ein unsichtbares Pflichtfeld (display:none) blockiert
                 in Chrome/Firefox das Absenden des kompletten Formulars stillschweigend,
                 ohne sichtbare Fehlermeldung - die Leer-Pruefung passiert stattdessen wie
                 gehabt serverseitig ($error bei leerem body_text, siehe oben). -->
            <textarea name="body_text" id="body_text_hidden" style="display:none;"></textarea>

            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                <input type="checkbox" id="chk_photo" <?= $showPhotosSection ? 'checked' : '' ?> style="width:auto;">
                Foto(s) einbinden
            </label>
            <div id="field_photo">
                <?php render_photo_editor_fields($photos); ?>
                <p style="font-size:0.85rem;color:#666;">Breite/Ausrichtung lassen sich nach der Vorschau noch feinjustieren.</p>
            </div>

            <p style="font-size:0.85rem;color:#666;">Logo und Fußzeile (Impressum/Datenschutz/Abmelden) der Vorlage bleiben immer unverändert. Nicht angehakte Module (Überschrift/Folgen-Link) bzw. eine leere Fotoliste erscheinen gar nicht erst im Newsletter.</p>

            <label>Vorlagenname (nur zum Speichern als Vorlage nötig)
                <input type="text" name="draft_name" value="<?= htmlspecialchars($currentDraftName, ENT_QUOTES) ?>" placeholder="z.B. Neue-Folge-Standard">
            </label>
            <p style="font-size:0.85rem;color:#666;">Ein bereits vorhandener Name wird beim erneuten Speichern überschrieben. Beim Speichern als Vorlage wird nichts verschickt, Fotos werden nicht mit übernommen.</p>

            <div class="button-row">
                <button type="submit" name="action" value="preview">Vorschau anzeigen</button>
                <button type="submit" name="action" value="save_draft" class="button-secondary" style="margin-bottom:0;">Als Vorlage speichern</button>
            </div>
        </form>
        <script>
            // Blendet die zu einer Checkbox gehoerenden Felder ein/aus - rein optisch,
            // die eigentliche Entscheidung (Modul an/aus) trifft serverseitig ohnehin
            // die Checkbox selbst (siehe admin/newsletter.php use_headline/use_episode_link) bzw.
            // bei Fotos schlicht eine leere/nicht-leere $photos-Liste (chk_photo ist rein optisch).
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

                // "target_select" ist kein Checkbox, sondern ein <select> - daher
                // eigene kleine Logik statt bind() (das nur .checked kennt).
                var targetSelect = document.getElementById('target_select');
                var singleEmailField = document.getElementById('field_single_email');
                if (targetSelect && singleEmailField) {
                    function updateSingleEmailField() {
                        singleEmailField.style.display = targetSelect.value === 'single' ? '' : 'none';
                    }
                    targetSelect.addEventListener('change', updateSingleEmailField);
                    updateSingleEmailField();
                }

                // "+ Weitere Adresse" haengt eine weitere leere Zeile an, die
                // "×"-Buttons entfernen ihre eigene Zeile wieder - mindestens eine
                // Zeile bleibt aber immer stehen, sonst laesst sich gar keine
                // Adresse mehr eingeben.
                var singleEmailRows = document.getElementById('single_email_rows');
                var addSingleEmailRowButton = document.getElementById('add_single_email_row');

                function bindRemoveButton(row) {
                    var removeButton = row.querySelector('[data-remove-email-row]');
                    if (!removeButton) return;
                    removeButton.addEventListener('click', function () {
                        if (singleEmailRows.children.length > 1) {
                            row.remove();
                        } else {
                            row.querySelector('input').value = '';
                        }
                    });
                }

                if (singleEmailRows && addSingleEmailRowButton) {
                    Array.prototype.forEach.call(singleEmailRows.children, bindRemoveButton);

                    addSingleEmailRowButton.addEventListener('click', function () {
                        var newRow = singleEmailRows.children[0].cloneNode(true);
                        newRow.querySelector('input').value = '';
                        singleEmailRows.appendChild(newRow);
                        bindRemoveButton(newRow);
                        newRow.querySelector('input').focus();
                    });
                }
            })();
        </script>
        </div>

        <h2>Bisherige Newsletter</h2>
        <?php if (empty($pastSends)): ?>
            <p>Noch kein Newsletter verschickt.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
                <tr><th>Betreff</th><th>Verschickt</th><th>Zielgruppe</th><th>Empfänger</th><th>Absender</th><th>Von</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($pastSends as $send): ?>
                <tr>
                    <td><?= htmlspecialchars($send['subject'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($send['sent_at'])), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($send['recipient_list_name'] ?? 'Newsletter', ENT_QUOTES) ?></td>
                    <td><?= (int) $send['recipient_count'] ?></td>
                    <td><?= htmlspecialchars($send['from_email'] ?? $availableSenders[$defaultFromEmail], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($send['sent_by_name'] ?? '—', ENT_QUOTES) ?></td>
                    <td>
                        <div class="actions">
                            <a class="button" href="<?= BASE_PATH ?>/admin/newsletter.php?view_id=<?= (int) $send['id'] ?>">Ansehen</a>
                            <a class="button" href="<?= BASE_PATH ?>/admin/newsletter.php?reuse_id=<?= (int) $send['id'] ?>">Übernehmen</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <h2>Gespeicherte Vorlagen</h2>
        <?php if (empty($drafts)): ?>
            <p>Noch keine Vorlage gespeichert.</p>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
                <tr><th>Name</th><th>Zuletzt geändert</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($drafts as $draft): ?>
                <tr>
                    <td><?= htmlspecialchars($draft['name'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($draft['updated_at'])), ENT_QUOTES) ?></td>
                    <td>
                        <div class="actions">
                            <a class="button" href="<?= BASE_PATH ?>/admin/newsletter.php?load_draft_id=<?= (int) $draft['id'] ?>">Laden</a>
                            <form method="post" onsubmit="return false;">
                                <input type="hidden" name="action" value="delete_draft">
                                <input type="hidden" name="draft_id" value="<?= (int) $draft['id'] ?>">
                                <button type="button" class="button-danger" onclick="requestDelete(this.form, 'Die Vorlage „<?= htmlspecialchars(addslashes($draft['name']), ENT_QUOTES) ?>“ wird dauerhaft gelöscht.')">Löschen</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<div id="confirm-step1" class="modal-overlay">
    <div class="modal-box">
        <p><strong>Bist du sicher?</strong></p>
        <p id="confirm-step1-text"></p>
        <div class="modal-actions">
            <button type="button" onclick="confirmStep1No()">Nein</button>
            <button type="button" class="button-danger" onclick="confirmStep1Yes()">Ja</button>
        </div>
    </div>
</div>
<div id="confirm-step2" class="modal-overlay">
    <div class="modal-box">
        <p><strong>Zur Bestätigung: dein Passwort</strong></p>
        <input type="password" id="confirm-password" placeholder="Passwort">
        <p id="confirm-error" class="error" style="display:none;"></p>
        <div class="modal-actions">
            <button type="button" onclick="confirmStep2Cancel()">Abbrechen</button>
            <button type="button" class="button-danger" onclick="confirmStep2Ok()">OK</button>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/sidebar-close.php'; ?>
<script src="<?= BASE_PATH ?>/admin/assets/confirm-delete.js?v=<?= @filemtime(__DIR__ . '/assets/confirm-delete.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/table-scroll-sync.js?v=<?= @filemtime(__DIR__ . '/assets/table-scroll-sync.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/toggle-create-form.js?v=<?= @filemtime(__DIR__ . '/assets/toggle-create-form.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/session-countdown.js?v=<?= @filemtime(__DIR__ . '/assets/session-countdown.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/scroll-restore.js?v=<?= @filemtime(__DIR__ . '/assets/scroll-restore.js') ?>"></script>
<script src="<?= BASE_PATH ?>/admin/assets/newsletter-richtext.js?v=<?= @filemtime(__DIR__ . '/assets/newsletter-richtext.js') ?>"></script>
</body>
</html>
