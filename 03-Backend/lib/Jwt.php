<?php
declare(strict_types=1);

namespace Suedsalat;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;

/**
 * Duenner Wrapper um firebase/php-jwt fuer die Access-Tokens der App-API
 * (anonyme Geraete-Identitaet, siehe lib/ApiAuth.php, api/auth/device.php).
 */
final class Jwt
{
    private const ALGO = 'HS256';

    public static function issue(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $payload = $claims + [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ];
        return FirebaseJwt::encode($payload, JWT_SECRET, self::ALGO);
    }

    /** Gibt die Claims zurueck, oder null bei ungueltigem/abgelaufenem Token. */
    public static function verify(string $token): ?array
    {
        try {
            $decoded = FirebaseJwt::decode($token, new Key(JWT_SECRET, self::ALGO));
            return (array) $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
