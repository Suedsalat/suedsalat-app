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

suedsalat_load_env(__DIR__ . '/../.env');

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
    }

    return $local . '@' . strtolower($domain);
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

define('APP_URL', env('APP_URL', 'https://www.suedsalat.eu'));
define('CRON_SECRET', env('CRON_SECRET'));
// Pfad-Anteil von APP_URL (z.B. "/APP", falls das Backend in einem Unterverzeichnis
// liegt statt im Webroot). Wird allen absoluten Links/Redirects im Admin-Bereich
// vorangestellt, damit diese unabhaengig vom Deployment-Pfad funktionieren.
define('BASE_PATH', rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/'));
define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('UPLOAD_URL_BASE', APP_URL . '/uploads');

// Feste Konstanten fuer Login-Sicherheit (siehe Konzept.md).
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);
define('PASSWORD_RESET_TTL_MINUTES', 60);
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

// Zusaetzliche Passwort-Bestaetigung vor endgueltigen Loeschvorgaengen im
// Admin-Bereich (Termine/Fotos/Aktivitaeten) - prueft das Passwort des
// AKTUELL eingeloggten Admins, unabhaengig davon, wessen Datensatz geloescht wird.
function verify_admin_password(\PDO $pdo, int $adminId, string $password): bool
{
    if ($password === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = :id');
    $stmt->execute([':id' => $adminId]);
    $hash = $stmt->fetchColumn();
    return $hash !== false && password_verify($password, $hash);
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

// Datei-basierte Variante fuer Stellen ohne eigenen Resize-Schritt (z.B.
// admin/gallery.php, das Fotos bisher unveraendert speichert) - laedt,
// stempelt, speichert wieder. Tut nichts bei Videos oder wenn GD fehlt.
function apply_mic_watermark(string $absPath): void
{
    if (!function_exists('imagecreatetruecolor')) {
        return;
    }
    $mime = mime_content_type($absPath);
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($absPath),
        'image/png' => @imagecreatefrompng($absPath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absPath) : false,
        default => false,
    };
    if ($image === false) {
        return;
    }

    apply_mic_watermark_to_gd_image($image);

    match ($mime) {
        'image/jpeg' => imagejpeg($image, $absPath, 85),
        'image/png' => imagepng($image, $absPath, 6),
        'image/webp' => function_exists('imagewebp') ? imagewebp($image, $absPath, 85) : null,
        default => null,
    };
    imagedestroy($image);
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
