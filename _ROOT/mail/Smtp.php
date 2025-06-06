<?php
namespace Dzg\Mail;

require __DIR__.'/PHPMailer/PHPMailer.php';
require __DIR__.'/PHPMailer/Exception.php';
#require __DIR__.'/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


/***********************
 * Summary of SMTP
 */
class SMTP
{
    private function __construct() {}

    public static function send($host, $user, $password, $encryption, $port, $from, $fromName, $to, $subject, $body, $attachments, $attachmentFolder, $debug = 0): bool
    {
        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = 'utf-8';
            $mail->Encoding = 'base64';
            $mail->setLanguage('de');
            $mail->SMTPDebug = $debug;
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $password;
            $mail->SMTPSecure = $encryption;
            $mail->Port = $port;
            $mail->setFrom($from, $fromName);
            $mail->addAddress($to);
            $mail->addReplyTo($from);
            foreach($attachments as $attachment) {
                $mail->addAttachment($attachmentFolder . '/' . $attachment, $attachment);
            }
            $mail->Subject = "=?UTF-8?B?".base64_encode($subject)."?=";
            $mail->Body = $body;

            $mail->send();

            return true;

        } catch(Exception $e) {
            return false;
        }
    }
}
