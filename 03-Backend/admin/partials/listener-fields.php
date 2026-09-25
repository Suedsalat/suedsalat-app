<?php
declare(strict_types=1);

// Formularfelder fuer Eintraege, die einem Hoererkonto gehoeren (App 2.0):
// - "Tipp von"/"Foto von": bei kontogebundenen Eintraegen kommt der Name live aus dem Konto,
//   das Feld ist dann nur Anzeige (umbenennen unter "Hoererkonten").
// - "Wessen Bild ist das?": entscheidet, ob das Bild mit "Meine Fotos loeschen" verschwindet.

use Suedsalat\ListenerContent;

/** Aktueller Spitzname des Kontos, dem der Eintrag gehoert - oder null. */
function admin_row_listener(PDO $pdo, ?array $row): ?array
{
    if ($row === null || empty($row['listener_id'])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, nickname, deletion_requested_at FROM listeners WHERE id = :id');
    $stmt->execute([':id' => (int) $row['listener_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * "Tipp von"-Feld. Ohne Konto frei editierbar wie bisher; mit Konto schreibgeschuetzt mit
 * Hinweis. Der gespeicherte Wert bleibt als Rueckfall-Name erhalten.
 */
function admin_submitter_field(PDO $pdo, string $label, string $hint, ?array $row, string $default): string
{
    $value = $row !== null ? (string) ($row['submitted_by_name'] ?? '') : $default;
    $listener = admin_row_listener($pdo, $row);
    if ($listener === null) {
        return '<label>' . htmlspecialchars($label, ENT_QUOTES) . ' <small>(' . htmlspecialchars($hint, ENT_QUOTES) . ')</small> '
            . '<input type="text" name="submitted_by_name" maxlength="100" value="' . htmlspecialchars($value, ENT_QUOTES) . '"></label>';
    }
    $shown = $listener['deletion_requested_at'] !== null ? ListenerContent::FORMER_MEMBER : (string) $listener['nickname'];
    return '<label>' . htmlspecialchars($label, ENT_QUOTES)
        . ' <small>(kommt aus dem Hörerkonto – ändern unter <a href="' . BASE_PATH . '/admin/listeners.php#listener-' . (int) $listener['id'] . '">Hörerkonten</a>)</small> '
        . '<input type="text" value="' . htmlspecialchars($shown, ENT_QUOTES) . '" readonly disabled>'
        . '<input type="hidden" name="submitted_by_name" value="' . htmlspecialchars($value, ENT_QUOTES) . '"></label>';
}

/**
 * Auswahl der Bildart. Nur sinnvoll, wenn der Eintrag einem Konto gehoert (bearbeiten) oder
 * gehoeren wird (aus einer Einsendung uebernehmen) - sonst leerer String.
 */
function admin_image_kind_field(PDO $pdo, string $table, ?array $row, bool $fromFeedback): string
{
    if (!$fromFeedback && admin_row_listener($pdo, $row) === null) {
        return '';
    }
    $current = $row['image_kind'] ?? null;
    $kind = in_array($current, ['own', 'poster'], true) ? $current : ListenerContent::DEFAULT_IMAGE_KIND[$table];
    $radio = static fn (string $value, string $text, string $note): string =>
        '<label style="display:flex;align-items:center;gap:8px;font-weight:normal;">'
        . '<input type="radio" name="image_kind" value="' . $value . '" style="width:auto;"' . ($kind === $value ? ' checked' : '') . '> '
        . $text . ' <small>– ' . $note . '</small></label>';
    return '<fieldset style="border:1px solid #ddd;border-radius:8px;padding:8px 12px;margin:8px 0;">'
        . '<legend>Wessen Bild ist das?</legend>'
        . $radio('own', 'Eigenes Foto des Hörers', 'verschwindet, wenn er sein Konto mit „Meine Fotos löschen“ löscht')
        . $radio('poster', 'Plakat oder fremdes Bild', 'bleibt immer')
        . '<small>Ein Bild, das ihr selbst hochladet, zählt automatisch als fremdes Bild.</small>'
        . '</fieldset>';
}

/** Gewaehlte Bildart aus dem Formular, oder null (= Voreinstellung / unveraendert). */
function admin_image_kind_from_post(): ?string
{
    $kind = $_POST['image_kind'] ?? null;
    return in_array($kind, ['own', 'poster'], true) ? $kind : null;
}

/**
 * Nach dem Speichern eines bearbeiteten Eintrags: selbst hochgeladenes Bild -> fremdes Bild
 * (bleibt bei Kontoloeschung), sonst die gewaehlte Bildart.
 */
function admin_save_image_kind(PDO $pdo, string $table, int $id, bool $adminUploaded): void
{
    $kind = $adminUploaded ? 'poster' : admin_image_kind_from_post();
    if ($kind === null || !array_key_exists($table, ListenerContent::DEFAULT_IMAGE_KIND)) {
        return;
    }
    $pdo->prepare("UPDATE {$table} SET image_kind = :k WHERE id = :id AND listener_id IS NOT NULL")
        ->execute([':k' => $kind, ':id' => $id]);
}
