<?php
declare(strict_types=1);

// Richtet den Testbereich auf Strato ein (APP-test/ neben APP/), siehe TESTBEREICH.md.
// Laeuft NUR im Ordner APP-test - im Live-Ordner bricht es sofort ab.
//
// Schritte (jeder nur, wenn noch nicht erledigt - beliebig oft aufrufbar):
//  1. .env des Testbereichs aus der Live-.env bauen: eigene Datenbank (aus testbereich-zugang.env),
//     eigene Adresse, eigene Schluessel, Mail-Betreff "[TEST]". Die Zugangsdatei wird danach geloescht.
//  2. Live-Tabellenstruktur anlegen (aus _testbereich-schema.sql, danach geloescht).
//  3. Inhalte aus Live kopieren: Admins, Folgen, Tipps, Veranstaltungen, Fotos, Rezensionen.
//     Von Nachrichten nur Name und Art (ohne Text), von Geraeten nur Platzhalter - so bleibt die
//     Generalprobe der Release-Migrationen echt, ohne persoenliche Daten zu kopieren.
// Danach die beiden Release-Migrationen im Testbereich aufrufen und dieses Skript loeschen.
// Werte aus den .env-Dateien werden nie ausgegeben, nur Schluesselnamen.

$secret = 'suedsalat-testbereich-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}
header('Content-Type: text/plain; charset=utf-8');

$testRoot = dirname(__DIR__);
if (basename($testRoot) !== 'APP-test') {
    die("ABBRUCH: Dieses Skript laeuft nur im Ordner APP-test.\n");
}
$liveRoot = dirname($testRoot) . '/APP';

/** @return array<string,string> */
function read_env(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$name, $value] = array_pad(preg_split('/\s*[=:]\s*/', $line, 2) ?: [], 2, '');
        if (trim($name) !== '') {
            $values[strtoupper(trim($name))] = trim($value);
        }
    }
    return $values;
}

try {
    // --- 1. .env ------------------------------------------------------------------------------
    $envPath = $testRoot . '/.env';
    if (is_file($envPath)) {
        echo "OK: .env existiert bereits.\n";
    } else {
        $zugang = $testRoot . '/testbereich-zugang.env';
        if (!is_file($zugang)) {
            die("ABBRUCH: testbereich-zugang.env fehlt (DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, APP_SECRET).\n");
        }
        $mine = read_env($zugang);
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'APP_SECRET'] as $key) {
            if (($mine[$key] ?? '') === '') {
                die("ABBRUCH: In testbereich-zugang.env fehlt {$key}.\n");
            }
        }
        $live = read_env($liveRoot . '/.env');
        $liveUrl = $live['APP_URL'] ?? 'https://www.xn--sdsalat-n2a.eu/APP';
        $testUrl = preg_replace('#/APP/?$#', '/APP-test', $liveUrl);
        if ($testUrl === $liveUrl) {
            die("ABBRUCH: APP_URL der Live-.env endet nicht auf /APP - bitte pruefen.\n");
        }
        $overrides = [
            'DB_HOST' => $mine['DB_HOST'],
            'DB_NAME' => $mine['DB_NAME'],
            'DB_USER' => $mine['DB_USER'],
            'DB_PASSWORD' => $mine['DB_PASSWORD'],
            'APP_SECRET' => $mine['APP_SECRET'],
            'APP_URL' => $testUrl,
            'JWT_SECRET' => bin2hex(random_bytes(32)),
            'CRON_SECRET' => bin2hex(random_bytes(16)),
            'API_AUTH_ENFORCE' => 'true',
            'MAIL_SUBJECT_PREFIX' => '[TEST]',
        ];
        if (($live['DB_NAME'] ?? '') === $mine['DB_NAME']) {
            die("ABBRUCH: Die Testdatenbank heisst genauso wie die Live-Datenbank.\n");
        }
        $lines = ["# Testbereich - erzeugt von _testbereich-einrichten-once.php am " . date('Y-m-d H:i')];
        foreach (array_merge($live, $overrides) as $key => $value) {
            $lines[] = "{$key}={$value}";
        }
        file_put_contents($envPath, implode("\n", $lines) . "\n");
        @chmod($envPath, 0600);
        unlink($zugang);
        echo "OK: .env geschrieben. Eigene Werte fuer: " . implode(', ', array_keys($overrides)) . "\n";
        echo "    Aus Live uebernommen: " . implode(', ', array_keys(array_diff_key($live, $overrides))) . "\n";

        $fcm = '/config/firebase-service-account.json';
        if (is_file($liveRoot . $fcm) && !is_file($testRoot . $fcm)) {
            copy($liveRoot . $fcm, $testRoot . $fcm);
            echo "OK: Push-Zugang kopiert (Pushes gehen nur an Geraete, die im Testbereich angemeldet sind).\n";
        }
    }

    require_once $testRoot . '/config/bootstrap.php';
    $test = \Suedsalat\Database::connection();

    // --- 2. Struktur -------------------------------------------------------------------------
    $hasTables = (int) $test->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admins'")->fetchColumn() > 0;
    if ($hasTables) {
        echo "OK: Tabellen existieren bereits.\n";
    } else {
        $schemaFile = __DIR__ . '/_testbereich-schema.sql';
        if (!is_file($schemaFile)) {
            die("ABBRUCH: _testbereich-schema.sql fehlt.\n");
        }
        $test->exec('SET FOREIGN_KEY_CHECKS=0');
        $count = 0;
        foreach (preg_split('/;\s*\n/', (string) file_get_contents($schemaFile)) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $test->exec($statement);
                $count += preg_match('/^\s*CREATE TABLE/i', $statement);
            }
        }
        $test->exec('SET FOREIGN_KEY_CHECKS=1');
        unlink($schemaFile);
        echo "OK: {$count} Tabellen angelegt (Live-Struktur vor dem Release).\n";
    }

    // --- 3. Inhalte --------------------------------------------------------------------------
    if ((int) $test->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
        echo "OK: Inhalte sind schon kopiert.\n";
    } else {
        $liveEnv = read_env($liveRoot . '/.env');
        $livePdo = new PDO(
            'mysql:host=' . $liveEnv['DB_HOST'] . ';dbname=' . $liveEnv['DB_NAME'] . ';charset=utf8mb4',
            $liveEnv['DB_USER'],
            $liveEnv['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        // Nur lesen: die Live-Datenbank wird hier ausschliesslich mit SELECT angefasst.
        $livePdo->exec('SET SESSION TRANSACTION READ ONLY');

        $insertRows = static function (PDO $pdo, string $table, array $rows): int {
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $pdo->prepare('INSERT INTO ' . $table . ' (`' . implode('`, `', $cols) . '`) VALUES ('
                    . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
            }
            return count($rows);
        };

        $test->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['admins', 'app_settings', 'episodes_cache', 'episode_push_sent', 'events', 'movie_tips',
                  'location_tips', 'photos', 'tip_reviews'] as $table) {
            $n = $insertRows($test, $table, $livePdo->query("SELECT * FROM {$table}")->fetchAll());
            echo "OK: {$table}: {$n} kopiert.\n";
        }

        // Geraete nur als Platzhalter, soweit Rezensionen darauf verweisen (fuer die
        // "Tipp von"-Migration: Rezension mit Geraet = von einem Hoerer, ohne = von Suedsalat).
        $devices = $livePdo->query('SELECT DISTINCT d.id, d.platform, d.created_at FROM devices d
                                    JOIN tip_reviews r ON r.device_id = d.id')->fetchAll();
        foreach ($devices as &$d) {
            $d['device_uuid'] = 'testkopie-' . $d['id'];
        }
        unset($d);
        echo 'OK: devices: ' . $insertRows($test, 'devices', $devices) . " Platzhalter.\n";

        // Nachrichten nur, soweit Tipps/Fotos daraus entstanden sind - Name und Art, ohne Text,
        // Anhang oder Folge. Die "Tipp von"-Migration holt sich daraus den Namen.
        $feedback = $livePdo->query("SELECT id, sender_name, type, created_at FROM feedback_messages WHERE id IN (
                SELECT created_via_feedback_id FROM events UNION SELECT created_via_feedback_id FROM movie_tips
                UNION SELECT created_via_feedback_id FROM location_tips UNION SELECT created_via_feedback_id FROM photos)")->fetchAll();
        foreach ($feedback as &$f) {
            $f['message'] = '(Kopie fuer den Testbereich - Text nicht uebernommen)';
            $f['status'] = 'erledigt';
        }
        unset($f);
        echo 'OK: feedback_messages: ' . $insertRows($test, 'feedback_messages', $feedback) . " (nur Name und Art).\n";
        $test->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    echo "\nFertig. Als Naechstes im Testbereich die Release-Migrationen aufrufen, dann dieses Skript loeschen.\n";
} catch (\Throwable $e) {
    echo 'FEHLER: ' . $e->getMessage() . "\n";
}
