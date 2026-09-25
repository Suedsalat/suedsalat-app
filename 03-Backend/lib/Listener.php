<?php
declare(strict_types=1);

namespace Suedsalat;

use PDO;

/**
 * Registrierte Hoerer (App 2.0), siehe 01-Brainstorming/Konzept-2.0-Hoererkonto.md.
 *
 * Ein Konto haengt an Geraeten: Beim Anmelden wird die Installation (devices) mit dem Konto
 * verknuepft, die Zugangsschlaessel der App bleiben dieselben. Abmelden loest die Verknuepfung,
 * Sperren und Loeschen wirken dadurch sofort auf allen Geraeten.
 */
final class Listener
{
    public const TERMS_VERSION = '2026-10';
    public const CODE_TTL_MINUTES = 15;
    public const CODE_MAX_ATTEMPTS = 5;
    public const DELETION_GRACE_DAYS = 30;
    public const NICKNAME_MIN = 2;
    public const NICKNAME_MAX = 30;

    /**
     * Spitznamen, die Hoerer nicht waehlen duerfen - verglichen ueber nicknameKey(). Wer Suedsalat
     * enthaelt, ist gesperrt ("Suedsalat-Fan"); bei Thorsten und Jenny die genannten Formen.
     */
    private const RESERVED_CONTAINS = ['suedsalat', 'sudsalat'];
    private const RESERVED_EXACT = [
        'thorsten', 'jenny',
        'thorstenk', 'jennyf', 'thorstenkoch', 'jennyfourate',
        'kochthorsten', 'fouratejenny', 'tkoch', 'jfourate',
        'admin', 'administrator', 'moderator', 'moderation', 'team',
    ];
    private const RESERVED_PREFIX = ['thorstenkoch', 'jennyfourate'];

    /** Vereinfachte Form fuer Vergleiche: "Süd-Salat" -> "suedsalat", "Thorsten K." -> "thorstenk". */
    public static function nicknameKey(string $nickname): string
    {
        $key = mb_strtolower(normalize_input($nickname), 'UTF-8');
        $key = strtr($key, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a']);
        return preg_replace('/[^a-z0-9]/', '', $key) ?? '';
    }

    public static function isReservedNickname(string $nickname): bool
    {
        $key = self::nicknameKey($nickname);
        foreach (self::RESERVED_CONTAINS as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }
        foreach (self::RESERVED_PREFIX as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        // "Thorsten1", "Jenny_2" usw. sehen aus wie die Admins - Ziffern hinten dran helfen nicht.
        if (preg_match('/^(thorsten|jenny|thorstenk|jennyf)[0-9]+$/', $key)) {
            return true;
        }
        return in_array($key, self::RESERVED_EXACT, true);
    }

    /** Fehlermeldung fuer die App, oder null wenn der Spitzname in Ordnung und frei ist. */
    public static function nicknameProblem(PDO $pdo, string $nickname, ?int $exceptListenerId = null): ?string
    {
        $nickname = trim(normalize_input($nickname));
        $length = mb_strlen($nickname);
        if ($length < self::NICKNAME_MIN || $length > self::NICKNAME_MAX) {
            return 'Der Spitzname muss ' . self::NICKNAME_MIN . ' bis ' . self::NICKNAME_MAX . ' Zeichen lang sein.';
        }
        if (!preg_match('/^[\p{L}\p{N} ._\-]+$/u', $nickname)) {
            return 'Im Spitznamen sind nur Buchstaben, Ziffern, Leerzeichen, Punkt, Bindestrich und Unterstrich erlaubt.';
        }
        $key = self::nicknameKey($nickname);
        if (strlen($key) < self::NICKNAME_MIN) {
            return 'Der Spitzname braucht mindestens ' . self::NICKNAME_MIN . ' Buchstaben oder Ziffern.';
        }
        if (self::isReservedNickname($nickname) || WordFilter::containsInsult($nickname)) {
            return 'Dieser Spitzname ist leider nicht möglich.';
        }
        $stmt = $pdo->prepare('SELECT id FROM listeners WHERE nickname_key = :k');
        $stmt->execute([':k' => $key]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId !== false && (int) $ownerId !== $exceptListenerId) {
            return 'Dieser Spitzname ist schon vergeben.';
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function findByEmail(PDO $pdo, string $email): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM listeners WHERE email = :e');
        $stmt->execute([':e' => $email]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public static function findById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM listeners WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Das mit diesem Geraet angemeldete Konto, oder null (Gast). Konten in der Rueckkehrfrist
     * sind auf allen Geraeten abgemeldet und liefern deshalb ebenfalls null.
     *
     * @return array<string,mixed>|null
     */
    public static function forDevice(PDO $pdo, ?int $deviceId): ?array
    {
        if ($deviceId === null) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT l.* FROM devices d JOIN listeners l ON l.id = d.listener_id
             WHERE d.id = :d AND l.deletion_requested_at IS NULL'
        );
        $stmt->execute([':d' => $deviceId]);
        return $stmt->fetch() ?: null;
    }

    public static function linkDevice(PDO $pdo, int $listenerId, int $deviceId): void
    {
        $pdo->prepare('UPDATE devices SET listener_id = :l WHERE id = :d')->execute([':l' => $listenerId, ':d' => $deviceId]);
        $pdo->prepare('UPDATE listeners SET last_login_at = NOW() WHERE id = :l')->execute([':l' => $listenerId]);
    }

    public static function unlinkDevice(PDO $pdo, int $deviceId): void
    {
        $pdo->prepare('UPDATE devices SET listener_id = NULL WHERE id = :d')->execute([':d' => $deviceId]);
    }

    public static function unlinkAllDevices(PDO $pdo, int $listenerId): void
    {
        $pdo->prepare('UPDATE devices SET listener_id = NULL WHERE listener_id = :l')->execute([':l' => $listenerId]);
    }

    // ---------------------------------------------------------------------------------------
    // Codes per Mail
    // ---------------------------------------------------------------------------------------

    /**
     * Ist das die Adresse des Pruefkontos fuer Apple/Google? Nur wenn REVIEW_LOGIN_EMAIL gesetzt ist
     * und REVIEW_LOGIN_CODE genau sechs Ziffern hat - sonst ist das Pruefkonto abgeschaltet.
     */
    public static function isReviewEmail(string $email): bool
    {
        if (REVIEW_LOGIN_EMAIL === '' || preg_match('/^\d{6}$/', REVIEW_LOGIN_CODE) !== 1) {
            return false;
        }
        return mb_strtolower(normalize_email(REVIEW_LOGIN_EMAIL), 'UTF-8') === $email;
    }

    private static function codeHash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email . '|' . $code, APP_SECRET);
    }

    /**
     * Legt einen neuen Code an und gibt ihn zurueck (nur zum Versenden, gespeichert wird der Hash).
     * Aeltere, noch offene Codes derselben Adresse und Art werden damit ungueltig.
     *
     * @param array<string,mixed>|null $payload
     */
    public static function issueCode(PDO $pdo, string $email, string $purpose, ?array $payload, ?int $deviceId): string
    {
        $pdo->prepare('DELETE FROM listener_login_codes WHERE email = :e AND purpose = :p AND used_at IS NULL')
            ->execute([':e' => $email, ':p' => $purpose]);

        // Das Pruefkonto bekommt immer denselben Code - die Store-Pruefer kommen nicht an die Mails.
        $code = self::isReviewEmail($email) ? (string) REVIEW_LOGIN_CODE : (string) random_int(100000, 999999);
        $pdo->prepare(
            'INSERT INTO listener_login_codes (email, purpose, code_hash, payload, device_id, expires_at)
             VALUES (:e, :p, :h, :payload, :d, DATE_ADD(NOW(), INTERVAL ' . self::CODE_TTL_MINUTES . ' MINUTE))'
        )->execute([
            ':e' => $email,
            ':p' => $purpose,
            ':h' => self::codeHash($email, $code),
            ':payload' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            ':d' => $deviceId,
        ]);
        return $code;
    }

    /**
     * Prueft einen Code. Liefert die Code-Zeile (mit entschluesseltem payload) und markiert sie als
     * benutzt, oder einen Fehlertext. Zaehlt Fehlversuche; nach CODE_MAX_ATTEMPTS ist der Code tot.
     *
     * @param list<string> $purposes
     * @return array{ok: bool, row?: array<string,mixed>, error?: string}
     */
    public static function consumeCode(PDO $pdo, string $email, array $purposes, string $code): array
    {
        $placeholders = implode(',', array_fill(0, count($purposes), '?'));
        $stmt = $pdo->prepare(
            "SELECT * FROM listener_login_codes
             WHERE email = ? AND purpose IN ($placeholders) AND used_at IS NULL
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(array_merge([$email], $purposes));
        $row = $stmt->fetch();

        if (!$row || strtotime((string) $row['expires_at']) < time()) {
            return ['ok' => false, 'error' => 'Der Code ist abgelaufen. Bitte fordere einen neuen an.'];
        }
        if ((int) $row['attempts'] >= self::CODE_MAX_ATTEMPTS) {
            return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte fordere einen neuen Code an.'];
        }
        if (!hash_equals((string) $row['code_hash'], self::codeHash($email, trim($code)))) {
            $pdo->prepare('UPDATE listener_login_codes SET attempts = attempts + 1 WHERE id = :id')->execute([':id' => $row['id']]);
            return ['ok' => false, 'error' => 'Der Code stimmt nicht.'];
        }

        $pdo->prepare('UPDATE listener_login_codes SET used_at = NOW() WHERE id = :id')->execute([':id' => $row['id']]);
        $row['payload'] = $row['payload'] !== null ? json_decode((string) $row['payload'], true) : null;
        return ['ok' => true, 'row' => $row];
    }

    // ---------------------------------------------------------------------------------------
    // Konto loeschen
    // ---------------------------------------------------------------------------------------

    /**
     * Rueckkehrfrist: Konto sofort stilllegen (auf allen Geraeten abgemeldet, Spitzname
     * verschwindet aus der App), endgueltig loescht der Cronjob nach DELETION_GRACE_DAYS.
     */
    public static function requestDeletion(PDO $pdo, int $listenerId, bool $deleteTexts, bool $deletePhotos): void
    {
        $pdo->prepare(
            'UPDATE listeners SET deletion_requested_at = NOW(),
                deletion_final_at = DATE_ADD(NOW(), INTERVAL ' . self::DELETION_GRACE_DAYS . ' DAY),
                deletion_delete_texts = :t, deletion_delete_photos = :p, deletion_reminder_sent_at = NULL,
                updated_at = NOW()
             WHERE id = :id'
        )->execute([':t' => $deleteTexts ? 1 : 0, ':p' => $deletePhotos ? 1 : 0, ':id' => $listenerId]);
        self::unlinkAllDevices($pdo, $listenerId);
    }

    /** Anmeldung innerhalb der Rueckkehrfrist: alles wie vorher. */
    public static function restore(PDO $pdo, int $listenerId): void
    {
        $pdo->prepare(
            'UPDATE listeners SET deletion_requested_at = NULL, deletion_final_at = NULL,
                deletion_delete_texts = 0, deletion_delete_photos = 0, deletion_reminder_sent_at = NULL,
                updated_at = NOW()
             WHERE id = :id'
        )->execute([':id' => $listenerId]);
    }

    /**
     * Endgueltig loeschen: Konto, Name und E-Mail weg. Inhalte werden entkoppelt bzw. je nach Wahl
     * geloescht - das erledigt ListenerContent, sobald Beitraege einem Konto gehoeren (Etappe 3).
     */
    public static function deleteFinally(PDO $pdo, int $listenerId): void
    {
        self::unlinkAllDevices($pdo, $listenerId);
        $pdo->prepare('DELETE FROM listener_login_codes WHERE email = (SELECT email FROM listeners WHERE id = :id)')
            ->execute([':id' => $listenerId]);
        $pdo->prepare('DELETE FROM listeners WHERE id = :id')->execute([':id' => $listenerId]);
    }

    /**
     * Laeuft im Cronjob mit: Erinnerung drei Tage vor Fristende, endgueltige Loeschung nach Ablauf,
     * alte Codes wegraeumen. Gibt eine kurze Zusammenfassung fuers Cron-Log zurueck.
     */
    public static function runMaintenance(PDO $pdo): string
    {
        $reminded = 0;
        $stmt = $pdo->query("SELECT * FROM listeners
                             WHERE deletion_requested_at IS NOT NULL AND deletion_reminder_sent_at IS NULL
                               AND deletion_final_at <= DATE_ADD(NOW(), INTERVAL 3 DAY) AND deletion_final_at > NOW()");
        foreach ($stmt->fetchAll() as $l) {
            ListenerMail::deletionReminder($l);
            $pdo->prepare('UPDATE listeners SET deletion_reminder_sent_at = NOW() WHERE id = :id')->execute([':id' => $l['id']]);
            $reminded++;
        }

        $deleted = 0;
        $stmt = $pdo->query('SELECT * FROM listeners WHERE deletion_requested_at IS NOT NULL AND deletion_final_at <= NOW()');
        foreach ($stmt->fetchAll() as $l) {
            ListenerContent::finalizeDeletion($pdo, (int) $l['id'], (bool) $l['deletion_delete_texts'], (bool) $l['deletion_delete_photos']);
            self::deleteFinally($pdo, (int) $l['id']);
            $deleted++;
        }

        $pdo->exec('DELETE FROM listener_login_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        // Erledigte Meldungen nach 12 Monaten loeschen (Datenschutzerklaerung); offene bleiben bis zur Entscheidung.
        $meldungen = $pdo->exec("DELETE FROM content_reports WHERE status <> 'open' AND handled_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)");

        return "Hoererkonten: {$reminded} Erinnerung(en), {$deleted} endgueltig geloescht, {$meldungen} alte Meldung(en) geloescht.";
    }

    /** Was die App ueber das eigene Konto sehen darf (kein interner Kram). @param array<string,mixed> $l */
    public static function publicProfile(array $l): array
    {
        return [
            'id' => (int) $l['id'],
            'first_name' => $l['first_name'],
            'last_name' => $l['last_name'],
            'email' => $l['email'],
            'nickname' => $l['nickname'],
            'blocked' => $l['blocked_at'] !== null,
        ];
    }
}
