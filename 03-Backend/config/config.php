<?php
declare(strict_types=1);

// Laedt Variablen aus .env (liegt eine Ebene ueber config/) in getenv()/$_ENV.
function suedsalat_load_env(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException(".env nicht gefunden unter: $path");
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        $value = trim($value);
        if ($name === '') {
            continue;
        }
        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
}

// SUEDSALAT_ENV_FILE ist nur in der lokalen Testumgebung gesetzt und zeigt dort auf eine
// eigene Test-.env (Testdatenbank, Mail-Fangordner). Live ist sie nie gesetzt - dann gilt
// wie immer die .env neben config/.
suedsalat_load_env(getenv('SUEDSALAT_ENV_FILE') ?: __DIR__ . '/../.env');

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

// Normalisiert Unicode-Eingaben (z.B. Umlaute) auf NFC. Manche Systeme/Tastaturen
// (v.a. macOS) liefern Umlaute in zerlegter NFD-Form, was sonst zu falschen
// String-Vergleichen gegen die in NFC gespeicherten Werte fuehrt.
function normalize_input(string $value): string
{
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($normalized !== false) {
            return $normalized;
        }
    }
    return $value;
}

// Normalisiert E-Mail-Adressen fuer Vergleich/Speicherung: NFC (siehe oben) plus
// Domain-Teil einheitlich in Punycode/ASCII (IDNA). Browser/Mailclients senden
// bei Umlaut-Domains (z.B. suedsalat.eu mit ue) mal die Unicode-, mal die
// Punycode-Form - ohne Normalisierung wuerden diese nie als gleich erkannt.
function normalize_email(string $email): string
{
    $email = normalize_input(trim($email));
    $atPos = strrpos($email, '@');
    if ($atPos === false) {
        return $email;
    }
    $local = substr($email, 0, $atPos);
    $domain = substr($email, $atPos + 1);

    if (function_exists('idn_to_ascii')) {
        $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($asciiDomain !== false) {
            $domain = $asciiDomain;
        }
    } else {
        // Ohne PHP-Erweiterung "intl" (lokal fehlt sie, auf dem Server ist sie nicht garantiert)
        // selbst umwandeln - sonst wuerde z. B. info@suedsalat.eu mit ue als ungueltig abgelehnt.
        $domain = implode('.', array_map('punycode_label', explode('.', mb_strtolower($domain, 'UTF-8'))));
    }

    return $local . '@' . strtolower($domain);
}

// Punycode (RFC 3492) fuer einen Teil eines Domainnamens: "suedsalat" mit ue -> "xn--sdsalat-n2a".
// Reine ASCII-Teile bleiben unveraendert. Ersatz fuer idn_to_ascii(), wenn "intl" fehlt.
function punycode_label(string $label): string
{
    if (preg_match('/^[\x00-\x7F]*$/', $label)) {
        return $label;
    }
    $base = 36;
    $tMin = 1;
    $tMax = 26;
    $codePoints = array_map('mb_ord', mb_str_split($label, 1, 'UTF-8'));
    $digit = static fn (int $d): string => chr($d < 26 ? 97 + $d : 22 + $d);
    $adapt = static function (int $delta, int $numPoints, bool $first) use ($base, $tMin, $tMax): int {
        $delta = $first ? intdiv($delta, 700) : intdiv($delta, 2);
        $delta += intdiv($delta, $numPoints);
        $k = 0;
        while ($delta > intdiv(($base - $tMin) * $tMax, 2)) {
            $delta = intdiv($delta, $base - $tMin);
            $k += $base;
        }
        return $k + intdiv(($base - $tMin + 1) * $delta, $delta + 38);
    };

    $output = '';
    foreach ($codePoints as $cp) {
        if ($cp < 128) {
            $output .= chr($cp);
        }
    }
    $handled = $basicCount = strlen($output);
    if ($basicCount > 0) {
        $output .= '-';
    }
    $n = 128;
    $delta = 0;
    $bias = 72;
    $total = count($codePoints);
    while ($handled < $total) {
        $m = min(array_filter($codePoints, static fn (int $cp): bool => $cp >= $n));
        $delta += ($m - $n) * ($handled + 1);
        $n = $m;
        foreach ($codePoints as $cp) {
            if ($cp < $n) {
                $delta++;
            }
            if ($cp === $n) {
                $q = $delta;
                for ($k = $base; ; $k += $base) {
                    $t = $k <= $bias ? $tMin : ($k >= $bias + $tMax ? $tMax : $k - $bias);
                    if ($q < $t) {
                        break;
                    }
                    $output .= $digit($t + ($q - $t) % ($base - $t));
                    $q = intdiv($q - $t, $base - $t);
                }
                $output .= $digit($q);
                $bias = $adapt($delta, $handled + 1, $handled === $basicCount);
                $delta = 0;
                $handled++;
            }
        }
        $delta++;
        $n++;
    }
    return 'xn--' . $output;
}

define('DB_HOST', env('DB_HOST'));
define('DB_NAME', env('DB_NAME'));
define('DB_USER', env('DB_USER'));
define('DB_PASSWORD', env('DB_PASSWORD'));

define('SMTP_HOST', env('SMTP_HOST'));
define('SMTP_PORT', (int) env('SMTP_PORT', '587'));
define('SMTP_USER', env('SMTP_USER'));
define('SMTP_PASSWORD', env('SMTP_PASSWORD'));
define('SMTP_ENCRYPTION', env('SMTP_ENCRYPTION', 'tls'));
define('SMTP_FROM_ADDRESS', env('SMTP_FROM_ADDRESS'));
define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', 'Suedsalat'));
// Nur Testumgebung: Mails werden als Dateien in diesen Ordner geschrieben statt verschickt.
// Live nicht gesetzt.
define('MAIL_CAPTURE_DIR', env('MAIL_CAPTURE_DIR'));
// Nur Testbereich auf Strato (APP-test/): Praefix fuer den Mail-Betreff, z. B. "[TEST]".
define('MAIL_SUBJECT_PREFIX', env('MAIL_SUBJECT_PREFIX'));

define('APP_URL', env('APP_URL', 'https://www.suedsalat.eu'));
define('CRON_SECRET', env('CRON_SECRET'));
// Pfad-Anteil von APP_URL (z.B. "/APP", falls das Backend in einem Unterverzeichnis
// liegt statt im Webroot). Wird allen absoluten Links/Redirects im Admin-Bereich
// vorangestellt, damit diese unabhaengig vom Deployment-Pfad funktionieren.
define('BASE_PATH', rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/'));
define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('UPLOAD_URL_BASE', APP_URL . '/uploads');

// So stehen eigene Beitraege von Thorsten und Jenny in der App ("Tipp von Suedsalat"),
// egal wer von beiden sie anlegt - siehe submitted_by_name bei Tipps, Veranstaltungen, Fotos.
define('OWN_CONTENT_NAME', 'Südsalat');

// Feste Konstanten fuer Login-Sicherheit (siehe Konzept.md).
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);
define('PASSWORD_RESET_TTL_MINUTES', 60); // 1 Stunde
define('ADMIN_IDLE_TIMEOUT_MINUTES', 8);

// --- API-Auth fuer die App (anonyme Geraete-Tokens, siehe lib/Jwt.php, lib/ApiAuth.php) ---
define('JWT_SECRET', env('JWT_SECRET'));
define('APP_SECRET', env('APP_SECRET'));
// Rollout-Schalter: solange false, werden fehlende/ungueltige Tokens nur geloggt statt
// mit 401 abgelehnt - noetig, damit bereits installierte App-Versionen ohne Token-Code
// nicht sofort ausfallen. Erst auf true stellen, wenn die neue App-Version ausgerollt ist.
define('API_AUTH_ENFORCE', env('API_AUTH_ENFORCE', 'false') === 'true');
define('JWT_ACCESS_TTL_MINUTES', 60);
define('JWT_REFRESH_TTL_DAYS', 180);

// Zusaetzliche Bestaetigung vor endgueltigen Loeschvorgaengen/sensiblen
// Aktionen im gesamten Admin-Bereich - prueft die AKTUELL eingeloggte Person,
// unabhaengig davon, wessen Datensatz betroffen ist. Bevorzugt den 6-stelligen
// Authenticator-App-Code (TOTP), wenn 2FA fuer diesen Account aktiv ist -
// faellt sonst (Account ohne aktivierte 2FA) automatisch aufs normale
// Passwort zurueck, damit niemand ausgesperrt wird, der (noch) kein 2FA
// eingerichtet hat. Ein Admin MIT aktivem 2FA kann hier weiterhin auch sein
// Passwort eingeben, nicht nur den Code - schadet nicht, ist aber bewusst
// nicht die im UI beworbene Standardeingabe.
function verify_admin_delete_confirmation(\PDO $pdo, int $adminId, string $submitted): bool
{
    if ($submitted === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT password_hash, totp_secret, totp_enabled FROM admins WHERE id = :id');
    $stmt->execute([':id' => $adminId]);
    $admin = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($admin === false) {
        return false;
    }
    if ($admin['totp_enabled'] && $admin['totp_secret'] && \Suedsalat\Totp::verify($admin['totp_secret'], $submitted)) {
        return true;
    }
    return password_verify($submitted, $admin['password_hash']);
}

// Alter Name als duenner Alias beibehalten, falls irgendwo noch referenziert -
// verhaelt sich identisch zu verify_admin_delete_confirmation().
function verify_admin_password(\PDO $pdo, int $adminId, string $password): bool
{
    return verify_admin_delete_confirmation($pdo, $adminId, $password);
}

// Verschleiert eine E-Mail-Adresse fuer die Anzeige beim Passwort-Reset
// ("Code wurde an th**@****.net geschickt") - zeigt die ersten 2 Zeichen des
// lokalen Teils, den Rest als Sternchen, und beim Domainteil nur noch die
// Endung (.de/.net/.com/...). Arbeitet rein auf der vom Nutzer selbst
// eingegebenen Adresse, verraet also nichts, was der Nutzer nicht ohnehin
// schon selbst eingetippt hat (kein Konto-Enumerations-Leck).
function mask_email_for_display(string $email): string
{
    $atPos = strrpos($email, '@');
    if ($atPos === false) {
        return $email;
    }
    $local = substr($email, 0, $atPos);
    $domain = substr($email, $atPos + 1);

    $visibleLocal = mb_substr($local, 0, 2);
    $hiddenLocalLength = max(0, mb_strlen($local) - mb_strlen($visibleLocal));
    $maskedLocal = $visibleLocal . str_repeat('*', $hiddenLocalLength);

    $lastDot = strrpos($domain, '.');
    $extension = $lastDot !== false ? substr($domain, $lastDot) : '';
    $maskedDomain = str_repeat('*', 4) . $extension;

    return $maskedLocal . '@' . $maskedDomain;
}

// Baut aus dem gemeinsamen Briefkopf-Template (Logo + gruener Balken mit
// Ueberschrift, Inhalt, Fusszeile mit Impressum/Datenschutz) eine fertige
// HTML-Mail fuer alle Transaktions-Mails (Freischaltungscode, Konto-
// Bestaetigung usw.) - bewusst getrennt vom Newsletter-eigenen Template
// (newsletter/email_template.html), das zusaetzlich Foto/Folgen-Link/
// Abmelde-Fusszeile kennt, was fuer diese Mails nicht passt.
function render_branded_email_html(string $headline, string $bodyHtml): string
{
    $template = file_get_contents(__DIR__ . '/branded_email_template.html');
    return str_replace(
        ['[EMAIL_BANNER_HEADLINE]', '[EMAIL_BODY]'],
        [htmlspecialchars($headline, ENT_QUOTES), $bodyHtml],
        $template
    );
}

// Wandelt eine volle Bild-URL (z.B. UPLOAD_URL_BASE.'/gallery/xyz.jpg') in den
// Pfad relativ zu UPLOAD_DIR um ('gallery/xyz.jpg') - fuer admin/photo-editor.php,
// das nur mit Pfaden unterhalb von uploads/ arbeiten darf. Gibt null zurueck,
// wenn die URL gar nicht aus uploads/ stammt (z.B. ein extern verlinktes Bild).
function upload_url_to_relative_path(string $url): ?string
{
    $prefix = UPLOAD_URL_BASE . '/';
    if (!str_starts_with($url, $prefix)) {
        return null;
    }
    return substr($url, strlen($prefix));
}

// Druckt das Mikro-Wasserzeichen (05-Assets/Mikro/Mikro_transparent.png, als
// admin/assets/img/watermark-mikro.png mit ausgeliefert) unten rechts auf ein
// bereits geladenes GD-Bild - genutzt beim Veroeffentlichen eines Fotos in
// Galerie/Filmtipps/Locations/Veranstaltungen (siehe apply_mic_watermark()
// fuer die Datei-basierte Variante ohne eigenen Resize-Schritt). Bewusst mit
// reduzierter Deckkraft (~45%), damit es als Branding erkennbar, aber nicht
// aufdringlich ist. Tut nichts, wenn die Wasserzeichen-Datei fehlt.
function apply_mic_watermark_to_gd_image($image): void
{
    $watermarkSource = __DIR__ . '/../admin/assets/img/watermark-mikro.png';
    if (!is_file($watermarkSource)) {
        return;
    }
    $watermark = @imagecreatefrompng($watermarkSource);
    if ($watermark === false) {
        return;
    }

    $targetWidth = imagesx($image);
    $targetHeight = imagesy($image);
    $wmWidth = max(28, min(140, (int) round($targetWidth * 0.12)));
    $wmHeight = (int) round($wmWidth * imagesy($watermark) / imagesx($watermark));
    if ($wmWidth < 1 || $wmHeight < 1) {
        imagedestroy($watermark);
        return;
    }

    $resizedWm = imagecreatetruecolor($wmWidth, $wmHeight);
    imagealphablending($resizedWm, false);
    imagesavealpha($resizedWm, true);
    $transparent = imagecolorallocatealpha($resizedWm, 0, 0, 0, 127);
    imagefill($resizedWm, 0, 0, $transparent);
    imagecopyresampled($resizedWm, $watermark, 0, 0, 0, 0, $wmWidth, $wmHeight, imagesx($watermark), imagesy($watermark));
    imagedestroy($watermark);

    // Deckkraft je Pixel Richtung transparent verschieben (~45% der
    // urspruenglichen Deckkraft bleibt uebrig) - imagecopymerge() allein
    // wuerde die Alpha-Kanten des Wasserzeichens sonst haesslich verfaelschen.
    for ($y = 0; $y < $wmHeight; $y++) {
        for ($x = 0; $x < $wmWidth; $x++) {
            $rgba = imagecolorat($resizedWm, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            $newAlpha = (int) min(127, $alpha + round((127 - $alpha) * 0.45));
            $colors = imagecolorsforindex($resizedWm, $rgba);
            $newColor = imagecolorallocatealpha($resizedWm, $colors['red'], $colors['green'], $colors['blue'], $newAlpha);
            imagesetpixel($resizedWm, $x, $y, $newColor);
        }
    }

    $margin = max(8, (int) round($targetWidth * 0.02));
    $destX = $targetWidth - $wmWidth - $margin;
    $destY = $targetHeight - $wmHeight - $margin;

    imagealphablending($image, true);
    imagesavealpha($image, true);
    imagecopy($image, $resizedWm, $destX, $destY, 0, 0, $wmWidth, $wmHeight);
    imagedestroy($resizedWm);
}

// Laedt $sourcePath, stempelt das Wasserzeichen drauf, speichert als
// $targetPath (kann identisch mit $sourcePath sein). Tut nichts bei Videos
// oder wenn GD fehlt. Das ist bewusst die EINZIGE Stelle, die ein Bild mit
// Wasserzeichen versieht - Original bleibt unangetastet, wenn $targetPath
// != $sourcePath (siehe apply_mic_watermark() fuer den alten In-Place-Fall).
function apply_mic_watermark_copy(string $sourcePath, string $targetPath): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $mime = mime_content_type($sourcePath);
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($sourcePath),
        'image/png' => @imagecreatefrompng($sourcePath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        default => false,
    };
    if ($image === false) {
        return false;
    }

    apply_mic_watermark_to_gd_image($image);

    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $saved = match ($mime) {
        'image/jpeg' => imagejpeg($image, $targetPath, 85),
        'image/png' => imagepng($image, $targetPath, 6),
        'image/webp' => function_exists('imagewebp') ? imagewebp($image, $targetPath, 85) : false,
        default => false,
    };
    imagedestroy($image);

    return (bool) $saved;
}

// Bequemlichkeits-Wrapper fuer den (seltenen) Fall, dass wirklich in derselben
// Datei gestempelt werden soll, ohne ein separates Original zu behalten.
function apply_mic_watermark(string $absPath): void
{
    apply_mic_watermark_copy($absPath, $absPath);
}

// Ordnet einem veroeffentlichten Bildpfad ("gallery/xyz.jpg") den Pfad seines
// unangetasteten Originals zu ("gallery/originals/xyz.jpg") - siehe
// [[project-suedsalat-app]]-Update zur Foto-Retusche: das Original bleibt
// IMMER ohne Wasserzeichen/Aufkleber erhalten, damit sich Aufkleber jederzeit
// nachtraeglich aendern/entfernen lassen, statt fest ins Bild "eingebrannt"
// zu sein. Das veroeffentlichte Bild (in der App/auf der Seite sichtbar) ist
// eine daraus erzeugte Kopie mit Wasserzeichen (+ ggf. Aufklebern).
function original_path_for_relative(string $relPublishedPath): string
{
    $dir = dirname($relPublishedPath);
    $file = basename($relPublishedPath);
    return ($dir !== '.' ? $dir . '/' : '') . 'originals/' . $file;
}

// Pfad der Aufkleber-Metadaten (JSON-Array aus {emoji,x,y,size}, Koordinaten
// in Pixeln des ORIGINALS) - liegt als "Sidecar"-Datei neben dem Original,
// keine eigene DB-Tabelle noetig.
function stickers_json_path_for_relative(string $relPublishedPath): string
{
    return original_path_for_relative($relPublishedPath) . '.json';
}

// Prueft, dass ein vom Nutzer kommender relativer Pfad tatsaechlich innerhalb
// von UPLOAD_DIR liegt (kein "../" o.ae. nach draussen) und dort bereits eine
// echte Datei ist - admin/photo-editor(-save).php duerfen nur bestehende,
// bereits hochgeladene Bilder ueberschreiben, keine beliebigen Dateien anlegen
// oder lesen. Gibt bei Erfolg den absoluten, aufgeloesten Pfad zurueck.
function resolve_upload_path(string $relativePath): ?string
{
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return null;
    }
    $real = realpath(UPLOAD_DIR . '/' . $relativePath);
    $uploadRoot = realpath(UPLOAD_DIR);
    if ($real === false || $uploadRoot === false || !str_starts_with($real, $uploadRoot . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $real;
}

// Loescht ein veroeffentlichtes Foto vollstaendig (Datei selbst, Original,
// evtl. Aufkleber-Metadaten) - genutzt beim Ersetzen/Entfernen eines Posters/
// Fotos in events.php/movie-tips.php/location-tips.php/gallery.php, damit in
// originals/ keine verwaisten Dateien liegen bleiben.
function delete_published_photo_and_original(string $relPublishedPath): void
{
    $publishedAbs = resolve_upload_path($relPublishedPath);
    if ($publishedAbs !== null) {
        unlink($publishedAbs);
    }
    $originalAbs = resolve_upload_path(original_path_for_relative($relPublishedPath));
    if ($originalAbs !== null) {
        unlink($originalAbs);
    }
    $stickersAbs = resolve_upload_path(stickers_json_path_for_relative($relPublishedPath));
    if ($stickersAbs !== null) {
        unlink($stickersAbs);
    }
}

// Wie resolve_upload_path(), aber fuer einen Zielpfad, der noch NICHT
// existieren muss (z.B. beim erstmaligen Anlegen eines Originals) - prueft
// nur, dass er syntaktisch innerhalb von UPLOAD_DIR liegen WUERDE (kein
// "../"), ohne realpath() auf eine bereits vorhandene Datei zu verlangen.
function resolve_upload_write_path(string $relativePath): ?string
{
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return null;
    }
    return UPLOAD_DIR . '/' . $relativePath;
}
