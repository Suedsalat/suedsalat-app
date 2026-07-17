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
define('ADMIN_IDLE_TIMEOUT_MINUTES', 5);

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
