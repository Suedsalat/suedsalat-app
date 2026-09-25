<?php
declare(strict_types=1);

/**
 * Gemeinsame Helfer fuer die Ablauftests unter tests/. Laufen NUR gegen die lokale
 * Testumgebung (SUEDSALAT_ENV_FILE, Testdatenbank, Mail-Fangordner), siehe TESTUMGEBUNG.md.
 */

if (getenv('SUEDSALAT_ENV_FILE') === false) {
    fwrite(STDERR, "Abbruch: nur mit SUEDSALAT_ENV_FILE (Testumgebung) ausfuehren.\n");
    exit(2);
}
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = \Suedsalat\Database::connection();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'suedsalat_test') {
    fwrite(STDERR, "Abbruch: das ist nicht die Testdatenbank.\n");
    exit(2);
}

$failures = 0;
$checks = 0;

function check(bool $ok, string $what): void
{
    global $failures, $checks;
    $checks++;
    echo ($ok ? '  ok   ' : '  FEHL ') . $what . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function finish(): never
{
    global $failures, $checks;
    echo PHP_EOL . ($failures === 0 ? "ALLE {$checks} PRUEFUNGEN BESTANDEN" : "{$failures} VON {$checks} PRUEFUNGEN FEHLGESCHLAGEN") . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}

/**
 * Aufruf gegen den lokalen PHP-Testserver. $body als Array wird JSON; $form (Array) wird als
 * multipart-Formular geschickt (fuer api/feedback.php, api/submit-review.php).
 *
 * @return array{0:int,1:array<string,mixed>}
 */
function api(string $method, string $path, ?string $token = null, ?array $body = null, array $headers = [], ?array $form = null): array
{
    $ch = curl_init(APP_URL . $path);
    $h = $form === null ? ['Content-Type: application/json'] : [];
    if ($token !== null) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge($h, $headers),
        CURLOPT_POSTFIELDS => $form ?? ($body !== null ? json_encode($body) : null),
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($raw, true) ?? ['_raw' => $raw]];
}

function deviceToken(string $uuid): string
{
    [, $r] = api('POST', '/api/auth/device.php', null, ['device_uuid' => $uuid, 'platform' => 'android'], ['X-App-Secret: ' . APP_SECRET]);
    return (string) $r['access_token'];
}

/** Neueste abgefangene Mail an diese Adresse: [Betreff, HTML]. */
function lastMail(string $email): array
{
    $files = glob(MAIL_CAPTURE_DIR . '/*.html') ?: [];
    rsort($files);
    foreach ($files as $f) {
        $html = (string) file_get_contents($f);
        if (preg_match('/^<!-- An: .*<(.+?)> \| Betreff: (.*) -->/', $html, $m) && strcasecmp($m[1], $email) === 0) {
            return [$m[2], $html];
        }
    }
    return ['', ''];
}

function codeFrom(string $html): string
{
    return preg_match('/letter-spacing:4px[^>]*>(\d{6})</', $html, $m) ? $m[1] : '';
}

/** Konto komplett ueber die Schnittstellen anlegen und das Geraet anmelden. */
function registerListener(string $token, string $email, string $nickname): void
{
    api('POST', '/api/listener/register.php', $token, [
        'first_name' => 'Test', 'last_name' => 'Hoerer', 'email' => $email, 'nickname' => $nickname, 'accept_terms' => true,
    ]);
    [, $html] = lastMail(strtolower($email));
    api('POST', '/api/listener/verify.php', $token, ['email' => $email, 'code' => codeFrom($html)]);
}

/** Tabellen leeren und Mail-Fangordner raeumen. */
function resetTables(PDO $pdo, array $tables): void
{
    foreach (glob(MAIL_CAPTURE_DIR . '/*.html') ?: [] as $f) {
        unlink($f);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) {
        $pdo->exec("TRUNCATE TABLE {$t}");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT IGNORE INTO admins (id, name, email, password_hash, role) VALUES (1, 'Thorsten', 'owner@test.local', 'x', 'owner')");
}
