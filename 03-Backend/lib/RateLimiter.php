<?php
declare(strict_types=1);

namespace Suedsalat;

/**
 * Generisches, DB-gestuetztes Rate-Limiting pro IP und Bucket-Name.
 * Gleiches Prinzip wie Auth::isRateLimited()/recordLoginAttempt() (login_attempts-
 * Tabelle), nur nicht auf Login beschraenkt.
 */
final class RateLimiter
{
    public static function tooMany(string $bucket, string $ipAddress, int $maxAttempts, int $windowMinutes): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rate_limits
             WHERE bucket = :bucket AND ip_address = :ip
               AND created_at > (NOW() - INTERVAL :minutes MINUTE)'
        );
        $stmt->bindValue(':bucket', $bucket);
        $stmt->bindValue(':ip', $ipAddress);
        $stmt->bindValue(':minutes', $windowMinutes, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() >= $maxAttempts;
    }

    public static function record(string $bucket, string $ipAddress): void
    {
        Database::connection()
            ->prepare('INSERT INTO rate_limits (bucket, ip_address) VALUES (:bucket, :ip)')
            ->execute([':bucket' => $bucket, ':ip' => $ipAddress]);
    }
}
