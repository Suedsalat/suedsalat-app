<?php
declare(strict_types=1);

namespace Suedsalat;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

final class Mailer
{
    /** @throws PHPMailerException */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody): void
    {
        // Testumgebung: nichts verschicken, sondern die Mail als Datei ablegen, damit sich
        // Inhalt und Aufmachung pruefen lassen. MAIL_CAPTURE_DIR ist live nie gesetzt.
        if (MAIL_CAPTURE_DIR !== null) {
            // Mikrosekunden im Namen, damit die Dateien auch bei mehreren Mails pro Sekunde in der
            // richtigen Reihenfolge sortieren (Tests lesen "die neueste Mail an X").
            $zeit = explode('.', sprintf('%.6F', microtime(true)));
            $datei = MAIL_CAPTURE_DIR . '/' . date('Ymd-His', (int) $zeit[0]) . '-' . $zeit[1] . '.html';
            file_put_contents($datei, "<!-- An: {$toName} <{$toEmail}> | Betreff: {$subject} -->\n" . $htmlBody);
            return;
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION; // 'tls' oder 'ssl'
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_FROM_ADDRESS, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
    }
}
