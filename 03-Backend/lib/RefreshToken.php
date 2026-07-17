<?php
declare(strict_types=1);

namespace Suedsalat;

/**
 * DB-gestuetzte Refresh-Tokens fuer die App-API (anonyme Geraete-Identitaet).
 * Token-Muster (Rohtoken an den Client, SHA-256-Hash in der DB) folgt dem
 * bestehenden password_resets/email_verifications-Pattern (siehe admin/forgot-password.php).
 *
 * Rotation: jede erfolgreiche Verwendung widerruft das alte Token und stellt ein
 * neues aus (replaced_by_id verkettet die Historie). Wird ein bereits rotiertes
 * (widerrufenes) Token erneut vorgelegt, gilt das als Diebstahl-Signal - dann wird
 * die gesamte Kette fuer dieses subject_id gesperrt.
 */
final class RefreshToken
{
    public static function issue(string $subjectType, int $subjectId, int $ttlDays): string
    {
        [$rawToken] = self::insert($subjectType, $subjectId, $ttlDays);
        return $rawToken;
    }

    /** @return array{0: string, 1: int} [Rohtoken, neue Zeilen-ID] */
    private static function insert(string $subjectType, int $subjectId, int $ttlDays): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new \DateTime())->modify("+{$ttlDays} days")->format('Y-m-d H:i:s');

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO refresh_tokens (token_hash, subject_type, subject_id, expires_at)
             VALUES (:token_hash, :subject_type, :subject_id, :expires_at)'
        );
        $stmt->execute([
            ':token_hash' => $tokenHash,
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
            ':expires_at' => $expiresAt,
        ]);

        return [$rawToken, (int) $pdo->lastInsertId()];
    }

    /**
     * Prueft ein Refresh-Token und rotiert es (altes wird widerrufen, neues ausgestellt).
     * Gibt bei Erfolg ['subject_type' => ..., 'subject_id' => ..., 'refresh_token' => <neues Rohtoken>]
     * zurueck, sonst null.
     */
    public static function verifyAndRotate(string $rawToken, int $ttlDays): ?array
    {
        $tokenHash = hash('sha256', $rawToken);
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT * FROM refresh_tokens WHERE token_hash = :hash');
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        if ($row['revoked_at'] !== null) {
            // Ein bereits widerrufenes/rotiertes Token wird erneut vorgelegt -> vermutlich
            // gestohlen/kopiert. Vorsorglich die gesamte Kette fuer dieses Subjekt sperren.
            self::revokeAllForSubject((string) $row['subject_type'], (int) $row['subject_id']);
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        [$newRawToken, $newId] = self::insert((string) $row['subject_type'], (int) $row['subject_id'], $ttlDays);

        $pdo->prepare(
            'UPDATE refresh_tokens
             SET revoked_at = NOW(), last_used_at = NOW(), replaced_by_id = :new_id
             WHERE id = :id'
        )->execute([
            ':new_id' => $newId,
            ':id' => $row['id'],
        ]);

        return [
            'subject_type' => (string) $row['subject_type'],
            'subject_id' => (int) $row['subject_id'],
            'refresh_token' => $newRawToken,
        ];
    }

    public static function revoke(string $rawToken): void
    {
        $tokenHash = hash('sha256', $rawToken);
        Database::connection()
            ->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = :hash AND revoked_at IS NULL')
            ->execute([':hash' => $tokenHash]);
    }

    public static function revokeAllForSubject(string $subjectType, int $subjectId): void
    {
        Database::connection()
            ->prepare(
                'UPDATE refresh_tokens SET revoked_at = NOW()
                 WHERE subject_type = :type AND subject_id = :id AND revoked_at IS NULL'
            )
            ->execute([':type' => $subjectType, ':id' => $subjectId]);
    }
}
