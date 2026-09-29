<?php
namespace BatSignal;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Mailer
{
    private static function settings(): array
    {
        $stmt = Database::get()->query('SELECT setting_key, setting_value FROM settings');
        $rows = $stmt->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public static function send(string $subject, string $htmlBody): bool
    {
        $s = self::settings();

        if (empty($s['smtp_host']) || empty($s['notify_email'])) {
            error_log('BatSignal Mailer: SMTP not configured, skipping email: ' . $subject);
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $s['smtp_host'];
            $mail->Port = (int)($s['smtp_port'] ?? 587);
            $mail->SMTPAuth = true;
            $mail->Username = $s['smtp_username'] ?? '';
            $mail->Password = $s['smtp_password'] ?? '';
            if (!empty($s['smtp_secure'])) {
                $mail->SMTPSecure = $s['smtp_secure']; // 'tls' or 'ssl'
            }

            $mail->setFrom($s['smtp_from_email'] ?: $s['smtp_username'], $s['smtp_from_name'] ?: 'BatSignal');
            foreach (array_map('trim', explode(',', $s['notify_email'])) as $to) {
                if ($to !== '') {
                    $mail->addAddress($to);
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('BatSignal Mailer error: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
