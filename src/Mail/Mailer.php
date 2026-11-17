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
            $mail->AltBody = self::alsText($htmlBody);

            $mail->send();
        } catch (MailerException) {
            throw new RuntimeException('E-Mail konnte nicht gesendet werden: ' . $mail->ErrorInfo);
        }
    }

    /**
     * Nur-Text-Fassung einer HTML-Mail: Links bleiben als „Text: URL“ erhalten (sonst wären die
     * Anwesenheits-Links in Textansicht verloren), <head> entfällt, Absätze/Zeilen werden zu Umbrüchen.
     */
    public static function alsText(string $html): string
    {
        $text = preg_replace('#<head\b.*?</head>#is', '', $html) ?? $html;
        $text = preg_replace_callback(
            '#<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>#is',
            fn (array $m): string => trim(strip_tags($m[2])) . ': ' . $m[1],
            $text
        ) ?? $text;
        $text = preg_replace('#<br\s*/?>|</(p|tr|h[1-6]|div)>#i', "\n", $text) ?? $text;
        $text = str_ireplace('</td>', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $zeilen = array_map('trim', explode("\n", $text));
        $text   = preg_replace("/\n{3,}/", "\n\n", implode("\n", $zeilen)) ?? '';
        return trim($text) . "\n";
    }
}
