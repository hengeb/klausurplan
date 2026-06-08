<?php

declare(strict_types=1);

namespace Klausurplan\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;
use RuntimeException;

class Mailer
{
    /**
     * Sendet eine HTML-E-Mail über SMTP (Konfiguration aus .env).
     *
     * @throws RuntimeException wenn das Senden fehlschlägt
     */
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
    ): void {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'] ?? '';
            $mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 587);
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USER'] ?? '';
            $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
            $mail->SMTPSecure = $_ENV['SMTP_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS; // 'ssl' für Port 465, 'tls' für Port 587
            $mail->CharSet    = PHPMailer::CHARSET_UTF8;

            $fromEmail = $_ENV['SMTP_USER'] ?? '';
            $fromName  = $_ENV['SMTP_FROM_NAME'] ?? 'Klausurplan';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $htmlBody));

            $mail->send();
        } catch (MailerException) {
            throw new RuntimeException('E-Mail konnte nicht gesendet werden: ' . $mail->ErrorInfo);
        }
    }
}
