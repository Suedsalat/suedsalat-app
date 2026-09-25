<?php
declare(strict_types=1);

namespace Suedsalat;

/**
 * Mails an registrierte Hoerer, alle im Suedsalat-Briefkopf (render_branded_email_html).
 * Namen und Spitznamen kommen von aussen und werden deshalb immer maskiert.
 * Versandfehler werden nur geloggt - ein Mailproblem darf keinen Anmelde- oder Loeschvorgang
 * abbrechen.
 */
final class ListenerMail
{
    private const P = 'margin:0 0 16px;font-size:16px;line-height:1.5;';

    private static function p(string $html): string
    {
        return '<p style="' . self::P . '">' . $html . '</p>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    private static function send(string $email, string $name, string $subject, string $headline, string $bodyHtml): void
    {
        try {
            Mailer::send($email, $name, $subject, render_branded_email_html($headline, $bodyHtml));
        } catch (\Throwable $e) {
            error_log('Hoerer-Mail fehlgeschlagen (' . $subject . '): ' . $e->getMessage());
        }
    }

    /** Code fuer Registrierung, Anmeldung oder sofortige Kontoloeschung. */
    public static function code(string $email, string $firstName, string $code, string $purpose): void
    {
        $zweck = match ($purpose) {
            'register' => 'um deine Registrierung bei der Südsalat-App abzuschließen',
            'delete_now' => 'um die <strong>sofortige und endgültige Löschung</strong> deines Kontos zu bestätigen',
            default => 'um dich in der Südsalat-App anzumelden',
        };
        $hinweis = $purpose === 'delete_now'
            ? 'Diese Löschung lässt sich nicht rückgängig machen. Falls du das nicht warst, ignoriere diese E-Mail – dann passiert nichts.'
            : 'Falls du das nicht angefordert hast, ignoriere diese E-Mail.';
        $gruss = $firstName !== '' ? 'Hallo ' . self::e($firstName) . ',' : 'Hallo,';

        $body = self::p($gruss)
            . self::p("gib diesen Code in der App ein, {$zweck}:")
            . '<p style="margin:0 0 16px;font-size:28px;font-weight:bold;letter-spacing:4px;text-align:center;">' . self::e($code) . '</p>'
            . self::p('Der Code ist ' . Listener::CODE_TTL_MINUTES . ' Minuten gültig.')
            . '<p style="margin:0;font-size:16px;line-height:1.5;">' . $hinweis . '</p>';

        $betreff = $purpose === 'delete_now' ? 'Kontolöschung bestätigen – Südsalat' : 'Dein Code für die Südsalat-App';
        self::send($email, $firstName, $betreff, $purpose === 'delete_now' ? 'Konto löschen' : 'Dein Code', $body);
    }

    /**
     * Bestaetigung direkt beim Loeschen (erfuellt auch Art. 12 Abs. 3 DSGVO: Information ueber
     * die ausgefuehrte Loeschung). @param array<string,mixed> $l
     */
    public static function deletionConfirmation(array $l, bool $immediate, bool $deleteTexts, bool $deletePhotos): void
    {
        $geloescht = ['dein Konto', 'dein Vor- und Nachname', 'deine E-Mail-Adresse', 'dein Spitzname'];
        if ($deleteTexts) {
            $geloescht[] = 'deine Rezensionen und Kommentare';
        }
        if ($deletePhotos) {
            $geloescht[] = 'deine eigenen Fotos und Videos';
        }
        $bleibt = ['Tipps, die wir aus deinen Vorschlägen übernommen haben', 'deine Nachrichten und Fragen an uns'];
        if (!$deleteTexts) {
            $bleibt[] = 'deine Rezensionen und Kommentare';
        }
        if (!$deletePhotos) {
            $bleibt[] = 'deine Fotos und Videos';
        }
        $liste = static fn (array $punkte): string => '<ul style="margin:0 0 16px;padding-left:20px;font-size:16px;line-height:1.5;">'
            . implode('', array_map(static fn ($x) => '<li>' . $x . '</li>', $punkte)) . '</ul>';

        if ($immediate) {
            $einleitung = 'wie gewünscht haben wir dein Konto <strong>sofort und endgültig</strong> gelöscht. Gelöscht wurden:';
            $schluss = 'Nach dieser E-Mail löschen wir auch deine E-Mail-Adresse. Du kannst dich jederzeit neu registrieren – als neues Mitglied.';
        } else {
            $datum = date('d.m.Y', strtotime((string) $l['deletion_final_at']));
            $einleitung = 'dein Konto ist jetzt <strong>stillgelegt</strong> und wird am <strong>' . $datum
                . '</strong> endgültig gelöscht. Dann werden gelöscht:';
            $schluss = 'Hast du es dir anders überlegt? Melde dich bis zum ' . $datum
                . ' einfach mit dieser E-Mail-Adresse in der App an – dann ist alles wieder da.';
        }

        $body = self::p('Hallo ' . self::e((string) $l['first_name']) . ',')
            . self::p($einleitung) . $liste($geloescht)
            . self::p('Ohne deinen Namen, als <em>„Ehemaliges Mitglied“</em>, bleiben bestehen:') . $liste($bleibt)
            . '<p style="margin:0;font-size:16px;line-height:1.5;">' . $schluss . '</p>';

        self::send((string) $l['email'], (string) $l['first_name'], 'Dein Konto bei Südsalat',
            $immediate ? 'Konto gelöscht' : 'Konto stillgelegt', $body);
    }

    /** Erinnerung drei Tage vor Ablauf der Rueckkehrfrist. @param array<string,mixed> $l */
    public static function deletionReminder(array $l): void
    {
        $datum = date('d.m.Y', strtotime((string) $l['deletion_final_at']));
        $body = self::p('Hallo ' . self::e((string) $l['first_name']) . ',')
            . self::p("dein Konto bei der Südsalat-App wird am <strong>{$datum}</strong> endgültig gelöscht.")
            . '<p style="margin:0;font-size:16px;line-height:1.5;">Wenn du es behalten möchtest, melde dich bis dahin einfach mit dieser E-Mail-Adresse in der App an – dann ist alles wieder da.</p>';
        self::send((string) $l['email'], (string) $l['first_name'], 'Dein Konto wird bald gelöscht – Südsalat', 'Erinnerung', $body);
    }

    /** Anmeldung innerhalb der Rueckkehrfrist. @param array<string,mixed> $l */
    public static function welcomeBack(array $l): void
    {
        $body = self::p('Hallo ' . self::e((string) $l['first_name']) . ',')
            . self::p('schön, dass du wieder da bist! Dein Konto ist wiederhergestellt – mit deinem Spitznamen <strong>'
                . self::e((string) $l['nickname']) . '</strong> und allen deinen Beiträgen.')
            . '<p style="margin:0;font-size:16px;line-height:1.5;">Die Löschung ist damit aufgehoben.</p>';
        self::send((string) $l['email'], (string) $l['first_name'], 'Willkommen zurück – Südsalat', 'Willkommen zurück', $body);
    }
}
