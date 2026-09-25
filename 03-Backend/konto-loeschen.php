<?php
declare(strict_types=1);

// Hoererkonto ueber die Website loeschen - fuer alle, die die App nicht (mehr) haben.
// Google Play verlangt diese Seite, sie gilt aber genauso fuer iPhone-Nutzer.
// Zwei Schritte ohne Sitzung:
//  1. E-Mail-Adresse -> Code per Mail. Die Antwort ist immer gleich, ob es ein Konto gibt oder
//     nicht - sonst liesse sich abfragen, wer bei Suedsalat angemeldet ist.
//  2. Code + Wahl (30 Tage stilllegen oder sofort endgueltig, Rezensionen/Fotos mitloeschen).
// Die Loeschung selbst laeuft genau wie in der App (api/listener/delete.php, delete-confirm.php).

require_once __DIR__ . '/config/bootstrap.php';

use Suedsalat\ApiAuth;
use Suedsalat\Database;
use Suedsalat\Listener;
use Suedsalat\ListenerContent;
use Suedsalat\ListenerMail;
use Suedsalat\RateLimiter;

const HOMEPAGE = 'https://www.xn--sdsalat-n2a.eu';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$schritt = 'start';     // start | code | stillgelegt | geloescht
$fehler = null;
$email = '';
$frist = null;          // Datum der endgueltigen Loeschung bei "stillgelegt"

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string) ($_POST['aktion'] ?? '');
    $email = mb_strtolower(normalize_email((string) ($_POST['email'] ?? '')), 'UTF-8');
    $ip = ApiAuth::clientIp();

    if ($email === '' || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
        $fehler = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    } elseif (RateLimiter::tooMany('web_delete', $ip, 20, 60)) {
        $fehler = 'Zu viele Anfragen. Bitte versuche es in einer Stunde noch einmal.';
        $schritt = $aktion === 'loeschen' ? 'code' : 'start';
    } elseif ($aktion === 'code') {
        RateLimiter::record('web_delete', $ip);
        $emailKey = 'e:' . substr(sha1($email), 0, 40);
        if (RateLimiter::tooMany('web_delete_mail', $emailKey, 5, 60)) {
            $fehler = 'Für diese E-Mail-Adresse wurden zu viele Codes angefordert. Bitte warte eine Stunde.';
        } else {
            RateLimiter::record('web_delete_mail', $emailKey);
            $pdo = Database::connection();
            $listener = Listener::findByEmail($pdo, $email);
            if ($listener !== null) {
                $code = Listener::issueCode($pdo, $email, 'delete_web', null, null);
                ListenerMail::code($email, (string) $listener['first_name'], $code, 'delete_web');
            }
            $schritt = 'code';
        }
    } elseif ($aktion === 'loeschen') {
        RateLimiter::record('web_delete', $ip);
        $schritt = 'code';
        $sofort = ($_POST['art'] ?? '') === 'sofort';
        $deleteTexts = isset($_POST['texte']);
        $deletePhotos = isset($_POST['fotos']);
        $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')) ?? '';

        $pdo = Database::connection();
        $result = Listener::consumeCode($pdo, $email, ['delete_web'], $code);
        $listener = $result['ok'] ? Listener::findByEmail($pdo, $email) : null;
        if (!$result['ok']) {
            $fehler = $result['error'];
        } elseif ($listener === null) {
            $fehler = 'Zu dieser E-Mail-Adresse gibt es kein Konto mehr.';
        } elseif ($sofort) {
            // Bestaetigungsmail VOR dem Loeschen - danach gibt es die Adresse nicht mehr.
            ListenerMail::deletionConfirmation($listener, true, $deleteTexts, $deletePhotos);
            ListenerContent::finalizeDeletion($pdo, (int) $listener['id'], $deleteTexts, $deletePhotos);
            Listener::deleteFinally($pdo, (int) $listener['id']);
            $schritt = 'geloescht';
        } else {
            // Schon stillgelegt: die laufende Frist nicht verlaengern.
            if ($listener['deletion_requested_at'] === null) {
                Listener::requestDeletion($pdo, (int) $listener['id'], $deleteTexts, $deletePhotos);
                ListenerContent::hideForDeletion($pdo, (int) $listener['id'], $deleteTexts, $deletePhotos);
                $listener = Listener::findById($pdo, (int) $listener['id']);
                ListenerMail::deletionConfirmation($listener, false, $deleteTexts, $deletePhotos);
            }
            $frist = date('d.m.Y', strtotime((string) $listener['deletion_final_at']));
            $schritt = 'stillgelegt';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Hörerkonto löschen – Südsalat-App</title>
<style>
  :root { --gruen: #77B538; --gruen-dunkel: #5a8c28; --sand: #E2DDBF; --nacht: #102024; --fehler: #b3261e; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #f6f4ea; color: var(--nacht); font: 17px/1.55 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  header { background: var(--nacht); color: #fff; padding: 18px 16px; border-bottom: 5px solid var(--gruen); }
  header p { margin: 0; max-width: 720px; margin-inline: auto; font-weight: 700; letter-spacing: .04em; }
  main { max-width: 720px; margin: 0 auto; padding: 24px 16px 48px; }
  h1 { font-size: 1.6em; line-height: 1.25; margin: 0 0 16px; }
  h2 { font-size: 1.15em; margin: 28px 0 8px; }
  ul { padding-left: 1.2em; }
  .karte { background: #fff; border: 1px solid var(--sand); border-radius: 12px; padding: 20px; margin: 20px 0; }
  label { display: block; font-weight: 600; margin: 14px 0 6px; }
  label.wahl { font-weight: 400; display: flex; gap: 10px; align-items: flex-start; margin: 10px 0; }
  label.wahl input { margin-top: 5px; flex: none; }
  input[type=email], input[type=text] { width: 100%; font: inherit; padding: 10px 12px; border: 1px solid #b9b49a; border-radius: 8px; }
  input[name=code] { letter-spacing: .3em; font-size: 1.3em; max-width: 12em; }
  button { font: inherit; font-weight: 600; margin-top: 18px; padding: 11px 20px; border: 0; border-radius: 8px; background: var(--gruen); color: #fff; cursor: pointer; }
  button:hover { background: var(--gruen-dunkel); }
  button.gefahr { background: var(--fehler); }
  .fehler { color: var(--fehler); font-weight: 600; }
  .klein { font-size: .9em; color: #4a5558; }
  a { color: var(--gruen-dunkel); }
  footer { max-width: 720px; margin: 0 auto; padding: 0 16px 32px; font-size: .9em; }
</style>
</head>
<body>
<header><p>SÜDSALAT – Themen aus dem Leben</p></header>
<main>
<h1>Hörerkonto der Südsalat-App löschen</h1>

<?php if ($schritt === 'geloescht'): ?>
    <div class="karte">
        <p><strong>Dein Konto ist endgültig gelöscht.</strong></p>
        <p>Eine Bestätigung haben wir dir per E-Mail geschickt. Danke, dass du dabei warst!</p>
    </div>
<?php elseif ($schritt === 'stillgelegt'): ?>
    <div class="karte">
        <p><strong>Dein Konto ist stillgelegt</strong> und wird am <strong><?= $e($frist) ?></strong> endgültig gelöscht.</p>
        <p>Bis dahin kannst du es zurückholen, indem du dich in der App wieder anmeldest. Eine Bestätigung haben wir dir per E-Mail geschickt.</p>
    </div>
<?php elseif ($schritt === 'code'): ?>
    <div class="karte">
        <p>Wenn es zu <strong><?= $e($email) ?></strong> ein Hörerkonto gibt, haben wir dir gerade einen sechsstelligen Code geschickt. Er ist <?= Listener::CODE_TTL_MINUTES ?> Minuten gültig.</p>
        <form method="post" action="">
            <input type="hidden" name="aktion" value="loeschen">
            <input type="hidden" name="email" value="<?= $e($email) ?>">
            <label for="code">Code aus der E-Mail</label>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>

            <label>Wie soll gelöscht werden?</label>
            <label class="wahl"><input type="radio" name="art" value="stilllegen" checked>
                <span><strong>30 Tage stilllegen, dann löschen</strong><br><span class="klein">Meldest du dich in dieser Zeit in der App wieder an, ist alles wieder da.</span></span></label>
            <label class="wahl"><input type="radio" name="art" value="sofort">
                <span><strong>Sofort endgültig löschen</strong><br><span class="klein">Das lässt sich nicht rückgängig machen.</span></span></label>

            <label>Außerdem löschen (freiwillig)</label>
            <label class="wahl"><input type="checkbox" name="texte" value="1">
                <span>meine Rezensionen und Kommentare</span></label>
            <label class="wahl"><input type="checkbox" name="fotos" value="1">
                <span>meine eigenen Fotos und Videos</span></label>

            <?php if ($fehler !== null): ?><p class="fehler"><?= $e($fehler) ?></p><?php endif; ?>
            <button type="submit" class="gefahr">Konto löschen</button>
        </form>
    </div>
    <p class="klein">Keine E-Mail bekommen? Schau im Spam-Ordner nach oder <a href="">fordere einen neuen Code an</a>.</p>
<?php else: ?>
    <p>Hier kannst du dein Hörerkonto der App „Südsalat“ löschen – auch wenn du die App nicht mehr installiert hast. Anbieter der App ist Thorsten Koch (siehe <a href="<?= HOMEPAGE ?>/seiten/impressum.html">Impressum</a>).</p>
    <p>In der App geht es genauso (iPhone und Android): <strong>Einstellungen › Mein Konto › Konto löschen</strong>.</p>

    <div class="karte">
        <form method="post" action="">
            <input type="hidden" name="aktion" value="code">
            <label for="email">E-Mail-Adresse deines Hörerkontos</label>
            <input type="email" id="email" name="email" value="<?= $e($email) ?>" autocomplete="email" required>
            <?php if ($fehler !== null): ?><p class="fehler"><?= $e($fehler) ?></p><?php endif; ?>
            <button type="submit">Code anfordern</button>
            <p class="klein">Wir schicken dir einen Code, damit niemand fremdes dein Konto löschen kann. Im nächsten Schritt wählst du aus, wie gelöscht wird.</p>
        </form>
    </div>

    <h2>Was gelöscht wird</h2>
    <ul>
        <li>dein Konto mit Vor- und Nachname, E-Mail-Adresse und Spitzname</li>
        <li>die Verknüpfung deines Kontos mit deinen Geräten und deine Anmeldecodes</li>
        <li>wenn du es auswählst: deine Rezensionen und Kommentare sowie deine eigenen Fotos und Videos</li>
    </ul>

    <h2>Was bleibt – ohne deinen Namen</h2>
    <ul>
        <li>Tipps, die wir aus deinen Vorschlägen übernommen haben</li>
        <li>Rezensionen, Kommentare, Fotos und Videos, die du nicht mitlöschen lässt – sie erscheinen als „Ehemaliges Mitglied“</li>
        <li>Nachrichten und Fragen, die du uns geschickt hast</li>
    </ul>

    <h2>Wann gelöscht wird</h2>
    <p>Standardmäßig legen wir dein Konto zuerst für 30 Tage still und löschen es danach endgültig. Wählst du „sofort“, ist es sofort endgültig gelöscht. Du bekommst in beiden Fällen eine Bestätigung per E-Mail.</p>
    <p>Probleme oder Fragen? Schreib uns an <a href="mailto:info@xn--sdsalat-n2a.eu">info@südsalat.eu</a> – wir löschen dein Konto dann für dich.</p>
<?php endif; ?>
</main>
<footer>
    <a href="<?= HOMEPAGE ?>/seiten/datenschutz.html">Datenschutzerklärung</a> ·
    <a href="<?= HOMEPAGE ?>/seiten/nutzungsbedingungen.html">Nutzungsbedingungen</a> ·
    <a href="<?= HOMEPAGE ?>/seiten/impressum.html">Impressum</a>
</footer>
</body>
</html>
