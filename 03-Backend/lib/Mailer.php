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
            $datei = MAIL_CAPTURE_DIR . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.html';
            file_put_contents($datei, "<!-- An: {$toName} <{$toEmail}> | Betreff: {$subject} -->
" . $htmlBody);
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
