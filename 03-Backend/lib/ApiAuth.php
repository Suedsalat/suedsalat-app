<?php
declare(strict_types=1);

namespace Suedsalat;

/**
 * Pruefung des Bearer-Access-Tokens fuer die App-API (api/*.php).
 *
 * Solange API_AUTH_ENFORCE=false ist (Rollout-Uebergang, siehe config.php), werden
 * fehlende/ungueltige Tokens nur geloggt statt mit 401 abgelehnt - das haelt bereits
 * installierte App-Versionen ohne Token-Code am Laufen, bis die neue Version
 * ausgerollt ist. Danach wird API_AUTH_ENFORCE=true gesetzt (reine .env-Aenderung).
 */
final class ApiAuth
{
    /** @return array<string,mixed>|null Die Token-Claims, oder null falls kein/ungueltiges Token (nur im Soft-Modus). */
    public static function requireDeviceToken(): ?array
    {
        $token = self::bearerToken();
        $claims = $token !== null ? Jwt::verify($token) : null;
        $valid = $claims !== null && ($claims['typ'] ?? null) === 'device';

        if (!$valid) {
            if (API_AUTH_ENFORCE) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Gueltiger Access-Token erforderlich.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            error_log('ApiAuth: Anfrage ohne gueltigen Device-Token (Soft-Modus, durchgelassen): ' . self::clientIp());
            return null;
        }

        return $claims;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if ($header === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if ($header === null || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return null;
        }

        return $matches[1];
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
