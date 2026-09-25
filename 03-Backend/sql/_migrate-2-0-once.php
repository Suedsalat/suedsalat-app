<?php
declare(strict_types=1);

// Migration fuer App-Version 2.0.0 (Hoererkonto, Moderation, Statistik-Einwilligung usw.),
// siehe 01-Brainstorming/Konzept-2.0-Hoererkonto.md. Waechst ueber alle Bau-Etappen mit.
//
// Beliebig oft gefahrlos ausfuehrbar: Tabellen nur, wenn sie fehlen; Spalten nur, wenn sie fehlen.
// Beim Release NACH _add-tip-submitter-name-once.php und VOR dem Hochladen der 2.0-PHP-Dateien
// ausfuehren. Nach Gebrauch UNBEDINGT vom Server loeschen (Sicherheitsrisiko sonst).

$secret = 'suedsalat-migrate20-2026-temp';
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/bootstrap.php';

use Suedsalat\Database;

header('Content-Type: text/plain; charset=utf-8');

$pdo = Database::connection();

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
    $stmt->execute([':t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c');
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function create_table(PDO $pdo, string $table, string $sql): void
{
    if (table_exists($pdo, $table)) {
        echo "OK: {$table} existiert bereits.\n";
        return;
    }
    $pdo->exec($sql);
    echo "OK: {$table} angelegt.\n";
}

function add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (column_exists($pdo, $table, $column)) {
        echo "OK: {$table}.{$column} existiert bereits.\n";
        return;
    }
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    echo "OK: {$table}.{$column} hinzugefuegt.\n";
}

try {
    // ---------------------------------------------------------------------------------------
    // Etappe 2: Hoererkonto
    // ---------------------------------------------------------------------------------------

    // Registrierte Hoerer. Vor- und Nachname erscheinen nie oeffentlich, nur der Spitzname.
    // nickname_key ist die vereinfachte Form (klein, ae/oe/ue/ss, nur a-z0-9) fuer Eindeutigkeit
    // und die Sperrliste. Waehrend der Rueckkehrfrist (deletion_requested_at gesetzt) bleiben
    // E-Mail und Spitzname reserviert - eine erneute Anmeldung stellt alles wieder her.
    create_table($pdo, 'listeners', "CREATE TABLE listeners (
        id INT PRIMARY KEY AUTO_INCREMENT,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL,
        nickname VARCHAR(30) NOT NULL,
        nickname_key VARCHAR(60) NOT NULL,
        email_verified_at DATETIME NOT NULL,
        terms_accepted_at DATETIME NOT NULL,
        terms_version VARCHAR(20) NOT NULL,
        blocked_at DATETIME NULL,
        blocked_reason VARCHAR(255) NULL,
        deletion_requested_at DATETIME NULL,
        deletion_final_at DATETIME NULL,
        deletion_delete_texts TINYINT(1) NOT NULL DEFAULT 0,
        deletion_delete_photos TINYINT(1) NOT NULL DEFAULT 0,
        deletion_reminder_sent_at DATETIME NULL,
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY listeners_email_uq (email),
        UNIQUE KEY listeners_nickname_key_uq (nickname_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Einmal-Codes per Mail: Anmeldung, Registrierung (die Anmeldedaten warten in payload,
    // bis der Code bestaetigt ist - erst dann entsteht das Konto) und sofortige Kontoloeschung.
    // Der Code selbst wird nie gespeichert, nur sein HMAC.
    create_table($pdo, 'listener_login_codes', "CREATE TABLE listener_login_codes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        email VARCHAR(255) NOT NULL,
        purpose ENUM('login','register','delete_now') NOT NULL,
        code_hash CHAR(64) NOT NULL,
        payload TEXT NULL,
        device_id INT NULL,
        attempts INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY listener_login_codes_email_idx (email, purpose)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Welches Geraet ist gerade mit welchem Konto angemeldet. Ein Hoerer kann mehrere Geraete
    // haben; Abmelden loest nur diese Verknuepfung.
    add_column($pdo, 'devices', 'listener_id', 'INT NULL');
    if (column_exists($pdo, 'devices', 'listener_id')) {
        $fk = $pdo->query("SELECT COUNT(*) FROM information_schema.referential_constraints
                           WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_devices_listener'")->fetchColumn();
        if ((int) $fk === 0) {
            $pdo->exec('ALTER TABLE devices ADD CONSTRAINT fk_devices_listener FOREIGN KEY (listener_id) REFERENCES listeners(id) ON DELETE SET NULL');
            echo "OK: Fremdschluessel devices.listener_id angelegt.\n";
        }
    }

    // ---------------------------------------------------------------------------------------
    // Etappe 3: Beitraege gehoeren einem Konto
    // ---------------------------------------------------------------------------------------
    // listener_id: wem der Beitrag gehoert (NULL = Gast-Altbestand oder eigener Suedsalat-Beitrag).
    // Der angezeigte Name kommt live aus dem Konto (Spitzname aendern wirkt ueberall, in der
    // Rueckkehrfrist erscheint "Ehemaliges Mitglied"); submitted_by_name bleibt der Rueckfall.

    foreach (['tip_reviews', 'feedback_messages', 'photos', 'movie_tips', 'location_tips', 'events'] as $table) {
        add_column($pdo, $table, 'listener_id', 'INT NULL');
    }

    // Ausgeblendet statt geloescht: waehrend der Rueckkehrfrist (Haekchen im Loesch-Dialog) und
    // spaeter nach Meldungen (Etappe 4). hidden_reason: 'deletion' oder 'reports'.
    foreach (['tip_reviews', 'photos'] as $table) {
        add_column($pdo, $table, 'hidden_at', 'DATETIME NULL');
        add_column($pdo, $table, 'hidden_reason', 'VARCHAR(20) NULL');
    }

    // Bild-Einstufung beim Uebernehmen: 'own' = eigenes Foto des Einsenders (wird bei
    // "Meine Fotos loeschen" entfernt), 'poster' = Plakat/Flyer/Pressebild (bleibt immer).
    add_column($pdo, 'photos', 'image_kind', "ENUM('own','poster') NOT NULL DEFAULT 'own'");
    foreach (['movie_tips', 'location_tips', 'events'] as $table) {
        add_column($pdo, $table, 'image_kind', "ENUM('own','poster') NULL");
        // Verliert ein Tipp sein Bild durch eine Kontoloeschung, wartet es hier bis zum Ende der
        // Rueckkehrfrist (bei Rueckkehr kommt es zurueck, ausser Thorsten hat schon ein neues
        // hinterlegt). image_removed_at + fehlendes Bild = Hinweis "Tipps ohne Bild".
        add_column($pdo, $table, 'hidden_image_path', 'VARCHAR(500) NULL');
        add_column($pdo, $table, 'image_removed_at', 'DATETIME NULL');
        add_column($pdo, $table, 'image_notice_dismissed_at', 'DATETIME NULL');
    }

    // ---------------------------------------------------------------------------------------
    // Etappe 4: Moderation
    // ---------------------------------------------------------------------------------------

    // Meldungen zu oeffentlichen Beitraegen. Melden duerfen auch Gaeste (reporter_device_id),
    // fuer das automatische Ausblenden zaehlen nur verschiedene registrierte Hoerer.
    create_table($pdo, 'content_reports', "CREATE TABLE content_reports (
        id INT PRIMARY KEY AUTO_INCREMENT,
        content_type VARCHAR(20) NOT NULL,
        content_id INT NOT NULL,
        reporter_listener_id INT NULL,
        reporter_device_id INT NULL,
        category VARCHAR(20) NOT NULL,
        report_text VARCHAR(1000) NULL,
        status ENUM('open','dismissed','removed') NOT NULL DEFAULT 'open',
        handled_by INT NULL,
        handled_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY content_reports_content_idx (content_type, content_id, status),
        KEY content_reports_status_idx (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // "Nutzer ausblenden": listener_id sieht die Beitraege von hidden_listener_id nicht mehr.
    create_table($pdo, 'listener_hidden', "CREATE TABLE listener_hidden (
        listener_id INT NOT NULL,
        hidden_listener_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (listener_id, hidden_listener_id),
        CONSTRAINT fk_listener_hidden_owner FOREIGN KEY (listener_id) REFERENCES listeners(id) ON DELETE CASCADE,
        CONSTRAINT fk_listener_hidden_target FOREIGN KEY (hidden_listener_id) REFERENCES listeners(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ---------------------------------------------------------------------------------------
    // Etappe 5: Statistik nur mit Einwilligung (§ 25 TDDDG)
    // ---------------------------------------------------------------------------------------

    // Aktueller Stand pro Installation - danach richtet sich, ob gezaehlt wird.
    // NULL = noch nicht gefragt (z. B. alte App-Version) -> es wird nichts gezaehlt.
    add_column($pdo, 'devices', 'stats_consent', "ENUM('granted','denied') NULL DEFAULT NULL");
    add_column($pdo, 'devices', 'stats_consent_version', 'VARCHAR(20) NULL DEFAULT NULL');
    add_column($pdo, 'devices', 'stats_consent_at', 'DATETIME NULL DEFAULT NULL');

    // Nachweis (Art. 7 Abs. 1 DSGVO): jede Entscheidung mit Zeitpunkt und Fassung des Dialogtexts.
    // Verschwindet mit der Installation (Geraete werden nach 12 Monaten ohne Nutzung geloescht).
    create_table($pdo, 'statistics_consents', "CREATE TABLE statistics_consents (
        id INT PRIMARY KEY AUTO_INCREMENT,
        device_id INT NOT NULL,
        listener_id INT NULL,
        decision ENUM('granted','denied') NOT NULL,
        text_version VARCHAR(20) NOT NULL,
        platform VARCHAR(10) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY statistics_consents_device_idx (device_id, created_at),
        CONSTRAINT fk_statistics_consents_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
        CONSTRAINT fk_statistics_consents_listener FOREIGN KEY (listener_id) REFERENCES listeners(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "Fertig.\n";
} catch (\Throwable $e) {
    echo 'FEHLER: ' . $e->getMessage() . "\n";
}
