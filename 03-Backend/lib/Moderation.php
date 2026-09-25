<?php
declare(strict_types=1);

namespace Suedsalat;

use PDO;

/**
 * Melden, automatisches Ausblenden und "Nutzer ausblenden" (App 2.0),
 * siehe Konzept-2.0-Hoererkonto.md, Abschnitt 5.
 *
 * Automatisch ausgeblendet werden nur Beitraege, die ohne Pruefung durch Thorsten/Jenny online
 * gehen (Rezensionen, Galeriefotos, Kommentare). Tipps und Veranstaltungen sind beim
 * Uebernehmen schon geprueft - Meldungen dazu landen in der Liste, blenden aber nichts aus.
 */
final class Moderation
{
    public const AUTO_HIDE_THRESHOLD = 3;

    public const CATEGORIES = [
        'insult' => 'Beleidigung/Hass',
        'spam' => 'Spam/Werbung',
        'image' => 'Unangemessenes Bild',
        'rights' => 'Verletzt meine Rechte',
        'other' => 'Sonstiges',
    ];

    /** Meldbare Beitragsarten: Tabelle, automatisch ausblendbar, Bezeichnung fuer Admin und Mails. */
    public const TYPES = [
        'review' => ['table' => 'tip_reviews', 'hideable' => true, 'label' => 'Rezension', 'akkusativ' => 'eine Rezension'],
        'photo' => ['table' => 'photos', 'hideable' => true, 'label' => 'Galeriefoto', 'akkusativ' => 'ein Galeriefoto'],
        'comment' => ['table' => 'gallery_comments', 'hideable' => true, 'label' => 'Kommentar', 'akkusativ' => 'einen Kommentar'],
        'movie_tip' => ['table' => 'movie_tips', 'hideable' => false, 'label' => 'Filmtipp', 'akkusativ' => 'einen Filmtipp'],
        'location_tip' => ['table' => 'location_tips', 'hideable' => false, 'label' => 'Locationtipp', 'akkusativ' => 'einen Locationtipp'],
        'event' => ['table' => 'events', 'hideable' => false, 'label' => 'Veranstaltung', 'akkusativ' => 'eine Veranstaltung'],
    ];

    public static function isKnownType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** Gibt es den Beitrag, und ist er gerade sichtbar? */
    public static function isVisible(PDO $pdo, string $type, int $id): bool
    {
        $t = self::TYPES[$type];
        $hiddenCheck = $t['hideable'] ? ' AND hidden_at IS NULL' : '';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$t['table']} WHERE id = :id{$hiddenCheck}");
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Konto des Verfassers, oder null (Gast-Altbestand, eigener Suedsalat-Beitrag). */
    public static function authorOf(PDO $pdo, string $type, int $id): ?int
    {
        $stmt = $pdo->prepare('SELECT listener_id FROM ' . self::TYPES[$type]['table'] . ' WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $author = $stmt->fetchColumn();
        return $author === false || $author === null ? null : (int) $author;
    }

    /**
     * Meldung aufnehmen. Wer denselben Beitrag schon gemeldet hat, meldet ihn nicht doppelt.
     * Nach AUTO_HIDE_THRESHOLD Meldungen verschiedener registrierter Hoerer wird ein ausblendbarer
     * Beitrag sofort fuer alle ausgeblendet, bis Thorsten entscheidet.
     *
     * @return array{duplicate: bool, hidden: bool}
     */
    public static function report(PDO $pdo, string $type, int $id, ?int $reporterListenerId, int $reporterDeviceId, string $category, ?string $text): array
    {
        $dup = $pdo->prepare(
            "SELECT COUNT(*) FROM content_reports
             WHERE content_type = :t AND content_id = :id AND status = 'open'
               AND ((:l1 IS NOT NULL AND reporter_listener_id = :l2) OR (:l3 IS NULL AND reporter_device_id = :d))"
        );
        $dup->execute([':t' => $type, ':id' => $id, ':l1' => $reporterListenerId, ':l2' => $reporterListenerId, ':l3' => $reporterListenerId, ':d' => $reporterDeviceId]);
        if ((int) $dup->fetchColumn() > 0) {
            return ['duplicate' => true, 'hidden' => false];
        }

        $pdo->prepare(
            'INSERT INTO content_reports (content_type, content_id, reporter_listener_id, reporter_device_id, category, report_text)
             VALUES (:t, :id, :l, :d, :c, :x)'
        )->execute([':t' => $type, ':id' => $id, ':l' => $reporterListenerId, ':d' => $reporterDeviceId, ':c' => $category, ':x' => $text]);

        $hidden = false;
        if (self::TYPES[$type]['hideable']) {
            $count = $pdo->prepare(
                "SELECT COUNT(DISTINCT reporter_listener_id) FROM content_reports
                 WHERE content_type = :t AND content_id = :id AND status = 'open' AND reporter_listener_id IS NOT NULL"
            );
            $count->execute([':t' => $type, ':id' => $id]);
            if ((int) $count->fetchColumn() >= self::AUTO_HIDE_THRESHOLD) {
                $upd = $pdo->prepare('UPDATE ' . self::TYPES[$type]['table'] . " SET hidden_at = NOW(), hidden_reason = 'reports'
                                      WHERE id = :id AND hidden_at IS NULL");
                $upd->execute([':id' => $id]);
                $hidden = $upd->rowCount() > 0;
            }
        }

        self::notifyAdmins($pdo, $type, $id, $category, $text, $hidden);
        return ['duplicate' => false, 'hidden' => $hidden];
    }

    /** Meldung(en) zurueckweisen: Beitrag bleibt bzw. wird wieder sichtbar. */
    public static function dismiss(PDO $pdo, string $type, int $id, int $adminId): void
    {
        $pdo->prepare("UPDATE content_reports SET status = 'dismissed', handled_by = :a, handled_at = NOW()
                       WHERE content_type = :t AND content_id = :id AND status = 'open'")
            ->execute([':a' => $adminId, ':t' => $type, ':id' => $id]);
        if (self::TYPES[$type]['hideable']) {
            $pdo->prepare('UPDATE ' . self::TYPES[$type]['table'] . " SET hidden_at = NULL, hidden_reason = NULL
                           WHERE id = :id AND hidden_reason = 'reports'")->execute([':id' => $id]);
        }
    }

    /**
     * Beitrag nach Meldung entfernen (Rezension, Galeriefoto, Kommentar). Tipps werden auf ihrer Seite bearbeitet.
     * Der Verfasser erfaehrt per Mail, dass und warum (EU-Gesetz ueber digitale Dienste, Art. 17).
     */
    public static function removeContent(PDO $pdo, string $type, int $id, int $adminId, string $reason = ''): void
    {
        $authorId = self::authorOf($pdo, $type, $id);
        if ($authorId !== null && $reason !== '') {
            $author = Listener::findById($pdo, $authorId);
            if ($author !== null && $author['deletion_requested_at'] === null) {
                ListenerMail::contentRemoved($author, self::TYPES[$type]['akkusativ'], $reason);
            }
        }
        if ($type === 'photo') {
            $stmt = $pdo->prepare('SELECT image_path FROM photos WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $relative = upload_url_to_relative_path((string) $stmt->fetchColumn());
            if ($relative !== null) {
                delete_published_photo_and_original($relative);
            }
        }
        if (in_array($type, ['review', 'photo', 'comment'], true)) {
            $pdo->prepare('DELETE FROM ' . self::TYPES[$type]['table'] . ' WHERE id = :id')->execute([':id' => $id]);
        }
        $pdo->prepare("UPDATE content_reports SET status = 'removed', handled_by = :a, handled_at = NOW()
                       WHERE content_type = :t AND content_id = :id AND status = 'open'")
            ->execute([':a' => $adminId, ':t' => $type, ':id' => $id]);
    }

    // ---------------------------------------------------------------------------------------
    // Nutzer ausblenden (pro Hoerer)
    // ---------------------------------------------------------------------------------------

    /** Den Verfasser eines Beitrags fuer diesen Hoerer ausblenden. Liefert Fehlertext oder null. */
    public static function hideAuthor(PDO $pdo, int $viewerId, string $type, int $id): ?string
    {
        $author = self::authorOf($pdo, $type, $id);
        if ($author === null) {
            return 'Dieser Beitrag gehört zu keinem Hörerkonto.';
        }
        if ($author === $viewerId) {
            return 'Dich selbst kannst du nicht ausblenden.';
        }
        $pdo->prepare('INSERT IGNORE INTO listener_hidden (listener_id, hidden_listener_id) VALUES (:v, :a)')
            ->execute([':v' => $viewerId, ':a' => $author]);
        return null;
    }

    public static function unhide(PDO $pdo, int $viewerId, int $hiddenListenerId): void
    {
        $pdo->prepare('DELETE FROM listener_hidden WHERE listener_id = :v AND hidden_listener_id = :a')
            ->execute([':v' => $viewerId, ':a' => $hiddenListenerId]);
    }

    /** @return list<array{id:int, nickname:string}> */
    public static function hiddenUsers(PDO $pdo, int $viewerId): array
    {
        $stmt = $pdo->prepare('SELECT l.id, l.nickname FROM listener_hidden h JOIN listeners l ON l.id = h.hidden_listener_id
                               WHERE h.listener_id = :v AND l.deletion_requested_at IS NULL ORDER BY l.nickname');
        $stmt->execute([':v' => $viewerId]);
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'nickname' => (string) $r['nickname']], $stmt->fetchAll());
    }

    /** SQL-Bedingung: Beitraege von Nutzern, die der Betrachter ausgeblendet hat, weglassen. */
    public static function viewerFilterSql(string $alias, ?int $viewerId): string
    {
        if ($viewerId === null) {
            return '';
        }
        return " AND ({$alias}.listener_id IS NULL OR {$alias}.listener_id NOT IN"
            . " (SELECT hidden_listener_id FROM listener_hidden WHERE listener_id = " . (int) $viewerId . '))';
    }

    // ---------------------------------------------------------------------------------------

    private static function notifyAdmins(PDO $pdo, string $type, int $id, string $category, ?string $text, bool $hidden): void
    {
        $label = self::TYPES[$type]['label'];
        $body = '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;">In der App wurde ein Beitrag gemeldet:</p>'
            . '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;"><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</strong> · Grund: ' . htmlspecialchars(self::CATEGORIES[$category] ?? $category, ENT_QUOTES, 'UTF-8') . '</p>';
        if ($text !== null && $text !== '') {
            $body .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;">„' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '“</p>';
        }
        if ($hidden) {
            $body .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;"><strong>Der Beitrag ist jetzt automatisch ausgeblendet</strong> – '
                . self::AUTO_HIDE_THRESHOLD . ' verschiedene Hörer haben ihn gemeldet. Er bleibt unsichtbar, bis du entscheidest.</p>';
        }
        $url = APP_URL . '/admin/reports.php';
        $body .= '<p style="margin:0;font-size:16px;line-height:1.5;"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Zu den Meldungen</a></p>';

        foreach ($pdo->query('SELECT name, email FROM admins')->fetchAll() as $admin) {
            try {
                Mailer::send((string) $admin['email'], (string) $admin['name'],
                    ($hidden ? 'Beitrag ausgeblendet' : 'Neue Meldung') . ' – Südsalat',
                    render_branded_email_html('Neue Meldung', $body));
            } catch (\Throwable $e) {
                error_log('Mail zur Meldung fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }
}
