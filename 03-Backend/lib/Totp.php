<?php
declare(strict_types=1);

namespace Suedsalat;

/**
 * Minimale TOTP-Implementierung (RFC 6238 / RFC 4226), kompatibel mit
 * Google Authenticator, Microsoft Authenticator etc. Keine externe Abhaengigkeit.
 */
final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD_SECONDS = 30;
    private const DIGITS = 6;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function getOtpAuthUri(string $secret, string $accountEmail, string $issuer = 'Suedsalat'): string
    {
        $label = rawurlencode($issuer . ':' . $accountEmail);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD_SECONDS,
        ]);
        return "otpauth://totp/$label?$params";
    }

    /** Prueft den 6-stelligen Code, toleriert +/- 1 Zeitfenster (Uhr-Drift). */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $currentSlice = (int) floor(time() / self::PERIOD_SECONDS);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::generateCode($secret, $currentSlice + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    private static function generateCode(string $secret, int $timeSlice): string
    {
        $key = self::base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );
        $otp = $truncated % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $binaryString = '';
        foreach (str_split($data) as $char) {
            $binaryString .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $chunks = str_split($binaryString, 5);
        $output = '';
        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $output;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(str_replace('=', '', $secret));
        $binaryString = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = str_split($binaryString, 8);
        $output = '';
        foreach ($bytes as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr((int) bindec($byte));
            }
        }
        return $output;
    }
}
