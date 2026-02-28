<?php
namespace App\Helpers;

use App\Config\Config;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = Config::get('MAIL_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = Config::get('MAIL_USER');
            $mail->Password = Config::get('MAIL_PASSWORD');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int) Config::get('MAIL_PORT', 587);
            $mail->setFrom(Config::get('MAIL_FROM', 'nao-responder@example.com'), 'Plataforma Academica');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('Mail error: ' . $e->getMessage());
            return false;
        }
    }
}
