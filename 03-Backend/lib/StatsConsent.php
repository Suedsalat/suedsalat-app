<?php
declare(strict_types=1);

namespace Suedsalat;

use PDO;

/**
 * Anonyme Statistik nur mit Einwilligung (App 2.0, § 25 TDDDG, Variante 2).
 *
 * Die Einwilligung gilt pro Installation, nicht pro Konto: Ein Gast kann zustimmen, und wer sich
 * anmeldet, nimmt seine Entscheidung nicht auf andere Geraete mit. Solange keine Entscheidung
 * vorliegt (alte App-Version, Dialog noch nicht beantwortet), wird nichts gezaehlt.
 *
 * TEXT_VERSION erhoehen, wenn sich der Dialogtext in der App inhaltlich aendert - dann fragt die
 * App erneut (needs_decision), weil sich die alte Einwilligung auf einen anderen Text bezog.
 */
final class StatsConsent
{
    public const TEXT_VERSION = '2026-10';
    public const DECISIONS = ['granted', 'denied'];

    public static function allowed(PDO $pdo, ?int $deviceId): bool
    {
        if ($deviceId === null) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM devices WHERE id = :id AND stats_consent = 'granted'");
        $stmt->execute([':id' => $deviceId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array{consent: ?string, text_version: ?string, decided_at: ?string, current_version: string, needs_decision: bool} */
    public static function state(PDO $pdo, int $deviceId): array
    {
        $stmt = $pdo->prepare('SELECT stats_consent, stats_consent_version, stats_consent_at FROM devices WHERE id = :id');
        $stmt->execute([':id' => $deviceId]);
        $row = $stmt->fetch() ?: ['stats_consent' => null, 'stats_consent_version' => null, 'stats_consent_at' => null];
        return [
            'consent' => $row['stats_consent'],
            'text_version' => $row['stats_consent_version'],
            'decided_at' => $row['stats_consent_at'],
            'current_version' => self::TEXT_VERSION,
            'needs_decision' => $row['stats_consent'] === null || $row['stats_consent_version'] !== self::TEXT_VERSION,
        ];
    }

    /** Entscheidung speichern (Stand am Geraet + Nachweis). Widerruf = 'denied', wirkt sofort. */
    public static function record(PDO $pdo, int $deviceId, ?int $listenerId, string $decision, string $textVersion): void
    {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE devices SET stats_consent = :d, stats_consent_version = :v, stats_consent_at = NOW() WHERE id = :id')
                ->execute([':d' => $decision, ':v' => $textVersion, ':id' => $deviceId]);
            $pdo->prepare('INSERT INTO statistics_consents (device_id, listener_id, decision, text_version, platform)
                           SELECT id, :l, :d, :v, platform FROM devices WHERE id = :id')
                ->execute([':l' => $listenerId, ':d' => $decision, ':v' => $textVersion, ':id' => $deviceId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Zahlen fuer den Admin-Bereich - bewusst ohne Namen oder Geraete.
     * @return array{granted:int, denied:int, not_asked:int, by_platform: array<string, array{granted:int, denied:int, not_asked:int}>}
     */
    public static function summary(PDO $pdo): array
    {
        $out = ['granted' => 0, 'denied' => 0, 'not_asked' => 0, 'by_platform' => []];
        $rows = $pdo->query("SELECT platform, COALESCE(stats_consent, 'not_asked') AS state, COUNT(*) AS n
                             FROM devices GROUP BY platform, COALESCE(stats_consent, 'not_asked')")->fetchAll();
        foreach ($rows as $r) {
            $state = (string) $r['state'];
            $platform = (string) $r['platform'];
            $out[$state] += (int) $r['n'];
            $out['by_platform'][$platform] ??= ['granted' => 0, 'denied' => 0, 'not_asked' => 0];
            $out['by_platform'][$platform][$state] += (int) $r['n'];
        }
        return $out;
    }
}
