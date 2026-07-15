<?php
declare(strict_types=1);

namespace Suedsalat;

/**
 * Verschickt Push-Benachrichtigungen ueber die Firebase Cloud Messaging
 * HTTP-v1-API. Bewusst ohne Composer-Abhaengigkeit umgesetzt (nur OpenSSL
 * fuer die JWT-Signatur und cURL fuer die HTTP-Aufrufe), damit kein
 * zusaetzliches Paket auf dem Server installiert werden muss.
 */
final class FcmSender
{
    private static ?array $serviceAccount = null;
    private static ?string $accessToken = null;

    private static function serviceAccount(): array
    {
        if (self::$serviceAccount === null) {
            $path = __DIR__ . '/../config/firebase-service-account.json';
            if (!is_file($path)) {
                throw new \RuntimeException('firebase-service-account.json nicht gefunden.');
            }
            self::$serviceAccount = json_decode((string) file_get_contents($path), true);
        }
        return self::$serviceAccount;
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function getAccessToken(): string
    {
        if (self::$accessToken !== null) {
            return self::$accessToken;
        }

        $account = self::serviceAccount();
        $now = time();

        $header = self::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64url((string) json_encode([
            'iss' => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signInput = "$header.$claims";
        openssl_sign($signInput, $signature, $account['private_key'], 'sha256WithRSAEncryption');
        $jwt = $signInput . '.' . self::base64url((string) $signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string) $response, true);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Firebase-Zugriffstoken konnte nicht abgerufen werden: ' . $response);
        }

        self::$accessToken = $data['access_token'];
        return self::$accessToken;
    }

    /**
     * Sendet Titel/Text an alle registrierten Geraete (push_tokens-Tabelle).
     * Entfernt Tokens, die FCM als ungueltig zurueckmeldet (z.B. App deinstalliert).
     */
    public static function sendToAllDevices(string $title, string $body): void
    {
        try {
            $projectId = self::serviceAccount()['project_id'];
            $accessToken = self::getAccessToken();
        } catch (\Throwable $e) {
            error_log('FCM: Push nicht moeglich - ' . $e->getMessage());
            return;
        }

        $pdo = Database::connection();
        $tokens = $pdo->query('SELECT device_token FROM push_tokens')->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tokens as $token) {
            $payload = json_encode([
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                ],
            ]);

            $ch = curl_init("https://fcm.googleapis.com/v1/projects/$projectId/messages:send");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $accessToken",
                    'Content-Type: application/json; charset=UTF-8',
                ],
                CURLOPT_POSTFIELDS => $payload,
            ]);
            curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($statusCode === 404 || $statusCode === 400) {
                $pdo->prepare('DELETE FROM push_tokens WHERE device_token = :token')->execute([':token' => $token]);
            }
        }
    }
}
