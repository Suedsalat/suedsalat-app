<?php
declare(strict_types=1);

namespace Suedsalat;

use PDO;

/**
 * Gemeinsame Bausteine der Konto-Schnittstellen unter api/listener/.
 * Anders als die uebrigen API-Endpunkte verlangen diese IMMER ein gueltiges Geraete-Token,
 * unabhaengig von API_AUTH_ENFORCE - ohne Geraet gibt es nichts, womit sich ein Konto
 * verknuepfen liesse.
 */
final class ListenerApi
{
    /** @param array<string,mixed> $data */
    public static function json(int $status, array $data): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function fail(int $status, string $message): never
    {
        self::json($status, ['error' => $message]);
    }

    public static function requireMethod(string $method): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== $method) {
            self::fail(405, "Nur $method erlaubt.");
        }
    }

    /** Geraete-ID aus dem Access-Token - ohne gueltiges Token 401, auch im Soft-Modus. */
    public static function requireDeviceId(): int
    {
        $token = ApiAuth::bearerToken();
        $claims = $token !== null ? Jwt::verify($token) : null;
        if ($claims === null || ($claims['typ'] ?? null) !== 'device' || !isset($claims['sub'])) {
            self::fail(401, 'Gültiger Access-Token erforderlich.');
        }
        return (int) $claims['sub'];
    }

    /** @return array<string,mixed> Das am Geraet angemeldete Konto, sonst 401. */
    public static function requireListener(PDO $pdo, int $deviceId): array
    {
        $listener = Listener::forDevice($pdo, $deviceId);
        if ($listener === null) {
            self::fail(401, 'Bitte melde dich an.');
        }
        return $listener;
    }

    /** @return array<string,mixed> JSON-Body oder leeres Array. */
    public static function input(): array
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    /** Mengenbegrenzung pro IP und zusaetzlich pro E-Mail-Adresse (als Hash im IP-Feld). */
    public static function limit(string $bucket, int $perIp, ?string $email = null, int $perEmail = 5): void
    {
        $ip = ApiAuth::clientIp();
        if (RateLimiter::tooMany($bucket, $ip, $perIp, 60)) {
            self::fail(429, 'Zu viele Anfragen. Bitte versuche es später noch einmal.');
        }
        RateLimiter::record($bucket, $ip);
        if ($email !== null) {
            $emailKey = 'e:' . substr(sha1($email), 0, 40);
            if (RateLimiter::tooMany($bucket . '_mail', $emailKey, $perEmail, 60)) {
                self::fail(429, 'Für diese E-Mail-Adresse wurden zu viele Codes angefordert. Bitte warte eine Stunde.');
            }
            RateLimiter::record($bucket . '_mail', $emailKey);
        }
    }

    /**
     * E-Mail-Adresse aus der Eingabe, normalisiert und geprueft, sonst 422. Fuer Konten komplett
     * klein geschrieben, damit "Angela@..." und "angela@..." dasselbe Konto sind; Umlaute vor dem @
     * sind erlaubt (FILTER_FLAG_EMAIL_UNICODE).
     */
    public static function email(array $input): string
    {
        $email = mb_strtolower(normalize_email((string) ($input['email'] ?? '')), 'UTF-8');
        if ($email === '' || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            self::fail(422, 'Bitte gib eine gültige E-Mail-Adresse ein.');
        }
        return $email;
    }

    /** Vor- oder Nachname: Pflicht, 1-100 Zeichen. */
    public static function personName(array $input, string $field, string $label): string
    {
        $value = trim(normalize_input((string) ($input[$field] ?? '')));
        if ($value === '' || mb_strlen($value) > 100) {
            self::fail(422, "Bitte gib deinen {$label} ein (höchstens 100 Zeichen).");
        }
        return $value;
    }
}
