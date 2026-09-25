<?php
declare(strict_types=1);

namespace Suedsalat;

use PDO;

/**
 * Kommentare unter Galerie-Fotos (App 2.0, Etappe 7): nur registrierte Hoerer, sofort sichtbar,
 * Spitzname live aus dem Konto. Melden/automatisch ausblenden/Nutzer ausblenden laufen ueber
 * Moderation (Typ "comment"), Kontoloeschung ueber ListenerContent ("Meine Texte loeschen").
 */
final class GalleryComments
{
    public const MAX_LAENGE = 1000;

    /**
     * Sichtbare Kommentare zu einem Foto, aelteste zuerst. Kommentare von Nutzern, die der
     * Betrachter ausgeblendet hat, fehlen fuer ihn.
     * @return list<array<string,mixed>>
     */
    public static function fuerFoto(PDO $pdo, int $photoId, ?int $viewerId): array
    {
        $stmt = $pdo->prepare(
            'SELECT c.id, c.comment_text, c.created_at, c.listener_id, '
            . ListenerContent::displayNameSql('c', 'author_name') . ' AS author_name
             FROM gallery_comments c ' . ListenerContent::joinSql('c') . '
             WHERE c.photo_id = :p AND c.hidden_at IS NULL' . Moderation::viewerFilterSql('c', $viewerId) . '
             ORDER BY c.created_at, c.id'
        );
        $stmt->execute([':p' => $photoId]);
        return ListenerContent::markOwn($stmt->fetchAll(), $viewerId);
    }

    /** Fehlertext oder null. */
    public static function textProblem(string $text): ?string
    {
        if ($text === '') {
            return 'Bitte schreib einen Kommentar.';
        }
        if (mb_strlen($text) > self::MAX_LAENGE) {
            return 'Der Kommentar ist zu lang (höchstens ' . self::MAX_LAENGE . ' Zeichen).';
        }
        if (WordFilter::containsInsult($text)) {
            return 'Bitte ohne Beleidigungen – so geht der Kommentar nicht online.';
        }
        return null;
    }

    /** Kommentar anlegen und die Admins informieren. Liefert die neue ID. */
    public static function anlegen(PDO $pdo, array $listener, int $photoId, string $text): int
    {
        $pdo->prepare('INSERT INTO gallery_comments (photo_id, listener_id, author_name, comment_text)
                       VALUES (:p, :l, :n, :t)')
            ->execute([':p' => $photoId, ':l' => (int) $listener['id'], ':n' => (string) $listener['nickname'], ':t' => $text]);
        $id = (int) $pdo->lastInsertId();
        self::adminsInformieren($pdo, $photoId, $id, (string) $listener['nickname'], $text);
        return $id;
    }

    /** Eigenen Kommentar loeschen. false, wenn es nicht der eigene ist. */
    public static function eigenenLoeschen(PDO $pdo, int $listenerId, int $commentId): bool
    {
        $stmt = $pdo->prepare('DELETE FROM gallery_comments WHERE id = :id AND listener_id = :l');
        $stmt->execute([':id' => $commentId, ':l' => $listenerId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $pdo->prepare("UPDATE content_reports SET status = 'dismissed', handled_at = NOW()
                       WHERE content_type = 'comment' AND content_id = :id AND status = 'open'")
            ->execute([':id' => $commentId]);
        return true;
    }

    /** @return array<int,int> Foto-ID => Anzahl sichtbarer Kommentare */
    public static function anzahlJeFoto(PDO $pdo, ?int $viewerId): array
    {
        $rows = $pdo->query('SELECT c.photo_id, COUNT(*) AS n FROM gallery_comments c
                             WHERE c.hidden_at IS NULL' . Moderation::viewerFilterSql('c', $viewerId) . '
                             GROUP BY c.photo_id')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['photo_id']] = (int) $r['n'];
        }
        return $out;
    }

    private static function adminsInformieren(PDO $pdo, int $photoId, int $commentId, string $nickname, string $text): void
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $p = 'margin:0 0 16px;font-size:16px;line-height:1.5;';
        $url = APP_URL . '/admin/gallery.php?edit=' . $photoId . '#comment-' . $commentId;
        $body = "<p style=\"{$p}\">Neuer Kommentar in der Galerie von <strong>{$e($nickname)}</strong>:</p>"
            . "<p style=\"{$p}\">„" . nl2br($e($text)) . '“</p>'
            . "<p style=\"margin:0;font-size:16px;line-height:1.5;\"><a href=\"{$e($url)}\">Zum Foto im Admin-Bereich</a></p>";
        foreach ($pdo->query('SELECT name, email FROM admins')->fetchAll() as $admin) {
            try {
                Mailer::send((string) $admin['email'], (string) $admin['name'], 'Neuer Kommentar in der Galerie – Südsalat',
                    render_branded_email_html('Neuer Kommentar', $body));
            } catch (\Throwable $ex) {
                error_log('Mail zum Kommentar fehlgeschlagen: ' . $ex->getMessage());
            }
        }
    }
}
