<?php

namespace Libraries\Mailer;

use PHPMailer\PHPMailer\PHPMailer as PHPMailerBase;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Mailer {

    private PHPMailerBase $mailer;
    private array $errors = [];

    public function __construct() {
        $this->mailer = new PHPMailerBase(true);

        if (defined('MAIL_HOST')) {
            $this->mailer->isSMTP();
            $this->mailer->Host = MAIL_HOST;
            $this->mailer->Port = MAIL_PORT;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = MAIL_USER;
            $this->mailer->Password = MAIL_PASS;
            $this->mailer->SMTPSecure = defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : PHPMailerBase::ENCRYPTION_STARTTLS;
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->Encoding = 'base64';
        }

        $this->mailer->setFrom(
            defined('MAIL_FROM') ? MAIL_FROM : 'noreply@amazonmarket.com',
            defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'AmazonMarket'
        );
    }

    public function to(string $address, string $name = ''): self {
        $this->mailer->addAddress($address, $name);
        return $this;
    }

    public function cc(string $address, string $name = ''): self {
        $this->mailer->addCC($address, $name);
        return $this;
    }

    public function bcc(string $address): self {
        $this->mailer->addBCC($address);
        return $this;
    }

    public function subject(string $subject): self {
        $this->mailer->Subject = $subject;
        return $this;
    }

    public function html(string $html): self {
        $this->mailer->isHTML(true);
        $this->mailer->Body = $html;
        return $this;
    }

    public function text(string $text): self {
        $this->mailer->isHTML(false);
        $this->mailer->Body = $text;
        return $this;
    }

    public function altBody(string $altBody): self {
        $this->mailer->AltBody = $altBody;
        return $this;
    }

    public function attach(string $filePath, string $fileName = '', string $encoding = PHPMailerBase::ENCODING_BASE64, string $type = ''): self {
        $this->mailer->addAttachment($filePath, $fileName, $encoding, $type);
        return $this;
    }

    public function stringAttach(string $content, string $fileName, string $encoding = PHPMailerBase::ENCODING_BASE64, string $type = 'application/pdf'): self {
        $this->mailer->addStringAttachment($content, $fileName, $encoding, $type);
        return $this;
    }

    public function send(): bool {
        try {
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            $this->errors[] = $this->mailer->ErrorInfo;
            return false;
        }
    }

    public function getErrors(): array {
        return $this->errors;
    }

    public function getErrorInfo(): string {
        return $this->mailer->ErrorInfo ?? '';
    }

    public function reset(): void {
        $this->mailer->clearAddresses();
        $this->mailer->clearCCs();
        $this->mailer->clearBCCs();
        $this->mailer->clearAttachments();
        $this->mailer->Subject = '';
        $this->mailer->Body = '';
        $this->mailer->AltBody = '';
        $this->mailer->isHTML(false);
        $this->errors = [];
    }
}