<?php
declare(strict_types=1);

namespace Suedsalat;

use PDO;

/**
 * Beitraege registrierter Hoerer und was mit ihnen beim Loeschen eines Kontos passiert,
 * siehe Konzept-2.0-Hoererkonto.md, Abschnitt 10 Punkt 7.
 *
 * - Tipps (Veranstaltungen, Film-, Locationtipps) bleiben IMMER, nur der Name wird anonymisiert.
 * - "Meine Texte loeschen" = eigene Rezensionen (und spaeter Kommentare).
 * - "Meine Fotos loeschen" = als 'own' eingestufte Bilder: Galerie-Fotos und Bilder an Tipps.
 *   Plakate/Flyer/Pressebilder ('poster') bleiben.
 * - Nachrichten und Fragen an Suedsalat bleiben immer, als "Ehemaliges Mitglied".
 */
final class ListenerContent
{
    public const FORMER_MEMBER = 'Ehemaliges Mitglied';

    /** Tabelle => Spalte mit dem Titel (fuer die Admin-Mail "Tipps ohne Bild") und Admin-Seite. */
    public const TIP_TABLES = [
        'movie_tips' => ['title', 'movie-tips.php', 'Filmtipp'],
        'location_tips' => ['name', 'location-tips.php', 'Locationtipp'],
        'events' => ['title', 'events.php', 'Veranstaltung'],
    ];

    /**
     * SQL fuer den angezeigten Namen eines Beitrags: live der Spitzname aus dem Konto, waehrend der
     * Rueckkehrfrist "Ehemaliges Mitglied", ohne Konto der gespeicherte Rueckfall-Name.
     * Passt zu joinSql() mit demselben Listener-Alias.
     */
    public static function displayNameSql(string $alias, string $fallbackColumn, string $listenerAlias = 'lst'): string
    {
        $former = self::FORMER_MEMBER;
        return "CASE WHEN {$listenerAlias}.id IS NULL THEN {$alias}.{$fallbackColumn}"
            . " WHEN {$listenerAlias}.deletion_requested_at IS NOT NULL THEN '{$former}'"
            . " ELSE {$listenerAlias}.nickname END";
    }

    public static function joinSql(string $alias, string $listenerAlias = 'lst'): string
    {
        return "LEFT JOIN listeners {$listenerAlias} ON {$listenerAlias}.id = {$alias}.listener_id";
    }

    /**
     * Fuer die App: 'listener_id' der Zeilen durch 'is_own' (gehoert dem Betrachter) ersetzen -
     * damit die App bei eigenen Beitraegen kein "Melden"/"Ausblenden" anbietet, ohne dass die
     * Schnittstelle verraet, welches Konto hinter fremden Beitraegen steht.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function markOwn(array $rows, ?int $viewerId): array
    {
        return array_map(static function (array $row) use ($viewerId): array {
            $row['is_own'] = $viewerId !== null && $row['listener_id'] !== null && (int) $row['listener_id'] === $viewerId;
            unset($row['listener_id']);
            return $row;
        }, $rows);
    }

    /** Voreinstellung der Bildart beim Uebernehmen (Konzept: Film/Veranstaltung Plakat, sonst eigenes Foto). */
    public const DEFAULT_IMAGE_KIND = [
        'movie_tips' => 'poster',
        'events' => 'poster',
        'location_tips' => 'own',
        'photos' => 'own',
    ];

    /**
     * Beim Uebernehmen einer Einsendung: Der neue Eintrag gehoert dem Konto des Einsenders
     * (Live-Name, Loeschen). $imageKind setzt die Bildart, sofern der Eintrag ein Bild hat;
     * null = Voreinstellung der Tabelle.
     */
    public static function adoptFromFeedback(PDO $pdo, string $table, int $contentId, int $feedbackId, ?string $imageKind = null): void
    {
        if (!array_key_exists($table, self::DEFAULT_IMAGE_KIND)) {
            throw new \InvalidArgumentException("Unbekannte Tabelle: {$table}");
        }
        $kind = in_array($imageKind, ['own', 'poster'], true) ? $imageKind : self::DEFAULT_IMAGE_KIND[$table];
        $pdo->prepare("UPDATE {$table} SET listener_id = (SELECT listener_id FROM feedback_messages WHERE id = :fid),
                           image_kind = IF(image_path IS NULL, image_kind, :kind)
                       WHERE id = :id")->execute([':fid' => $feedbackId, ':kind' => $kind, ':id' => $contentId]);
    }

    /**
     * Beginn der Rueckkehrfrist (und erster Schritt bei "sofort endgueltig"): gewaehlte Beitraege
     * ausblenden, eigene Bilder von Tipps abnehmen und Thorsten informieren.
     */
    public static function hideForDeletion(PDO $pdo, int $listenerId, bool $deleteTexts, bool $deletePhotos): void
    {
        if ($deleteTexts) {
            $pdo->prepare("UPDATE tip_reviews SET hidden_at = NOW(), hidden_reason = 'deletion'
                           WHERE listener_id = :id AND hidden_at IS NULL")->execute([':id' => $listenerId]);
        }
        if (!$deletePhotos) {
            return;
        }

        $pdo->prepare("UPDATE photos SET hidden_at = NOW(), hidden_reason = 'deletion'
                       WHERE listener_id = :id AND image_kind = 'own' AND hidden_at IS NULL")->execute([':id' => $listenerId]);

        $lostImages = [];
        foreach (self::TIP_TABLES as $table => [$titleColumn, $page, $label]) {
            $stmt = $pdo->prepare("SELECT id, {$titleColumn} AS title FROM {$table}
                                   WHERE listener_id = :id AND image_kind = 'own' AND image_path IS NOT NULL");
            $stmt->execute([':id' => $listenerId]);
            foreach ($stmt->fetchAll() as $row) {
                $lostImages[] = ['label' => $label, 'title' => (string) $row['title'], 'page' => $page, 'id' => (int) $row['id']];
            }
            $pdo->prepare("UPDATE {$table} SET hidden_image_path = image_path, image_path = NULL,
                               image_removed_at = NOW(), image_notice_dismissed_at = NULL
                           WHERE listener_id = :id AND image_kind = 'own' AND image_path IS NOT NULL")->execute([':id' => $listenerId]);
        }
        if ($lostImages !== []) {
            self::notifyOwnerAboutLostImages($pdo, $lostImages);
        }
    }

    /** Anmeldung innerhalb der Rueckkehrfrist: Ausgeblendetes wieder zeigen, Bilder zurueckhaengen. */
    public static function restoreAfterReturn(PDO $pdo, int $listenerId): void
    {
        foreach (['tip_reviews', 'photos'] as $table) {
            $pdo->prepare("UPDATE {$table} SET hidden_at = NULL, hidden_reason = NULL
                           WHERE listener_id = :id AND hidden_reason = 'deletion'")->execute([':id' => $listenerId]);
        }
        foreach (array_keys(self::TIP_TABLES) as $table) {
            $stmt = $pdo->prepare("SELECT id, image_path, hidden_image_path FROM {$table}
                                   WHERE listener_id = :id AND hidden_image_path IS NOT NULL");
            $stmt->execute([':id' => $listenerId]);
            foreach ($stmt->fetchAll() as $row) {
                if ($row['image_path'] === null) {
                    // Noch kein Ersatz hinterlegt: das eigene Bild kommt zurueck.
                    $pdo->prepare("UPDATE {$table} SET image_path = hidden_image_path, hidden_image_path = NULL,
                                       image_removed_at = NULL, image_notice_dismissed_at = NULL WHERE id = :id")
                        ->execute([':id' => $row['id']]);
                } else {
                    // Thorsten hat schon ein neues Bild hinterlegt - das bleibt, das alte faellt weg.
                    self::deleteImageFile((string) $row['hidden_image_path']);
                    $pdo->prepare("UPDATE {$table} SET hidden_image_path = NULL, image_removed_at = NULL WHERE id = :id")
                        ->execute([':id' => $row['id']]);
                }
            }
        }
    }

    /**
     * Endgueltige Loeschung: Gewaehltes wirklich loeschen, alles andere vom Konto entkoppeln und als
     * "Ehemaliges Mitglied" stehen lassen. Muss VOR Listener::deleteFinally() laufen.
     */
    public static function finalizeDeletion(PDO $pdo, int $listenerId, bool $deleteTexts, bool $deletePhotos): void
    {
        // Bei "sofort endgueltig" gab es keine Rueckkehrfrist - Bilder jetzt abnehmen (inkl. Info
        // an Thorsten), damit der Ablauf danach derselbe ist.
        $stmt = $pdo->prepare('SELECT deletion_requested_at FROM listeners WHERE id = :id');
        $stmt->execute([':id' => $listenerId]);
        if ($stmt->fetchColumn() === null) {
            self::hideForDeletion($pdo, $listenerId, $deleteTexts, $deletePhotos);
        }

        $former = self::FORMER_MEMBER;
        $id = [':id' => $listenerId];

        // Rezensionen
        if ($deleteTexts) {
            $pdo->prepare('DELETE FROM tip_reviews WHERE listener_id = :id')->execute($id);
        } else {
            $pdo->prepare('UPDATE tip_reviews SET reviewer_name = :n, listener_id = NULL, device_id = NULL WHERE listener_id = :id')
                ->execute([':n' => $former, ':id' => $listenerId]);
        }

        // Galerie: eigene Fotos loeschen (falls gewaehlt), der Rest bleibt ohne Namen
        if ($deletePhotos) {
            $stmt = $pdo->prepare("SELECT id, image_path FROM photos WHERE listener_id = :id AND image_kind = 'own'");
            $stmt->execute($id);
            foreach ($stmt->fetchAll() as $photo) {
                self::deleteImageFile((string) $photo['image_path']);
                $pdo->prepare('DELETE FROM photos WHERE id = :pid')->execute([':pid' => $photo['id']]);
            }
        }
        $pdo->prepare('UPDATE photos SET submitted_by_name = :n, listener_id = NULL WHERE listener_id = :id')
            ->execute([':n' => $former, ':id' => $listenerId]);

        // Tipps bleiben immer; abgenommene eigene Bilder werden jetzt endgueltig geloescht
        foreach (array_keys(self::TIP_TABLES) as $table) {
            $stmt = $pdo->prepare("SELECT id, hidden_image_path FROM {$table} WHERE listener_id = :id AND hidden_image_path IS NOT NULL");
            $stmt->execute($id);
            foreach ($stmt->fetchAll() as $row) {
                self::deleteImageFile((string) $row['hidden_image_path']);
            }
            $pdo->prepare("UPDATE {$table} SET submitted_by_name = :n, listener_id = NULL, hidden_image_path = NULL
                           WHERE listener_id = :id")->execute([':n' => $former, ':id' => $listenerId]);
        }

        // Nachrichten und Fragen an Suedsalat bleiben immer
        $pdo->prepare('UPDATE feedback_messages SET sender_name = :n, listener_id = NULL WHERE listener_id = :id')
            ->execute([':n' => $former, ':id' => $listenerId]);
    }

    private static function deleteImageFile(string $url): void
    {
        $relative = upload_url_to_relative_path($url);
        if ($relative !== null) {
            delete_published_photo_and_original($relative);
        }
    }

    /** @param list<array{label:string,title:string,page:string,id:int}> $lostImages */
    private static function notifyOwnerAboutLostImages(PDO $pdo, array $lostImages): void
    {
        $items = '';
        foreach ($lostImages as $item) {
            $url = APP_URL . '/admin/' . $item['page'] . '?edit=' . $item['id'];
            $items .= '<li>' . htmlspecialchars($item['label'] . ' „' . $item['title'] . '“', ENT_QUOTES, 'UTF-8')
                . ' – <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">neues Bild hinterlegen</a></li>';
        }
        $body = '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;">Ein Hörer hat sein Konto gelöscht und dabei '
            . '„Meine Fotos löschen“ gewählt. Deshalb fehlt jetzt das Bild bei:</p>'
            . '<ul style="margin:0 0 16px;padding-left:20px;font-size:16px;line-height:1.5;">' . $items . '</ul>'
            . '<p style="margin:0;font-size:16px;line-height:1.5;">Die Tipps selbst bleiben mit Text und Rezensionen in der App. '
            . 'Hinterlegst du ein neues Bild, bleibt es – auch wenn der Hörer innerhalb der Rückkehrfrist zurückkommt.</p>';

        $owners = $pdo->query("SELECT name, email FROM admins WHERE role = 'owner'")->fetchAll();
        foreach ($owners as $owner) {
            try {
                Mailer::send((string) $owner['email'], (string) $owner['name'], 'Tipp ohne Bild – Südsalat',
                    render_branded_email_html('Tipps ohne Bild', $body));
            } catch (\Throwable $e) {
                error_log('Mail "Tipps ohne Bild" fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }
}
