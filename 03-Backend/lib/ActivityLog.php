<?php
declare(strict_types=1);

namespace Suedsalat;

use PDO;

/**
 * Liefert direkt von einem Admin angelegte Termine/Fotos (nicht aus einem
 * Nutzer-Feedback uebernommen) in einer einheitlichen Form, damit Dashboard
 * und Aktivitaeten-Seite dieselbe Liste nutzen koennen.
 */
final class ActivityLog
{
    public static function adminActions(PDO $pdo, ?int $limit = null): array
    {
        $events = $pdo->query(
            "SELECT e.*, a.name AS created_by_name
             FROM events e
             LEFT JOIN admins a ON a.id = e.created_by
             WHERE e.created_via_feedback_id IS NULL"
        )->fetchAll();

        $photos = $pdo->query(
            "SELECT p.*, a.name AS created_by_name
             FROM photos p
             LEFT JOIN admins a ON a.id = p.created_by
             WHERE p.created_via_feedback_id IS NULL"
        )->fetchAll();

        $items = [];

        foreach ($events as $event) {
            $items[] = [
                'source' => 'admin',
                'entity' => 'event',
                'sort_date' => $event['created_at'],
                'date' => $event['created_at'],
                'status_label' => 'Termin erstellt',
                'from' => $event['created_by_name'] ?? '—',
                'type' => 'Termin',
                'content' => $event['title'] . ' (' . date('d.m.Y', strtotime($event['event_date'])) . ')',
                'image_path' => null,
                'edit_link' => '/admin/events.php?edit=' . (int) $event['id'],
                'delete_action' => '/admin/events.php',
                'entity_id' => (int) $event['id'],
            ];
        }

        foreach ($photos as $photo) {
            $items[] = [
                'source' => 'admin',
                'entity' => 'photo',
                'sort_date' => $photo['published_at'],
                'date' => $photo['published_at'],
                'status_label' => 'Foto hochgeladen',
                'from' => $photo['created_by_name'] ?? '—',
                'type' => 'Foto',
                'content' => $photo['description'] ?: '(ohne Beschreibung)',
                'image_path' => $photo['image_path'],
                'edit_link' => '/admin/gallery.php?edit=' . (int) $photo['id'],
                'delete_action' => '/admin/gallery.php',
                'entity_id' => (int) $photo['id'],
            ];
        }

        usort($items, fn (array $a, array $b): int => strcmp($b['sort_date'], $a['sort_date']));

        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        return $items;
    }
}
